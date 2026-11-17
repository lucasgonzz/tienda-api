<?php

namespace App\Http\Middleware;

use App\Exceptions\PasswordResetStorageException;
use App\Services\PasswordResetCache;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Routing\Middleware\ThrottleRequests;

/** Mantiene las reglas y cabeceras públicas; solo cambia el almacenamiento de estos tres límites. */
class ThrottlePasswordReset extends ThrottleRequests
{
    private $reglas;

    public function __construct(RateLimiter $limiter)
    {
        parent::__construct($limiter);
        $this->reglas = $limiter;
    }

    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        try {
            $regla = $this->reglas->limiter($maxAttempts);
            $this->limiter = new RateLimiter(app(PasswordResetCache::class));
            if ($regla) {
                $this->limiter->for($maxAttempts, $regla);
            }

            return parent::handle($request, $next, $maxAttempts);
        } catch (PasswordResetStorageException $e) {
            return $e->respuesta($request);
        }
    }
}
