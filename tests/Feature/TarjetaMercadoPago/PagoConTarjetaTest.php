<?php

namespace Tests\Feature\TarjetaMercadoPago;

use App\Cart;
use App\Jobs\BroadcastOrderCreated;
use App\Jobs\SendOrderEmails;
use App\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El cobro con tarjeta dentro de la tienda (mision `tienda-cobro-tarjeta`, 24/11/2026):
 * CA2, CA3, CA7, CA8, CA9 y CA11 del PLAN.
 *
 * ── Que fija esta clase ───────────────────────────────────────────────────────────────────────
 *
 *  - `POST /api/mercado-pago/tarjeta/pagar` cobra Y, solo si el pago queda aprobado, crea el pedido
 *    (201 `{order_id}`), con lo cobrado anotado: `payment_id`, metodo, cuotas, recargo, cuotas y medio
 *    cobrados. Los mails y el aviso por broadcast salen UNA vez.
 *  - El monto y las cuotas los decide el SERVIDOR: lo que se manda a Mercado Pago es el `total` de la
 *    opcion elegida, nunca un `transaction_amount` del body. Si el `monto_esperado` no coincide, 409
 *    `monto_cambio` sin llamar a Mercado Pago.
 *  - Las validaciones cortan ANTES de cobrar (cuotas invalidas, metodo invalido, sin credencial,
 *    cuotas con interes de Mercado Pago, consulta de stock).
 *  - Regla PCI: los datos crudos de la tarjeta no viajan ni se loguean; solo el token del Brick.
 *  - Idempotente: el doble envio devuelve el mismo pedido y cobra una vez; un carrito con el pago
 *    aprobado pero sin pedido se completa sin volver a cobrar.
 *
 * Mercado Pago esta falseado por URL (`falsearMercadoPago()`): nada sale a la red.
 */
class PagoConTarjetaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCobroConTarjeta;

    protected function setUp(): void
    {
        parent::setUp();

        // Solo los jobs del pedido se interceptan: el resto de la app corre de verdad.
        Bus::fake([SendOrderEmails::class, BroadcastOrderCreated::class]);

        $this->armarCobroConTarjeta();
    }

    protected function tearDown(): void
    {
        $this->olvidarCuotas();

        parent::tearDown();
    }

    /**
     * Guion de un pago aprobado de este carrito: 3 cuotas de tarjeta de credito por 126.500.
     *
     * @param \App\Cart $cart
     * @param array $campos Pisa el pago.
     * @return void
     */
    private function aprobarElPago(Cart $cart, array $campos = [])
    {
        $this->falsearMercadoPago(['pagos' => [$this->respuestaDeMp($this->pagoDeMp($cart, $campos), 201)]]);
    }

    /**
     * CA2: 3 cuotas aprobadas -> 201 y un pedido con lo cobrado.
     */
    public function test_pago_aprobado_en_3_cuotas_crea_el_pedido_con_lo_cobrado()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->aprobarElPago($cart);

        $respuesta = $this->pagarConTarjeta($cart)->assertStatus(201);

        $pedido = Order::findOrFail((int) $respuesta->json('order_id'));

        $this->assertSame(9001, (int) $pedido->payment_id);
        $this->assertSame($this->metodo_tarjeta->id, (int) $pedido->payment_method_id);
        $this->assertSame('Sin confirmar', $pedido->order_status->name);
        $this->assertSame(3, (int) $pedido->cuotas);
        $this->assertEquals(10.0, (float) $pedido->cuotas_recargo);
        $this->assertSame(3, (int) $pedido->cuotas_cobradas);
        $this->assertSame('credit_card', $pedido->medio_de_pago_cobrado);

        // La linea de A queda al precio web (con el 5 % de la tienda), como hoy.
        $precio_de_la_linea = (float) DB::table('article_order')
            ->where('order_id', $pedido->id)->where('article_id', $this->articulo->id)->value('price');
        $this->assertEqualsWithDelta(105000.0, $precio_de_la_linea, 0.001);

        // El carrito queda con el pago aprobado anotado.
        $cart->refresh();
        $this->assertSame(9001, (int) $cart->payment_id);
        $this->assertSame('approved', $cart->payment_status);

        $this->assertSame(1, $this->pedidosDelComercio());
    }

    /**
     * CA2: lo que viaja a `POST /v1/payments`.
     */
    public function test_el_cuerpo_a_mercado_pago_lleva_monto_del_servidor_token_e_idempotencia()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->aprobarElPago($cart);

        $this->pagarConTarjeta($cart)->assertStatus(201);

        $cobros = $this->cobrosAMp();
        $this->assertCount(1, $cobros, 'Un solo cobro.');

        $cuerpo = $cobros[0]['body'];

        $this->assertEqualsWithDelta(126500.0, (float) $cuerpo['transaction_amount'], 0.001);
        $this->assertEquals(3, $cuerpo['installments']);
        $this->assertSame('tok_1', $cuerpo['token']);
        $this->assertSame('visa', $cuerpo['payment_method_id']);
        $this->assertEquals(310, $cuerpo['issuer_id']);
        $this->assertSame(true, $cuerpo['binary_mode'], 'Cada pago queda aprobado o rechazado: nunca «en revision».');
        $this->assertSame((string) $cart->id, $cuerpo['external_reference']);

        $this->assertSame($this->claveEsperada($cart->id, 'tok_1'), $this->claveDeIdempotencia($cobros[0]));

        // Se cobra con la credencial del comercio, no con otra.
        $autorizacion = $cobros[0]['headers']['Authorization'][0] ?? '';
        $this->assertSame('Bearer '.self::$ACCESS_TOKEN_DE_PRUEBA, $autorizacion);
    }

    /**
     * CA2: el aviso al comercio solo sale con el pago aprobado, y una vez.
     */
    public function test_mails_y_aviso_se_despachan_una_vez_solo_con_pago_aprobado()
    {
        $cart = $this->carritoDeTarjeta(true);

        // 1) Rechazado: ni mail ni aviso.
        $this->falsearMercadoPago(['pagos' => [
            $this->respuestaDeMp($this->pagoDeMp($cart, ['status' => 'rejected', 'status_detail' => 'cc_rejected_other_reason']), 201),
        ]]);

        $this->pagarConTarjeta($cart)->assertStatus(422);

        Bus::assertNotDispatchedAfterResponse(SendOrderEmails::class);
        Bus::assertNotDispatchedAfterResponse(BroadcastOrderCreated::class);

        // 2) Aprobado: una vez cada uno.
        $this->aprobarElPago($cart);

        $this->pagarConTarjeta($cart, ['tarjeta' => ['token' => 'tok_2']])->assertStatus(201);

        Bus::assertDispatchedAfterResponseTimes(SendOrderEmails::class, 1);
        Bus::assertDispatchedAfterResponseTimes(BroadcastOrderCreated::class, 1);
    }

    /**
     * CA3: 1 pago -> se cobra el precio web + envio, sin consultar `installments`.
     */
    public function test_un_pago_no_consulta_installments_y_cobra_el_precio_web()
    {
        $cart = $this->carritoDeTarjeta(true);

        $this->aprobarElPago($cart, ['installments' => 1, 'transaction_amount' => 115000]);

        $respuesta = $this->pagarConTarjeta($cart, ['cuotas' => 1, 'monto_esperado' => 115000])->assertStatus(201);

        $cuerpo = $this->cobrosAMp()[0]['body'];

        $this->assertEqualsWithDelta(115000.0, (float) $cuerpo['transaction_amount'], 0.001);
        $this->assertEquals(1, $cuerpo['installments']);
        $this->assertCount(0, $this->llamadasA('GET', '/v1/payment_methods/installments$'), 'Con 1 pago no hay nada que consultar.');

        $pedido = Order::findOrFail((int) $respuesta->json('order_id'));

        $this->assertNull($pedido->cuotas);
        $this->assertSame(1, (int) $pedido->cuotas_cobradas);
    }

    /**
     * CA7: si el comprador vio otro total, se frena sin cobrar y se le devuelven las opciones de hoy.
     */
    public function test_monto_esperado_distinto_409_sin_llamar_a_mercado_pago()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->aprobarElPago($cart);

        $respuesta = $this->pagarConTarjeta($cart, ['monto_esperado' => 120000])
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'monto_cambio');

        $this->assertOpciones($this->opcionesDeE1ConEnvio(), $respuesta->json('opciones'));

        $this->assertCount(0, $this->llamadas_a_mp, 'Ninguna llamada a Mercado Pago.');
        $this->assertSame(0, $this->pedidosDelComercio());
    }

    /**
     * CA7: un `transaction_amount` del body no cuenta: se cobra el total del servidor.
     */
    public function test_transaction_amount_del_body_se_ignora()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->aprobarElPago($cart);

        $this->pagarConTarjeta($cart, ['transaction_amount' => 1, 'tarjeta' => ['transaction_amount' => 1]])->assertStatus(201);

        $this->assertEqualsWithDelta(126500.0, (float) $this->cobrosAMp()[0]['body']['transaction_amount'], 0.001);
    }

    /**
     * Los casos de CA8, por nombre: la mutacion del cuerpo/escenario la hace el test.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function casosQueCortanAntesDeCobrar()
    {
        return [
            'cuotas que el comercio no ofrece'          => ['cuotas_invalidas', 'cuotas_invalidas'],
            'metodo de tarjeta de otro comercio'        => ['metodo_de_otro_comercio', 'metodo_invalido'],
            'metodo de tipo MercadoPago (Checkout Pro)' => ['metodo_checkout_pro', 'metodo_invalido'],
            'metodo de tipo Transferencia'              => ['metodo_transferencia', 'metodo_invalido'],
            'comercio sin credencial de Mercado Pago'   => ['sin_credencial', 'sin_credencial'],
            'tarjeta sin esas cuotas sin interes'       => ['cuotas_con_interes', 'cuotas_con_interes'],
            'carrito con consulta de stock'             => ['consulta_de_stock', 'consulta_stock'],
        ];
    }

    /**
     * CA8: cada validacion devuelve 422 con su `codigo`, SIN llamar a `POST /v1/payments` y sin pedido.
     *
     * @dataProvider casosQueCortanAntesDeCobrar
     * @param string $caso Que se rompe.
     * @param string $codigo_esperado
     */
    public function test_validaciones_que_cortan_antes_de_cobrar($caso, $codigo_esperado)
    {
        $cart  = $this->carritoDeTarjeta(true);
        $extra = [];

        $guion = ['pagos' => [$this->respuestaDeMp($this->pagoDeMp($cart), 201)]];

        switch ($caso) {
            case 'cuotas_invalidas':
                $extra = ['cuotas' => 4, 'monto_esperado' => 130000];
                break;

            case 'metodo_de_otro_comercio':
                $ajeno = $this->metodoDeTarjeta($this->comercioConTienda(), $this->metodo_cc_id);
                $extra = ['payment_method_id' => $ajeno->id];
                break;

            case 'metodo_checkout_pro':
                $extra = ['payment_method_id' => $this->metodo_mp->id, 'cuotas' => 1, 'monto_esperado' => 115000];
                break;

            case 'metodo_transferencia':
                $extra = ['payment_method_id' => $this->metodo_transferencia->id, 'cuotas' => 1, 'monto_esperado' => 115000];
                break;

            case 'sin_credencial':
                $this->metodo_mp->access_token = null;
                $this->metodo_mp->save();
                break;

            case 'cuotas_con_interes':
                // Mercado Pago le sumaria 34,5 % de interes al comprador en 3 cuotas.
                $guion['installments'] = $this->respuestaDeMp($this->respuestaDeInstallments([1 => 0, 3 => 34.5, 6 => 0]));
                break;

            case 'consulta_de_stock':
                if (!Schema::hasColumn('article_cart', 'stock_a_consultar')) {
                    $this->markTestSkipped('La base de testing no tiene la migracion de consultas de stock de empresa-api.');
                }

                $this->comercio->online_configuration()->update(['permitir_pedidos_sin_stock' => true]);
                $sin_stock = $this->articuloPublicado($this->comercio, ['final_price' => 100000.0, 'stock' => 0, 'name' => 'Articulo sin stock']);
                $cart      = $this->carritoDelArticulo($sin_stock, 1, ['payment_method_id' => $this->metodo_tarjeta->id]);
                break;
        }

        $this->falsearMercadoPago($guion);

        $this->pagarConTarjeta($cart, $extra)
            ->assertStatus(422)
            ->assertJsonPath('codigo', $codigo_esperado);

        $this->assertCount(0, $this->cobrosAMp(), 'Ningun cobro a Mercado Pago.');
        $this->assertSame(0, $this->pedidosDelComercio());
        Bus::assertNotDispatchedAfterResponse(SendOrderEmails::class);
    }

    /**
     * CA9 (regla PCI): aunque el body traiga el numero y el codigo de seguridad, a Mercado Pago solo
     * viaja el token, y nada sensible se escribe en el log.
     */
    public function test_datos_crudos_de_tarjeta_no_viajan_ni_se_loguean()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->aprobarElPago($cart);
        $this->capturarLogs();

        $this->pagarConTarjeta($cart, [
            'card_number'   => '4509953566233704',
            'security_code' => 'CVV-737',
            'tarjeta'       => ['card_number' => '4509953566233704', 'security_code' => 'CVV-737'],
        ])->assertStatus(201);

        $cobros = $this->cobrosAMp();
        $this->assertCount(1, $cobros);

        foreach (['card_number', 'security_code', 'cardholder'] as $clave) {
            $this->assertFalse($this->tieneLaClave($cobros[0]['body'], $clave), 'El cuerpo a Mercado Pago no puede tener `'.$clave.'`.');
        }

        $this->assertStringNotContainsString('4509953566233704', $cobros[0]['crudo']);
        $this->assertStringNotContainsString('CVV-737', $cobros[0]['crudo']);

        $this->assertNotEmpty($this->logs_capturados, 'El cobro tiene que dejar rastro en el log (cart_id, payment_id, status).');

        foreach ($this->logs_capturados as $linea) {
            foreach (['tok_1', '4509953566233704', 'CVV-737'] as $secreto) {
                $this->assertStringNotContainsString($secreto, $linea, 'Un secreto de la tarjeta llego al log.');
            }
        }
    }

    /**
     * CA11: el doble clic (o el reintento del navegador) devuelve el pedido ya creado y no cobra de nuevo.
     */
    public function test_doble_envio_devuelve_el_mismo_pedido_y_cobra_una_vez()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->aprobarElPago($cart);

        $primera = $this->pagarConTarjeta($cart)->assertStatus(201);
        $segunda = $this->pagarConTarjeta($cart)->assertStatus(201);

        $this->assertSame($primera->json('order_id'), $segunda->json('order_id'));
        $this->assertCount(1, $this->cobrosAMp(), '`POST /v1/payments` se llama una sola vez.');
        $this->assertSame(1, $this->pedidosDelComercio());
    }

    /**
     * CA11: se cobro (el pago quedo aprobado en el carrito) pero el pedido no llego a crearse: el
     * reintento consulta ese pago y crea el pedido SIN volver a cobrar.
     */
    public function test_carrito_con_pago_aprobado_sin_pedido_crea_el_pedido_sin_volver_a_cobrar()
    {
        $cart = $this->carritoDeTarjeta(true);

        Cart::whereKey($cart->id)->update(['payment_id' => 9001, 'payment_status' => 'approved']);

        $this->falsearMercadoPago([
            'consulta' => $this->respuestaDeMp($this->pagoDeMp($cart)),
        ]);

        $respuesta = $this->pagarConTarjeta($cart)->assertStatus(201);

        $this->assertCount(0, $this->cobrosAMp(), 'No se vuelve a cobrar.');
        $this->assertCount(1, $this->llamadasA('GET', '/v1/payments/9001$'), 'Se consulta el pago ya aprobado.');

        $pedido = Order::findOrFail((int) $respuesta->json('order_id'));

        $this->assertSame(9001, (int) $pedido->payment_id);
        $this->assertSame(3, (int) $pedido->cuotas_cobradas);
        $this->assertSame(1, $this->pedidosDelComercio());
    }
}
