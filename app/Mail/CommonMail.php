<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CommonMail extends Mailable
{
    use Queueable, SerializesModels;

    public $logoUrl;
    public $body;
    public $asunto;
    public $footer;

    public function __construct(array $data)
    {

        $this->logoUrl  = $data['logoUrl'] ?? 'https://comerciocity.com/img/logo.cc4cb183.png';
        $this->body     = $data['mensaje'] ?? [];
        $this->asunto   = $data['asunto'] ?? 'Notificacion';
        $this->footer   = $data['footer'] ?? null;
    }


    public function build()
    {
        return $this->from(env('MAIL_FROM_ADDRESS'), 'comerciocity.com')
            ->subject($this->asunto)
            ->markdown('emails.common-mail')
            ->with([
                'logoUrl' => $this->logoUrl,
                'body' => $this->body,
                'footer' => $this->footer,
            ]);
    }
}
