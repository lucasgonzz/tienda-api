<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lectura (sólo lectura) del estado "se está actualizando el sistema" de un comercio.
 *
 * Misión aviso-actualizacion-sistema. El estado vive en la tabla `actualizaciones_del_sistema`, que crea y
 * escribe SÓLO empresa-api (tienda-api no tiene migraciones: comparte la base con empresa). Una fila por
 * `owner_id`, y `owner_id` es el id del comercio, o sea el `commerce_id` que la tienda usa en sus rutas.
 *
 * Regla de bloqueo (contrato entre proyectos): la tienda se bloquea si y sólo si
 * `estado = 'actualizando' AND vence_at > ahora`. El `vence_at` es la red de seguridad: si empresa-api
 * prendió el estado y nunca lo apagó (falló el deploy, se cayó el admin), la tienda se libera sola.
 *
 * 🔴 Esta clase NUNCA tiene que tirar abajo la tienda. La tienda se despliega ANTES que el release de empresa,
 * así que "la tabla no existe todavía" es el estado normal durante días; y ante cualquier error de base la
 * respuesta es "no bloquea": preferimos mostrar la tienda de más antes que cerrarla por un fallo de lectura.
 * Ese caso tampoco se cachea, para volver a intentar en la request siguiente.
 */
class ActualizacionDelSistema
{
    /** Estado que bloquea la tienda (el que escribe empresa-api mientras dura la actualización). */
    const ESTADO_ACTUALIZANDO = 'actualizando';

    /** Segundos que se cachea la lectura por comercio: la tienda se entera en <= 10 s de que empezó o terminó. */
    const SEGUNDOS_DE_CACHE = 10;

    /** Prefijo de la clave de cache; se le suma el id del comercio. */
    const PREFIJO_DE_CACHE = 'tienda.actualizacion_del_sistema.';

    /**
     * Dice si la tienda del comercio está bloqueada por una actualización del sistema en curso.
     *
     * @param int $commerce_id Id del comercio (`users.id` del dueño, el `commerce_id` de las rutas).
     * @return bool true si hay una fila `actualizando` cuyo `vence_at` todavía no pasó.
     */
    public static function bloquea(int $commerce_id): bool
    {
        if ($commerce_id <= 0) {
            return false;
        }

        $fila = self::leer_estado($commerce_id);

        if (is_null($fila) || $fila['estado'] !== self::ESTADO_ACTUALIZANDO || empty($fila['vence_at'])) {
            return false;
        }

        // El vencimiento se compara en cada request contra la hora actual (no se cachea el resultado):
        // así un `vence_at` que cae dentro de la ventana de cache libera la tienda en el momento justo.
        try {
            return Carbon::parse($fila['vence_at'])->greaterThan(Carbon::now());
        } catch (Throwable $excepcion) {
            return false;
        }
    }

    /**
     * Devuelve el estado del comercio (estado y vencimiento), cacheado 10 segundos.
     *
     * Se cachea también "no hay fila" (null) para no consultar la base en cada request de una tienda que no
     * se está actualizando, que es el caso de siempre. Si la lectura falla (tabla inexistente, base caída) no
     * se cachea nada y se devuelve null.
     *
     * @param int $commerce_id Id del comercio.
     * @return array|null ['estado' => string, 'vence_at' => string|null], o null si no hay fila o no se pudo leer.
     */
    protected static function leer_estado(int $commerce_id): ?array
    {
        $clave = self::PREFIJO_DE_CACHE . $commerce_id;

        try {
            // `Cache::has` distingue "cacheado como null" de "sin cachear"; `remember` no lo hace con null.
            if (Cache::has($clave)) {
                $guardado = Cache::get($clave);

                return is_array($guardado) ? $guardado : null;
            }

            $fila = DB::table('actualizaciones_del_sistema')
                ->where('owner_id', $commerce_id)
                // Misma fila que elige empresa-api por dueño (`orderBy('id')`, la de id más bajo): si hubiera dos,
                // las dos puntas tienen que leer la misma.
                ->orderBy('id')
                ->first(['estado', 'vence_at']);

            $estado = is_null($fila) ? null : [
                'estado' => (string) $fila->estado,
                'vence_at' => is_null($fila->vence_at) ? null : (string) $fila->vence_at,
            ];

            Cache::put($clave, $estado ?? false, self::SEGUNDOS_DE_CACHE);

            return $estado;
        } catch (Throwable $excepcion) {
            // Tabla inexistente (empresa-api viejo) o error de base: no bloquea y no se cachea.
            return null;
        }
    }
}
