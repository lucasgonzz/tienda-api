<?php

namespace App\Notifications;

use App\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Broadcasting\Channel;

class OrderCreated extends Notification 
{

    private $order;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        Log::info('Notificación enviada a: ' . $notifiable->id);
        return ['broadcast'];
    }

    public function broadcastOn()
    {
        // return 'order.'.$this->order->user_id;
        return [new Channel('order.' . $this->order->user_id)];
    }



    // public function broadcastWith($notifiable)
    // {
    //     Log::info('1 Datos enviados en broadcast: ', ['order_id' => $this->order->id]);
    //     return [
    //         'order_id' => $this->order->id,
    //     ];
    // }

    public function toBroadcast($notifiable)
    {
        Log::info('2 Datos enviados en broadcast: ', ['order_id' => $this->order->id]);
        return new BroadcastMessage([
            'order_id' => $this->order->id,
        ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'order_id' => $this->order->id,
        ];
    }
}
