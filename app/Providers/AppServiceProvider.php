<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\View;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;
use Illuminate\Session\SessionManager;
use App\Http\Middleware\StartSession;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        /*
         * El StartSession propio reemplaza al del framework: una respuesta que estaba en vuelo
         * durante un login/logout no le vuelve a mandar al navegador la cookie de la sesion que
         * ese login/logout destruyo (el caso de Fenix del 23/9/2026, en el docblock de
         * App\Http\Middleware\StartSession).
         *
         * Va sobre la clase BASE y no en el Kernel porque el grupo `web` no es el unico que la
         * usa: el pipeline de EnsureFrontendRequestsAreStateful de Sanctum —el camino de toda la
         * API de la tienda— la nombra por la clase de Illuminate y la resuelve por el
         * contenedor. SessionServiceProvider la registra como singleton en su register(), que
         * corre antes que este (los providers del framework van primero en config/app.php), asi
         * que este binding es el que queda. Mismo constructor que el del framework.
         */
        $this->app->singleton(BaseStartSession::class, function ($app) {
            return new StartSession($app->make(SessionManager::class), function () use ($app) {
                return $app->make(CacheFactory::class);
            });
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        View::addNamespace('mail', resource_path('views/vendor/mail'));
        
        Relation::morphMap([
            'article' => 'App\Article',
            'promocion_vinoteca' => 'App\PromocionVinoteca',
        ]);

    }
}
