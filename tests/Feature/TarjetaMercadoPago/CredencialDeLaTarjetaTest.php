<?php

namespace Tests\Feature\TarjetaMercadoPago;

use App\Cart;
use App\Http\Controllers\MercadoPagoController;
use App\Order;
use App\OrderStatus;
use App\PaymentMethod;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\TestCase;

/**
 * La credencial de la fila de tarjeta y el email del pagador (mision `tienda-cobro-tarjeta`, ronda 2
 * de revision, 24/11/2026).
 *
 *  - La `public_key` que publica `GET /api/payment-methods` para la fila de tarjeta sale de la MISMA
 *    credencial con la que `cobrar()` cobra (`credentials_for_payment_method()` de esa fila), y una fila
 *    de tarjeta con credencial PROPIA se publica aunque el comercio no tenga fila MercadoPago.
 *  - El webhook consulta el pago con esa misma credencial.
 *  - Si el Brick no manda el email del pagador, se usa el del comprador del pedido (el que usa `/orders`).
 */
class CredencialDeLaTarjetaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCobroConTarjeta;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([\App\Jobs\SendOrderEmails::class, \App\Jobs\BroadcastOrderCreated::class]);

        $this->armarCobroConTarjeta();
    }

    protected function tearDown(): void
    {
        $this->olvidarCuotas();

        parent::tearDown();
    }

    /**
     * Le da credencial propia a la fila de tarjeta.
     *
     * @return void
     */
    private function darCredencialPropiaALaTarjeta()
    {
        $this->metodo_tarjeta->access_token = 'TEST-AT-T';
        $this->metodo_tarjeta->public_key   = 'TEST-PK-T';
        $this->metodo_tarjeta->save();
    }

    /**
     * La fila de tarjeta publicada, o null.
     *
     * @return array|null
     */
    private function filaPublicadaDeTarjeta()
    {
        foreach ($this->getJson('/api/payment-methods/'.$this->comercio->id)->assertStatus(200)->json('payment_methods') as $fila) {
            if ((int) $fila['id'] === (int) $this->metodo_tarjeta->id) {
                return $fila;
            }
        }

        return null;
    }

    public function test_la_public_key_publicada_es_la_de_la_credencial_con_la_que_se_cobra()
    {
        $this->darCredencialPropiaALaTarjeta();

        $fila = $this->filaPublicadaDeTarjeta();

        $this->assertNotNull($fila);
        $this->assertSame('TEST-PK-T', $fila['public_key'], 'El Brick tiene que tokenizar contra la cuenta que cobra.');

        // Y se cobra con esa misma credencial.
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago(['pagos' => [$this->respuestaDeMp($this->pagoDeMp($cart), 201)]]);
        $this->pagarConTarjeta($cart)->assertStatus(201);

        $this->assertSame('Bearer TEST-AT-T', $this->cobrosAMp()[0]['headers']['Authorization'][0] ?? '');
    }

    public function test_una_fila_de_tarjeta_con_credencial_propia_se_publica_sin_fila_mercado_pago()
    {
        $this->darCredencialPropiaALaTarjeta();
        PaymentMethod::whereKey($this->metodo_mp->id)->delete();

        $fila = $this->filaPublicadaDeTarjeta();

        $this->assertNotNull($fila, 'Con credencial propia la tarjeta se ofrece aunque no haya fila MercadoPago.');
        $this->assertSame('TEST-PK-T', $fila['public_key']);
        $this->assertArrayNotHasKey('access_token', $fila);
    }

    public function test_el_webhook_consulta_el_pago_con_la_credencial_de_la_tarjeta()
    {
        $this->darCredencialPropiaALaTarjeta();
        PaymentMethod::whereKey($this->metodo_mp->id)->delete();

        $estado = OrderStatus::first() ?: OrderStatus::create(['name' => 'Sin confirmar']);
        $order  = Order::create([
            'buyer_id' => $this->comprador->id, 'user_id' => $this->comercio->id, 'status' => 'unconfirmed',
            'deliver' => 0, 'order_status_id' => $estado->id,
        ]);
        $cart = Cart::create([
            'user_id' => $this->comercio->id, 'buyer_id' => null, 'total' => 100000, 'deliver' => 0,
            'payment_method_id' => $this->metodo_tarjeta->id, 'order_id' => $order->id,
        ]);

        $autorizaciones = [];

        Http::swap(new HttpFactorySinSalida(app('events')));
        Http::fake(function ($request) use (&$autorizaciones, $cart) {
            if ($request->url() !== MercadoPagoController::API_PAGOS.'9001') {
                return null;
            }

            $autorizaciones[] = $request->header('Authorization')[0] ?? '';

            return Http::response($this->pagoDeMp($cart, ['id' => 9001]), 200);
        });

        $this->postJson(
            '/api/mercado-pago/webhook?commerce_id='.$this->comercio->id.'&type=payment&data.id=9001',
            ['type' => 'payment', 'data' => ['id' => '9001']]
        )->assertStatus(200)->assertExactJson(['ok' => true]);

        $this->assertContains('Bearer TEST-AT-T', $autorizaciones, 'El pago se consulta con la credencial de la tarjeta.');
        $this->assertSame(9001, (int) $order->fresh()->payment_id);
    }

    public function test_sin_email_en_la_tarjeta_se_usa_el_del_comprador_del_pedido()
    {
        $cart = $this->carritoDeTarjeta(true);
        $this->falsearMercadoPago(['pagos' => [$this->respuestaDeMp($this->pagoDeMp($cart), 201)]]);

        $this->pagarConTarjeta($cart, ['tarjeta' => ['payer' => ['email' => '']]])->assertStatus(201);

        $this->assertSame($this->comprador->email, $this->cobrosAMp()[0]['body']['payer']['email']);
    }
}
