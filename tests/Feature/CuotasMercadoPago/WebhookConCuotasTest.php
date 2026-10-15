<?php

namespace Tests\Feature\CuotasMercadoPago;

use App\Cart;
use App\Http\Controllers\Helpers\CuotasMercadoPagoHelper;
use App\Http\Controllers\MercadoPagoController;
use App\Order;
use App\OrderStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\TestCase;

/**
 * Lo que el webhook anota de lo que Mercado Pago cobro de verdad (mision
 * `tienda-cuotas-mercado-pago`, 15/10/2026).
 *
 * ── Por que existe ────────────────────────────────────────────────────────────────────────────
 *
 * Con Checkout Pro la tienda no puede FORZAR que el comprador pague en las cuotas que eligio:
 * `installments` es un tope y el dinero en cuenta no se puede excluir. Entonces el unico lugar
 * donde se sabe como se pago de verdad es la notificacion del pago, y el webhook lo deja en el
 * pedido (`orders.cuotas_cobradas`, `orders.medio_de_pago_cobrado`).
 *
 * ── Que fija esta clase ───────────────────────────────────────────────────────────────────────
 *  - Con el pago APROBADO y un pedido, se anotan las cuotas y el tipo de medio que trae el pago.
 *  - Si el carrito tenia `cuotas = N` y el pago no coincide (otra cantidad o un medio que no es
 *    tarjeta de credito) queda un `Log::warning` con los ids; si coincide, o si el comprador eligio
 *    contado, no hay aviso.
 *  - Un pago que no esta aprobado no anota nada (no cobro nada) ni avisa.
 *  - Es idempotente, y sin las columnas no se escribe nada.
 *  - La respuesta del webhook sigue siendo solo `ok`: la ruta es publica.
 *
 * Nada sale a la red: el pago se falsea con `Http::fake()`.
 */
class WebhookConCuotasTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCuotasMercadoPago;

    /** Id de pago con la magnitud real de los de Mercado Pago (12 digitos): no entra en un INT. */
    const PAYMENT_ID = 125733412876;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armarCuotas(1000.0);

        // El escenario por defecto: el metodo ofrece cuotas, o sea la feature APLICA a estos carritos.
        // Los casos de "no aplica" arman un metodo sin vinculo o vinculado sin cuotas cargadas.
        $this->cuotasDelMetodo([[3, 10]]);
    }

    protected function tearDown(): void
    {
        $this->olvidarCuotas();

        parent::tearDown();
    }

    /**
     * Un carrito del comercio con su pedido, como los deja `OrderController@store`.
     *
     * @param array $atributos_del_carrito `cuotas`...
     * @param bool $con_pedido
     * @return array{0: \App\Cart, 1: \App\Order|null}
     */
    private function carritoConPedido(array $atributos_del_carrito = [], $con_pedido = true)
    {
        $cart = Cart::create(array_merge([
            'user_id'           => $this->comercio->id,
            'buyer_id'          => null,
            'total'             => 2000,
            'deliver'           => 0,
            'payment_method_id' => $this->payment_method->id,
        ], $atributos_del_carrito));

        if (!$con_pedido) {
            return [$cart, null];
        }

        $estado = OrderStatus::first();

        if (!$estado) {
            $estado = OrderStatus::create(['name' => 'Sin confirmar']);
        }

        $order = Order::create([
            'buyer_id'        => 1,
            'user_id'         => $this->comercio->id,
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'order_status_id' => $estado->id,
        ]);

        $cart->order_id = $order->id;
        $cart->save();

        return [$cart, $order];
    }

    /**
     * El pago tal como lo devuelve la API de Mercado Pago.
     *
     * @param \App\Cart $cart
     * @param array $campos Lo que pisa a los valores por defecto (aprobado, 3 cuotas, tarjeta de credito).
     * @return array
     */
    private function pago(Cart $cart, array $campos = [])
    {
        return array_merge([
            'id'                 => self::PAYMENT_ID,
            'status'             => 'approved',
            'status_detail'      => 'accredited',
            'transaction_amount' => 2200,
            'installments'       => 3,
            'payment_type_id'    => 'credit_card',
            'payment_method_id'  => 'visa',
            'external_reference' => (string) $cart->id,
            'metadata'           => ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id],
        ], $campos);
    }

    /**
     * Mercado Pago avisa del pago: se consulta (falseado) y se procesa.
     *
     * @param array $pago
     * @return \Illuminate\Testing\TestResponse
     */
    private function notificar(array $pago)
    {
        // Fabrica limpia en cada aviso: los stubs de `Http::fake()` se acumulan y gana el PRIMERO, asi
        // que un segundo aviso del mismo pago (con otro contenido) veria la respuesta del primero.
        Http::swap(new HttpFactorySinSalida(app('events')));

        Http::fake([
            MercadoPagoController::API_PAGOS.self::PAYMENT_ID => Http::response($pago, 200),
        ]);

        return $this->postJson(
            '/api/mercado-pago/webhook?commerce_id='.$this->comercio->id.'&type=payment&data.id='.self::PAYMENT_ID,
            ['type' => 'payment', 'data' => ['id' => (string) self::PAYMENT_ID]]
        );
    }

    /**
     * Afirma que quedo (o no) el aviso de desajuste en el log.
     *
     * @return void
     */
    private function assertAvisoDeDesajuste(array $esperado)
    {
        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) use ($esperado) {
            if (strpos($mensaje, 'cobro distinto') === false) {
                return false;
            }

            foreach ($esperado as $clave => $valor) {
                if (!array_key_exists($clave, $contexto) || $contexto[$clave] != $valor) {
                    return false;
                }
            }

            return true;
        })->once();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Lo que se anota
    |---------------------------------------------------------------------------------------------
    */

    public function test_un_pago_aprobado_en_las_cuotas_elegidas_se_anota_sin_aviso()
    {
        Log::spy();

        list($cart, $order) = $this->carritoConPedido(['cuotas' => 3]);

        $this->notificar($this->pago($cart))->assertStatus(200)->assertExactJson(['ok' => true]);

        $order->refresh();

        $this->assertSame(3, (int) $order->cuotas_cobradas);
        $this->assertSame('credit_card', $order->medio_de_pago_cobrado);
        $this->assertSame(self::PAYMENT_ID, (int) $order->payment_id, 'Lo que el webhook ya hacia sigue igual.');

        Log::shouldNotHaveReceived('warning');
    }

    public function test_si_el_comprador_pago_en_menos_cuotas_se_anota_lo_cobrado_y_queda_el_aviso()
    {
        Log::spy();

        list($cart, $order) = $this->carritoConPedido(['cuotas' => 6]);

        $this->notificar($this->pago($cart, ['installments' => 1]))->assertStatus(200);

        $order->refresh();

        $this->assertSame(1, (int) $order->cuotas_cobradas, 'El pedido queda con lo que se cobro de verdad.');
        $this->assertSame('credit_card', $order->medio_de_pago_cobrado);

        $this->assertAvisoDeDesajuste([
            'commerce_id'     => $this->comercio->id,
            'cart_id'         => $cart->id,
            'order_id'        => $order->id,
            'payment_id'      => self::PAYMENT_ID,
            'cuotas_elegidas' => 6,
            'cuotas_cobradas' => 1,
            'medio_cobrado'   => 'credit_card',
        ]);
    }

    public function test_si_el_comprador_pago_con_dinero_en_cuenta_queda_el_aviso()
    {
        Log::spy();

        list($cart, $order) = $this->carritoConPedido(['cuotas' => 3]);

        $this->notificar($this->pago($cart, ['installments' => 1, 'payment_type_id' => 'account_money']))->assertStatus(200);

        $order->refresh();

        $this->assertSame('account_money', $order->medio_de_pago_cobrado);

        $this->assertAvisoDeDesajuste(['cart_id' => $cart->id, 'order_id' => $order->id, 'medio_cobrado' => 'account_money']);
    }

    public function test_con_la_misma_cantidad_pero_con_otro_medio_tambien_hay_aviso()
    {
        Log::spy();

        list($cart) = $this->carritoConPedido(['cuotas' => 3]);

        $this->notificar($this->pago($cart, ['installments' => 3, 'payment_type_id' => 'debit_card']))->assertStatus(200);

        $this->assertAvisoDeDesajuste(['cuotas_elegidas' => 3, 'cuotas_cobradas' => 3, 'medio_cobrado' => 'debit_card']);
    }

    public function test_si_el_comprador_eligio_contado_se_anota_y_no_hay_aviso()
    {
        Log::spy();

        list($cart, $order) = $this->carritoConPedido();

        $this->notificar($this->pago($cart, ['installments' => 1, 'payment_type_id' => 'debit_card']))->assertStatus(200);

        $order->refresh();

        $this->assertSame(1, (int) $order->cuotas_cobradas);
        $this->assertSame('debit_card', $order->medio_de_pago_cobrado);

        Log::shouldNotHaveReceived('warning');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Lo que NO se anota
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Un pago que no esta aprobado no cobro nada: anotarlo dejaria en el pedido «3 cuotas con
     * tarjeta» sobre algo que no se pago.
     */
    public function test_un_pago_que_no_esta_aprobado_no_anota_nada_ni_avisa()
    {
        Log::spy();

        foreach (['rejected', 'in_process', 'pending'] as $estado) {
            list($cart, $order) = $this->carritoConPedido(['cuotas' => 3]);

            $this->notificar($this->pago($cart, ['status' => $estado, 'installments' => 1, 'payment_type_id' => 'account_money']))
                ->assertStatus(200);

            $order->refresh();

            $this->assertNull($order->cuotas_cobradas, 'Estado '.$estado.': no cobro nada.');
            $this->assertNull($order->medio_de_pago_cobrado, 'Estado '.$estado.': no cobro nada.');
            $this->assertSame($estado, $cart->refresh()->payment_status, 'Lo que el webhook ya hacia (el estado del pago) sigue igual.');
        }

        Log::shouldNotHaveReceived('warning');
    }

    public function test_un_pago_que_no_trae_cuotas_ni_medio_no_pisa_lo_anotado_con_null()
    {
        list($cart, $order) = $this->carritoConPedido();

        $this->notificar($this->pago($cart))->assertStatus(200);
        $this->notificar($this->pago($cart, ['installments' => null, 'payment_type_id' => null]))->assertStatus(200);

        $order->refresh();

        $this->assertSame(3, (int) $order->cuotas_cobradas);
        $this->assertSame('credit_card', $order->medio_de_pago_cobrado);
    }

    public function test_notificar_dos_veces_el_mismo_pago_es_idempotente()
    {
        Log::spy();

        list($cart, $order) = $this->carritoConPedido(['cuotas' => 3]);

        $this->notificar($this->pago($cart))->assertStatus(200);
        $this->notificar($this->pago($cart))->assertStatus(200);

        $order->refresh();

        $this->assertSame(3, (int) $order->cuotas_cobradas);
        $this->assertSame('credit_card', $order->medio_de_pago_cobrado);
        $this->assertSame(1, Order::where('id', $order->id)->count());

        Log::shouldNotHaveReceived('warning');
    }

    public function test_un_carrito_sin_pedido_no_rompe_el_webhook_pero_el_aviso_queda()
    {
        Log::spy();

        list($cart) = $this->carritoConPedido(['cuotas' => 6], false);

        $this->notificar($this->pago($cart, ['installments' => 1]))->assertStatus(200)->assertExactJson(['ok' => true]);

        $this->assertAvisoDeDesajuste(['cart_id' => $cart->id, 'cuotas_elegidas' => 6, 'cuotas_cobradas' => 1]);
    }

    public function test_sin_las_columnas_del_cobro_no_se_escribe_nada_en_el_pedido()
    {
        CuotasMercadoPagoHelper::forzar_esquema(['cobro' => false]);

        list($cart, $order) = $this->carritoConPedido(['cuotas' => 3]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->notificar($this->pago($cart))->assertStatus(200)->assertExactJson(['ok' => true]);

        DB::disableQueryLog();

        foreach (DB::getQueryLog() as $consulta) {
            if (preg_match('/^\s*update\s+`orders`/i', $consulta['query'])) {
                $this->assertStringNotContainsString('cuotas_cobradas', $consulta['query']);
                $this->assertStringNotContainsString('medio_de_pago_cobrado', $consulta['query']);
            }
        }

        $order->refresh();

        $this->assertNull($order->cuotas_cobradas);
        $this->assertNull($order->medio_de_pago_cobrado);
        $this->assertSame(self::PAYMENT_ID, (int) $order->payment_id, 'Lo de siempre se sigue escribiendo.');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Solo se anota cuando la feature aplica al carrito
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 «Sin vinculo = exactamente como hoy»: el pedido de un comercio que no usa la feature NO
     * gana el medio ni las cuotas cobradas, por mas que el pago este aprobado.
     */
    public function test_un_metodo_sin_vinculo_no_anota_nada_aunque_el_pago_este_aprobado()
    {
        $sin_vinculo = $this->metodoMercadoPago($this->comercio, null);

        list($cart, $order) = $this->carritoConPedido(['payment_method_id' => $sin_vinculo->id]);

        $this->notificar($this->pago($cart))->assertStatus(200)->assertExactJson(['ok' => true]);

        $order->refresh();

        $this->assertNull($order->cuotas_cobradas);
        $this->assertNull($order->medio_de_pago_cobrado);
        $this->assertSame(self::PAYMENT_ID, (int) $order->payment_id, 'Lo de siempre se sigue escribiendo.');
        $this->assertSame('approved', $cart->refresh()->payment_status);
    }

    public function test_un_metodo_vinculado_sin_cuotas_cargadas_tampoco_anota()
    {
        $sin_filas = $this->metodoMercadoPago($this->comercio, $this->metodoDeCuentaCorriente('Vinculo sin cuotas'));

        list($cart, $order) = $this->carritoConPedido(['payment_method_id' => $sin_filas->id]);

        $this->notificar($this->pago($cart))->assertStatus(200);

        $order->refresh();

        $this->assertNull($order->cuotas_cobradas);
        $this->assertNull($order->medio_de_pago_cobrado);
    }

    public function test_un_carrito_sin_metodo_de_pago_no_anota()
    {
        list($cart, $order) = $this->carritoConPedido(['payment_method_id' => null]);

        $this->notificar($this->pago($cart))->assertStatus(200);

        $order->refresh();

        $this->assertNull($order->cuotas_cobradas);
        $this->assertNull($order->medio_de_pago_cobrado);
    }

    /**
     * Si el comprador ya habia elegido cuotas, la feature le aplico aunque el comerciante las haya
     * sacado despues: el pedido tiene que quedar con lo que se cobro.
     */
    public function test_si_el_comprador_eligio_cuotas_se_anota_aunque_el_comerciante_las_haya_sacado_despues()
    {
        $sin_filas = $this->metodoMercadoPago($this->comercio, $this->metodoDeCuentaCorriente('Vinculo sin cuotas'));

        list($cart, $order) = $this->carritoConPedido(['payment_method_id' => $sin_filas->id, 'cuotas' => 3]);

        $this->notificar($this->pago($cart))->assertStatus(200);

        $order->refresh();

        $this->assertSame(3, (int) $order->cuotas_cobradas);
        $this->assertSame('credit_card', $order->medio_de_pago_cobrado);
    }

    public function test_el_metodo_de_otro_comercio_no_hace_que_la_feature_aplique()
    {
        $otro = $this->comercioConTienda();
        $ajeno = $this->metodoMercadoPago($otro, $this->metodo_cc_id);
        $this->cuota($otro, $this->metodo_cc_id, 3, 10);

        list($cart, $order) = $this->carritoConPedido(['payment_method_id' => $ajeno->id]);

        $this->notificar($this->pago($cart))->assertStatus(200);

        $order->refresh();

        $this->assertNull($order->cuotas_cobradas);
        $this->assertNull($order->medio_de_pago_cobrado);
    }

    /**
     * El aviso de desajuste NO depende de si se anota: con cuotas elegidas y un cobro distinto
     * queda el warning aunque el metodo ya no ofrezca cuotas.
     */
    public function test_el_aviso_de_desajuste_sigue_aunque_el_metodo_no_ofrezca_cuotas()
    {
        Log::spy();

        $sin_vinculo = $this->metodoMercadoPago($this->comercio, null);

        list($cart) = $this->carritoConPedido(['payment_method_id' => $sin_vinculo->id, 'cuotas' => 6]);

        $this->notificar($this->pago($cart, ['installments' => 1]))->assertStatus(200);

        $this->assertAvisoDeDesajuste(['cart_id' => $cart->id, 'cuotas_elegidas' => 6, 'cuotas_cobradas' => 1]);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 🔴 Una falla al leer las cuotas NO rompe el webhook
    |---------------------------------------------------------------------------------------------
    */

    public function test_si_falla_la_lectura_de_cuotas_el_webhook_responde_igual_y_no_anota()
    {
        Log::spy();

        list($cart, $order) = $this->carritoConPedido();

        $this->fallarLasLecturasDeCuotas();

        $this->notificar($this->pago($cart))->assertStatus(200)->assertExactJson(['ok' => true]);

        $this->assertGreaterThanOrEqual(1, $this->lecturas_de_cuotas, 'La falla simulada tiene que haberse disparado.');

        $order->refresh();

        $this->assertNull($order->cuotas_cobradas);
        $this->assertNull($order->medio_de_pago_cobrado);
        $this->assertSame(self::PAYMENT_ID, (int) $order->payment_id, 'El pago se registra como siempre.');
        $this->assertSame('approved', $cart->refresh()->payment_status);

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'no se pudieron leer las cuotas') !== false
                && (int) $contexto['commerce_id'] === (int) $this->comercio->id;
        })->once();
    }
}
