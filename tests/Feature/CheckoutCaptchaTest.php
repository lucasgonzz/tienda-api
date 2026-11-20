<?php

namespace Tests\Feature;

use App\Buyer;
use App\Cart;
use App\Order;
use App\User;
use App\OnlineConfiguration;
use App\Http\Controllers\Helpers\CaptchaHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutCaptchaTest extends TestCase
{
    use DatabaseTransactions;

    private $owner;
    private $buyer;
    private $cart;
    private $claims = [];
    private $cache;
    private $tokenPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('empresa_testing_tienda_u13_20261120', DB::connection()->getDatabaseName());
        $this->owner = User::create(['name' => 'CAPTCHA sintético', 'company_name' => 'Sintético',
            'email' => uniqid('captcha-').'@example.test', 'password' => bcrypt('synthetic'), 'status' => 'commerce']);
        $this->buyer = Buyer::create(['name' => 'Invitado', 'email' => 'captcha@example.test', 'user_id' => $this->owner->id]);
        OnlineConfiguration::create(['user_id' => $this->owner->id,
            'notificar_pedido_al_negocio' => 0, 'notificar_pedido_al_cliente' => 0]);
        DB::table('order_statuses')->updateOrInsert(['name' => 'Sin confirmar'], ['updated_at' => now()]);
        $this->cart = $this->newCart();
        $this->cache = Cache::store('file');
        $this->tokenPrefix = bin2hex(random_bytes(16));
        config(['services.turnstile' => ['enabled' => true, 'site_key' => 'synthetic-public',
            'secret_key' => 'synthetic-secret', 'hostname' => 'shop.example.test', 'action' => 'checkout']]);
    }

    private function newCart()
    {
        return Cart::create(['user_id' => $this->owner->id, 'total' => 100, 'deliver' => 0])->fresh();
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->claims as $key) { $this->cache->forget($key); }
        } finally {
            parent::tearDown();
        }
    }

    private function submit($extra = [])
    {
        if (isset($extra['captcha_token'])) {
            $extra['captcha_token'] = $this->tokenPrefix.$extra['captcha_token'];
            $this->claims[] = 'checkout-captcha:'.hash('sha256', $extra['captcha_token']);
        }
        return $this->withSession(['carritos_propios' => [$this->cart->id],
            'checkout_buyer_id' => $this->buyer->id, 'borrador_u13' => ['address' => 'Conservar']])
            ->postJson('/api/orders', array_merge(['cart_id' => $this->cart->id,
                'commerce_id' => $this->owner->id, 'address' => 'Dirección sintética'], $extra));
    }

    private function success()
    {
        return ['success' => true, 'hostname' => 'shop.example.test', 'action' => 'checkout'];
    }

    private function assertUnchanged($response, $before, $status, $code)
    {
        $response->assertStatus($status)->assertJson(['code' => $code, 'recoverable' => true]);
        $response->assertSessionHas('borrador_u13', ['address' => 'Conservar']);
        $this->assertSame($before, $this->cart->fresh()->getAttributes());
        $this->assertSame(0, Order::where('user_id', $this->owner->id)->count());
    }

    public function test_direct_orders_sin_buyer_previo_ni_token_no_crea_y_conserva_borrador()
    {
        Http::fake();
        $before = $this->cart->getAttributes();
        $this->assertUnchanged($this->submit(), $before, 422, 'captcha_required');
        Http::assertNothingSent();
    }

    public static function rejectedResponses(): array
    {
        return [
            'rechazado' => [['success' => false], 200, 422],
            'success texto' => [['success' => 'true', 'hostname' => 'shop.example.test', 'action' => 'checkout'], 200, 422],
            'otro hostname' => [['success' => true, 'hostname' => 'evil.example.test', 'action' => 'checkout'], 200, 422],
            'otra action' => [['success' => true, 'hostname' => 'shop.example.test', 'action' => 'login'], 200, 422],
            'sin hostname action' => [['success' => true], 200, 422],
            'json nulo' => ['null', 200, 503],
            'html malformado' => ['<html>Error</html>', 200, 503],
            'error http' => [['success' => true, 'hostname' => 'shop.example.test', 'action' => 'checkout'], 500, 503],
        ];
    }

    /** @dataProvider rejectedResponses */
    public function test_respuesta_invalida_nunca_crea_pedido($body, $httpStatus, $status)
    {
        Http::fake([CaptchaHelper::VERIFY_URL => Http::response($body, $httpStatus)]);
        $this->assertUnchanged($this->submit(['captcha_token' => 'synthetic-token']),
            $this->cart->getAttributes(), $status, $status === 503 ? 'captcha_unavailable' : 'captcha_invalid');
        Http::assertSentCount(1);
    }

    public function test_timeout_y_reintento_con_nuevo_token()
    {
        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            if (++$attempt === 1) { throw new ConnectionException('timeout sintético'); }
            return Http::response($this->success());
        });
        $this->assertUnchanged($this->submit(['captcha_token' => 'timeout-token']),
            $this->cart->getAttributes(), 503, 'captcha_unavailable');
        $this->submit(['captcha_token' => 'nuevo-token'])->assertCreated();
        $this->assertSame(1, Order::where('user_id', $this->owner->id)->count());
    }

    public function test_token_verificado_solo_en_orders_una_vez_y_no_reutilizable()
    {
        Http::fake([CaptchaHelper::VERIFY_URL => Http::response($this->success())]);
        $response = $this->submit(['captcha_token' => 'single-token', 'commerce_id' => $this->owner->id + 100]);
        $response->assertCreated();
        $order = Order::findOrFail($response->json('order_id'));
        $this->assertSame((int) $this->owner->id, (int) $order->user_id);
        Http::assertSent(fn ($r) => $r->url() === CaptchaHelper::VERIFY_URL
            && $r['secret'] === 'synthetic-secret' && $r['response'] === $this->tokenPrefix.'single-token');
        $this->cart = $this->newCart();
        $this->submit(['captcha_token' => 'single-token'])->assertStatus(422)->assertJson(['code' => 'captcha_invalid']);
        $this->assertSame(1, Order::where('user_id', $this->owner->id)->count());
        $this->assertNull($this->cart->fresh()->order_id);
        Http::assertSentCount(1);
    }

    public function test_sesion_guest_creada_por_buyer_no_es_exencion()
    {
        Http::fake();
        $this->postJson('/api/buyer', ['name' => 'Invitado', 'email' => $this->buyer->email,
            'commerce_id' => $this->owner->id])->assertSuccessful();
        $this->assertAuthenticatedAs($this->buyer, 'buyer');
        $this->assertUnchanged($this->submit(), $this->cart->getAttributes(), 422, 'captcha_required');
        Http::assertNothingSent();
    }

    public function test_email_de_cuenta_en_buyer_no_es_autenticacion_ni_exencion()
    {
        Http::fake();
        $this->buyer->update(['password' => bcrypt('synthetic-password')]);
        $this->postJson('/api/buyer', ['email' => $this->buyer->email,
            'commerce_id' => $this->owner->id])->assertSuccessful();
        $this->assertGuest('buyer');
        $this->assertUnchanged($this->submit(), $this->cart->getAttributes(), 422, 'captcha_required');
        Http::assertNothingSent();
    }

    public function test_error_del_verificador_no_crea_pedido()
    {
        Http::fake(function () { throw new \RuntimeException('error sintético'); });
        $this->assertUnchanged($this->submit(['captcha_token' => 'error-token']),
            $this->cart->getAttributes(), 503, 'captcha_unavailable');
    }

    public function test_cuenta_autenticada_con_credencial_no_necesita_captcha()
    {
        Http::fake();
        $this->buyer->update(['password' => bcrypt('synthetic-password')]);
        $this->actingAs($this->buyer, 'buyer');
        $this->submit()->assertCreated();
        Http::assertNothingSent();
    }

    public function test_apagado_explicito_sin_claves_permite_checkout()
    {
        Http::fake();
        config(['services.turnstile' => ['enabled' => false]]);
        $this->getJson('/api/buyer/checkout-captcha')->assertOk()->assertJsonPath('captcha.state', 'disabled')
            ->assertJsonPath('captcha.required', false)->assertJsonPath('captcha.site_key', null);
        $this->submit()->assertCreated();
        Http::assertNothingSent();
    }

    public static function incompleteConfiguration(): array
    {
        return [['secret_key', ''], ['site_key', ''], ['hostname', ''], ['action', ''], ['enabled', 'typo'], ['enabled', null]];
    }

    /** @dataProvider incompleteConfiguration */
    public function test_incompleto_no_publica_protegido_ni_abre_pedido($key, $value)
    {
        Http::fake();
        config(['services.turnstile.'.$key => $value]);
        $this->getJson('/api/buyer/checkout-captcha')->assertOk()->assertJsonPath('captcha.state', 'misconfigured')
            ->assertJsonPath('captcha.required', true)->assertJsonPath('captcha.site_key', null);
        $this->assertUnchanged($this->submit(['captcha_token' => 'unused']), $this->cart->getAttributes(), 503, 'captcha_unavailable');
        Http::assertNothingSent();
    }

    public function test_configuracion_publica_sin_secreto_y_throttle_vigente()
    {
        $response = $this->getJson('/api/buyer/checkout-captcha')->assertOk()->assertJsonPath('captcha.state', 'enabled')
            ->assertJsonPath('captcha.site_key', 'synthetic-public')->assertJsonPath('captcha.action', 'checkout');
        $this->assertStringNotContainsString('synthetic-secret', $response->getContent());
        foreach (['/api/buyer', '/api/orders', '/api/buyer/checkout-captcha'] as $uri) {
            $request = \Illuminate\Http\Request::create($uri, str_ends_with($uri, 'checkout-captcha') ? 'GET' : 'POST');
            $route = app('router')->getRoutes()->match($request);
            $this->assertContains('throttle:20,1', $route->gatherMiddleware());
            $this->assertContains('api', $route->gatherMiddleware());
        }
    }

    public function test_cache_caida_no_habilita_pedido()
    {
        Http::fake();
        Cache::shouldReceive('store')->with('file')->andReturnSelf();
        Cache::shouldReceive('add')->once()->andThrow(new \RuntimeException('cache sintética caída'));
        $this->assertUnchanged($this->submit(['captcha_token' => 'new-token']), $this->cart->getAttributes(), 503, 'captcha_unavailable');
        Http::assertNothingSent();
    }
}
