<?php

namespace App\Mail;

use App\Http\Controllers\Helpers\ImageHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($code, $commerce)
    {
        $this->code = $code;
        $this->commerce = $commerce;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Recupero de Contraseña')
                    ->from($this->commerce->email, $this->commerce->company_name)
                    ->markdown('emails.password-reset', [
                        'code'      => $this->code,
                        'commerce'  => $this->commerce,
                        'logo_url'  => ImageHelper::image($this->commerce),
                    ]);
    }
}
