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
 * El webhook sigue con la proxima credencial si una devuelve 401/403 (mision `tienda-cobro-tarjeta`,
 * ronda 3 de revision, 24/11/2026).
 *
 * El comercio puede tener dos credenciales (la de la fila MercadoPago y la propia de la fila de tarjeta). Si
 * Mercado Pago contesta 401/403 con la primera (credencial vencida o de otra cuenta), el webhook no puede
 * cortar la ronda: prueba la siguiente y solo falla si ninguna sirve.
 */
class WebhookConCredencialesRechazadasTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCobroConTarjeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armarCobroConTarjeta();

        $this->metodo_tarjeta->access_token = 'TEST-AT-T';
        $this->metodo_tarjeta->public_key   = 'TEST-PK-T';
        $this->metodo_tarjeta->save();
    }

    protected function tearDown(): void
    {
        $this->olvidarCuotas();

        parent::tearDown();
    }

    /**
     * Un pedido del comercio con su carrito, sin pago.
     *
     * @return array{0: \App\Cart, 1: \App\Order}
     */
    private function carritoConPedido()
    {
        $estado = OrderStatus::first() ?: OrderStatus::create(['name' => 'Sin confirmar']);
        $order  = Order::create([
            'buyer_id' => $this->comprador->id, 'user_id' => $this->comercio->id, 'status' => 'unconfirmed',
            'deliver' => 0, 'order_status_id' => $estado->id,
        ]);
        $cart = Cart::create([
            'user_id' => $this->comercio->id, 'buyer_id' => null, 'total' => 100000, 'deliver' => 0,
            'payment_method_id' => $this->metodo_tarjeta->id, 'order_id' => $order->id,
        ]);

        return [$cart, $order];
    }

    /**
     * Mercado Pago avisa del pago 9001; cada credencial contesta con el status que se le indique.
     *
     * @param \App\Cart $cart
     * @param array<string, int> $status_por_token Token => status HTTP (200 devuelve el pago).
     * @param array $tokens_usados Se llena por referencia con los tokens con los que se consulto.
     * @return \Illuminate\Testing\TestResponse
     */
    private function notificar(Cart $cart, array $status_por_token, array &$tokens_usados)
    {
        Http::swap(new HttpFactorySinSalida(app('events')));
        Http::fake(function ($request) use ($status_por_token, &$tokens_usados, $cart) {
            if ($request->url() !== MercadoPagoController::API_PAGOS.'9001') {
                return null;
            }

            $token = str_replace('Bearer ', '', $request->header('Authorization')[0] ?? '');
            $tokens_usados[] = $token;
            $status = isset($status_por_token[$token]) ? $status_por_token[$token] : 404;

            return $status === 200
                ? Http::response($this->pagoDeMp($cart, ['id' => 9001]), 200)
                : Http::response(['message' => 'no autorizado'], $status);
        });

        return $this->postJson(
            '/api/mercado-pago/webhook?commerce_id='.$this->comercio->id.'&type=payment&data.id=9001',
            ['type' => 'payment', 'data' => ['id' => '9001']]
        );
    }

    public function test_un_401_o_403_de_la_primera_credencial_no_corta_la_ronda()
    {
        foreach ([401, 403] as $rechazo) {
            list($cart, $order) = $this->carritoConPedido();
            $usados = [];

            $this->notificar($cart, ['TEST-AT-C' => $rechazo, 'TEST-AT-T' => 200], $usados)
                ->assertStatus(200)
                ->assertExactJson(['ok' => true]);

            $this->assertSame(['TEST-AT-C', 'TEST-AT-T'], $usados, 'Se probaron las dos credenciales, en orden.');
            $this->assertSame(9001, (int) $order->fresh()->payment_id, 'Con '.$rechazo.' de la primera, la segunda alcanza.');
        }
    }

    public function test_si_ninguna_credencial_sirve_no_se_registra_el_pago()
    {
        list($cart, $order) = $this->carritoConPedido();
        $usados = [];

        $respuesta = $this->notificar($cart, ['TEST-AT-C' => 401, 'TEST-AT-T' => 403], $usados);

        $this->assertNotTrue($respuesta->json('ok'), 'No se registra como pago ok.');
        $this->assertSame(['TEST-AT-C', 'TEST-AT-T'], $usados);
        $this->assertNull($order->fresh()->payment_id);
    }
}
