<?php

namespace App\Mail;

use App\Http\Controllers\Helpers\OrderMailDataHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmacion al comprador de que su pedido fue recibido (prompt 386).
 */
class PedidoRecibidoComprador extends Mailable
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
        // Mismo payload que el mail al comercio: la vista decide que mostrar segun 'para_comercio'
        // en el partial de datos, pero el detalle de articulos y totales es identico para ambos.
        $data = OrderMailDataHelper::build($this->order);

        return $this->subject('Recibimos tu pedido N° '.$this->order->num.' - '.$data['company_name'])
                    ->view('emails.orders.pedido-recibido-comprador', [
                        'data' => $data,
                    ]);
    }
}
