<?php

namespace Tests\Feature\TarjetaMercadoPago;

use App\Cart;
use App\Jobs\BroadcastOrderCreated;
use App\Jobs\SendOrderEmails;
use App\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * El pedido entra (y se avisa al comercio) SOLO con el pago con tarjeta aprobado (mision
 * `tienda-cobro-tarjeta`, 24/11/2026): CA4, CA5, CA6 y CA10 del PLAN.
 *
 * ── Por que existe ────────────────────────────────────────────────────────────────────────────
 *
 * Ticket 160 de soporte: con Checkout Pro el pedido se crea ANTES de pagar, y Masquito recibio el
 * mail de pedidos que el comprador cancelo. Con el cobro adentro de la pagina el pedido nace
 * dentro del mismo POST que cobra y solo si Mercado Pago aprobo.
 *
 * ── Que fija esta clase ───────────────────────────────────────────────────────────────────────
 *
 *  - Rechazado: 422 `pago_rechazado` con un mensaje claro para el comprador, sin pedido, sin mails
 *    ni aviso; el carrito queda libre para reintentar con otra tarjeta y queda UN solo pedido.
 *  - En proceso (no deberia pasar con `binary_mode`): se cancela el pago y no hay pedido.
 *  - Mercado Pago sin respuesta: 503 y sin pedido; el reintento usa la MISMA clave de idempotencia.
 *  - `POST /api/orders` con un carrito cuyo metodo es de tarjeta da 422 `pagar_con_tarjeta`: ningun
 *    pedido de tarjeta nace sin cobro. Con otros metodos (Transferencia) todo sigue igual.
 *
 * Mercado Pago esta falseado por URL: nada sale a la red.
 */
class PedidoSoloConPagoAprobadoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCobroConTarjeta;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SendOrderEmails::class, BroadcastOrderCreated::class]);

        $this->armarCobroConTarjeta();
    }

    protected function tearDown(): void
    {
        $this->olvidarCuotas();

        parent::tearDown();
    }

    /**
     * Un pago rechazado de este carrito.
     *
     * @param \App\Cart $cart
     * @param string $detalle `status_detail` de Mercado Pago.
     * @return array Respuesta de guion.
     */
    private function pagoRechazado(Cart $cart, $detalle)
    {
        return $this->respuestaDeMp($this->pagoDeMp($cart, ['status' => 'rejected', 'status_detail' => $detalle]), 201);
    }

    /**
     * CA4: rechazo por fondos -> mensaje claro, nada se crea y nada se avisa.
     */
    public function test_pago_rechazado_no_crea_pedido_ni_avisa_y_da_mensaje_claro()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago(['pagos' => [$this->pagoRechazado($cart, 'cc_rejected_insufficient_amount')]]);

        $this->pagarConTarjeta($cart)
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'pago_rechazado')
            ->assertJsonPath('message', 'Tu tarjeta no tiene fondos suficientes. Probá con otra tarjeta o elegí otro medio de pago.');

        $this->assertSame(0, $this->pedidosDelComercio());
        Bus::assertNotDispatchedAfterResponse(SendOrderEmails::class);
        Bus::assertNotDispatchedAfterResponse(BroadcastOrderCreated::class);
        $this->assertNull($cart->fresh()->order_id, 'El carrito queda sin pedido.');
    }

    /**
     * CA4: despues de un rechazo, otra tarjeta (otro token) aprobada crea el pedido; queda uno solo.
     */
    public function test_despues_de_un_rechazo_se_puede_reintentar_y_queda_un_solo_pedido()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago(['pagos' => [
            $this->pagoRechazado($cart, 'cc_rejected_insufficient_amount'),
            $this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9002]), 201),
        ]]);

        $this->pagarConTarjeta($cart, ['tarjeta' => ['token' => 'tok_1']])->assertStatus(422);

        $segunda = $this->pagarConTarjeta($cart, ['tarjeta' => ['token' => 'tok_2']])->assertStatus(201);

        $pedido = Order::findOrFail((int) $segunda->json('order_id'));

        $this->assertSame(9002, (int) $pedido->payment_id);
        $this->assertSame(1, $this->pedidosDelComercio());
        Bus::assertDispatchedAfterResponseTimes(SendOrderEmails::class, 1);
    }

    /**
     * CA4: un motivo de rechazo que la tabla no conoce igual da un texto claro.
     */
    public function test_status_detail_desconocido_usa_el_texto_por_defecto()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago(['pagos' => [$this->pagoRechazado($cart, 'cc_rejected_motivo_que_no_existe')]]);

        $this->pagarConTarjeta($cart)
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'pago_rechazado')
            ->assertJsonPath('message', 'Tu tarjeta rechazó el pago. Probá con otra tarjeta o elegí otro medio de pago.');
    }

    /**
     * CA5: Mercado Pago contesta `in_process` (con `binary_mode` no deberia): se cancela el pago
     * para que no quede cobrado sin pedido y no se crea nada.
     */
    public function test_pago_en_proceso_se_cancela_y_no_crea_pedido()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago(['pagos' => [
            $this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9002, 'status' => 'in_process', 'status_detail' => 'pending_contingency']), 201),
        ]]);

        $this->pagarConTarjeta($cart)
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'pago_no_confirmado');

        $cancelaciones = $this->llamadasA('PUT', '/v1/payments/9002$');
        $this->assertCount(1, $cancelaciones, 'Se pide cancelar el pago en proceso.');
        $this->assertSame('cancelled', $cancelaciones[0]['body']['status']);

        $this->assertSame(0, $this->pedidosDelComercio());
        Bus::assertNotDispatchedAfterResponse(SendOrderEmails::class);
    }

    /**
     * CA6: Mercado Pago no contesta -> 503 sin pedido. El reintento con el MISMO token manda la
     * MISMA clave de idempotencia (si el primer cobro si entro, Mercado Pago devuelve ese mismo
     * pago en vez de cobrar dos veces) y, aprobado, crea un solo pedido.
     */
    public function test_sin_respuesta_de_mercado_pago_503_y_el_reintento_usa_la_misma_clave()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago(['pagos' => [
            $this->sinRespuestaDeMp(),
            $this->respuestaDeMp($this->pagoDeMp($cart), 201),
        ]]);

        $this->pagarConTarjeta($cart)
            ->assertStatus(503)
            ->assertJsonPath('codigo', 'pago_sin_respuesta');

        $this->assertSame(0, $this->pedidosDelComercio());

        $this->pagarConTarjeta($cart)->assertStatus(201);

        $cobros = $this->cobrosAMp();
        $this->assertCount(2, $cobros);
        $this->assertNotNull($this->claveDeIdempotencia($cobros[0]));
        $this->assertSame($this->claveDeIdempotencia($cobros[0]), $this->claveDeIdempotencia($cobros[1]), 'Misma clave en el reintento.');
        $this->assertSame($this->claveEsperada($cart->id, 'tok_1'), $this->claveDeIdempotencia($cobros[1]));

        $this->assertSame(1, $this->pedidosDelComercio());
    }

    /**
     * CA6: un 500 de Mercado Pago tambien es «sin respuesta» (no un rechazo del comprador).
     */
    public function test_un_error_500_de_mercado_pago_tambien_da_503_sin_pedido()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago(['pagos' => [$this->respuestaDeMp(['message' => 'internal_error'], 500)]]);

        $this->pagarConTarjeta($cart)
            ->assertStatus(503)
            ->assertJsonPath('codigo', 'pago_sin_respuesta');

        $this->assertSame(0, $this->pedidosDelComercio());
    }

    /**
     * CA10: el camino viejo (`POST /orders`) no puede crear un pedido de tarjeta sin cobrarlo: es lo
     * que haria una SPA vieja contra una API nueva.
     */
    public function test_post_orders_con_metodo_de_tarjeta_422_sin_pedido()
    {
        $cart = $this->carritoDeTarjeta(true);

        $this->pedirPedidoComun($cart)
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'pagar_con_tarjeta');

        $this->assertSame(0, $this->pedidosDelComercio());
        Bus::assertNotDispatchedAfterResponse(SendOrderEmails::class);
        Bus::assertNotDispatchedAfterResponse(BroadcastOrderCreated::class);
        $this->assertCount(0, $this->llamadas_a_mp);
    }

    /**
     * CA10: con Transferencia el pedido se crea como siempre (esto ya pasa hoy: es la guarda de que
     * el bloqueo de la tarjeta no toca a los demas metodos).
     */
    public function test_post_orders_con_transferencia_sigue_igual()
    {
        $cart = $this->carritoDeTarjeta(true, $this->metodo_transferencia);

        $respuesta = $this->pedirPedidoComun($cart)->assertStatus(201);

        $pedido = Order::findOrFail((int) $respuesta->json('order_id'));

        $this->assertSame($this->metodo_transferencia->id, (int) $pedido->payment_method_id);
        $this->assertNull($pedido->payment_id, 'Sin cobro online.');
        $this->assertSame(1, $this->pedidosDelComercio());
        Bus::assertDispatchedAfterResponseTimes(SendOrderEmails::class, 1);
        Bus::assertDispatchedAfterResponseTimes(BroadcastOrderCreated::class, 1);
    }
}
