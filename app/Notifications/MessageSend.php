<?php

namespace App\Notifications;

use App\Http\Controllers\Helpers\ImageHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MessageSend extends Notification
{
    use Queueable;
    private $message;
    private $for_buyer;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($message, $for_buyer = false, $title = null)
    {
        $this->message = $message;
        $this->for_buyer = $for_buyer;
        $this->title = $title;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        if ($this->for_buyer) {
            return ['broadcast', 'mail'];
        } 
        return ['broadcast'];
    }

    public function broadcastOn()
    {
        if (!$this->for_buyer) {
            return 'message.from_buyer.'.$this->message->user_id;
        } else {
            return 'message.from_commerce.'.$this->message->buyer_id;
        }
    }


    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'message' => $this->message,
        ]);
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->subject($this->title)
                    ->from($notifiable->user->email, $notifiable->user->company_name)
                    ->markdown('emails.message-send', [
                        'commerce'  => $notifiable->user,
                        'message'   => $this->message->text,
                        'logo_url'  => ImageHelper::image($notifiable->user),
                    ]);
    }
}
