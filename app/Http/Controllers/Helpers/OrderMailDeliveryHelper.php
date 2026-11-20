<?php

namespace App\Http\Controllers\Helpers;

use App\Order;
use App\OrderCommunicationDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

class OrderMailDeliveryHelper
{
    public static function recipients($raw): array
    {
        $emails = [];
        foreach (preg_split('/[,;]+/', (string) $raw) as $part) {
            $email = strtolower(trim($part));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = $email;
            }
        }
        return array_values($emails);
    }

    /** Sólo después del commit. El claim se confirma ANTES del transporte, sin lock durante SMTP. */
    public static function deliver(Order $order, string $type, string $recipient, callable $send): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('order_mail_requires_commit');
        }
        if (!in_array($type, ['order_received', 'new_order_merchant'], true)
            || self::recipients($recipient) !== [$recipient]) {
            throw new \InvalidArgumentException('invalid_delivery_identity');
        }
        // No confiar en user_id de un modelo construido por el llamador.
        $owner = Order::whereKey($order->id)->value('user_id');
        if (!$owner || (int) $owner !== (int) $order->user_id) {
            throw new \LogicException('invalid_order_owner');
        }
        $identity = [
            'owner_id' => $owner, 'order_id' => $order->id, 'channel' => 'email',
            'communication_type' => $type, 'recipient_hash' => hash('sha256', $recipient),
        ];
        try {
            OrderCommunicationDelivery::create($identity);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }

        $claimed = DB::transaction(function () use ($identity) {
            $row = OrderCommunicationDelivery::where($identity)->lockForUpdate()->firstOrFail();
            if ($row->status === 'attempting') {
                // No confundir otro worker activo con un fallo reintentable.
                // Un intento antiguo queda incierto; JAMÁS se reclama de nuevo por antigüedad.
                if ($row->last_attempt_at && $row->last_attempt_at->lt(now()->subMinutes(15))) {
                    OrderCommunicationDelivery::where($identity)->where('status', 'attempting')
                        ->update(['status' => 'uncertain', 'error_code' => 'orphaned_attempt', 'updated_at' => now()]);
                }
                return false;
            }
            if (!in_array($row->status, ['prepared', 'failed'], true)) {
                return false;
            }
            OrderCommunicationDelivery::where($identity)->update([
                'status' => 'attempting', 'attempt_count' => $row->attempt_count + 1,
                'last_attempt_at' => now(), 'error_code' => null, 'updated_at' => now(),
            ]);
            return true;
        });
        if (!$claimed) {
            return;
        }

        try {
            $accepted = $send();
        } catch (\Throwable $e) {
            // Sólo una respuesta SMTP negativa inequívoca permite otro intento.
            // Timeout, caída o error no clasificado pueden ocultar una aceptación.
            $rejected = $e instanceof UnexpectedResponseException && $e->getCode() >= 400 && $e->getCode() <= 599;
            $values = ['status' => $rejected ? 'failed' : 'uncertain',
                'error_code' => $rejected ? 'smtp_rejected' : 'transport_uncertain'];
            if ($rejected) {
                $values['failed_at'] = now();
            }
            self::finish($identity, $values);
            return;
        }

        // Un envío cancelado (por ejemplo MessageSending) no prueba aceptación.
        self::finish($identity, $accepted === null
            ? ['status' => 'uncertain', 'error_code' => 'transport_no_receipt']
            : ['status' => 'transport_accepted', 'accepted_at' => now(), 'error_code' => null]);
    }

    private static function finish(array $identity, array $values): void
    {
        OrderCommunicationDelivery::where($identity)->where('status', 'attempting')
            ->update($values + ['updated_at' => now()]);
    }
}
