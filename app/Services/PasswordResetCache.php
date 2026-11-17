<?php

namespace App\Services;

use App\Exceptions\PasswordResetStorageException;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * El código y sus topes son estado de seguridad entre solicitudes, no una optimización.
 * Doblep usaba CACHE_DRIVER=array: el mail salía, pero el proceso siguiente perdía el código.
 * Este repositorio tiene almacenamiento propio persistente y comprueba las escrituras.
 */
class PasswordResetCache extends Repository
{
    public function __construct()
    {
        $nombre = config('cache.password_reset_store', 'password_reset');
        $driver = config('cache.stores.'.$nombre.'.driver');
        if (!in_array($driver, ['file', 'redis', 'memcached', 'database', 'dynamodb'], true)) {
            throw new PasswordResetStorageException('configuracion_no_persistente');
        }

        $store = $this->operar('abrir', function () use ($nombre) {
            return Cache::store($nombre)->getStore();
        });
        if (!$store instanceof LockProvider) {
            throw new PasswordResetStorageException('almacenamiento_sin_candado');
        }
        parent::__construct($store);

        // La comprobación ocurre también al verificar y cambiar: un disco sin permisos no debe
        // confundirse con un código incorrecto ni confirmar una escritura que el store rechazó.
        // Clave y valor fijos: borrar una marca compartida crea carreras; claves aleatorias dejan
        // directorios hash sin límite en FileStore incluso después de borrar sus archivos.
        $this->put('password_reset_comprobacion', true, 30);
    }

    private function operar($operacion, callable $accion)
    {
        try {
            return $accion();
        } catch (PasswordResetStorageException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PasswordResetStorageException($operacion, 0, $e);
        }
    }

    public function get($key, $default = null): mixed
    {
        return $this->operar('leer', function () use ($key, $default) {
            return parent::get($key, $default);
        });
    }

    public function put($key, $value, $ttl = null)
    {
        return $this->operar('escribir', function () use ($key, $value, $ttl) {
            if (!parent::put($key, $value, $ttl) || parent::get($key) !== $value) {
                throw new PasswordResetStorageException('escritura_no_confirmada');
            }
            return true;
        });
    }

    public function forget($key)
    {
        return $this->operar('borrar', function () use ($key) {
            $resultado = parent::forget($key);
            if (parent::get($key) !== null) {
                throw new PasswordResetStorageException('borrado_no_confirmado');
            }
            return $resultado;
        });
    }

    public function add($key, $value, $ttl = null)
    {
        return $this->operar('agregar', function () use ($key, $value, $ttl) {
            $resultado = parent::add($key, $value, $ttl);
            if (!$this->has($key)) {
                throw new PasswordResetStorageException('alta_no_confirmada');
            }
            return $resultado;
        });
    }

    public function increment($key, $value = 1)
    {
        return $this->operar('incrementar', function () use ($key, $value) {
            // FileStore hace leer/sumar/escribir y omite el booleano de put. El candado evita
            // perder incrementos concurrentes y permite confirmar exactamente el valor escrito.
            return $this->con_candado('password_reset_contador:'.$key, 10, 5, function () use ($key, $value) {
                $resultado = parent::increment($key, $value);
                if ($resultado === false || parent::get($key) !== $resultado) {
                    throw new PasswordResetStorageException('contador_no_confirmado');
                }
                return $resultado;
            });
        });
    }

    public function con_candado($clave, $segundos, $espera, callable $accion)
    {
        $error_de_accion = null;
        try {
            return $this->getStore()->lock($clave, $segundos)->block($espera, function () use ($accion, &$error_de_accion) {
                try {
                    return $accion();
                } catch (Throwable $e) {
                    $error_de_accion = $e;
                    throw $e;
                }
            });
        } catch (LockTimeoutException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($error_de_accion === $e) {
                throw $e;
            }
            throw new PasswordResetStorageException('candado', 0, $e);
        }
    }
}
