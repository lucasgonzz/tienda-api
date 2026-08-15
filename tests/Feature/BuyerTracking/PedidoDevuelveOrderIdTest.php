<?php

namespace Tests\Feature\BuyerTracking;

use App\Buyer;
use App\Cart;
use App\Order;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST /api/orders` devuelve el id del pedido recien creado
 * (mision tracking-buyers-tienda, pasada de arreglos del 15/8/2026).
 *
 * Que modo de falla protege, que es lo unico que justifica tocar un controller vivo:
 *
 * El evento `checkout_complete` es el que cierra el embudo, y sin `order_id` no hay forma de
 * atar la venta al recorrido del comprador. En la rama de Mercado Pago —el medio de pago
 * DOMINANTE— el SPA (mixins/cart.js) nunca llama a getCurrentOrder, asi que el unico lugar de
 * donde puede sacar el id es la respuesta del POST. Y esa respuesta era `response(null, 201)`:
 * cuerpo vacio. O sea que el grueso de los checkout_complete se iba sin poder atarse a nada.
 *
 * 🔴 El cambio es ESTRICTAMENTE ADITIVO, y por eso fue admisible: se paso de cuerpo vacio a
 * cuerpo con una clave, con el mismo status 201. No rompe al SPA viejo (ignora el cuerpo del
 * 201) ni al SPA nuevo contra una API vieja (lee el id de forma defensiva). Se verifico ademas
 * que no exista ningun otro consumidor del endpoint: el unico POST a /orders de todo el SPA es
 * mixins/cart.js, no hay webhooks de Mercado Pago que peguen ahi, y ningun test afirmaba que el
 * cuerpo estuviera vacio.
 *
 * ⚠️ Este es el primer test que ejercita OrderController@store de punta a punta. Antes no se
 * podia: BroadcastOrderCreatedTest lo dice en su docblock y por eso afirma sobre el fuente. Lo
 * que cambio es que el slot ahora TIENE base de testing con el esquema real (374 tablas), asi
 * que el endpoint se puede correr de verdad contra ella con DatabaseTransactions.
 */
class PedidoDevuelveOrderIdTest extends TestCase
{
    use DatabaseTransactions;

    const RUTA = '/api/orders';

    /** @var \App\User */
    private $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('carts') || !Schema::hasTable('orders')) {
            $this->markTestSkipped('La base del slot no tiene el esquema real de la tienda.');
        }

        $this->comercio = User::create([
            'name'     => 'Comercio de prueba pedidos',
            'email'    => 'pedidos-'.Str::random(10).'@test.local',
            'password' => bcrypt('secreto'),
            'status'   => 'commerce',
        ]);
    }

    /**
     * El 201 trae el id del pedido que se acaba de crear, y es EL id, no cualquier numero.
     *
     * La segunda mitad es la que hace que esto pruebe algo: un `order_id` hardcodeado, o el
     * `num` en vez del `id`, pasarian un test que solo mirara que la clave exista.
     */
    public function test_el_201_devuelve_el_order_id_del_pedido_recien_creado()
    {
        $this->asegurarEstadoSinConfirmar();

        $cart      = $this->crearCarrito();
        $comprador = $this->crearComprador();

        $respuesta = $this->postJson(self::RUTA, [
            'cart_id'     => $cart->id,
            'commerce_id' => $this->comercio->id,
            'buyer_id'    => $comprador->id,
            'address'     => 'San Martin 100',
        ]);

        $respuesta->assertStatus(201);
        $respuesta->assertJsonStructure(['order_id']);

        $pedido = Order::where('user_id', $this->comercio->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($pedido, 'el pedido tiene que haberse creado: si no, no se probo nada');
        $this->assertSame(
            (int) $pedido->id,
            (int) $respuesta->json('order_id'),
            'tiene que ser el id del pedido creado, no el num ni otro numero'
        );
    }

    /**
     * La otra salida del metodo NO se toca.
     *
     * Cuando Mercado Pago vuelve a postear un carrito que ya se consumio,
     * no_es_denuevo_por_mercadopago() da falso y la respuesta sigue siendo un 200 con cuerpo
     * vacio. Ese camino es el que evita crear el pedido dos veces por la misma compra, y este
     * caso esta para que nadie lo "unifique" con el de arriba.
     */
    public function test_el_reintento_de_mercado_pago_sigue_devolviendo_200_sin_cuerpo()
    {
        $respuesta = $this->postJson(self::RUTA, [
            /* Un carrito que no existe es exactamente lo que ve un reintento: ya se borro. */
            'cart_id'     => 0,
            'commerce_id' => $this->comercio->id,
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame('', $respuesta->getContent(), 'ese camino no devuelve cuerpo');

        $this->assertSame(
            0,
            Order::where('user_id', $this->comercio->id)->count(),
            'un reintento no puede crear un pedido nuevo'
        );
    }

    /**
     * Carrito minimo: sin articulos, que es todo lo que este test necesita.
     *
     * store() recorre $cart->articles para adjuntarlos al pedido; con el carrito vacio ese
     * bucle no corre y el camino que importa —crear el pedido y devolver su id— se ejercita
     * igual, sin arrastrar el esquema entero de articulos, variantes y configuracion online.
     *
     * @return \App\Cart
     */
    private function crearCarrito()
    {
        $cart = Cart::create([
            'user_id'  => $this->comercio->id,
            'buyer_id' => null,
            'total'    => 1500,
            'deliver'  => 0,
        ]);

        /* Que quede a la vista que el carrito esta vacio a proposito. */
        $this->assertSame(0, DB::table('article_cart')->where('cart_id', $cart->id)->count());

        return $cart;
    }

    /**
     * El estado inicial del pedido, que store() busca por nombre.
     *
     * `orders.order_status_id` es NOT NULL y la base del slot trae el esquema pero no los datos
     * de catalogo, asi que sin esta fila el endpoint no puede crear ningun pedido. Se siembra
     * solo si falta, y DatabaseTransactions revierte lo que se haya insertado.
     *
     * @return void
     */
    private function asegurarEstadoSinConfirmar()
    {
        $existe = DB::table('order_statuses')->where('name', 'Sin confirmar')->exists();

        if (!$existe) {
            DB::table('order_statuses')->insert([
                'name'       => 'Sin confirmar',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Comprador del pedido. Hace falta de verdad: `orders.buyer_id` es NOT NULL en el esquema
     * real, asi que un pedido sin comprador no se puede crear ni desde el endpoint.
     *
     * Se manda por el payload igual que lo hace el SPA (store() usa
     * `$request->buyer_id ? $request->buyer_id : $this->buyerId()`).
     *
     * @return \App\Buyer
     */
    private function crearComprador()
    {
        return Buyer::create([
            'name'    => 'Comprador de prueba pedidos',
            'email'   => 'comprador-'.Str::random(8).'@test.local',
            'user_id' => $this->comercio->id,
        ]);
    }
}
