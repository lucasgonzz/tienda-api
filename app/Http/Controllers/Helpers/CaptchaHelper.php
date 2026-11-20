<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Turnstile se consume SOLO en orders, nunca al identificar al comprador. */
class CaptchaHelper
{
    const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function publicConfiguration(): array
    {
        $enabled = config('services.turnstile.enabled');
        $off = in_array($enabled, [false, 0, '0', 'false', 'off', 'no'], true);
        $on = in_array($enabled, [true, 1, '1', 'true', 'on', 'yes'], true);
        $ready = $on && is_string(config('services.turnstile.site_key'))
            && trim(config('services.turnstile.site_key')) !== ''
            && is_string(config('services.turnstile.secret_key'))
            && trim(config('services.turnstile.secret_key')) !== ''
            && is_string(config('services.turnstile.hostname'))
            && preg_match('/\A[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?\z/i', config('services.turnstile.hostname'))
            && is_string(config('services.turnstile.action'))
            && preg_match('/\A[a-zA-Z0-9_-]{1,32}\z/', config('services.turnstile.action'));
        return [
            'provider' => 'turnstile',
            'state' => $off ? 'disabled' : ($ready ? 'enabled' : 'misconfigured'),
            'required' => !$off,
            'site_key' => $ready ? config('services.turnstile.site_key') : null,
            'action' => $ready ? config('services.turnstile.action') : null,
        ];
    }

    public static function checkOrder(Request $request): void
    {
        // POST /buyer loguea fichas sin credencial: check() solo NO acredita una cuenta.
        $buyer = Auth::guard('buyer')->user();
        if ($buyer && (!empty($buyer->password) || !empty($buyer->provider_id))) {
            return;
        }
        $public = self::publicConfiguration();
        if ($public['state'] === 'disabled') {
            return;
        }
        if ($public['state'] !== 'enabled') {
            self::fail('captcha_unavailable', 503);
        }
        $token = $request->input('captcha_token');
        if (!is_string($token) || trim($token) === '' || strlen($token) > 2048) {
            self::fail('captcha_required');
        }
        try {
            // Claim atómico ANTES de HTTP: una respuesta/token no habilita dos pedidos,
            // incluso ante concurrencia. Se guarda sólo hash, nunca token ni secreto.
            // Store file persistente explícito: nunca array/null de un request.
            // Turnstile limita validez a 300s; retenemos el claim 600s, sin renovar tokens.
            if (!Cache::store('file')->add('checkout-captcha:'.hash('sha256', $token), true, 600)) {
                self::fail('captcha_invalid');
            }
            $response = Http::asForm()->connectTimeout(2)->timeout(5)->post(self::VERIFY_URL, [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
            ]);
            $data = $response->json();
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Errores del transporte/cache no abren el checkout ni exponen datos privados.
            self::fail('captcha_unavailable', 503);
        }
        if (!$response->successful() || !is_array($data)) {
            self::fail('captcha_unavailable', 503);
        }
        if (($data['success'] ?? null) !== true
            || ($data['hostname'] ?? null) !== config('services.turnstile.hostname')
            || ($data['action'] ?? null) !== config('services.turnstile.action')) {
            self::fail('captcha_invalid');
        }
    }

    private static function fail(string $code, int $status = 422): void
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'error' => 'No pudimos verificar la compra. Volvé a intentar la verificación.',
            'code' => $code,
            'recoverable' => true,
            'errors' => ['captcha_token' => ['Volvé a verificar para confirmar el pedido.']],
        ], $status));
    }
}
