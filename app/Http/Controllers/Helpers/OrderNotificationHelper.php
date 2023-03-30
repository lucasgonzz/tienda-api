<?php

namespace App\Http\Controllers\Helpers;

class OrderNotificationHelper
{
    static function getCreatedMessage($order) {
        $message = '¡Hola '.$order->buyer->name.'! Ya recibimos tu pedido 😀. Te vamos a avisar cuando lo aprobemos.';
        if ($order->payment_method == 'tarjeta') {
            $message .= ' Y una vez aprobado procesamos el pago.';
        }
        return $message;
    }
}
