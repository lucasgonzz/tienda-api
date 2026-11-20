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
                    $emails = OrderMailDeliveryHelper::recipients($configuration->mail_notificacion_pedidos);
                    // Un valor configurado inválido no habilita otra casilla inesperada.
                    if (trim((string) $configuration->mail_notificacion_pedidos) === '') {
                        $emails = OrderMailDeliveryHelper::recipients($order->user ? $order->user->email : null);
                    }
                    $this->deliver($order, 'new_order_merchant', $emails);
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

    private static function diagnostic($code): void
    {
        try {
            Log::warning('Order mail', ['error_code' => $code]);
        } catch (\Throwable $ignored) {
            // Ni un logger roto puede invalidar el pedido.
        }
    }
}
