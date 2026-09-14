<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Repositorio público: prohibido volver a escribir client_id/client_secret/redirect reales en
    // el código. Se cargan únicamente desde el .env (default vacío si no está configurado).
    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID', ''),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET', ''),
        'redirect' => env('FACEBOOK_REDIRECT_URI', '')
        // 'redirect' => 'http://localhost:8080/auth/facebook/callback'
    ],

    'google' => [
        'client_id' => env('GOOGLE_ID'),
        'client_secret' => env('GOOGLE_SECRET'),
        'redirect' => env('GOOGLE_URL'),
    ],

    // API de Zipnova (envíos por correo), misión zipnova-envios 14/9/2026. Acá NO hay credenciales:
    // cada comercio conecta su cuenta desde el ERP y el token viaja cifrado en platform_connectors.
    // Solo el host (configurable sin tocar código) y las opciones SSL: el PHP de wamp no valida el
    // certificado si no se le pasa el bundle, y desactivar la verificación no es opción porque en
    // ese header viaja el token. Mismo bloque que empresa-api (App\Services\Zipnova\ZipnovaClient).
    'zipnova' => [
        'base_url'         => env('ZIPNOVA_BASE_URL', 'https://api.zipnova.com.ar/v2'),
        'timeout'          => (int) env('ZIPNOVA_TIMEOUT', 20),
        'guzzle_verify'    => env('ZIPNOVA_GUZZLE_VERIFY_SSL', true),
        'guzzle_ca_bundle' => env('ZIPNOVA_GUZZLE_CA_BUNDLE', ''),
    ],

];
