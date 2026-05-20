<?php

/**
 * CORS nativo de Laravel 10 (HandleCors).
 * Si definís SANCTUM_STATEFUL_CORS o FRONTEND_URL, se usan como orígenes permitidos; si no, se permite cualquier origen (comportamiento cercano al middleware Cors anterior).
 */
$explicit_origins = array_values(array_filter([
    env('SANCTUM_STATEFUL_CORS'),
    env('FRONTEND_URL'),
]));

return [

    /**
     * Rutas donde Laravel envía cabeceras CORS.
     * El login/registro de tienda están en web.php (/login, /register, …), no bajo /api/*.
     */
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'register',
        'register/*',
        'sociallogin/*',
        'auth/*/callback',
        'password-reset/*',
        'payment-notification',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => $explicit_origins !== [] ? $explicit_origins : ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
