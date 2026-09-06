<?php

namespace Tests\Feature\Integraciones;

use App\Cart;
use App\Http\Controllers\Helpers\CartOwnershipHelper;
use App\Http\Controllers\MercadoPagoController;
use App\Order;
use App\OrderStatus;
use App\PaymentMethod;
use App\PaymentMethodType;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Que le manda la tienda a Mercado Pago al armar la preferencia, y que hace con lo que Mercado
 * Pago le avisa por el webhook (mision `mercado-pago-cobro-demo`, 5/9/2026).
 *
 * ── Lo que fija esta clase ────────────────────────────────────────────────────────────────────
 *
 * 1. La preferencia lleva, en produccion, las URLs de retorno + `auto_return`, la URL del webhook
 *    y la referencia al carrito. Fuera de produccion no lleva URLs (Mercado Pago exige URLs
 *    publicas y en local no las hay), pero la referencia al carrito va igual.
 * 2. El webhook NO confia en la notificacion: consulta el pago a la API de Mercado Pago con la
 *    credencial del comercio y recien ahi escribe `payment_id`/`payment_status` en el carrito y
 *    `payment_id` en el pedido. Lo que no es un pago, lo que no referencia un carrito y lo que
 *    referencia un carrito de OTRO comercio se responde 200 y no se toca.
 * 3. Si la API de Mercado Pago no contesta, el webhook responde 500 para que Mercado Pago
 *    reintente. Es el unico caso que no responde 200.
 *
 * ⚠️ Sobre la base: tienda-api no tiene database/migrations (el esquema lo gobierna empresa-api),
 * asi que aca se corre contra la base real del slot con DatabaseTransactions. El id de pago que se
 * usa (12 digitos) necesita `carts.payment_id` / `orders.payment_id` en BIGINT: los amplia la
 * migracion `2026_09_05_100000_ampliar_payment_id_de_carts_y_orders` de empresa-api. Si la base
 * del slot todavia los tiene en INT, estos tests fallan con "Out of range value" — y ese es
 * exactamente el defecto que la migracion arregla, no un problema del test.
 *
 * `preference()` entero no se prueba porque `$preference->save()` sale a la red; se prueba
 * `datos_de_preferencia()` por reflexion, que es todo lo que esta mision le agrego.
 */
class PreferenciaYWebhookDeMercadoPagoTest extends TestCase
{
    use DatabaseTransactions;

    /** Id de pago con la magnitud real de los de Mercado Pago en 2026 (12 digitos): no entra en un INT. */
    const PAYMENT_ID_REAL = 125733412876;

    /** @var \App\User */
    private $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures
    |---------------------------------------------------------------------------------------------
    */

    private function tipoMercadoPago()
    {
        $tipo = PaymentMethodType::where('name', 'MercadoPago')->first();

        if (!$tipo) {
            $tipo = new PaymentMethodType;
            $tipo->name = 'MercadoPago';
            $tipo->save();
        }

        return $tipo;
    }

    /**
     * Medio de pago de Mercado Pago del comercio con credencial cargada: es lo que hace que
     * `MercadoPagoCredentialsHelper::access_token()` devuelva algo y el webhook llegue a consultar
     * la API.
     *
     * @return \App\PaymentMethod
     */
    private function paymentMethodMp()
    {
        $payment_method = new PaymentMethod;
        $payment_method->name                   = 'Mercado Pago';
        $payment_method->user_id                = $this->comercio->id;
        $payment_method->payment_method_type_id = $this->tipoMercadoPago()->id;
        $payment_method->public_key             = 'PUBLIC-KEY-DE-PRUEBA';
        $payment_method->access_token           = 'TOKEN-DE-PRUEBA';
        $payment_method->save();

        return $payment_method;
    }

