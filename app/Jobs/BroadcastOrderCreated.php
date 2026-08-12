<?php

namespace App\Jobs;

use App\Notifications\OrderCreated;
use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Avisa por broadcast al sistema del comercio que entro un pedido nuevo de la tienda.
 *
 * REGLA DE ORO, la misma que ya tiene SendOrderEmails: este Job NUNCA puede tirar una excepcion
 * hacia arriba. Se despacha con dispatchAfterResponse(), o sea que corre en el mismo proceso PHP
 * del request que creo el pedido; el pedido YA esta guardado a esa altura y ningun problema del
 * aviso puede terminar en un error para el comprador. Todo va adentro de try/catch.
 *
 * El catch es de \Throwable y no de \Exception (que es lo que usa SendOrderEmails) porque un
 * problema de configuracion de broadcasting no siempre llega como Exception: una credencial de
 * Pusher ausente o un driver mal escrito tiran Error/TypeError, que \Exception no atrapa.
 *
 * Recibe escalares y no el modelo Order a proposito: asi no hay ninguna consulta a `orders` ni
 * re-hidratacion de SerializesModels (que ademas tiraria ModelNotFound si el pedido se borrara
 * entre la respuesta y el despacho).
 */
class BroadcastOrderCreated implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int
     */
    public $order_id;

    /**
     * @var string|int
     */
    public $order_num;

    /**
     * user_id del comercio duenio del pedido: es el que define el canal.
     * @var int
     */
    public $commerce_user_id;

    /**
     * @var int
     */
    public $tries = 1;

    /**
     * @param int $order_id
     * @param string|int $order_num
     * @param int $commerce_user_id
     */
    public function __construct($order_id, $order_num, $commerce_user_id)
    {
        $this->order_id         = $order_id;
        $this->order_num        = $order_num;
        $this->commerce_user_id = $commerce_user_id;
    }

    /**
     * @return void
     */
    public function handle()
    {
        try {
            $commerce = User::find($this->commerce_user_id);

            if (is_null($commerce)) {
                Log::warning('BroadcastOrderCreated: no se encontro el comercio, no se emite el aviso', [
                    'order_id' => $this->order_id,
                    'user_id'  => $this->commerce_user_id,
                ]);
                return;
            }

            // El Order se arma en memoria y no se lee de la base: la notificacion solo usa id, num
            // y user_id, y esos tres datos ya vienen en el Job.
            $order = new \App\Order([
                'num'     => $this->order_num,
                'user_id' => $this->commerce_user_id,
            ]);
            $order->id = $this->order_id;

            $commerce->notify(new OrderCreated($order));

            Log::info('BroadcastOrderCreated: aviso de pedido nuevo emitido', [
                'order_id' => $this->order_id,
                'canal'    => OrderCreated::CANAL_BASE.$this->commerce_user_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('BroadcastOrderCreated: fallo el aviso de pedido nuevo, el pedido igual se creo bien', [
                'order_id' => $this->order_id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
