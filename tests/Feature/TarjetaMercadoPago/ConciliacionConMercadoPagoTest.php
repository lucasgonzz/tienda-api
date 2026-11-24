<?php

namespace Tests\Feature\TarjetaMercadoPago;

use App\Jobs\BroadcastOrderCreated;
use App\Jobs\SendOrderEmails;
use App\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Nunca se cobra dos veces el mismo carrito (mision `tienda-cobro-tarjeta`, ronda 2 de revision, 24/11/2026).
 *
 * ── Por que existe ────────────────────────────────────────────────────────────────────────────
 *
 * La clave de idempotencia es `cart_id` + sha256(token), y la SPA pide un token NUEVO al Brick en cada
 * «Pagar». Entonces, si Mercado Pago aprueba el pago P1 pero la respuesta no llega (conexion cortada,
 * 5xx, cURL 56), el reintento sale con otro token, otra clave, y cobra P2 mientras P1 quedo aprobado.
 * Antes de cobrar, el servidor CONCILIA con Mercado Pago: busca los pagos de ese carrito
 * (`GET /v1/payments/search?external_reference=<cart_id>`) y
 *  - si hay uno aprobado por el monto esperado, crea el pedido con ESE pago y no vuelve a cobrar;
 *  - si hay uno en proceso, no cobra de nuevo;
 *  - si la busqueda misma falla, no cobra «a ciegas»: 503.
 * Tambien se atrapan los cortes de transporte que Guzzle tira como `RequestException` (no solo `ConnectionException`).
 *
 * Mercado Pago esta falseado por URL: nada sale a la red.
 */
class ConciliacionConMercadoPagoTest extends TestCase
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
     * El reintento despues de un 503, con OTRO token, reusa el pago que quedo aprobado: un pedido, un solo cobro.
     */
    public function test_el_reintento_con_otro_token_reusa_el_pago_aprobado_y_no_cobra_dos_veces()
    {
        $cart = $this->carritoDeTarjeta(true);
        $p1   = $this->pagoDeMp($cart, ['id' => 9001]);

        $this->falsearMercadoPago([
            // 1er Pagar: Mercado Pago aprobo P1 pero la respuesta no llego.
            'pagos'     => [$this->sinRespuestaDeMp()],
            // La 1ra busqueda (antes de cobrar) no ve nada; la 2da (el reintento) ya ve P1 aprobado.
            'busquedas' => [$this->busquedaDeMp([]), $this->busquedaDeMp([$p1])],
        ]);

        $this->pagarConTarjeta($cart, ['tarjeta' => ['token' => 'tok_1']])
            ->assertStatus(503)
            ->assertJsonPath('codigo', 'pago_sin_respuesta');

        $this->assertSame(0, $this->pedidosDelComercio());

        $respuesta = $this->pagarConTarjeta($cart, ['tarjeta' => ['token' => 'tok_2']])->assertStatus(201);

        $this->assertCount(1, $this->cobrosAMp(), 'El reintento NO manda un segundo POST /v1/payments.');
        $this->assertSame(1, $this->pedidosDelComercio());

        $pedido = Order::findOrFail((int) $respuesta->json('order_id'));

        $this->assertSame(9001, (int) $pedido->payment_id, 'El pedido queda con el pago que ya estaba aprobado.');
        $this->assertSame(3, (int) $pedido->cuotas_cobradas);
        Bus::assertDispatchedAfterResponseTimes(SendOrderEmails::class, 1);
    }

    /**
     * La busqueda es por el carrito: `external_reference` = id del carrito, con la credencial del comercio.
     */
    public function test_la_busqueda_es_por_el_external_reference_del_carrito()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago(['pagos' => [$this->respuestaDeMp($this->pagoDeMp($cart), 201)]]);

        $this->pagarConTarjeta($cart)->assertStatus(201);

        $busquedas = $this->llamadasA('GET', '/v1/payments/search$');

        $this->assertCount(1, $busquedas);
        $this->assertStringContainsString('external_reference='.$cart->id, $busquedas[0]['url']);
        $this->assertSame('Bearer '.self::$ACCESS_TOKEN_DE_PRUEBA, $busquedas[0]['headers']['Authorization'][0] ?? '');
    }

    /**
     * Un corte de transporte que Guzzle tira como `RequestException` (cURL 56) tambien es «sin respuesta»,
     * no un 500 generico.
     */
    public function test_un_corte_de_transporte_con_request_exception_tambien_da_503()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago(['pagos' => [$this->transporteCortadoDeMp()]]);

        $this->pagarConTarjeta($cart)
            ->assertStatus(503)
            ->assertJsonPath('codigo', 'pago_sin_respuesta');

        $this->assertSame(0, $this->pedidosDelComercio());
    }

    /**
     * Si la busqueda falla (por transporte o por un 5xx), NO se cobra «a ciegas»: 503 y ningun POST de cobro.
     */
    public function test_si_la_busqueda_falla_no_se_cobra()
    {
        foreach ([$this->sinRespuestaDeMp(), $this->transporteCortadoDeMp(), $this->respuestaDeMp(['message' => 'internal'], 500)] as $falla) {
            $this->llamadas_a_mp = [];
            $cart = $this->carritoDeTarjeta(true);

            $this->falsearMercadoPago([
                'pagos'     => [$this->respuestaDeMp($this->pagoDeMp($cart), 201)],
                'busquedas' => [$falla],
            ]);

            $this->pagarConTarjeta($cart)
                ->assertStatus(503)
                ->assertJsonPath('codigo', 'pago_sin_respuesta');

            $this->assertCount(0, $this->cobrosAMp(), 'Sin poder conciliar no se cobra.');
        }

        $this->assertSame(0, $this->pedidosDelComercio());
    }

    /**
     * Un pago del carrito que quedo en proceso: no se cobra de nuevo y el comprador recibe un mensaje claro.
     */
    public function test_un_pago_en_proceso_del_carrito_impide_cobrar_de_nuevo()
    {
        $cart = $this->carritoDeTarjeta(true);
        $p1   = $this->pagoDeMp($cart, ['id' => 9001, 'status' => 'in_process', 'status_detail' => 'pending_contingency']);

        $this->falsearMercadoPago([
            'pagos'     => [$this->respuestaDeMp($this->pagoDeMp($cart, ['id' => 9002]), 201)],
            'busquedas' => [$this->busquedaDeMp([$p1])],
        ]);

        $respuesta = $this->pagarConTarjeta($cart);

        $this->assertContains($respuesta->status(), [422, 503]);
        $this->assertSame('pago_en_proceso', $respuesta->json('codigo'));
        $this->assertNotEmpty($respuesta->json('message'));
        $this->assertCount(0, $this->cobrosAMp());
        $this->assertSame(0, $this->pedidosDelComercio());
    }
}
