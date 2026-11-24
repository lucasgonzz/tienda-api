<?php

namespace Tests\Feature\TarjetaMercadoPago;

use App\Jobs\BroadcastOrderCreated;
use App\Jobs\SendOrderEmails;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * La devolucion automatica del pago cuando el pedido no se pudo crear (mision `tienda-cobro-tarjeta`,
 * ronda 2 de revision, 24/11/2026).
 *
 * Si Mercado Pago aprueba el pago y despues explota algo dentro de la transaccion del pedido (aca, la
 * creacion del `Order`), la excepcion se lleva puesto el pedido: el servidor pide UNA devolucion del pago,
 * responde 500 `pedido_no_creado` y no deja ni pedido, ni mails, ni aviso. Si la devolucion tambien falla,
 * el mensaje al comprador NO puede decir que se devolvio el pago (le dice que el comercio se lo devuelve y
 * lo contacta) y queda un `Log::error`.
 */
class DevolucionDelPagoTest extends TestCase
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
     * Hace explotar la creacion del pedido, que ocurre DESPUES del cobro aprobado.
     *
     * @return void
     */
    private function hacerFallarLaCreacionDelPedido()
    {
        Event::listen('eloquent.creating: App\Order', function () {
            throw new \RuntimeException('falla simulada al crear el pedido');
        });
    }

    public function test_si_el_pedido_falla_despues_del_cobro_se_devuelve_el_pago_una_vez()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago(['pagos' => [$this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9001]), 201)]]);
        $this->hacerFallarLaCreacionDelPedido();
        $this->capturarLogs();

        $this->pagarConTarjeta($cart)
            ->assertStatus(500)
            ->assertJsonPath('codigo', 'pedido_no_creado')
            ->assertJsonPath('message', 'No pudimos registrar tu pedido y devolvimos el pago. Probá de nuevo en unos minutos.');

        $devoluciones = $this->llamadasA('POST', '/v1/payments/9001/refunds$');

        $this->assertCount(1, $devoluciones, 'Una sola devolucion, del pago correcto.');
        $this->assertCount(1, $this->cobrosAMp());
        $this->assertSame(0, $this->pedidosDelComercio());
        Bus::assertNotDispatchedAfterResponse(SendOrderEmails::class);
        Bus::assertNotDispatchedAfterResponse(BroadcastOrderCreated::class);

        $this->assertNotEmpty(array_filter($this->logs_capturados, function ($linea) {
            return strpos($linea, 'error ') === 0 && strpos($linea, '9001') !== false;
        }), 'Queda un Log::error con el pago.');
    }

    public function test_si_la_devolucion_falla_el_mensaje_no_dice_que_se_devolvio()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago([
            'pagos'      => [$this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9001]), 201)],
            'devolucion' => $this->respuestaDeMp(['message' => 'refund_failed'], 500),
        ]);
        $this->hacerFallarLaCreacionDelPedido();
        $this->capturarLogs();

        $respuesta = $this->pagarConTarjeta($cart)
            ->assertStatus(500)
            ->assertJsonPath('codigo', 'pedido_no_creado');

        $mensaje = $respuesta->json('message');

        $this->assertStringNotContainsString('devolvimos el pago', $mensaje);
        $this->assertStringContainsString('comercio', $mensaje, 'Le dice que el comercio se lo va a devolver y lo va a contactar.');
        $this->assertSame(0, $this->pedidosDelComercio());

        $this->assertNotEmpty(array_filter($this->logs_capturados, function ($linea) {
            return strpos($linea, 'error ') === 0 && strpos($linea, '9001') !== false && stripos($linea, 'devol') !== false;
        }), 'La devolucion fallida queda en un Log::error.');
    }
}
