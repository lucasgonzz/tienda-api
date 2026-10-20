<?php

namespace App\Mail;

use App\Http\Controllers\Helpers\OrderMailDataHelper;
use App\OnlineConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Mail con el codigo para recuperar la contraseña del comprador de la tienda.
 *
 * Tiene el mismo diseño que los mails de pedido (`emails/orders/layout`): tarjeta blanca, logo del
 * comercio (o su nombre si no tiene logo), titulo, bajada y el codigo en una caja destacada con el color
 * de acento de la tienda. El logo, el nombre y el color salen de `OrderMailDataHelper`, el mismo lugar
 * que usan los mails de pedido, para que el comprador reconozca al comercio en los dos.
 */
class PasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Codigo que el comprador tiene que escribir en la pagina (4 o 6 digitos, segun lo que pidio la pantalla).
     *
     * @var int|string
     */
    public $code;

    /**
     * Comercio dueño de la tienda (puede ser null si el comercio ya no existe: el mail sale igual).
     *
     * @var \App\User|null
     */
    public $commerce;

    /**
     * @param  int|string  $code  Codigo ya generado.
     * @param  \App\User|null  $commerce  Comercio de la tienda.
     */
    public function __construct($code, $commerce)
    {
        $this->code = $code;
        $this->commerce = $commerce;
    }

    /**
     * Arma el mail. La vida del codigo (en minutos) se lee de la constante del controlador para que el
     * texto del mail y el tope real nunca queden desfasados.
     *
     * @return $this
     */
    public function build()
    {
        $configuration = is_null($this->commerce)
            ? null
            : OnlineConfiguration::where('user_id', $this->commerce->id)->first();

        $company_name = (is_null($this->commerce) || empty($this->commerce->company_name))
            ? 'La tienda'
            : $this->commerce->company_name;

        $accent_color = $this->color_de_acento_seguro(OrderMailDataHelper::accentColor($configuration));

        return $this->subject('Tu código para recuperar la contraseña - '.$company_name)
                    ->from('contacto@comerciocity.com', 'comerciocity.com')
                    ->view('emails.password-reset', [
                        'code'         => $this->code,
                        'commerce'     => $this->commerce,
                        'company_name' => $company_name,
                        'logo_url'     => OrderMailDataHelper::logoUrl($this->commerce, $configuration),
                        'accent_color' => $accent_color,
                        'text_color'   => $this->color_de_texto_sobre($accent_color),
                        'minutes'      => \App\Http\Controllers\PasswordResetController::MINUTOS_DE_VIDA_DEL_CODIGO,
                    ]);
    }

    /**
     * El color de acento tal cual si es un hexadecimal (#abc o #aabbcc); si es otra cosa (un nombre, un
     * rgb(), basura) se usa el gris oscuro neutro de los mails de pedido. Asi la caja del codigo nunca
     * sale con un estilo roto y el valor de la base no se cuela crudo en el atributo `style`.
     *
     * @param  string  $color
     * @return string
     */
    private function color_de_acento_seguro($color)
    {
        if (is_string($color) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($color))) {
            return trim($color);
        }

        return '#111827';
    }

    /**
     * Blanco u oscuro, el que mejor se lee sobre el color de acento (luminancia relativa del WCAG): un
     * acento claro (amarillo, celeste) con texto blanco se volveria ilegible, y el codigo es lo unico
     * que el mail tiene que mostrar.
     *
     * @param  string  $hex  Hexadecimal de 3 o 6 digitos con el #, ya validado.
     * @return string
     */
    private function color_de_texto_sobre($hex)
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        $canales = [];
        foreach ([0, 2, 4] as $posicion) {
            $valor = hexdec(substr($hex, $posicion, 2)) / 255;
            $canales[] = $valor <= 0.03928 ? $valor / 12.92 : pow(($valor + 0.055) / 1.055, 2.4);
        }

        $luminancia = 0.2126 * $canales[0] + 0.7152 * $canales[1] + 0.0722 * $canales[2];

        // Por encima de ~0.4 el texto oscuro tiene mas contraste que el blanco.
        return $luminancia > 0.4 ? '#111827' : '#ffffff';
    }
}
