<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plataforma de integracion (una app Comercio City en Mercado Pago, Mercado Libre, Tienda Nube).
 *
 * 🔴 ESTE REPO SOLO LEE ESTA TABLA. El esquema y la escritura los gobierna `empresa-api`: ahi
 * viven las migraciones, el seeder (`PlatformSeeder`) y el OAuth que carga las credenciales. La
 * base es compartida entre el ERP y la tienda, el codigo no — por eso el modelo esta duplicado
 * aca en vez de importado. Si cambia el de `empresa-api/app/Models/Platform.php`, cambia este.
 *
 * De esta tabla la tienda solo necesita el `slug`, para poder encontrar el conector de Mercado
 * Pago del comercio (`PlatformConnector::find_for_user_and_slug`).
 */
class Platform extends Model
{
    /** Slug persistido para Mercado Libre. */
    public const SLUG_MERCADO_LIBRE = 'mercado_libre';

    /** Slug persistido para Tienda Nube. */
    public const SLUG_TIENDA_NUBE = 'tienda_nube';

    /**
     * Slug persistido para Mercado Pago. Es la plataforma que ancla el conector de cobros de
     * cada comercio: es el unico que la tienda mira, para resolver con que credencial cobra.
     */
    public const SLUG_MERCADO_PAGO = 'mercado_pago';

    protected $guarded = [];

    /**
     * Oculta secretos en cualquier respuesta JSON.
     *
     * Mismo criterio (y misma lista) que `empresa-api/app/Models/Platform.php`. Hoy este repo no
     * serializa `Platform` en ningun endpoint, pero el $hidden va igual: la superficie publica de
     * la tienda ya filtro secretos una vez (ver `OnlineConfiguration::$hidden`, 15/8/2026) y el
     * costo de dejarlo puesto de entrada es cero.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'client_id',
        'client_secret',
    ];

    /**
     * `client_secret` usa el cast nativo `encrypted` de Laravel: lo escribe cifrado `empresa-api`
     * y se descifra con la APP_KEY al leer el atributo. Los dos repos comparten APP_KEY (el
     * instalador de `admin-api` la copia del `.env` de la `empresa-api` del mismo cliente), asi
     * que el descifrado de este lado funciona. Si algun cliente viejo tuviera claves distintas,
     * leer el atributo tira `DecryptException`: por eso todo lo que descifra de verdad pasa por
     * `MercadoPagoCredentialsHelper`, que lo atrapa y cae al fallback.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'extra_config'  => 'array',
        'client_secret' => 'encrypted',
    ];

    /**
     * Conectores de comercios hacia esta plataforma.
     *
     * @return HasMany
     */
    public function connectors(): HasMany
    {
        return $this->hasMany(PlatformConnector::class, 'platform_id');
    }
}
