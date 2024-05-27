<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\OrderNotificationHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Message;
use App\Notifications\MessageSend;

class MessageHelper
{
    static function sendOrderCreatedMessage($order) {
        $created_message = OrderNotificationHelper::getCreatedMessage($order);
        $message = Message::create([
            'user_id' => $order->user_id,
            'buyer_id' => $order->buyer_id,
            'text' => $created_message,
        ]);
        $title = 'Recibimos tu pedido';
        // $order->buyer->notify(new MessageSend($message, true, $title));
        // $order->user->notify(new MessageSend($message));
    }
}
