<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Confía en proxies / balanceadores delante de la aplicación (Hostinger, Cloudflare, etc.).
 */
class TrustProxies extends Middleware
{
    /**
     * Lista de proxies de confianza; null permite todos cuando está detrás de un LB conocido.
     *
     * @var array<int|string>|string|null
     */
    protected $proxies;

    /**
     * Cabeceras usadas para detectar el cliente real detrás del proxy.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
