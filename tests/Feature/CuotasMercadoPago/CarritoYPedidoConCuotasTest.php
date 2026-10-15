<?php

namespace Tests\Feature\CuotasMercadoPago;

use App\Buyer;
use App\Cart;
use App\Http\Controllers\Helpers\CuotasMercadoPagoHelper;
use App\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lo que elige el comprador en cuotas, del carrito al pedido (mision `tienda-cuotas-mercado-pago`,
 * 15/10/2026).
 *
 * ── Que fija esta clase ───────────────────────────────────────────────────────────────────────
 *
 * CARRITO (`CartController@sync_checkout_fields`):
 *  - `cuotas` del payload queda en `carts.cuotas` SOLO si el metodo de pago que el carrito guarda
 *    ofrece esa cantidad; si no, NULL (metodo que no ofrece cuotas, cantidad no cargada, metodo de
 *    otro comercio, sin metodo, o un payload sin el campo: un SPA viejo).
 *  - Cada escritura del carrito reemplaza lo anterior: un PUT sin `cuotas` lo limpia.
 *
 * PEDIDO (`OrderController@store`):
 *  - copia `carts.cuotas` a `orders.cuotas` y resuelve `orders.cuotas_recargo` (el neto, en
 *    porcentaje) DE LA BASE, no de lo que haya guardado el carrito.
 *  - un carrito sin cuotas crea el pedido de siempre, sin tocar las columnas.
 *
 * SIN COLUMNA: si la base no tiene `carts.cuotas` / `orders.cuotas*`, no se escribe NADA de esto
 * (se mira en las consultas, no solo en el resultado: las columnas de la base de testing SI
 * existen, asi que el unico que las frena es la guarda) y todo sale como antes.
 */
class CarritoYPedidoConCuotasTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCuotasMercadoPago;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armarCuotas(1000.0);
    }

    protected function tearDown(): void
    {
        $this->olvidarCuotas();

        parent::tearDown();
    }

    /**
     * `carts.cuotas` tal como quedo en la base.
     *
     * @param \App\Cart $cart
     * @return int|null
     */
    private function cuotasGuardadasDelCarrito(Cart $cart)
    {
        $valor = DB::table('carts')->where('id', $cart->id)->value('cuotas');

        return is_null($valor) ? null : (int) $valor;
    }

    /**
     * El `PUT /api/carts` del SPA: mismo metodo de pago, una linea del articulo y lo que se pase.
     *
     * @param \App\Cart $cart
     * @param array $campos
     * @return \Illuminate\Testing\TestResponse
     */
    private function actualizarCarrito(Cart $cart, array $campos = [])
    {
        return $this->withSession(['carritos_propios' => [$cart->id]])
            ->putJson('/api/carts', array_merge([
                'id'                   => $cart->id,
                'articles'             => [$this->lineaDelPayload($this->articulo, 2)],
                'promociones_vinoteca' => [],
                'payment_method_id'    => $this->payment_method->id,
            ], $campos));
    }

    /**
     * Las consultas de escritura sobre una tabla que se hicieron durante `$accion`.
     *
     * @param string $tabla
     * @param callable $accion
     * @return array<int, string>
     */
    private function escriturasSobre($tabla, callable $accion)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $accion();

        DB::disableQueryLog();

        $escrituras = [];

        foreach (DB::getQueryLog() as $consulta) {
            $sql = strtolower($consulta['query']);

            if (preg_match('/^\s*(insert into|update)\s+`'.preg_quote($tabla, '/').'`/', $sql)) {
                $escrituras[] = $consulta['query'];
            }
        }

        return $escrituras;
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El carrito
    |---------------------------------------------------------------------------------------------
    */

    public function test_el_carrito_guarda_las_cuotas_que_el_metodo_ofrece()
    {
        $this->cuotasDelMetodo([[3, 10], [6, 20]]);

        $cart = $this->carritoPorElEndpoint(2, ['cuotas' => 6]);

        $this->assertSame(6, $this->cuotasGuardadasDelCarrito($cart));
    }

    public function test_la_respuesta_del_carrito_lleva_las_cuotas_guardadas()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        $respuesta = $this->crearCarrito($this->comercio, [
            'articles'          => [$this->lineaDelPayload($this->articulo, 2)],
            'payment_method_id' => $this->payment_method->id,
            'cuotas'            => 3,
        ]);

        $respuesta->assertStatus(201);
        $this->assertSame(3, (int) $respuesta->json('cart.cuotas'));
    }

    public function test_una_cantidad_que_el_metodo_no_ofrece_se_guarda_como_null()
    {
        $this->cuotasDelMetodo([[3, 10], [6, 20]]);

        $cart = $this->carritoPorElEndpoint(2, ['cuotas' => 4]);

        $this->assertNull($this->cuotasGuardadasDelCarrito($cart));
    }

    public function test_un_metodo_sin_vinculo_guarda_null()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $sin_vinculo = $this->metodoMercadoPago($this->comercio, null);

        $cart = $this->carritoPorElEndpoint(2, ['payment_method_id' => $sin_vinculo->id, 'cuotas' => 3]);

        $this->assertNull($this->cuotasGuardadasDelCarrito($cart));
    }

    public function test_el_metodo_de_pago_de_otro_comercio_no_cuenta()
    {
        $otro_comercio = $this->comercioConTienda();
        $otro_metodo = $this->metodoMercadoPago($otro_comercio, $this->metodo_cc_id);
        $this->cuota($otro_comercio, $this->metodo_cc_id, 3, 10);

        $cart = $this->carritoPorElEndpoint(2, ['payment_method_id' => $otro_metodo->id, 'cuotas' => 3]);

        $this->assertNull($this->cuotasGuardadasDelCarrito($cart), 'Las cuotas de un metodo que no es del comercio del carrito no valen.');
    }

    public function test_un_payload_sin_cuotas_deja_el_carrito_sin_cuotas_como_un_spa_viejo()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        $cart = $this->carritoPorElEndpoint(2);

        $this->assertNull($this->cuotasGuardadasDelCarrito($cart));
    }

    public function test_sin_metodo_de_pago_guarda_null()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        $cart = $this->carritoPorElEndpoint(2, ['cuotas' => 3], false);

        $this->assertNull($this->cuotasGuardadasDelCarrito($cart));
    }

    public function test_la_cantidad_se_acepta_como_texto()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        $cart = $this->carritoPorElEndpoint(2, ['cuotas' => '3']);

        $this->assertSame(3, $this->cuotasGuardadasDelCarrito($cart));
    }

    public function test_cada_escritura_del_carrito_reemplaza_las_cuotas_anteriores()
    {
        $this->cuotasDelMetodo([[3, 10], [6, 20]]);

        $cart = $this->carritoPorElEndpoint(2, ['cuotas' => 3]);
        $this->assertSame(3, $this->cuotasGuardadasDelCarrito($cart));

        $this->actualizarCarrito($cart, ['cuotas' => 6])->assertStatus(200);
        $this->assertSame(6, $this->cuotasGuardadasDelCarrito($cart), 'El PUT cambia la cantidad.');

        $this->actualizarCarrito($cart)->assertStatus(200);
        $this->assertNull($this->cuotasGuardadasDelCarrito($cart), 'Un PUT sin cuotas (contado, o un SPA viejo) las limpia.');

        $this->actualizarCarrito($cart, ['cuotas' => 3])->assertStatus(200);
        $sin_vinculo = $this->metodoMercadoPago($this->comercio, null);
        $this->actualizarCarrito($cart, ['payment_method_id' => $sin_vinculo->id, 'cuotas' => 3])->assertStatus(200);
        $this->assertNull($this->cuotasGuardadasDelCarrito($cart), 'Si cambia a un metodo que no ofrece cuotas, se limpian.');
    }

    public function test_el_resto_del_carrito_no_cambia_por_tener_cuotas()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        $cart = $this->carritoPorElEndpoint(2, ['cuotas' => 3]);

        $this->assertSame(2000.0, (float) $cart->total, 'Las cuotas no tocan el total del carrito: el recargo viaja aparte, en la preferencia.');
        $this->assertSame($this->payment_method->id, (int) $cart->payment_method_id);
    }

    public function test_sin_la_columna_del_carrito_no_se_escribe_nada_de_cuotas()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        CuotasMercadoPagoHelper::forzar_esquema(['carrito' => false]);

        $cart = null;

        $escrituras = $this->escriturasSobre('carts', function () use (&$cart) {
            $cart = $this->carritoPorElEndpoint(2, ['cuotas' => 3]);
        });

        $this->assertNotEmpty($escrituras, 'El carrito se guardo igual.');

        foreach ($escrituras as $consulta) {
            $this->assertStringNotContainsString('`cuotas`', $consulta, 'Sin la columna, ninguna escritura del carrito puede nombrarla.');
        }

        $this->assertNull($this->cuotasGuardadasDelCarrito($cart));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El pedido
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Crea el pedido por `POST /api/orders` desde un carrito guardado directamente (sin lineas:
     * lo que se prueba es la copia de las cuotas, no el armado de los renglones).
     *
     * @param array $atributos_del_carrito `payment_method_id`, `cuotas`...
     * @return array{0: \App\Order, 1: \App\Cart}
     */
    private function crearPedidoDesdeUnCarrito(array $atributos_del_carrito = [])
    {
        if (!DB::table('order_statuses')->where('name', 'Sin confirmar')->exists()) {
            DB::table('order_statuses')->insert(['name' => 'Sin confirmar', 'created_at' => now(), 'updated_at' => now()]);
        }

        $comprador = Buyer::create([
            'name'    => 'Comprador de prueba cuotas',
            'email'   => 'cuotas-'.Str::random(8).'@test.local',
            'user_id' => $this->comercio->id,
        ]);

        $cart = Cart::create(array_merge([
            'user_id'           => $this->comercio->id,
            'buyer_id'          => null,
            'total'             => 2000,
            'deliver'           => 0,
            'payment_method_id' => $this->payment_method->id,
        ], $atributos_del_carrito));

        $respuesta = $this->withSession([
                'carritos_propios'  => [$cart->id],
                'checkout_buyer_id' => $comprador->id,
            ])
            ->postJson('/api/orders', [
                'cart_id'     => $cart->id,
                'commerce_id' => $this->comercio->id,
                'address'     => 'San Martin 100',
            ]);

        $respuesta->assertStatus(201);

        return [Order::findOrFail((int) $respuesta->json('order_id')), $cart];
    }

    public function test_el_pedido_copia_las_cuotas_y_resuelve_el_recargo_de_la_base()
    {
        $this->cuotasDelMetodo([[3, 10], [6, 20]]);

        list($order) = $this->crearPedidoDesdeUnCarrito(['cuotas' => 6]);

        $this->assertSame(6, (int) $order->cuotas);
        $this->assertSame(20.0, (float) $order->cuotas_recargo, 'El neto sale de la base, en porcentaje.');
    }

    public function test_el_recargo_del_pedido_es_el_neto_del_descuento()
    {
        $this->cuota($this->comercio, $this->metodo_cc_id, 3, 15, 5);

        list($order) = $this->crearPedidoDesdeUnCarrito(['cuotas' => 3]);

        $this->assertSame(10.0, (float) $order->cuotas_recargo);
    }

    public function test_un_carrito_sin_cuotas_crea_el_pedido_de_siempre()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        list($order) = $this->crearPedidoDesdeUnCarrito();

        $this->assertNull($order->cuotas);
        $this->assertNull($order->cuotas_recargo);
        $this->assertNull($order->cuotas_cobradas);
        $this->assertNull($order->medio_de_pago_cobrado);
        $this->assertSame($this->payment_method->id, (int) $order->payment_method_id, 'El resto del pedido sale como siempre.');
    }

    public function test_si_el_comerciante_saco_esa_cantidad_entre_el_carrito_y_el_pedido_el_pedido_nace_sin_cuotas_y_queda_en_el_log()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        Log::spy();

        list($order) = $this->crearPedidoDesdeUnCarrito(['cuotas' => 12]);

        $this->assertNull($order->cuotas);
        $this->assertNull($order->cuotas_recargo, 'No hay recargo que resolver si la cantidad ya no se ofrece: no se inventa uno.');

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'ya no ofrece') !== false && $contexto['cuotas'] === 12;
        })->once();
    }

    public function test_sin_las_columnas_del_pedido_no_se_escribe_nada_de_cuotas()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        CuotasMercadoPagoHelper::forzar_esquema(['pedido' => false]);

        $order = null;

        $escrituras = $this->escriturasSobre('orders', function () use (&$order) {
            list($order) = $this->crearPedidoDesdeUnCarrito(['cuotas' => 3]);
        });

        $this->assertNotEmpty($escrituras, 'El pedido se creo igual.');

        foreach ($escrituras as $consulta) {
            $this->assertStringNotContainsString('`cuotas', $consulta, 'Sin las columnas, ninguna escritura del pedido puede nombrarlas.');
        }

        $this->assertNull($order->cuotas);
        $this->assertNull($order->cuotas_recargo);
    }
}
