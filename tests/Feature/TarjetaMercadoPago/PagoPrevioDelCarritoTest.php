<?php

namespace Tests\Feature\TarjetaMercadoPago;

use App\Cart;
use App\Jobs\BroadcastOrderCreated;
use App\Jobs\SendOrderEmails;
use App\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Un pago aprobado del carrito bloquea cualquier cobro nuevo (mision `tienda-cobro-tarjeta`, ronda 3
 * de revision, 24/11/2026).
 *
 * ── Por que existe ────────────────────────────────────────────────────────────────────────────
 *
 * La ronda 2 concilio con Mercado Pago, pero solo reusaba un pago aprobado del MISMO monto. Si el comprador
 * cambia de cuotas despues de un 503 (o de un 409 `monto_cambio`), el monto es otro, la SPA manda un token
 * nuevo y se cobraba P2 mientras P1 seguia aprobado. Regla nueva: CUALQUIER pago del carrito
 * (`external_reference` = id del carrito) aprobado y sin devolver bloquea el cobro, sea del monto que sea;
 * el pedido se crea con ESE pago y registra lo que Mercado Pago realmente cobro.
 *
 *  - `cuotas` y `cuotas_cobradas` = `installments` del pago; `cuotas_recargo` = el recargo REAL en porcentaje
 *    sobre el total en 1 pago (`(cobrado - total_en_1_pago) / total_en_1_pago`, minimo 0).
 *  - La respuesta 201 suma `pago_previo: true` (aditivo) para que la SPA lo muestre.
 *  - Si el pago previo es MENOR al total en 1 pago actual (el carrito crecio): 409 `pago_previo_no_cubre`,
 *    sin cobrar y sin pedido.
 *  - Un pago devuelto (`refunded`, `cancelled`, `charged_back`) NO bloquea; y si Mercado Pago contesta el
 *    cobro con un pago de esos (respuesta original repetida por la misma clave de idempotencia) no se crea
 *    pedido: 409 `token_usado`.
 */
