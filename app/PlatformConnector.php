<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

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
     * Mismo filtro, mismo `orderBy('id', 'DESC')` y mismo `first()` que `empresa-api`. El orden
     * importa — si un comercio quedara con dos conectores para la misma plataforma, los dos
     * repos tienen que elegir el mismo.
     *
     * ── 🔴 Por que hay un try/catch que en `empresa-api` no esta ──────────────────────────────
     *
     * `platform_connectors` y `platforms` las crea una migracion de `empresa-api` de mayo de
     * 2026, y `tienda-api` se despliega POR CLIENTE, independiente del ERP. O sea que hay bases
     * de clientes andando, hoy, sin esas dos tablas. Medido el 3/9/2026 sobre las cuatro bases
     * de cliente del MySQL local (`ferretotal`, `leudinox`, `golonorte_bien`,
     * `pack_descartables`): ninguna las tiene, y las cuatro tienen `payment_methods`.
     *
     * Sin este catch, la consulta tira `QueryException` y sale sin atrapar hasta el controller:
     *
     *     GET /api/payment-methods/2600  ->  HTTP 500
     *     SQLSTATE[42S02]: Base table or view not found: 1146
     *     Table 'ferretotal.platform_connectors' doesn't exist
     *
     * Y ese endpoint lista TODOS los medios de pago, no solo Mercado Pago: el comercio se queda
     * sin checkout entero por una tabla que su base nunca necesito. Es el escenario "tienda nueva
     * + empresa vieja" que el plan de la mision daba por cubierto y no lo estaba.
     *
     * La asimetria es real y por eso el catch va de este lado nada mas: `empresa-api` es DUEÑO de
     * esa migracion, asi que por construccion su base siempre tiene las tablas. Este repo no.
     *
     * No se atrapa mas ancho que `QueryException`, y lo que se atrapa se loguea con el SQLSTATE:
     * si algun dia esto tapa un error de configuracion de verdad, el comercio sigue cobrando por
     * `payment_methods` (que es como cobra hoy) pero queda el rastro para encontrarlo.
     *
     * @param int $user_id Comercio (owner) dueño del conector.
     * @param string $platform_slug Slug de `platforms` (ver constantes de `Platform`).
     * @return self|null
     */
    public static function find_for_user_and_slug(int $user_id, string $platform_slug): ?self
    {
        try {
            return static::with('platform')
                ->where('user_id', $user_id)
                ->whereHas('platform', function ($platform_query) use ($platform_slug) {
                    $platform_query->where('slug', $platform_slug);
                })
                ->orderBy('id', 'DESC')
                ->first();
        } catch (QueryException $e) {
            Log::error(
                'PlatformConnector::find_for_user_and_slug: no se pudo consultar el conector de "'.
                $platform_slug.'" del comercio '.$user_id.' (SQLSTATE '.$e->getCode().'). '.
                'El comercio sigue cobrando por payment_methods. Detalle: '.$e->getMessage()
            );

            return null;
        }
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
