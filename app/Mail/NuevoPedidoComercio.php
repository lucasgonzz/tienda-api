<?php

namespace App\Mail;

use App\Http\Controllers\Helpers\OrderMailDataHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso al comercio de que entro un pedido nuevo desde la tienda online (prompt 386).
 */
class NuevoPedidoComercio extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @var \App\Order
     */
    public $order;

    /**
     * @param \App\Order $order
     */
    public function __construct($order)
    {
        $this->order = $order;
    }

    /**
     * @return $this
     */
    public function build()
    {
        // Arma, una sola vez, todos los datos que necesita la plantilla (logo, color, comprador,
        // entrega y desglose de totales). El blade solo imprime, no consulta ni calcula nada.
        $data = OrderMailDataHelper::build($this->order);

        return $this->subject('Nuevo pedido N° '.$this->order->num.' de '.$data['buyer_name'])
                    ->view('emails.orders.nuevo-pedido-comercio', [
                        'data' => $data,
                    ]);
    }
}