class PagoPrevioDelCarritoTest extends TestCase
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
     * Cambiar de cuotas despues de un 503 reusa el pago que quedo aprobado: un pedido, UN solo cobro.
     */
    public function test_cambiar_de_cuotas_tras_un_503_reusa_el_pago_previo_y_no_cobra_de_nuevo()
    {
        $cart = $this->carritoDeTarjeta(true);
        $p1   = $this->pagoDeMp($cart, ['id' => 9001, 'installments' => 3, 'transaction_amount' => 126500]);

        $this->falsearMercadoPago([
            'pagos'     => [$this->sinRespuestaDeMp()],
            'busquedas' => [$this->busquedaDeMp([]), $this->busquedaDeMp([$p1])],
        ]);

        // 1er Pagar en 3 cuotas: Mercado Pago aprobo P1 pero la respuesta no llego.
        $this->pagarConTarjeta($cart, ['tarjeta' => ['token' => 'tok_1']])->assertStatus(503);

        // El comprador cambia a 6 cuotas (138.000): otro monto, token nuevo.
        $respuesta = $this->pagarConTarjeta($cart, [
            'cuotas'         => 6,
            'monto_esperado' => 138000,
            'tarjeta'        => ['token' => 'tok_2'],
        ])->assertStatus(201)->assertJsonPath('pago_previo', true);

        $this->assertCount(1, $this->cobrosAMp(), 'NO se manda un segundo POST /v1/payments.');
        $this->assertSame(1, $this->pedidosDelComercio());

        $pedido = Order::findOrFail((int) $respuesta->json('order_id'));

        $this->assertSame(9001, (int) $pedido->payment_id);
        $this->assertSame(3, (int) $pedido->cuotas, 'Las cuotas son las que Mercado Pago cobro, no las del segundo intento.');
        $this->assertSame(3, (int) $pedido->cuotas_cobradas);
        $this->assertSame('credit_card', $pedido->medio_de_pago_cobrado);
        // 126.500 cobrados contra 115.000 en 1 pago: 11.500 de recargo = 10 %.
        $this->assertEqualsWithDelta(10.0, (float) $pedido->cuotas_recargo, 0.01);
    }

    /**
     * Lo mismo por el camino del carrito que ya tiene el pago aprobado anotado (`GET /v1/payments/{id}`):
     * el comprador pide 1 pago y el pago aprobado es de 3 cuotas.
     */
    public function test_el_pago_aprobado_anotado_en_el_carrito_se_reusa_aunque_sea_de_otro_monto()
    {
        $cart = $this->carritoDeTarjeta(true);

        Cart::whereKey($cart->id)->update(['payment_id' => 9001, 'payment_status' => 'approved']);

        $this->falsearMercadoPago([
            'consulta' => $this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9001, 'installments' => 3, 'transaction_amount' => 126500])),
            'pagos'    => [$this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9002, 'installments' => 1, 'transaction_amount' => 115000]), 201)],
        ]);

        $respuesta = $this->pagarConTarjeta($cart, ['cuotas' => 1, 'monto_esperado' => 115000, 'tarjeta' => ['token' => 'tok_9']])
            ->assertStatus(201)
            ->assertJsonPath('pago_previo', true);

        $this->assertCount(0, $this->cobrosAMp());
        $this->assertSame(9001, (int) Order::findOrFail((int) $respuesta->json('order_id'))->payment_id);
        $this->assertSame(1, $this->pedidosDelComercio());
    }

    /**
     * Si el pago previo es menor al total en 1 pago actual (el carrito crecio), no se cobra ni se crea pedido.
     */
    public function test_un_pago_previo_que_no_cubre_el_total_actual_da_409_sin_cobrar()
    {
        $cart = $this->carritoDeTarjeta(true);
        $p1   = $this->pagoDeMp($cart, ['id' => 9001, 'installments' => 1, 'transaction_amount' => 100000]);

        $this->falsearMercadoPago([
            'pagos'     => [$this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9002]), 201)],
            'busquedas' => [$this->busquedaDeMp([$p1])],
        ]);
        $this->capturarLogs();

        $respuesta = $this->pagarConTarjeta($cart, ['cuotas' => 1, 'monto_esperado' => 115000])
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'pago_previo_no_cubre');

        $this->assertStringContainsString('Ya tenés un pago aprobado de $', $respuesta->json('message'));
        $this->assertStringContainsString('Comunicate con el comercio', $respuesta->json('message'));
        $this->assertCount(0, $this->cobrosAMp());
        $this->assertSame(0, $this->pedidosDelComercio());
        $this->assertNotEmpty(array_filter($this->logs_capturados, function ($linea) {
            return strpos($linea, 'error ') === 0 && strpos($linea, '9001') !== false;
        }), 'Queda un Log::error con el pago.');
    }

    /**
     * Un pago devuelto no bloquea: el cobro nuevo sigue su camino y el pedido queda con el pago nuevo.
     */
    public function test_un_pago_devuelto_no_bloquea_un_cobro_nuevo()
    {
        $cart = $this->carritoDeTarjeta(true);
        $p1   = $this->pagoDeMp($cart, ['id' => 9001, 'status' => 'refunded']);

        $this->falsearMercadoPago([
            'pagos'     => [$this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9002]), 201)],
            'busquedas' => [$this->busquedaDeMp([$p1])],
        ]);

        $respuesta = $this->pagarConTarjeta($cart)->assertStatus(201);

        $this->assertCount(1, $this->cobrosAMp());
        $this->assertSame(9002, (int) Order::findOrFail((int) $respuesta->json('order_id'))->payment_id);
    }

    /**
     * Mercado Pago repite la respuesta ORIGINAL (aprobada) de un token ya usado, pero ese pago figura
     * devuelto en la busqueda: no se crea pedido contra un pago devuelto.
     */
    public function test_no_se_crea_pedido_contra_un_pago_devuelto_aunque_el_cobro_lo_conteste_aprobado()
    {
        $cart     = $this->carritoDeTarjeta(true);
        $devuelto = $this->pagoDeMp($cart, ['id' => 9001, 'status' => 'refunded']);

        $this->falsearMercadoPago([
            // La respuesta del cobro es la original, `approved`, del mismo pago 9001.
            'pagos'     => [$this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9001]), 201)],
            'busquedas' => [$this->busquedaDeMp([$devuelto])],
        ]);
        $this->capturarLogs();

        $this->pagarConTarjeta($cart)
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'token_usado')
            ->assertJsonPath('message', 'Volvé a cargar los datos de la tarjeta para pagar.');

        $this->assertSame(0, $this->pedidosDelComercio());
        Bus::assertNotDispatchedAfterResponse(SendOrderEmails::class);
        $this->assertNotEmpty(array_filter($this->logs_capturados, function ($linea) {
            return strpos($linea, 'warning ') === 0 && strpos($linea, '9001') !== false;
        }), 'Queda un Log::warning con el pago.');
    }

    /**
     * Si la devolucion FALLA (el pago sigue aprobado), el mensaje dice que no se cobra de nuevo, y es cierto:
     * el proximo Pagar reusa ese pago y crea el pedido sin un segundo cobro.
     */
    public function test_si_la_devolucion_falla_el_proximo_intento_reusa_el_pago_sin_cobrar()
    {
        $cart = $this->carritoDeTarjeta(true);
        $p1   = $this->pagoDeMp($cart, ['id' => 9001]);

        $this->falsearMercadoPago([
            'pagos'      => [$this->respuestaDeMp($p1, 201)],
            'devolucion' => $this->respuestaDeMp(['message' => 'refund_failed'], 500),
            // 1ra busqueda: nada. 2da (el reintento): P1 sigue aprobado.
            'busquedas'  => [$this->busquedaDeMp([]), $this->busquedaDeMp([$p1])],
        ]);

        // El pedido falla solo la primera vez que se intenta crear.
        $ya_fallo = false;
        Event::listen('eloquent.creating: App\Order', function () use (&$ya_fallo) {
            if (!$ya_fallo) {
                $ya_fallo = true;

                throw new \RuntimeException('falla simulada al crear el pedido');
            }
        });

        $primera = $this->pagarConTarjeta($cart, ['tarjeta' => ['token' => 'tok_1']])
            ->assertStatus(500)
            ->assertJsonPath('codigo', 'pedido_no_creado');

        $this->assertStringContainsString('no se te cobra de nuevo', $primera->json('message'));
        $this->assertStringNotContainsString('devolvimos el pago', $primera->json('message'));

        $this->pagarConTarjeta($cart, ['tarjeta' => ['token' => 'tok_2']])->assertStatus(201);

        $this->assertCount(1, $this->cobrosAMp(), 'El reintento no cobra de nuevo.');
        $this->assertSame(1, $this->pedidosDelComercio());
    }
}
