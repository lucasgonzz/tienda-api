<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

trait ConCachePersistenteDeRecuperacion
{
    private $ruta_cache_recuperacion;

    protected function preparar_cache_recuperacion()
    {
        $this->ruta_cache_recuperacion = storage_path('framework/testing/password-reset-'.bin2hex(random_bytes(12)));
        config(['cache.password_reset_store' => 'password_reset', 'cache.stores.password_reset.path' => $this->ruta_cache_recuperacion]);
        Cache::forgetDriver('password_reset');
        $this->beforeApplicationDestroyed(function () {
            Cache::forgetDriver('password_reset');
            File::deleteDirectory($this->ruta_cache_recuperacion);
        });
    }
}
