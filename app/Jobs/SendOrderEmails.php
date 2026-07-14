<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ClientMailConfigHelper;
use App\Mail\NuevoPedidoComercio;
use App\Mail\PedidoRecibidoComprador;
use App\OnlineConfiguration;
use App\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Manda los dos mails de un pedido nuevo: el aviso al comercio y la confirmacion al comprador
 * (prompt 387).
 *
 * REGLA DE ORO: este Job NUNCA puede tirar una excepcion hacia arriba. Se despacha con
 * dispatchAfterResponse(), pero corre en el mismo proceso PHP del request que creo el pedido; una
 * excepcion que escape de aca puede terminar en un 500 y dejar al comprador sin confirmacion de una
 * compra que en realidad SI se guardo. Todo va dentro de try/catch, y cada mail dentro del suyo.
 */
class SendOrderEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int
     */
    public $order_id;

    /**
     * @var int
     */
    public $tries = 1;

    /**
     * @param int $order_id
     */
    public function __construct($order_id)
    {
        $this->order_id = $order_id;
    }

    /**
     * @return void
     */
    public function handle()
    {
        try {
            $order = Order::where('id', $this->order_id)
                            ->withAll()
                            ->with('promociones_vinoteca', 'user')
                            ->first();

            if (is_null($order)) {
                Log::warning('SendOrderEmails: no se encontro el pedido', ['order_id' => $this->order_id]);
                return;
            }

            $order->articles = ArticleHelper::setArticlesVariants($order->articles);

            $configuration = OnlineConfiguration::where('user_id', $order->user_id)->first();

            if (is_null($configuration)) {
                Log::warning('SendOrderEmails: el comercio no tiene online_configuration, no se envian mails', [
                    'order_id' => $this->order_id,
                    'user_id'  => $order->user_id,
                ]);
                return;
            }

            // Casilla propia del comercio si esta configurada; si no, el .env del sistema.
            ClientMailConfigHelper::apply($order->user_id);

            $this->enviarAlComercio($order, $configuration);
            $this->enviarAlComprador($order, $configuration);
        } catch (\Exception $e) {
            Log::error('SendOrderEmails: error general enviando los mails del pedido', [
                'order_id' => $this->order_id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Aviso al comercio. Destinatarios: mail_notificacion_pedidos (uno o varios separados por coma);
     * si esta vacio, el email de la cuenta del comercio (comportamiento historico).
     *
     * @param \App\Order $order
     * @param \App\OnlineConfiguration $configuration
     * @return void
     */
    private function enviarAlComercio($order, $configuration)
    {
        try {
            if (!$configuration->notificar_pedido_al_negocio) {
                Log::info('SendOrderEmails: aviso al comercio desactivado', ['order_id' => $this->order_id]);
                return;
            }

            $emails = $this->parseEmails($configuration->mail_notificacion_pedidos);

            if (count($emails) < 1 && !is_null($order->user) && !empty($order->user->email)) {
                $emails = $this->parseEmails($order->user->email);
            }

            if (count($emails) < 1) {
                Log::warning('SendOrderEmails: no hay ninguna casilla valida para avisarle al comercio', [
                    'order_id' => $this->order_id,
                ]);
                return;
            }

            Mail::to($emails)->send(new NuevoPedidoComercio($order));

            Log::info('SendOrderEmails: aviso al comercio enviado', [
                'order_id' => $this->order_id,
                'emails'   => $emails,
            ]);
        } catch (\Exception $e) {
            // Que falle el mail al comercio no puede impedir el del comprador.
            Log::error('SendOrderEmails: fallo el aviso al comercio', [
                'order_id' => $this->order_id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Confirmacion al comprador.
     *
     * @param \App\Order $order
     * @param \App\OnlineConfiguration $configuration
     * @return void
     */
    private function enviarAlComprador($order, $configuration)
    {
        try {
            if (!$configuration->notificar_pedido_al_cliente) {
                Log::info('SendOrderEmails: confirmacion al comprador desactivada', ['order_id' => $this->order_id]);
                return;
            }

            if (is_null($order->buyer)) {
                return;
            }

            $emails = $this->parseEmails($order->buyer->email);

            if (count($emails) < 1) {
                // Caso normal, no es un error: compra de invitado sin mail, o pedido cargado por un
                // vendedor a nombre de un cliente sin correo.
                Log::info('SendOrderEmails: el comprador no tiene un mail valido, no se le envia confirmacion', [
                    'order_id' => $this->order_id,
                ]);
                return;
            }

            Mail::to($emails)->send(new PedidoRecibidoComprador($order));

            Log::info('SendOrderEmails: confirmacion al comprador enviada', [
                'order_id' => $this->order_id,
            ]);
        } catch (\Exception $e) {
            Log::error('SendOrderEmails: fallo la confirmacion al comprador', [
                'order_id' => $this->order_id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parte un string con uno o varios correos separados por coma (o punto y coma) y devuelve solo
     * los que tienen formato valido. Un correo mal escrito por el comercio no puede hacer fallar el
     * envio a los demas.
     *
     * @param string|null $raw
     * @return array
     */
    private function parseEmails($raw)
    {
        $emails = [];

        if (is_null($raw) || trim((string) $raw) === '') {
            return $emails;
        }

        $parts = preg_split('/[,;]+/', (string) $raw);

        foreach ($parts as $part) {
            $email = trim($part);

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $email;
            } else {
                if ($email !== '') {
                    Log::warning('SendOrderEmails: se descarta un correo con formato invalido', [
                        'order_id' => $this->order_id,
                        'email'    => $email,
                    ]);
                }
            }
        }

        return $emails;
    }
}