    /**
     * Carrito del comercio (o de otro, si se pasa `user_id`), sin comprador: la sesion del test
     * no es de nadie, asi que `CartOwnershipHelper::puede()` lo va a dar por ajeno.
     *
     * @param array $atributos
     * @return \App\Cart
     */
    private function carrito(array $atributos = [])
    {
        return Cart::create(array_merge([
            'user_id'  => $this->comercio->id,
            'buyer_id' => null,
        ], $atributos));
    }

    /**
     * Pedido minimo del comercio, ya atado al carrito (como deja `OrderController@store`).
     *
     * @param \App\Cart $cart
     * @return \App\Order
     */
    private function pedidoDe(Cart $cart)
    {
        $estado = OrderStatus::first();
        $this->assertNotNull($estado, 'La base del slot tiene que tener al menos un order_status.');

        $order = Order::create([
            'buyer_id'        => 1,
            'user_id'         => $this->comercio->id,
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'order_status_id' => $estado->id,
        ]);

        $cart->order_id = $order->id;
        $cart->save();

        return $order;
    }

    /**
     * Invoca `datos_de_preferencia()` por reflexion.
     *
     * @param \App\Cart|null $cart
     * @param string $url_del_request Con que URL llego el request de la preferencia.
     * @return array<string, mixed>
     */
    private function datosDePreferencia($cart, $url_del_request = 'https://api-tienda.test/api/mercado-pago/preference', array $server = [])
    {
        $controller = new MercadoPagoController();
        $metodo = new ReflectionMethod($controller, 'datos_de_preferencia');
        $metodo->setAccessible(true);

        $request = Request::create($url_del_request, 'POST', [], [], [], $server);

        return $metodo->invoke($controller, $request, $this->comercio, $cart);
    }

    /**
     * Como llega un request en el shared hosting: Laravel servido desde `/public/index.php`, sin
     * reescritura. Es lo que hace que `$request->root()` termine en `/public`.
     *
     * @return array<string, string>
     */
    private function servidorConPublic()
    {
        return [
            'SCRIPT_NAME'     => '/public/index.php',
            'SCRIPT_FILENAME' => '/home/x/tienda/api/public/index.php',
            'PHP_SELF'        => '/public/index.php',
        ];
    }

    /**
     * Deja la app "en produccion" hasta que termine el test. Es lo que mira
     * `app()->environment('production')` en el controller.
     *
     * @return void
     */
    private function enProduccion()
    {
        $this->app['env'] = 'production';
    }

