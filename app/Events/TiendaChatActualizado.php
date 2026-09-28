<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Aviso en vivo al sistema de gestion (empresa-spa, submodulo Mensajes de Tienda Online) de que
 * una conversacion de la tienda tiene novedades. Mision mensajes-tienda-online (28/9/2026),
 * contrato C1.
 *
 * 🔴 ESTE EVENTO TIENE UN GEMELO en `empresa-api` (app/Events/TiendaChatActualizado.php): mismo
 * nombre, mismo canal, mismo broadcastAs y mismo payload. La SPA del ERP escucha un solo evento y
 * no distingue cual de los dos sistemas lo mando. Si aca cambia una clave o un tipo, no explota
 * nada en ningun lado: el mensaje simplemente no aparece en la bandeja. Cualquier cambio de forma
 * se hace en los dos repos a la vez y de manera compatible hacia atras.
 *
 * Desde tienda-api se emite UNA sola cosa: el mensaje nuevo del comprador (POST /api/messages),
 * a traves de App\Jobs\BroadcastMensajeDelComprador, que es el que arma el payload. El evento solo
 * lo transporta: no consulta la base ni serializa modelos.
 *
 * Canal PRIVADO `tienda-mensajes.{owner_id}` (en Pusher: `private-tienda-mensajes.{owner_id}`).
 * A diferencia de App\Notifications\OrderCreated, que tuvo que ser publico, aca el canal lo
 * autoriza empresa-api (routes/channels.php de ese repo: duenio o empleado del comercio), que es
 * el mismo sistema contra el que se autentica el Echo de empresa-spa. Emitir a un canal privado
 * no requiere autorizacion: solo suscribirse. Por eso tienda-api puede emitir aca sin tener
 * ninguna ruta de broadcasting propia.
 *
 * ShouldBroadcastNow y no ShouldBroadcast: sale en el acto, sin pasar por la cola. Ya corre
 * despues de la respuesta (dispatchAfterResponse del Job) y adentro de su try/catch; encolarlo
 * sacaria el envio real de ese try/catch.
 */
class TiendaChatActualizado implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * Id del DUENIO del comercio (buyers.user_id = messages.user_id). Define el canal.
     *
     * @var int
     */
    public $owner_id;

    /**
     * Payload del contrato C1, ya armado a mano por quien emite. Ver broadcastWith().
     *
     * @var array
     */
    public $payload;

    /**
     * @param int   $owner_id
     * @param array $payload
     */
    public function __construct($owner_id, array $payload)
    {
        $this->owner_id = (int) $owner_id;
        $this->payload  = $payload;
    }

    /**
     * @return \Illuminate\Broadcasting\PrivateChannel
     */
    public function broadcastOn()
    {
        return new PrivateChannel('tienda-mensajes.'.$this->owner_id);
    }

    /**
     * Nombre corto: en la SPA se escucha con
     * `Echo.private('tienda-mensajes.'+owner_id).listen('.TiendaChatActualizado', cb)` (con el punto).
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'TiendaChatActualizado';
    }

    /**
     * El payload tal cual lo armo el emisor. Nada de $model->toArray() ni propiedades publicas
     * automaticas: con broadcastWith() definido, Laravel manda SOLO esto (mas `socket`, que el
     * broadcaster de Pusher saca antes de enviar).
     *
     * @return array
     */
    public function broadcastWith()
    {
        return $this->payload;
    }
}
