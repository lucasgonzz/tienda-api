<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Registro de rutas y límites de tasa para Laravel 10.
 */
class RouteServiceProvider extends ServiceProvider
{
    /**
     * Namespace base para rutas estilo Controller@method.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * Ruta home por defecto (auth redirects).
     */
    public const HOME = '/home';

    /**
     * Registra rutas y el rate limiter `api` usado por throttle:api en Kernel si se migra.
     *
     * @return void
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $user_key = optional($request->user())->id;

            return Limit::perMinute(60)->by($user_key ?? $request->ip());
        });

        $this->routes(function () {
            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));

            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));
        });
    }
}
