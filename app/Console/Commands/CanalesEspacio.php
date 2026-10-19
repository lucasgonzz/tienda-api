<?php

namespace App\Console\Commands;

use App\Broadcasting\EspacioDeCanales;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan canales:espacio` — muestra con qué espacio de nombres emite esta tienda-api sus
 * canales en vivo (misión pusher-canales-por-sistema, 19/10/2026).
 *
 * Sirve para comparar, después de un despliegue, que empresa-api y tienda-api del MISMO cliente
 * dan el mismo espacio (si no, el ERP y la tienda dejan de oírse en modo estricto sin ningún error
 * en ningún lado). empresa-api tiene el mismo comando con la misma salida.
 *
 * Imprime UNA sola línea JSON, con las claves en este orden:
 *
 *     {"base":"u767360347_ferretotal","entorno":"production","espacio":"26d5013a6846","canales_viejos_de_tienda":true,"driver":"pusher"}
 *
 * 🔴 NUNCA imprime el secret de Pusher (ni la key ni el app_id): el espacio se deriva de él, y
 * esta salida se pega en informes y en la consola de Lucas.
 */
class CanalesEspacio extends Command
{
    /**
     * Nombre del comando.
     *
     * @var string
     */
    protected $signature = 'canales:espacio';

    /**
     * Descripción que muestra `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Muestra en una línea JSON la base, el entorno, el espacio de los canales en vivo, el modo de los canales de tienda y el driver de broadcast (sin el secret)';

    /**
     * Arma la línea y la imprime.
     *
     * @return int
     */
    public function handle()
    {
        // La base por defecto de la API: la misma que usa EspacioDeCanales para calcular.
        $base = '';
        try {
            $base = (string) DB::connection()->getDatabaseName();
        } catch (\Throwable $excepcion) {
            $base = (string) config('database.connections.' . config('database.default') . '.database', '');
        }

        // Lo que se muestra, en el orden del contrato con el comando gemelo de empresa-api.
        $datos = [
            'base'                     => $base,
            'entorno'                  => (string) config('app.env'),
            'espacio'                  => EspacioDeCanales::espacio(),
            'canales_viejos_de_tienda' => EspacioDeCanales::canales_viejos_de_tienda(),
            'driver'                   => (string) config('broadcasting.default'),
        ];

        $this->line(json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
