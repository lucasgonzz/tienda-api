<?php

namespace Tests\Feature\CatalogoPorLista;

use App\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (j) EL COMERCIO DEL PEDIDO SALE DEL CARRITO GUARDADO, NO DEL PAYLOAD (mision
 * catalogo-por-lista-tienda, 5/10/2026, revision independiente: hallazgo M2).
 *
 * ── El defecto que fija ──────────────────────────────────────────────────────────────────────────
 *
 * La red de seguridad del pedido (`CatalogoPorListaHelper::no_visibles_del_carrito()`) evalua la
 * restriccion contra `$cart->user_id`, el comercio con el que se GUARDO el carrito. Pero
 * `OrderController@store` creaba el pedido con `$request->commerce_id`, un valor del payload, tanto en
 * `orders.user_id` como en el `num`. Cuando la restriccion sale de la `position` (un visitante, o un
 * logueado sin cliente del ERP, con la lista mas alta restringida) eso se esquivaba en dos pasos:
 *
 *   1. `POST /api/carts` con `commerce_id` de CUALQUIER otro `users.id` sin listas: ahi no hay lista
 *      restringida, no se descarta nada y se guarda el articulo no habilitado.
 *   2. `POST /api/orders` con `commerce_id` = el comercio REAL: la red miraba el comercio falso y
 *      dejaba pasar, y el pedido se creaba en el ERP real con un articulo que su lista no habilita.
 *
 * Es exactamente el "POST armado a mano" que la red declara cubrir. Ahora el pedido nace en el comercio
 * del carrito (`user_id` y `num`), asi que nunca queda en uno que el carrito no es.
 *
 * Ferretotal (la lista se asigna por CLIENTE del ERP) no estaba expuesto: la eleccion por cliente no
 * mira el comercio.
 */
class ComercioDelPedidoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCatalogoPorLista;

    /** @var int `order_statuses.id` de "Sin confirmar", que `OrderController@store` busca por nombre. */
    private $estado_sin_confirmar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();
        $this->armarFerretotal();

        /* El escenario de esta clase: la restriccion sale de la `position`. La lista mas alta (la
           Minorista, la del visitante y la del logueado sin cliente) es la restringida, y solo el
           `habilitado` esta habilitado para ella. */
        $this->restringirLista($this->minorista);

        DB::table('article_price_type')
            ->where('article_id', $this->habilitado->id)
            ->where('price_type_id', $this->minorista->id)
            ->update(['visible_en_tienda' => 1]);

        $existente = DB::table('order_statuses')->where('name', 'Sin confirmar')->value('id');

        if (is_null($existente)) {
            $existente = DB::table('order_statuses')->insertGetId([
                'name'       => 'Sin confirmar',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        $this->estado_sin_confirmar = (int) $existente;
    }

    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * 🔴 El carrito se guarda con un comercio ajeno (sin listas: no descarta nada) y el pedido se pide
     * con el comercio real. El pedido NO puede quedar en el comercio real con un articulo que su lista
     * no habilita: nace en el comercio del carrito, con la numeracion de ESE comercio.
     */
    public function test_el_pedido_nace_en_el_comercio_del_carrito_y_no_en_el_del_payload()
    {
        $comprador = $this->compradorSinCliente($this->comercio);
        $this->comoComprador($comprador);

        /* Premisa: con el comercio REAL el carrito si descarta lo no habilitado. */
        $real = $this->postJson('/api/carts', $this->payloadDeCarrito($this->comercio->id, [$this->sin_marcar]));
        $real->assertStatus(201);
        $this->assertSame([$this->sin_marcar->id], array_column($real->json('articulos_no_disponibles'), 'id'),
            'el comercio real restringe: el carrito no deja pasar el articulo no habilitado');

        /* El comercio ajeno: otro users.id con su configuracion online y sin listas. */
        $ajeno = $this->comercioConTienda();

        /* El comercio real ya tiene pedidos: la numeracion de uno y otro comercio no coincide. */
        $this->pedidoExistenteDelComercioReal($comprador->id, 41);

        $carrito = $this->postJson('/api/carts', $this->payloadDeCarrito($ajeno->id, [$this->sin_marcar]));
        $carrito->assertStatus(201);
        $this->assertArrayNotHasKey('articulos_no_disponibles', $carrito->json(),
            'premisa del ataque: en el comercio ajeno no hay lista restringida y el carrito guarda el articulo');
        $cart_id = (int) $carrito->json('cart.id');

        $pedidos_del_real = Order::where('user_id', $this->comercio->id)->count();

        $respuesta = $this->postJson('/api/orders', [
            'cart_id'     => $cart_id,
            'commerce_id' => $this->comercio->id,
            'address'     => 'San Martin 100',
        ]);

        $this->assertSame($pedidos_del_real, Order::where('user_id', $this->comercio->id)->count(),
            'no se creo ningun pedido en el comercio real con un articulo que su lista no habilita');

        $this->assertContains($respuesta->getStatusCode(), [201, 422], 'o se crea en el comercio del carrito o se corta');

        if ($respuesta->getStatusCode() == 201) {
            $pedido = Order::find($respuesta->json('order_id'));

            $this->assertSame((int) $ajeno->id, (int) $pedido->user_id, 'el pedido nace en el comercio del carrito');
            $this->assertSame(1, (int) $pedido->num, 'y con la numeracion de ese comercio, no la del payload (41 + 1)');
        }
    }

    /**
     * Control del camino de siempre: el comercio del payload es el del carrito (lo que hace el SPA) y el
     * pedido nace ahi, con su numeracion.
     */
    public function test_con_el_mismo_comercio_en_el_carrito_y_en_el_payload_el_pedido_es_el_de_siempre()
    {
        $comprador = $this->compradorSinCliente($this->comercio);
        $this->comoComprador($comprador);

        $this->pedidoExistenteDelComercioReal($comprador->id, 41);

        $cart_id = (int) $this->postJson('/api/carts', $this->payloadDeCarrito($this->comercio->id, [$this->habilitado]))
            ->assertStatus(201)
            ->json('cart.id');

        $respuesta = $this->postJson('/api/orders', [
            'cart_id'     => $cart_id,
            'commerce_id' => $this->comercio->id,
            'address'     => 'San Martin 100',
        ]);

        $respuesta->assertStatus(201);

        $pedido = Order::find($respuesta->json('order_id'));

        $this->assertSame((int) $this->comercio->id, (int) $pedido->user_id);
        $this->assertSame(42, (int) $pedido->num, 'la numeracion sigue la del comercio: 41 + 1');
    }

    /**
     * Un pedido ya cargado del comercio real, con un numero alto.
     *
     * @param  int  $buyer_id
     * @param  int  $num
     * @return void
     */
    private function pedidoExistenteDelComercioReal($buyer_id, $num)
    {
        DB::table('orders')->insert([
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'buyer_id'        => $buyer_id,
            'order_status_id' => $this->estado_sin_confirmar,
            'user_id'         => $this->comercio->id,
            'num'             => $num,
            'created_at'      => Carbon::now(),
            'updated_at'      => Carbon::now(),
        ]);
    }

    /**
     * El payload de `POST /api/carts` con una linea por articulo.
     *
     * @param  int  $commerce_id
     * @param  array  $articulos
     * @return array
     */
    private function payloadDeCarrito($commerce_id, array $articulos)
    {
        $lineas = [];

        foreach ($articulos as $articulo) {
            /** @var \App\Article $articulo */
            $lineas[] = [
                'id'          => $articulo->id,
                'user_id'     => $articulo->user_id,
                'name'        => $articulo->name,
                'final_price' => 1500,
                'cost'        => null,
                'amount'      => 1,
                'pivot'       => ['amount' => 1, 'notes' => null, 'variant_id' => null],
            ];
        }

        return [
            'commerce_id' => $commerce_id,
            'cart'        => [
                'articles'             => $lineas,
                'promociones_vinoteca' => [],
            ],
        ];
    }
}
