<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        'https://api.kioscoverde.com/public/payment-notification*',
        '/sociallogin/google',
        '/sociallogin/facebook',
        '/sociallogin/github',
        '/sociallogin/twitter',
        '/sociallogin/vkontakte',

        /*
         * -----------------------------------------------------------------------------------
         * Ingesta de tracking de compradores (mision tracking-buyers-tienda).
         * 🔴 SI VENIS A SACAR ESTA LINEA, LEE ESTO PRIMERO.
         * -----------------------------------------------------------------------------------
         *
         * Sin esta excepcion el endpoint NO FUNCIONA en ningun cliente real, y falla del peor
         * modo posible: en silencio. Esta MEDIDO, no deducido (15/8/2026, curl contra la API
         * levantada con `artisan serve`):
         *
         *   POST /api/buyer-tracking/events con Origin del SPA y sin X-XSRF-TOKEN  -> HTTP 419
         *   POST /api/buyer-tracking/events sin Origin (lo que hacen los tests)    -> HTTP 204
         *
         * El porque: Kernel.php mete EnsureFrontendRequestsAreStateful en el grupo `api`, y ese
         * middleware inyecta config('sanctum.middleware.verify_csrf_token'), que apunta a esta
         * clase. En todo cliente real el Referer/Origin del SPA matchea SANCTUM_STATEFUL_DOMAINS
         * (lo escribe el instalador del admin), asi que el pipeline stateful corre SIEMPRE.
         *
         * Las demas rutas de /api aguantan porque axios adjunta el X-XSRF-TOKEN desde la cookie
         * solo. Esta no: el SPA manda el tracking con navigator.sendBeacon, que NO PUEDE mandar
         * headers. Y sendBeacon devuelve true por haber ENCOLADO, no por haber llegado, asi que
         * el fallback por axios tampoco corre. Resultado sin esta linea: 419 antes del
         * controller, el Log::warning del helper nunca se ejecuta, y no queda ni una señal. Es
         * exactamente la clase de error que el docblock del helper dice estar evitando.
         *
         * Por que es seguro aflojarle el CSRF a esta ruta y solo a esta:
         *   - solo escribe filas de telemetria: no toca la cuenta del comprador, ni un pedido,
         *     ni nada que tenga que ver con plata;
         *   - ya es publicamente accesible sin sesion, como TODO routes/api.php (el grupo
         *     `auth:sanctum` esta comentado en :141), asi que el CSRF no estaba protegiendo
         *     ninguna autoridad que el atacante no tuviera igual;
         *   - lo peor que puede lograr un CSRF aca es ensuciar datos de tracking; dejarlo
         *     prendido rompe la funcionalidad entera, en silencio y en todos los clientes.
         *
         * El comodin cubre solo este prefijo. El resto de /api sigue con el CSRF puesto: hay un
         * test que fija que /api/carts siga devolviendo 419 con Origin y sin token.
         */
        'api/buyer-tracking/*',
    ];
}
