<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;

/**
 * El broadcaster de Pusher de tienda-api, con el espacio de nombres del sistema (misión
 * pusher-canales-por-sistema, 19/10/2026; el porqué está en App\Broadcasting\EspacioDeCanales).
 *
 * Es UN solo punto central a propósito: ningún evento ni notificación se toca uno por uno. El
 * `broadcastOn()` de cada clase (OrderCreated, TiendaChatActualizado, AddedModel, MessageSend…)
 * sigue devolviendo el nombre LÓGICO del canal, y acá se traduce al físico justo antes del
 * `trigger` a Pusher.
 *
 * Por qué `formatChannels()`: en Laravel 10 `PusherBroadcaster::broadcast()` hace
 *
 *     $channels = Collection::make($this->formatChannels($channels));
 *     $channels->chunk(100)->each(fn ($channels) => $this->pusher->trigger($channels->toArray(), …));
 *
 * o sea que es el único lugar por donde pasan TODOS los canales antes de salir, y el chunk de 100
 * (el tope de canales por trigger de Pusher) se aplica DESPUÉS de duplicar los nombres en modo
 * transición: un evento con 60 canales sale en dos triggers, no en uno inválido.
 *
 * Lo que NO se sobreescribe: `auth()`. tienda-api no autoriza canales (routes/channels.php está
 * todo comentado y tienda-spa no se suscribe a privados); los privados que emite
 * (`private-tienda-mensajes.{owner}`) los autoriza empresa-api, que tiene su propio broadcaster.
 */
class PusherBroadcasterConEspacio extends PusherBroadcaster
{
    /**
     * Pasa cada canal (objeto Channel o string) a su nombre de Pusher y lo traduce a los nombres
     * por los que hay que emitirlo según el modo (EspacioDeCanales::nombres_para_emitir):
     * transición → [lógico, físico]; estricto → [físico].
     *
     * Sin repetidos y en orden: si dos canales del mismo evento terminan en el mismo nombre, sale
     * una vez (Pusher entregaría el mismo evento dos veces al que está suscripto).
     *
     * @param  array  $channels  Lo que devolvió `broadcastOn()`, ya envuelto en array.
     * @return string[]
     */
    protected function formatChannels(array $channels)
    {
        // Los nombres que se le pasan al trigger, sin repetidos.
        $nombres = [];

        // parent::formatChannels() hace el `(string) $channel` de siempre: un PrivateChannel
        // queda `private-…`, un string queda igual.
        foreach (parent::formatChannels($channels) as $canal) {
            foreach (EspacioDeCanales::nombres_para_emitir($canal) as $nombre) {
                if (!in_array($nombre, $nombres, true)) {
                    $nombres[] = $nombre;
                }
            }
        }

        return $nombres;
    }
}
