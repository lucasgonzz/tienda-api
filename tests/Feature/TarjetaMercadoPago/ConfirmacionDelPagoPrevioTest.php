<?php

namespace Tests\Feature\TarjetaMercadoPago;

use App\Jobs\BroadcastOrderCreated;
use App\Jobs\SendOrderEmails;
use App\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * La busqueda de pagos no se toma como verdad (mision `tienda-cobro-tarjeta`, ronda 3, endurecimiento).
 *
 * Antes de reusar un pago que encontro `GET /v1/payments/search`, se CONFIRMA con `GET /v1/payments/{id}`
 * (aprobado y sin devolucion total) y se exige una marca propia del carrito: `metadata.cart_id` (la escriben
 * nuestro cobro con tarjeta y la preferencia de Checkout Pro). Un pago sin esa marca (por ejemplo de OTRA
 * integracion de la misma cuenta que usa el mismo `external_reference`) ni se reusa ni bloquea: queda un
 * `Log::warning` y se sigue como si no estuviera. Si la confirmacion falla (transporte o 5xx) no se cobra a
 * ciegas: 503.
 *
 * Ademas:
 *  - Si el pedido no se pudo crear y la devolucion FALLO, el pago aprobado queda anotado en el carrito FUERA de
 *    la transaccion que se revirtio: el proximo Pagar lo toma sin depender de que la busqueda ya lo muestre.
 *  - Un 4xx real de Mercado Pago (cuerpo con `"status": 400`) es `pago_rechazado`, no «pago en proceso».
 */
