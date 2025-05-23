<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
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
