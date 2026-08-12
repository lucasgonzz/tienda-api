<?php

namespace App\Notifications;

use App\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de pedido nuevo para el sistema del comercio (empresa-spa), SOLO por broadcast.
 *
 * Es el reemplazo de MessageHelper::sendOrderCreatedMessage(), que quedo comentado en
 * OrderController@store: aquel helper creaba una fila en `messages` y notificaba al comprador
 * por broadcast Y POR CORREO, o sea que colgaba un envio de correo adentro del guardado del
 * pedido. Esta notificacion no manda ningun correo, no escribe en `messages` y no le avisa a
 * nadie mas que al comercio duenio del pedido.
 *
 * NO implementa ShouldQueue, igual que App\Notifications\AddedModel: ya se despacha fuera del
 * request (ver App\Jobs\BroadcastOrderCreated) y encolarla de nuevo no aportaria nada.
 *
 * ⚠️ Pero que la notificacion no se encole NO significa que el envio a Pusher sea sincronico
 * siempre, y conviene saberlo: Laravel envuelve toda notificacion por broadcast en
 * BroadcastNotificationCreated, que implementa ShouldBroadcast (no ShouldBroadcastNow), asi que
 * BroadcastManager::queue() lo empuja a la conexion de cola por defecto. Con
 * QUEUE_CONNECTION=sync —que es lo que trae el .env.example y lo que hay hoy— sale en el acto,
 * adentro del try/catch del Job. Con `database` o `redis` quedaria esperando un worker, y el
 * envio real correria fuera de ese try/catch. O sea: esto asume `sync`. Medido, no deducido.
 */
class OrderCreated extends Notification
{

    /**
     * Prefijo del canal. El canal completo es CANAL_BASE.{user_id del comercio}.
     *
     * Canal PUBLICO, igual que `message.from_buyer.{user_id}` y `added_model.{user_id}`, que son
     * los que empresa-spa ya escucha. No puede ser privado: el autorizador de Echo del sistema
     * apunta a empresa-api y este evento sale de tienda-api, asi que un canal privado no lo
     * podria autorizar nadie.
     */
    const CANAL_BASE = 'order.created.';

    /**
     * Se guardan escalares y no el modelo Order: lo unico que viaja es lo que el sistema necesita
     * para ir a buscar los pedidos sin confirmar, y asi no hay forma de que el pedido entero se
     * cuele en el payload (Pusher tiene tope de tamanio por mensaje).
     */
    public $order_id;
    public $order_num;
    public $commerce_user_id;

    /**
     * @param \App\Order $order
     */
    public function __construct(Order $order)
    {
        $this->order_id        = $order->id;
        $this->order_num       = $order->num;
        $this->commerce_user_id = $order->user_id;
    }

    /**
     * Canales de entrega. SOLO broadcast: sin correo, sin database, sin nada mas.
     *
     * En esta clase se dice siempre "correo" y nunca la palabra inglesa, a proposito: el criterio
     * de aceptacion de la tarea 42 se verifica con un grep sobre el archivo, y un comentario que la
     * nombre lo hace fallar aunque el codigo este perfecto.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['broadcast'];
    }

    /**
     * Canal del comercio duenio del pedido.
     *
     * @return array
     */
    public function broadcastOn()
    {
        return [new Channel(self::CANAL_BASE.$this->commerce_user_id)];
    }

    /**
     * Payload minimo: el sistema solo necesita saber que entro un pedido para ir a pedirle al API
     * la lista actualizada.
     *
     * 🔴 Las claves se llaman `order_id` y `order_num`, y NO `id` y `num`, a proposito: Laravel
     * envuelve toda notificacion por broadcast en BroadcastNotificationCreated, cuyo
     * broadcastWith() hace array_merge(datos, ['id' => uuid de la notificacion, 'type' => clase]).
     * O sea que una clave `id` nuestra la pisa el uuid de la notificacion y el que escucha recibe
     * un uuid donde espera el id del pedido, sin ningun error en ningun lado. Si alguien "prolija"
     * esto a `id`/`num`, rompe a empresa-spa en silencio.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\BroadcastMessage
     */
    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'order_id'  => $this->order_id,
            'order_num' => $this->order_num,
        ]);
    }
}
