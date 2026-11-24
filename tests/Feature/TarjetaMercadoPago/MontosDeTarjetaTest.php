<?php

namespace Tests\Feature\TarjetaMercadoPago;

use App\Http\Controllers\Helpers\CuotasMercadoPagoHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Los montos que ve el comprador en el checkout con tarjeta (mision `tienda-cobro-tarjeta`,
 * 24/11/2026): CA1 y CA15 del PLAN.
 *
 * ── Que fija esta clase ───────────────────────────────────────────────────────────────────────
 *
 *  - `POST /api/mercado-pago/tarjeta/montos` devuelve las opciones de cuotas CALCULADAS POR EL
 *    SERVIDOR: precio web (con el `online_price_surchage` de la tienda) + envio, y sobre esa base el
 *    recargo de cada fila de `cuotas`. Siempre existe «1 pago» (regla 4: sin recargo adicional).
 *  - El comprador ve solo el valor de cada cuota; el `total` viaja para que la SPA lo mande de vuelta
 *    como `monto_esperado`.
 *  - Un carrito que no es de la sesion da 403; un metodo que no es de tarjeta o es de otro comercio, 422.
 *  - El vinculo de cuotas vale para los metodos de tipo Tarjeta Mercado Pago y sigue sin valer para
 *    los demas (Transferencia).
 *
 * Escenario E1: ver `ArmaCobroConTarjeta`. Nada sale a la red.
 */
class MontosDeTarjetaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCobroConTarjeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armarCobroConTarjeta();
    }

    protected function tearDown(): void
    {
        $this->olvidarCuotas();

        parent::tearDown();
    }

    /**
     * CA1: con envio. Cuentas: web 105.000 + envio 10.000 = 115.000; 3 cuotas: recargo
     * `round(115000 x 10 / 100, 2)` = 11.500 -> 126.500 / 3 = 42.166,67; 6 cuotas: +23.000 -> 138.000 / 6 = 23.000.
     */
    public function test_las_opciones_salen_del_servidor_con_recargo_de_tienda_envio_y_cuotas()
    {
        $cart = $this->carritoDeTarjeta(true);

        $respuesta = $this->pedirMontos($cart)->assertStatus(200);

        $this->assertOpciones($this->opcionesDeE1ConEnvio(), $respuesta->json('opciones'));
    }

    /**
     * CA1: sin envio (retiro). 1 pago 105.000; 3 cuotas: 105.000 + 10.500 = 115.500 -> 38.500,00.
     */
    public function test_sin_envio_las_cuotas_se_calculan_sobre_el_precio_web()
    {
        $cart = $this->carritoDeTarjeta(false);

        $respuesta = $this->pedirMontos($cart)->assertStatus(200);

        $opciones = $respuesta->json('opciones');

        $this->assertOpciones([
            ['cuotas' => 1, 'valor_cuota' => 105000.00, 'total' => 105000.00],
            ['cuotas' => 3, 'valor_cuota' => 38500.00,  'total' => 115500.00],
            ['cuotas' => 6, 'valor_cuota' => 21000.00,  'total' => 126000.00],
        ], $opciones);
    }

    /**
     * CA1: un carrito que no es de esta sesion (ni invitado ni comprador) no se puede cotizar.
     */
    public function test_carrito_ajeno_403()
    {
        $cart = $this->carritoDeTarjeta(true);

        // Sin `carritos_propios` ni comprador de checkout en la sesion: es el carrito de otro.
        // El armado del carrito (`POST /api/carts`) deja su id en `carritos_propios` y la sesion del test
        // persiste entre pedidos: sin vaciarla, este carrito seria «propio» y no el de otro.
        $this->flushSession();

        $this->postJson('/api/mercado-pago/tarjeta/montos', [
            'cart_id'           => $cart->id,
            'payment_method_id' => $this->metodo_tarjeta->id,
        ])->assertStatus(403);
    }

    /**
     * CA1: el metodo tiene que ser de tipo Tarjeta Mercado Pago Y del comercio del carrito.
     */
    public function test_metodo_que_no_es_tarjeta_o_de_otro_comercio_422()
    {
        $cart = $this->carritoDeTarjeta(true);

        // Un metodo de tarjeta de OTRO comercio.
        $otro_comercio = $this->comercioConTienda();
        $ajeno         = $this->metodoDeTarjeta($otro_comercio, $this->metodo_cc_id);

        $this->pedirMontos($cart, $ajeno)->assertStatus(422);

        // El Checkout Pro (tipo MercadoPago) y la transferencia no son «tarjeta en la pagina».
        $this->pedirMontos($cart, $this->metodo_mp)->assertStatus(422);
        $this->pedirMontos($cart, $this->metodo_transferencia)->assertStatus(422);
    }

    /**
     * CA15: `cuotas_disponibles()` ofrece las cuotas del metodo de tarjeta; un metodo de tipo
     * Transferencia, aunque este vinculado y haya cuotas cargadas, sigue sin ofrecer (`[]`).
     */
    public function test_el_vinculo_de_cuotas_vale_para_tarjeta_y_no_para_transferencia()
    {
        $this->assertSame([
            ['cantidad_cuotas' => 3, 'recargo' => '10.00'],
            ['cantidad_cuotas' => 6, 'recargo' => '20.00'],
        ], CuotasMercadoPagoHelper::cuotas_disponibles($this->metodo_tarjeta));

        $this->metodo_transferencia->current_acount_payment_method_id = $this->metodo_cc_id;
        $this->metodo_transferencia->save();

        CuotasMercadoPagoHelper::olvidar();

        $this->assertSame([], CuotasMercadoPagoHelper::cuotas_disponibles($this->metodo_transferencia->fresh()));
    }
}
