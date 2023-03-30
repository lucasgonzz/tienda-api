<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class CallCreated extends Notification
{
    use Queueable;
    private $call;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($call)
    {
        $this->call = $call;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['broadcast'];
    }

    public function broadcastOn()
    {
        return 'call.'.$this->call->user_id;
    }


    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'call' => $this->call,
        ]);
    }
}
