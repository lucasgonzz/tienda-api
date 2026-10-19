<?php

namespace App\Providers;

use App\Broadcasting\PusherBroadcasterConEspacio;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * 🔴 El driver `pusher` se reemplaza por App\Broadcasting\PusherBroadcasterConEspacio (misión
     * pusher-canales-por-sistema, 19/10/2026): toda la flota comparte UNA app de Pusher y los
     * canales llevaban solo un id, así que dos sistemas con el mismo id de dueño se escuchaban
     * entre sí (demo3 y Ferretotal, los dos con dueño 2600). El broadcaster nuevo le agrega a cada
     * canal el sufijo `@espacio` propio del sistema (ver App\Broadcasting\EspacioDeCanales), en un
     * solo punto, sin tocar el `broadcastOn()` de ningún evento.
     *
     * `Broadcast::pusher($config)` es el mismo método público de BroadcastManager que usa el driver
     * de fábrica (createPusherDriver): arma el cliente Pusher\Pusher con la misma config, el mismo
     * cliente Guzzle opcional y el mismo logger. Lo único que cambia es la clase que lo envuelve.
     *
     * Va ANTES de Broadcast::routes() para que quede registrado antes de cualquier uso. El driver
     * se resuelve perezoso (al primer broadcast), así que el orden no cambia nada hoy, pero así no
     * depende de eso.
     *
     * @return void
     */
    public function boot()
    {
        Broadcast::extend('pusher', function ($app, array $config) {
            return new PusherBroadcasterConEspacio(Broadcast::pusher($config));
        });

        Broadcast::routes();

        require base_path('routes/channels.php');
    }
}
