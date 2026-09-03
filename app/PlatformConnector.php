<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conector OAuth de un comercio hacia una `Platform` (Mercado Pago, ML, Tienda Nube).
 *
 * 🔴 ESTE REPO SOLO LEE ESTA TABLA. Quien la escribe es `empresa-api`: el operador conecta su
 * cuenta desde ABM -> Integraciones del ERP y el callback de OAuth deja el token aca. La tienda
 * lo unico que hace es leer con que credencial de Mercado Pago tiene que cobrar
 * (`MercadoPagoCredentialsHelper`). No hay ni un `save()` sobre este modelo en este repo, y no
 * tiene que haberlo: el `auth_url` y todo el flujo de conexion son del ERP.
 *
 * Por que esta duplicado y no importado: `empresa` y `tienda` comparten la BASE, no el codigo.
 * Es el mismo motivo por el que `App\OnlineConfiguration` existe en los dos repos. La contracara
 * es que los dos modelos tienen que decir lo mismo, en particular el cast `encrypted` y el
 * $hidden: si aca faltara el cast, el token se leeria como ciphertext crudo y la tienda
 * intentaria cobrar con una cadena `eyJpdiI6...` en vez de un access token.
 *
 * @see app/Http/Controllers/Helpers/MercadoPagoCredentialsHelper.php
 * @see empresa-api/app/Models/PlatformConnector.php (el original)
 */
class PlatformConnector extends Model
{
    /** Estado inicial: falta completar OAuth en el navegador. */
    public const STATUS_SIN_CONECTAR = 'sin_conectar';

    /** OAuth completado y tokens validos guardados. */
    public const STATUS_CONECTADO = 'conectado';

    /** Ultimo intento de token/callback fallo (ver `error_message`). */
    public const STATUS_ERROR = 'error';

    protected $guarded = [];

    /**
     * Secretos que nunca deben viajar en una respuesta JSON.
     *
     * Misma lista que en `empresa-api`. En este repo importa todavia mas: las rutas que tocan
     * metodos de pago (`GET /api/payment-methods/{commerce_id}`, `POST /api/mercado-pago/
     * preference`) son PUBLICAS, sin auth. El endpoint del listado devuelve la public key
     * vigente y nada mas; el access token no sale por ningun lado.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
        // `auth_code` es el code de OAuth ya canjeado. Es de un solo uso, pero es material de OAuth
        // y no tiene por que serializarse. Mismo criterio que en `empresa-api`.
        'auth_code',
    ];

    /**
     * `access_token` / `refresh_token` usan el cast nativo `encrypted` de Laravel, igual que en
     * `empresa-api` (y que `OnlineConfiguration::$casts['mail_password']` de este repo). Los
     * escribe cifrados el ERP con la APP_KEY compartida y se descifran al leer el atributo.
     *
     * Leer `$conector->access_token` puede tirar `DecryptException` (APP_KEY distinta en un
     * cliente instalado antes de que el instalador unificara la clave, o una fila que quedo en
     * plano). Ese caso se atrapa en `MercadoPagoCredentialsHelper` y cae al fallback: el comercio
     * sigue cobrando por `payment_methods` en vez de reventar el checkout con un 500.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at'    => 'datetime',
        'access_token'  => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    /**
     * Conector del comercio hacia una plataforma dada por slug, sin crearlo si no existe.
     *
     * Copia exacta de `empresa-api`: mismo filtro, mismo `orderBy('id', 'DESC')` y mismo
     * `first()`. El orden importa — si un comercio quedara con dos conectores para la misma
     * plataforma, los dos repos tienen que elegir el mismo.
     *
     * @param int $user_id Comercio (owner) dueño del conector.
     * @param string $platform_slug Slug de `platforms` (ver constantes de `Platform`).
     * @return self|null
     */
    public static function find_for_user_and_slug(int $user_id, string $platform_slug): ?self
    {
        return static::with('platform')
            ->where('user_id', $user_id)
            ->whereHas('platform', function ($platform_query) use ($platform_slug) {
                $platform_query->where('slug', $platform_slug);
            })
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Indica si el conector tiene una credencial usable, sin exponer ni desencriptar el token.
     *
     * Copia exacta del criterio de `empresa-api`: hay access_token guardado Y (no hay fecha de
     * vencimiento registrada, o esa fecha todavia es futura). Se lee el atributo crudo
     * (`attributes`) en vez de `$this->access_token` para no forzar el desencriptado del cast
     * solo para ver si esta vacio — que ademas es lo que puede tirar excepcion.
     *
     * @return bool
     */
    public function is_connected(): bool
    {
        $has_token = !empty($this->attributes['access_token']);
        $not_expired = empty($this->expires_at) || $this->expires_at->isFuture();

        return $has_token && $not_expired;
    }

    /**
     * Comercio dueño del conector.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Plataforma a la que apunta este conector.
     *
     * @return BelongsTo
     */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class, 'platform_id');
    }
}
