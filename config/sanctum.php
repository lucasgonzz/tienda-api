<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    /*
     * 🔴 Si SANCTUM_STATEFUL_DOMAINS no esta en el .env del cliente, la tienda no anda.
     *
     * Sin default, env() devuelve null y explode(',', null) da [''], que no matchea ningun
     * dominio. Entonces EnsureFrontendRequestsAreStateful (Kernel.php, grupo `api`) no monta
     * StartSession, y sin sesion:
     *
     *   - todas las rutas con auth:buyer devuelven 401;
     *   - CartOwnershipHelper::ids() devuelve [] y el comprador pierde su propio carrito: 403 en
     *     PUT/DELETE /api/carts y en POST /orders. O sea que NO SE PUEDE COMPRAR.
     *
     * Antes de la mision de seguridad del 15/8/2026 esta variable faltante era casi inocua: nada
     * estaba detras de auth y el carrito no chequeaba pertenencia. Ahora es fatal, y por eso el
     * default dejo de ser opcional.
     *
     * El default de abajo cubre desarrollo y el caso de un solo dominio. NO alcanza para el
     * despliegue real de un cliente, donde la tienda vive en `cliente.com.ar` y la API en
     * `api.cliente.com.ar`: ahi la variable TIENE que estar seteada con el dominio del SPA.
     * Verificarlo cliente por cliente antes de desplegar. CartOwnershipHelper deja un
     * Log::warning cuando detecta que la sesion no arranco, para que el sintoma no sea mudo.
     */
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,localhost:8080,localhost:8081,127.0.0.1,127.0.0.1:8000,::1',
        \Laravel\Sanctum\Sanctum::currentApplicationUrlWithPort()
    ))),

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. If this value is null, personal access tokens do
    | not expire. This won't tweak the lifetime of first-party sessions.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'verify_csrf_token' => App\Http\Middleware\VerifyCsrfToken::class,
        'encrypt_cookies' => App\Http\Middleware\EncryptCookies::class,
    ],

];
