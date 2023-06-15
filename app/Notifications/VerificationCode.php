<?php

namespace App\Notifications;

use App\Http\Controllers\Helpers\ImageHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerificationCode extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($code, $commerce)
    {
        $this->code = $code;
        $this->commerce = $commerce;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->subject('Verificacion de cuenta')
                    ->from('contacto@comerciocity.com', 'comerciocity.com')
                    ->markdown('emails.verification-code', [
                        'code'      => $this->code,
                        'commerce'  => $this->commerce,
                        'buyer'     => $notifiable,
                        'logo_url'  => $this->commerce->image_url,
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
            //
        ];
    }
}
