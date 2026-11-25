<?php

namespace Tests\Feature\TarjetaMercadoPago;

use App\Cart;
use App\Http\Controllers\MercadoPagoController;
use App\Order;
use App\OrderStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\TestCase;

/**
 * El webhook anota con que se pago TODO pago aprobado (mision `tienda-cobro-tarjeta`, 24/11/2026):
 * CA12 del PLAN.
 *
 * ── Por que existe ────────────────────────────────────────────────────────────────────────────
 *
 * Ticket 160 de soporte: los pedidos de Masquito pagados con dinero en cuenta de Mercado Pago no
 * mostraron nada en la ficha, porque el webhook solo anotaba `cuotas_cobradas` y
 * `medio_de_pago_cobrado` cuando la feature de cuotas aplicaba al carrito. La regla 8 del pedido:
 * en la ficha se ve con que medio pago y en cuantas cuotas, para TODO pago.
 *
 * Aca el caso nuevo: un metodo `MercadoPago` SIN vinculo de cuotas (la feature no aplica) y un pago
 * aprobado con dinero en cuenta -> el pedido queda con el medio y la cantidad. Un pago rechazado
 * sigue sin anotar nada. (Los cinco tests viejos de `CuotasMercadoPago/WebhookConCuotasTest` que
 * fijaban la regla contraria quedaron actualizados.)
 *
 * Nada sale a la red: el pago se falsea con `Http::fake()`.
 */
class WebhookAnotaTodoPagoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCobroConTarjeta;

    /** Id de pago con la magnitud real de los de Mercado Pago (12 digitos). */
    const PAYMENT_ID = 125733412876;

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
     * Un carrito del comercio con su pedido, como los deja `OrderController@store`, pagando con el
     * metodo MercadoPago SIN vinculo de cuotas.
     *
     * @return array{0: \App\Cart, 1: \App\Order}
     */
    private function carritoConPedido()
    {
        $cart = Cart::create([
            'user_id'           => $this->comercio->id,
            'buyer_id'          => null,
            'total'             => 2000,
            'deliver'           => 0,
            'payment_method_id' => $this->metodo_mp->id,
        ]);

        $estado = OrderStatus::first() ?: OrderStatus::create(['name' => 'Sin confirmar']);

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
     * Mercado Pago avisa del pago: se consulta (falseado) y se procesa.
     *
     * @param \App\Cart $cart
     * @param array $campos Pisa el pago (por defecto aprobado, dinero en cuenta, 1 cuota).
     * @return \Illuminate\Testing\TestResponse
     */
    private function notificar(Cart $cart, array $campos = [])
    {
        $pago = array_merge([
            'id'                 => self::PAYMENT_ID,
            'status'             => 'approved',
            'status_detail'      => 'accredited',
            'transaction_amount' => 105000,
            'installments'       => 1,
            'payment_type_id'    => 'account_money',
            'payment_method_id'  => 'account_money',
            'external_reference' => (string) $cart->id,
            'metadata'           => ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id],
        ], $campos);

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
     * CA12: sin vinculo de cuotas (la feature no aplica) y pagado con dinero en cuenta, el pedido
     * igual queda con el medio y la cantidad de cuotas cobradas.
     */
    public function test_dinero_en_cuenta_sin_feature_se_anota()
    {
        list($cart, $order) = $this->carritoConPedido();

        $this->notificar($cart)->assertStatus(200)->assertExactJson(['ok' => true]);

        $order->refresh();

        $this->assertSame('account_money', $order->medio_de_pago_cobrado);
        $this->assertSame(1, (int) $order->cuotas_cobradas);
        $this->assertSame(self::PAYMENT_ID, (int) $order->payment_id, 'Lo que el webhook ya hacia sigue igual.');
    }

    /**
     * CA12: un pago rechazado no cobro nada: no se anota nada.
     */
    public function test_rechazado_sigue_sin_anotar()
    {
        list($cart, $order) = $this->carritoConPedido();

        $this->notificar($cart, ['status' => 'rejected', 'status_detail' => 'cc_rejected_other_reason'])->assertStatus(200);

        $order->refresh();

        $this->assertNull($order->cuotas_cobradas);
        $this->assertNull($order->medio_de_pago_cobrado);
        $this->assertSame('rejected', $cart->refresh()->payment_status, 'El estado del pago se registra como siempre.');
    }
}