class ConfirmacionDelPagoPrevioTest extends TestCase
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
     * S3: un pago aprobado de OTRA integracion (sin nuestra marca) con el mismo external_reference no se
     * reusa: se cobra, y el pedido queda con el pago nuevo.
     */
    public function test_un_pago_aprobado_sin_la_marca_del_carrito_no_se_reusa()
    {
        $cart  = $this->carritoDeTarjeta(true);
        $ajeno = $this->pagoDeMp($cart, ['id' => 9001, 'metadata' => []]);

        $this->falsearMercadoPago([
            'pagos'     => [$this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9002]), 201)],
            'busquedas' => [$this->busquedaDeMp([$ajeno])],
        ]);
        $this->capturarLogs();

        $respuesta = $this->pagarConTarjeta($cart)->assertStatus(201);

        $this->assertCount(1, $this->cobrosAMp(), 'Se cobra: el pago ajeno no cuenta.');
        $this->assertSame(9002, (int) Order::findOrFail((int) $respuesta->json('order_id'))->payment_id);
        $this->assertNotEmpty(array_filter($this->logs_capturados, function ($linea) {
            return strpos($linea, 'warning ') === 0 && strpos($linea, '9001') !== false;
        }), 'Queda un Log::warning con el pago sin marca.');
    }

    /**
     * Un pago en proceso SIN nuestra marca tampoco bloquea el cobro.
     */
    public function test_un_pago_en_proceso_sin_la_marca_no_bloquea()
    {
        $cart  = $this->carritoDeTarjeta(true);
        $ajeno = $this->pagoDeMp($cart, ['id' => 9001, 'status' => 'in_process', 'metadata' => []]);

        $this->falsearMercadoPago([
            'pagos'     => [$this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9002]), 201)],
            'busquedas' => [$this->busquedaDeMp([$ajeno])],
        ]);

        $this->pagarConTarjeta($cart)->assertStatus(201);

        $this->assertCount(1, $this->cobrosAMp());
    }

    /**
     * S5/H: la busqueda (atrasada) todavia muestra aprobado un pago recien devuelto. La confirmacion con
     * `GET /v1/payments/{id}` lo ve devuelto: no se crea el pedido contra el.
     */
    public function test_la_confirmacion_descarta_un_pago_que_la_busqueda_muestra_aprobado_pero_ya_se_devolvio()
    {
        $cart       = $this->carritoDeTarjeta(true);
        $en_busqueda = $this->pagoDeMp($cart, ['id' => 9001]);
        $devuelto    = $this->pagoDeMp($cart, ['id' => 9001, 'status' => 'refunded', 'transaction_amount_refunded' => 126500]);

        $this->falsearMercadoPago([
            'pagos'     => [$this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9002]), 201)],
            'busquedas' => [$this->busquedaDeMp([$en_busqueda])],
            'consulta'  => $this->respuestaDeMp($devuelto),
        ]);

        $respuesta = $this->pagarConTarjeta($cart)->assertStatus(201);

        $this->assertCount(1, $this->llamadasA('GET', '/v1/payments/9001$'), 'Se confirmo el pago antes de reusarlo.');
        $this->assertSame(9002, (int) Order::findOrFail((int) $respuesta->json('order_id'))->payment_id, 'Nunca un pedido contra el pago devuelto.');
    }

    /**
     * Un pago con la marca del Checkout Pro del mismo carrito (`metadata.cart_id` + `commerce_id`, sin `origen`)
     * se confirma y se reusa.
     */
    public function test_un_pago_de_checkout_pro_del_mismo_carrito_se_confirma_y_se_reusa()
    {
        $cart = $this->carritoDeTarjeta(true);
        $p1   = $this->pagoDeMp($cart, ['id' => 9001, 'metadata' => ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id]]);

        $this->falsearMercadoPago(['busquedas' => [$this->busquedaDeMp([$p1])]]);

        $this->pagarConTarjeta($cart)->assertStatus(201)->assertJsonPath('pago_previo', true);

        $this->assertCount(0, $this->cobrosAMp());
        $this->assertCount(1, $this->llamadasA('GET', '/v1/payments/9001$'));
    }

    /**
     * Si la confirmacion falla (transporte o 5xx), no se cobra a ciegas: 503.
     */
    public function test_si_la_confirmacion_falla_no_se_cobra()
    {
        foreach ([$this->sinRespuestaDeMp(), $this->transporteCortadoDeMp(), $this->respuestaDeMp(['message' => 'internal'], 500)] as $falla) {
            $this->llamadas_a_mp = [];
            $cart = $this->carritoDeTarjeta(true);
            $p1   = $this->pagoDeMp($cart, ['id' => 9001]);

            $this->falsearMercadoPago([
                'pagos'     => [$this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9002]), 201)],
                'busquedas' => [$this->busquedaDeMp([$p1])],
                'consulta'  => $falla,
            ]);

            $this->pagarConTarjeta($cart)->assertStatus(503)->assertJsonPath('codigo', 'pago_sin_respuesta');

            $this->assertCount(0, $this->cobrosAMp());
        }

        $this->assertSame(0, $this->pedidosDelComercio());
    }

    /**
     * Escenario I: el pedido no se pudo crear y la devolucion fallo; la busqueda todavia no muestra P1. El pago
     * aprobado quedo anotado en el carrito (fuera de la transaccion revertida) y el proximo Pagar lo reusa.
     */
    public function test_si_la_devolucion_falla_el_pago_queda_anotado_y_el_proximo_intento_no_cobra()
    {
        $cart = $this->carritoDeTarjeta(true);
        $p1   = $this->pagoDeMp($cart, ['id' => 9001]);

        $this->falsearMercadoPago([
            'pagos'      => [$this->respuestaDeMp($p1, 201)],
            'devolucion' => $this->respuestaDeMp(['message' => 'refund_failed'], 500),
            // La busqueda NUNCA muestra P1 (atrasada): solo el carrito anotado puede salvar el reintento.
            'busquedas'  => [$this->busquedaDeMp([])],
        ]);

        $ya_fallo = false;
        Event::listen('eloquent.creating: App\Order', function () use (&$ya_fallo) {
            if (!$ya_fallo) {
                $ya_fallo = true;

                throw new \RuntimeException('falla simulada al crear el pedido');
            }
        });

        $this->pagarConTarjeta($cart, ['tarjeta' => ['token' => 'tok_1']])->assertStatus(500);

        $this->assertSame(9001, (int) $cart->fresh()->payment_id, 'El pago aprobado quedo anotado en el carrito.');
        $this->assertSame('approved', $cart->fresh()->payment_status);

        $this->pagarConTarjeta($cart, ['tarjeta' => ['token' => 'tok_2']])->assertStatus(201);

        $this->assertCount(1, $this->cobrosAMp(), 'El reintento no cobra de nuevo.');
        $this->assertSame(1, $this->pedidosDelComercio());
    }

    /**
     * S1: un 4xx real de Mercado Pago trae `"status": 400` en el cuerpo (un numero, no el estado de un pago):
     * es un rechazo del cobro, no un pago «en proceso» que haya que cancelar.
     */
    public function test_un_4xx_de_mercado_pago_con_status_numerico_es_pago_rechazado()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago(['pagos' => [
            $this->respuestaDeMp(['message' => 'invalid token', 'error' => 'bad_request', 'status' => 400, 'cause' => []], 400),
        ]]);

        $this->pagarConTarjeta($cart)
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'pago_rechazado');

        $this->assertCount(0, $this->llamadasA('PUT', '/v1/payments/\d+$'), 'No hay pago que cancelar.');
        $this->assertSame(0, $this->pedidosDelComercio());
    }
}
