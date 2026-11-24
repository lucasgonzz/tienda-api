<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ClientMailConfigHelper;
use App\Http\Controllers\Helpers\OrderMailDeliveryHelper;
use App\Mail\NuevoPedidoComercio;
use App\Mail\PedidoRecibidoComprador;
use App\OnlineConfiguration;
use App\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Nunca propaga errores auxiliares a la compra confirmada ni registra PII del transporte. */
class SendOrderEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $order_id;
    public $tries = 1;

    public function __construct($order_id)
    {
        $this->order_id = $order_id;
    }

    public function handle()
    {
        try {
            if (DB::transactionLevel() !== 0) {
                self::diagnostic('order_not_committed');
                return;
            }
            $order = Order::whereKey($this->order_id)->withAll()
                ->with('promociones_vinoteca', 'user')->first();
            if (!$order) {
                return;
            }
            $configuration = OnlineConfiguration::where('user_id', $order->user_id)->first();
            if (!$configuration) {
                return;
            }
            $order->articles = ArticleHelper::setArticlesVariants($order->articles);

            ClientMailConfigHelper::isolated($order->user_id, function () use ($order, $configuration) {
                if ($configuration->notificar_pedido_al_negocio) {
                    $this->deliver($order, 'new_order_merchant', $this->merchant_recipients($order, $configuration));
                } else {
                    // Aviso apagado por el comercio: queda registrado para poder explicar "no me llegó".
                    self::diagnostic('merchant_notification_disabled', $order->id);
                }
                if ($configuration->notificar_pedido_al_cliente && $order->buyer
                    && (int) $order->buyer->user_id === (int) $order->user_id) {
                    $this->deliver($order, 'order_received',
                        OrderMailDeliveryHelper::recipients($order->buyer->email));
                }
            });
        } catch (\Throwable $e) {
            self::diagnostic('order_mail_job_failed');
        }
    }

    /**
     * Destinatarios del aviso al comercio.
     *
     * Los correos válidos del campo de configuración; si el campo está vacío o no tiene ninguno válido,
     * el correo de la cuenta (como hacía la versión anterior), así un error de tipeo no apaga el aviso.
     * Cada decisión relevante deja un código en el log con el id del pedido (nunca direcciones).
     *
     * @param \App\Order $order Pedido con la relación user cargada.
     * @param \App\OnlineConfiguration $configuration Configuración de la tienda del pedido.
     * @return array Lista de correos válidos (puede estar vacía).
     */
    private function merchant_recipients($order, $configuration): array
    {
        $raw = (string) $configuration->mail_notificacion_pedidos;
        $emails = OrderMailDeliveryHelper::recipients($raw);
        // Valores escritos en el campo que no son un correo válido (sólo se cuentan, nunca se registran).
        $discarded = 0;
        foreach (preg_split('/[,;]+/', $raw) as $part) {
            if (trim($part) !== '' && OrderMailDeliveryHelper::recipients($part) === []) {
                $discarded++;
            }
        }
        if ($emails) {
            if ($discarded > 0) {
                self::diagnostic('merchant_recipient_discarded', $order->id, ['discarded' => $discarded]);
            }
            return $emails;
        }
        $account = OrderMailDeliveryHelper::recipients($order->user ? $order->user->email : null);
        if (trim($raw) !== '') {
            self::diagnostic($account ? 'merchant_recipients_invalid_using_account' : 'merchant_no_valid_recipient', $order->id);
        } elseif (!$account) {
            self::diagnostic('merchant_no_valid_recipient', $order->id);
        }
        return $account;
    }

    private function deliver($order, $type, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            try {
                OrderMailDeliveryHelper::deliver($order, $type, $recipient, function () use ($order, $type, $recipient) {
                    $mail = $type === 'order_received'
                        ? new PedidoRecibidoComprador($order) : new NuevoPedidoComercio($order);
                    return Mail::to($recipient)->send($mail);
                });
            } catch (\Throwable $e) {
                // Una falla de DB o render no impide intentar los otros destinatarios.
                self::diagnostic('order_mail_delivery_failed');
            }
        }
    }

    /**
     * Registra un código de diagnóstico: sólo código, id del pedido y conteos; nunca direcciones.
     *
     * @param string $code Código estable (por ejemplo merchant_notification_disabled).
     * @param int|null $order_id Pedido al que se refiere.
     * @param array $counts Conteos adicionales (enteros).
     */
    private static function diagnostic($code, $order_id = null, array $counts = []): void
    {
        try {
            Log::warning('Order mail', array_merge(['error_code' => $code, 'order_id' => $order_id], $counts));
        } catch (\Throwable $ignored) {
            // Ni un logger roto puede invalidar el pedido.
        }
    }
}
