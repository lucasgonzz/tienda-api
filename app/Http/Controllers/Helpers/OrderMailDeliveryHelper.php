<?php

namespace App\Http\Controllers\Helpers;

use App\Order;
use App\OrderCommunicationDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

class OrderMailDeliveryHelper
{
    /** Tabla de entregas. Esquema de empresa-api (U9): la tienda puede correr días contra un ERP que todavía no la creó. */
    const TABLA = 'order_communication_deliveries';

    /** ¿Existe la tabla de entregas? null = sin medir. Ver hay_tabla_de_entregas(). */
    private static $hay_tabla = null;

    /**
     * ¿Existe la tabla de entregas que crea empresa-api?
     *
     * 🔴 SI VENÍS A SIMPLIFICAR, LEÉ ESTO: la tienda se despliega antes que el release de empresa, así que
     * durante días corre contra una base SIN esta tabla. Sin esta guarda, el insert del registro tiraba un
     * 1146 que se relanzaba y SendOrderEmails dejaba de mandar TODOS los correos (comercio y comprador): una
     * regresión frente a la tienda anterior, que los mandaba sin registro. Con la tabla ausente el envío
     * vuelve a ser el de antes (sin idempotencia, que sin dónde registrar no puede haberla).
     *
     * La ausencia se decide con Schema::hasTable ANTES de tocar la tabla, NO atrapando el 1146: capturar el
     * error en el insert enmascararía cualquier otro 1146 (una tabla distinta, un esquema a medias) y dejaría
     * pasar sin registro envíos que sí deben tener su fila.
     *
     * Memoizado por request (es una consulta al information_schema). En consola NO se memoiza: la suite
     * esconde y restaura la tabla en caliente dentro del mismo proceso, y un worker de cola de larga vida
     * tampoco tiene que seguir mandando sin registro después de que el release de empresa cree la tabla
     * (un "no existe" memoizado reabriría el doble envío). Mismo molde que BuyerTrackingHelper::hayTabla().
     *
     * @return bool
     */
    private static function hay_tabla_de_entregas(): bool
    {
        if (is_null(self::$hay_tabla) || app()->runningInConsole()) {
            self::$hay_tabla = Schema::hasTable(self::TABLA);
        }
        return self::$hay_tabla;
    }

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
        if (!self::hay_tabla_de_entregas()) {
            // Esquema anterior de empresa-api: envío previo, sin registro ni reintento clasificado. Un error
            // del transporte sale tal cual y SendOrderEmails lo aísla por destinatario, como antes.
            $send();
            return;
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
