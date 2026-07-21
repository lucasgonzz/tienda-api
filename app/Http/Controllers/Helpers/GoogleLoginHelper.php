<?php

namespace App\Http\Controllers\Helpers;

use App\OnlineConfiguration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Configura Socialite en runtime con las credenciales de Google propias de cada comercio
 * (prompt 590, grupo 164). Antes estas credenciales eran globales y vivian en el .env de
 * tienda-api (GOOGLE_ID/GOOGLE_SECRET/GOOGLE_URL); ahora viven en online_configurations por
 * comercio (misma base de datos que empresa-api, columnas agregadas en el prompt 589).
 *
 * Filosofia: igual que ClientMailConfigHelper (mismo patron para la config SMTP por comercio).
 * Si el comercio no tiene el login con Google habilitado o le faltan credenciales, este helper
 * NO cae a las credenciales viejas del .env: devuelve false y quien lo llama corta el flujo con
 * un error controlado.
 */
class GoogleLoginHelper
{
    /**
     * Busca el online_configuration del comercio y, si el login con Google esta habilitado y
     * tiene ambas credenciales cargadas, configura Socialite (config 'services.google.*') en
     * runtime con las credenciales propias de ESE comercio.
     *
     * @param int|string $commerce_id Id del User dueño de la tienda (User::id).
     * @return bool true si se pudo configurar Socialite con las credenciales del comercio.
     */
    static function apply($commerce_id) {
        $configuration = Self::getConfiguration($commerce_id);

        if (is_null($configuration)) {
            Log::info('GoogleLoginHelper: comercio sin online_configuration, no se puede loguear con Google', [
                'commerce_id' => $commerce_id,
            ]);
            return false;
        }

        if (!Self::isConfigured($configuration)) {
            Log::info('GoogleLoginHelper: login con Google deshabilitado o credenciales incompletas para el comercio', [
                'commerce_id' => $commerce_id,
            ]);
            return false;
        }

        // El redirect se deja como esta configurado (dominio generico de la tienda / .env): no es
        // una credencial propia del comercio, a diferencia del client_id/client_secret.
        Config::set('services.google.client_id', $configuration->google_client_id);
        Config::set('services.google.client_secret', $configuration->google_client_secret);

        return true;
    }

    /**
     * Determina si el comercio tiene el login con Google realmente disponible: habilitado y con
     * las dos credenciales cargadas. Lo usa tambien CommerceController para decidir si el SPA
     * debe mostrar el boton (evita mostrar un boton que despues falla porque falta configurar
     * algo en la base de datos).
     *
     * @param int|string $commerce_id
     * @return bool
     */
    static function isAvailable($commerce_id) {
        $configuration = Self::getConfiguration($commerce_id);
        return !is_null($configuration) && Self::isConfigured($configuration);
    }

    /**
     * @param OnlineConfiguration $configuration
     * @return bool true si el master switch esta activo y ambas credenciales estan cargadas.
     */
    private static function isConfigured($configuration) {
        return $configuration->google_login_enabled
                && !empty($configuration->google_client_id)
                && !empty($configuration->google_client_secret);
    }

    /**
     * @param int|string $commerce_id
     * @return OnlineConfiguration|null
     */
    private static function getConfiguration($commerce_id) {
        return OnlineConfiguration::where('user_id', $commerce_id)->first();
    }
}
