<?php

namespace Tests\Feature\TarjetaMercadoPago;

use App\Jobs\BroadcastOrderCreated;
use App\Jobs\SendOrderEmails;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * La respuesta 201 cuando se reusa un pago aprobado previo (mision `tienda-cobro-tarjeta`, ronda 3).
 *
 * Ademas de `pago_previo: true`, trae (aditivo, SOLO en ese caso) `order: {cuotas_cobradas, total_cobrado}` con
 * lo que Mercado Pago realmente cobro, para que la SPA se lo muestre al comprador. En un cobro comun la
 * respuesta sigue siendo `{order_id}` a secas.
 */
class PagoPrevioRespuestaTest extends TestCase
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

    public function test_el_201_con_pago_previo_trae_las_cuotas_y_el_total_cobrados()
    {
        $cart = $this->carritoDeTarjeta(true);
        $p1   = $this->pagoDeMp($cart, ['id' => 9001, 'installments' => 3, 'transaction_amount' => 126500]);

        $this->falsearMercadoPago(['busquedas' => [$this->busquedaDeMp([$p1])]]);

        $respuesta = $this->pagarConTarjeta($cart, ['cuotas' => 6, 'monto_esperado' => 138000, 'tarjeta' => ['token' => 'tok_2']])
            ->assertStatus(201)
            ->assertJsonPath('pago_previo', true);

        $this->assertEquals(3, $respuesta->json('order.cuotas_cobradas'));
        $this->assertEqualsWithDelta(126500.0, (float) $respuesta->json('order.total_cobrado'), 0.001);
        $this->assertNotEmpty($respuesta->json('order_id'), 'order_id sigue en la respuesta.');
    }

    public function test_el_201_de_un_cobro_comun_no_trae_pago_previo_ni_order()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago(['pagos' => [$this->respuestaDeMp($this->pagoDeMp($cart), 201)]]);

        $respuesta = $this->pagarConTarjeta($cart)->assertStatus(201);

        $this->assertSame(['order_id'], array_keys($respuesta->json()));
    }
}
