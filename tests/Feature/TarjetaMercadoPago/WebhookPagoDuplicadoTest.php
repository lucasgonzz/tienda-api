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
 * El webhook no pisa un pago aprobado con OTRO pago aprobado (mision `tienda-cobro-tarjeta`, ronda 2
 * de revision, 24/11/2026).
 *
 * Si por una falla de red se cobraron dos pagos del mismo carrito (P1 y P2) y el pedido quedo con P2, la
 * notificacion de P1 no puede reescribir `payment_id` del pedido: seria perder el rastro del cobro que
 * quedo en el pedido. Se deja el pedido como esta y queda un `Log::error` con los DOS ids (alarma de pago
 * duplicado para devolver uno).
 */
class WebhookPagoDuplicadoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCobroConTarjeta;

    /** Pago con el que quedo el pedido. */
    const PAGO_DEL_PEDIDO = 9002;

    /** Pago duplicado que notifica Mercado Pago despues. */
    const PAGO_DUPLICADO = 9001;

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
     * Un carrito con su pedido, los dos ya pagados con P2 (aprobado).
     *
     * @return array{0: \App\Cart, 1: \App\Order}
     */
    private function carritoConPedidoPagadoConP2()
    {
        $estado = OrderStatus::first() ?: OrderStatus::create(['name' => 'Sin confirmar']);

        $order = Order::create([
            'buyer_id'        => $this->comprador->id,
            'user_id'         => $this->comercio->id,
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'order_status_id' => $estado->id,
            'payment_id'      => self::PAGO_DEL_PEDIDO,
        ]);

        $cart = Cart::create([
            'user_id'           => $this->comercio->id,
            'buyer_id'          => null,
            'total'             => 100000,
            'deliver'           => 0,
            'payment_method_id' => $this->metodo_tarjeta->id,
            'payment_id'        => self::PAGO_DEL_PEDIDO,
            'payment_status'    => 'approved',
            'order_id'          => $order->id,
        ]);

        return [$cart, $order];
    }

    /**
     * Mercado Pago avisa del pago P1 (aprobado, mismo carrito).
     *
     * @param \App\Cart $cart
     * @return \Illuminate\Testing\TestResponse
     */
    private function notificarP1(Cart $cart)
    {
        $pago = $this->pagoDeMp($cart, ['id' => self::PAGO_DUPLICADO]);

        Http::swap(new HttpFactorySinSalida(app('events')));

        Http::fake([
            MercadoPagoController::API_PAGOS.self::PAGO_DUPLICADO => Http::response($pago, 200),
        ]);

        return $this->postJson(
            '/api/mercado-pago/webhook?commerce_id='.$this->comercio->id.'&type=payment&data.id='.self::PAGO_DUPLICADO,
            ['type' => 'payment', 'data' => ['id' => (string) self::PAGO_DUPLICADO]]
        );
    }

    public function test_el_webhook_de_un_pago_aprobado_distinto_no_pisa_el_del_pedido_y_loguea_error()
    {
        list($cart, $order) = $this->carritoConPedidoPagadoConP2();
        $this->capturarLogs();

        $this->notificarP1($cart)->assertStatus(200);

        $this->assertSame(self::PAGO_DEL_PEDIDO, (int) $order->fresh()->payment_id, 'El pedido sigue con su pago.');
        $this->assertSame(self::PAGO_DEL_PEDIDO, (int) $cart->fresh()->payment_id);
        $this->assertNull($order->fresh()->cuotas_cobradas, 'Tampoco se anota el cobro del pago duplicado.');

        $alarmas = array_filter($this->logs_capturados, function ($linea) {
            return strpos($linea, 'error ') === 0
                && strpos($linea, (string) self::PAGO_DEL_PEDIDO) !== false
                && strpos($linea, (string) self::PAGO_DUPLICADO) !== false;
        });

        $this->assertNotEmpty($alarmas, 'Tiene que quedar un Log::error con los dos ids de pago.');
    }
}