    /**
     * Lo que la API de Mercado Pago devuelve para un pago, con lo que este backend escribio al
     * crear la preferencia.
     *
     * @param \App\Cart|null $cart
     * @param string $status
     * @return array<string, mixed>
     */
    private function pagoDeMercadoPago($cart, $status = 'approved')
    {
        return [
            'id'                 => self::PAYMENT_ID_REAL,
            'status'             => $status,
            'status_detail'      => $status === 'approved' ? 'accredited' : 'cc_rejected_other_reason',
            'transaction_amount' => 5,
            'external_reference' => $cart ? (string) $cart->id : null,
            'metadata'           => $cart ? ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id] : [],
        ];
    }

    private function urlDelWebhook($extra = '')
    {
        return '/api/mercado-pago/webhook?commerce_id='.$this->comercio->id.'&type=payment&data.id='.self::PAYMENT_ID_REAL.$extra;
    }

    /*
    |---------------------------------------------------------------------------------------------
    | La preferencia
    |---------------------------------------------------------------------------------------------
    */

    /**
     * En produccion la preferencia lleva retorno, retorno automatico, webhook y referencia.
     *
     * @return void
     */
    public function test_en_produccion_la_preferencia_lleva_retorno_webhook_y_referencia_al_carrito()
    {
        $this->enProduccion();
        $this->comercio->online = 'https://tienda.test/';
        $cart = $this->carrito();

        $datos = $this->datosDePreferencia($cart, 'https://api-tienda.test/public/api/mercado-pago/preference', $this->servidorConPublic());

        $this->assertSame('https://tienda.test/pago-exitoso', $datos['back_urls']['success']);
        $this->assertSame('https://tienda.test/pago-pendiente', $datos['back_urls']['pending']);
        $this->assertSame('https://tienda.test/pago-rechazado', $datos['back_urls']['failure']);
        $this->assertSame('approved', $datos['auto_return']);
        $this->assertSame(
            'https://api-tienda.test/public/api/mercado-pago/webhook?commerce_id='.$this->comercio->id,
            $datos['notification_url'],
            'En el shared hosting Laravel vive bajo /public y el webhook tiene que llevar ese mismo prefijo: es la URL que acaba de funcionar para pedir la preferencia.'
        );
        $this->assertSame((string) $cart->id, $datos['external_reference']);
        $this->assertSame($cart->id, $datos['metadata']['cart_id']);
        $this->assertSame($this->comercio->id, $datos['metadata']['commerce_id']);
    }

    /**
     * 🔴 `auto_return` sin `back_urls.success` hace que Mercado Pago rechace la preferencia
     * entera. Si el comercio no tiene la URL de su tienda cargada, no se manda ninguna de las dos
     * y la preferencia se crea igual (el comprador vuelve con el boton de Mercado Pago).
     *
     * @return void
     */
    public function test_sin_url_de_tienda_no_se_mandan_ni_back_urls_ni_auto_return()
    {
        $this->enProduccion();
        $this->comercio->online = null;

        $datos = $this->datosDePreferencia($this->carrito());

        $this->assertArrayNotHasKey('back_urls', $datos);
        $this->assertArrayNotHasKey('auto_return', $datos);
        $this->assertArrayHasKey('notification_url', $datos, 'El webhook no depende de la URL de la tienda.');
    }

    /**
     * Fuera de produccion no viajan URLs (Mercado Pago las exige publicas), pero la referencia al
     * carrito va igual: es lo que permite probar el webhook contra un pago de sandbox.
     *
     * @return void
     */
    public function test_fuera_de_produccion_no_hay_urls_pero_si_referencia_al_carrito()
    {
        $this->comercio->online = 'https://tienda.test';
        $cart = $this->carrito();

        $datos = $this->datosDePreferencia($cart);

        $this->assertArrayNotHasKey('back_urls', $datos);
        $this->assertArrayNotHasKey('auto_return', $datos);
        $this->assertArrayNotHasKey('notification_url', $datos);
        $this->assertSame((string) $cart->id, $datos['external_reference']);
    }

    /**
     * Sin carrito (SPA viejo que no manda `cart_id`, o carrito ajeno) la preferencia se arma
     * igual, solo que sin referencia. Es lo que hacia antes de esta mision.
     *
     * @return void
     */
    public function test_sin_carrito_la_preferencia_se_arma_sin_referencia()
    {
        $datos = $this->datosDePreferencia(null);

        $this->assertArrayNotHasKey('external_reference', $datos);
        $this->assertArrayNotHasKey('metadata', $datos);
    }

    /**
     * Un `cart_id` que no es de quien pide no se usa: la sesion del test no es dueña de nada, asi
     * que el carrito existe pero se descarta.
     *
     * @return void
     */
    public function test_un_carrito_ajeno_no_se_usa_como_referencia()
    {
        $cart = $this->carrito();

        $controller = new MercadoPagoController();
        $metodo = new ReflectionMethod($controller, 'carrito_del_pago');
        $metodo->setAccessible(true);

        $request = Request::create('/api/mercado-pago/preference', 'POST', ['cart_id' => $cart->id]);

        $this->assertNull($metodo->invoke($controller, $request), 'Un carrito que no es de quien pide no puede ser la referencia del pago.');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El webhook
    |---------------------------------------------------------------------------------------------
    */

    /**
     * El camino feliz: Mercado Pago avisa, se consulta el pago, y queda en el carrito y en el
     * pedido. Con un id de pago del tamano real.
     *
     * @return void
     */
    public function test_el_webhook_guarda_el_pago_en_el_carrito_y_en_el_pedido()
    {
        $this->paymentMethodMp();
        $cart  = $this->carrito();
        $order = $this->pedidoDe($cart);

        Http::fake([
            MercadoPagoController::API_PAGOS.self::PAYMENT_ID_REAL => Http::response($this->pagoDeMercadoPago($cart), 200),
        ]);

        $respuesta = $this->postJson($this->urlDelWebhook(), [
            'type'   => 'payment',
            'action' => 'payment.updated',
            'data'   => ['id' => (string) self::PAYMENT_ID_REAL],
        ]);

        $respuesta->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertArrayNotHasKey('cart_id', $respuesta->json(), 'La ruta es publica: no devuelve ids internos.');

        $cart->refresh();
        $order->refresh();

        $this->assertSame(self::PAYMENT_ID_REAL, (int) $cart->payment_id);
        $this->assertSame('approved', $cart->payment_status);
        $this->assertSame(self::PAYMENT_ID_REAL, (int) $order->payment_id, 'El pedido tiene que quedar con el id del pago, que es lo que el ERP consulta.');

        Http::assertSent(function ($request) {
            return $request->url() === MercadoPagoController::API_PAGOS.self::PAYMENT_ID_REAL
                && $request->hasHeader('Authorization', 'Bearer TOKEN-DE-PRUEBA');
        });
    }

    /**
     * 🔴 El estado sale de la API de Mercado Pago, no del body. Un body que diga "approved" sobre
     * un pago que Mercado Pago tiene como rechazado deja el carrito en rechazado.
     *
     * @return void
     */
    public function test_el_webhook_toma_el_estado_de_la_api_y_no_del_body()
    {
        $this->paymentMethodMp();
        $cart = $this->carrito();

        Http::fake([
            MercadoPagoController::API_PAGOS.self::PAYMENT_ID_REAL => Http::response($this->pagoDeMercadoPago($cart, 'rejected'), 200),
        ]);

        $this->postJson($this->urlDelWebhook(), [
            'type'   => 'payment',
            'data'   => ['id' => (string) self::PAYMENT_ID_REAL, 'status' => 'approved'],
            'status' => 'approved',
        ])->assertStatus(200);

        $this->assertSame('rejected', $cart->refresh()->payment_status);
    }

    /**
     * El formato IPN viejo (`?topic=payment&id=`) tambien se acepta.
     *
     * @return void
     */
    public function test_el_webhook_acepta_el_formato_ipn_viejo()
    {
        $this->paymentMethodMp();
        $cart = $this->carrito();

        Http::fake([
            MercadoPagoController::API_PAGOS.self::PAYMENT_ID_REAL => Http::response($this->pagoDeMercadoPago($cart), 200),
        ]);

        $this->post('/api/mercado-pago/webhook?commerce_id='.$this->comercio->id.'&topic=payment&id='.self::PAYMENT_ID_REAL)
            ->assertStatus(200)
            ->assertJson(['ok' => true]);

        $this->assertSame('approved', $cart->refresh()->payment_status);
    }

    /**
     * Lo que no es un pago se ignora sin salir a la red.
     *
     * @return void
     */
    public function test_el_webhook_ignora_lo_que_no_es_un_pago()
    {
        $this->paymentMethodMp();
        Http::fake();

        $this->postJson('/api/mercado-pago/webhook?commerce_id='.$this->comercio->id.'&topic=merchant_order&id=99', [
            'topic' => 'merchant_order',
            'id'    => 99,
        ])->assertStatus(200)->assertJson(['ignorado' => true]);

        Http::assertNothingSent();
    }

    /**
     * Sin credencial del comercio no hay con que consultar el pago: 200 y afuera, sin red.
     *
     * @return void
     */
    public function test_sin_credencial_del_comercio_el_webhook_no_sale_a_la_red()
    {
        Http::fake();

        $this->postJson($this->urlDelWebhook(), ['type' => 'payment', 'data' => ['id' => (string) self::PAYMENT_ID_REAL]])
            ->assertStatus(200)
            ->assertJson(['ignorado' => true]);

        Http::assertNothingSent();
    }

    /**
     * 🔴 La base puede ser compartida entre comercios: un pago que referencia el carrito de OTRO
     * comercio no lo toca, aunque la credencial del comercio de la query lo haya podido consultar.
     *
     * @return void
     */
    public function test_el_webhook_no_toca_un_carrito_de_otro_comercio()
    {
        $this->paymentMethodMp();
        $ajeno = $this->carrito(['user_id' => $this->comercio->id + 1000]);

        Http::fake([
            MercadoPagoController::API_PAGOS.self::PAYMENT_ID_REAL => Http::response($this->pagoDeMercadoPago($ajeno), 200),
        ]);

        $this->postJson($this->urlDelWebhook(), ['type' => 'payment', 'data' => ['id' => (string) self::PAYMENT_ID_REAL]])
            ->assertStatus(200)
            ->assertJson(['ignorado' => true]);

        $ajeno->refresh();
        $this->assertNull($ajeno->payment_id);
        $this->assertNull($ajeno->payment_status);
    }

    /**
     * Un pago que no referencia ningun carrito conocido se ignora.
     *
     * @return void
     */
    public function test_el_webhook_ignora_un_pago_sin_carrito()
    {
        $this->paymentMethodMp();

        Http::fake([
            MercadoPagoController::API_PAGOS.self::PAYMENT_ID_REAL => Http::response($this->pagoDeMercadoPago(null), 200),
        ]);

        $this->postJson($this->urlDelWebhook(), ['type' => 'payment', 'data' => ['id' => (string) self::PAYMENT_ID_REAL]])
            ->assertStatus(200)
            ->assertJson(['ignorado' => true, 'motivo' => 'sin carrito']);
    }

    /**
     * Un pago que Mercado Pago no conoce para este comercio (404) se ignora con 200: reintentar no
     * lo va a hacer aparecer.
     *
     * @return void
     */
    public function test_un_pago_desconocido_para_mercado_pago_se_ignora()
    {
        $this->paymentMethodMp();

        Http::fake([
            MercadoPagoController::API_PAGOS.self::PAYMENT_ID_REAL => Http::response(['message' => 'Payment not found'], 404),
        ]);

        $this->postJson($this->urlDelWebhook(), ['type' => 'payment', 'data' => ['id' => (string) self::PAYMENT_ID_REAL]])
            ->assertStatus(200)
            ->assertJson(['ignorado' => true]);
    }

    /**
     * 🔴 Si la API de Mercado Pago no contesta, se responde 500 A PROPOSITO: es lo que hace que
     * Mercado Pago reintente la notificacion. Con 200 la daria por entregada y el pago quedaria sin
     * registrar.
     *
     * @return void
     */
    public function test_si_mercado_pago_no_contesta_el_webhook_pide_reintento()
    {
        $this->paymentMethodMp();
        $cart = $this->carrito();

        Http::fake([
            MercadoPagoController::API_PAGOS.self::PAYMENT_ID_REAL => Http::response('', 503),
        ]);

        $this->postJson($this->urlDelWebhook(), ['type' => 'payment', 'data' => ['id' => (string) self::PAYMENT_ID_REAL]])
            ->assertStatus(500);

        $this->assertNull($cart->refresh()->payment_id, 'Con Mercado Pago caido no se escribe nada.');
    }

    /**
     * Con nginx reescribiendo `/public` (VPS), el request llega limpio y el webhook sale limpio.
     *
     * @return void
     */
    public function test_sin_public_en_el_request_el_webhook_sale_sin_public()
    {
        $this->enProduccion();
        $this->comercio->online = 'https://tienda.test';

        $datos = $this->datosDePreferencia($this->carrito());

        $this->assertSame(
            'https://api-tienda.test/api/mercado-pago/webhook?commerce_id='.$this->comercio->id,
            $datos['notification_url']
        );
    }

    /**
     * El caso positivo de `carrito_del_pago()`: el carrito que esta sesion creo (lo registra
     * `CartOwnershipHelper::registrar`, como hace `CartController@store`) llega como referencia.
     * Es el corazon de la mision: sin esto el webhook no tiene a que pedido atarle el pago.
     *
     * @return void
     */
    public function test_el_carrito_de_esta_sesion_si_se_usa_como_referencia()
    {
        $cart = $this->carrito();
        CartOwnershipHelper::registrar($cart->id);

        $controller = new MercadoPagoController();
        $metodo = new ReflectionMethod($controller, 'carrito_del_pago');
        $metodo->setAccessible(true);

        $request = Request::create('/api/mercado-pago/preference', 'POST', ['cart_id' => $cart->id]);
        $resuelto = $metodo->invoke($controller, $request);

        $this->assertNotNull($resuelto);
        $this->assertSame($cart->id, $resuelto->id);

        $datos = $this->datosDePreferencia($resuelto);
        $this->assertSame((string) $cart->id, $datos['external_reference']);
    }

    /**
     * El id del pago viaja en una URL autenticada con el token del comercio: solo digitos. Cualquier
     * otra cosa se ignora sin salir a la red.
     *
     * @return void
     */
    public function test_un_id_de_pago_que_no_es_numerico_se_ignora_sin_salir_a_la_red()
    {
        $this->paymentMethodMp();
        Http::fake();

        $this->postJson('/api/mercado-pago/webhook?commerce_id='.$this->comercio->id.'&type=payment', [
            'type' => 'payment',
            'data' => ['id' => 'search?external_reference=55'],
        ])->assertStatus(200)->assertJson(['ignorado' => true]);

        $this->postJson('/api/mercado-pago/webhook?commerce_id='.$this->comercio->id.'&type=payment', [
            'type' => 'payment',
            'data' => ['id' => '../../merchant_orders/123'],
        ])->assertStatus(200)->assertJson(['ignorado' => true]);

        Http::assertNothingSent();
    }

    /**
     * Un carrito con un pago APROBADO no se pisa con la notificacion de OTRO pago rechazado (la
     * primera tarjeta rechazada llegando despues que la segunda aprobada). El mismo pago aprobado
     * si se vuelve a escribir: es idempotente.
     *
     * @return void
     */
    public function test_un_pago_aprobado_no_se_pisa_con_otro_pago_rechazado()
    {
        $this->paymentMethodMp();
        $cart = $this->carrito(['payment_id' => self::PAYMENT_ID_REAL, 'payment_status' => 'approved']);

        $rechazado = self::PAYMENT_ID_REAL - 1;
        $pago = $this->pagoDeMercadoPago($cart, 'rejected');
        $pago['id'] = $rechazado;

        Http::fake([
            MercadoPagoController::API_PAGOS.$rechazado => Http::response($pago, 200),
            MercadoPagoController::API_PAGOS.self::PAYMENT_ID_REAL => Http::response($this->pagoDeMercadoPago($cart), 200),
        ]);

        $this->postJson('/api/mercado-pago/webhook?commerce_id='.$this->comercio->id.'&type=payment&data.id='.$rechazado, [
            'type' => 'payment',
            'data' => ['id' => (string) $rechazado],
        ])->assertStatus(200)->assertJson(['ignorado' => true, 'motivo' => 'ya hay un pago aprobado']);

        $cart->refresh();
        $this->assertSame(self::PAYMENT_ID_REAL, (int) $cart->payment_id, 'El pago aprobado tiene que quedar.');
        $this->assertSame('approved', $cart->payment_status);

        // El mismo pago aprobado, notificado de nuevo, se procesa igual (idempotente).
        $this->postJson($this->urlDelWebhook(), ['type' => 'payment', 'data' => ['id' => (string) self::PAYMENT_ID_REAL]])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }
}
