<?php

namespace App\Jobs;

use App\Buyer;
use App\Events\TiendaChatActualizado;
use App\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Avisa en vivo al sistema de gestion que un comprador de la tienda mando un mensaje.
 * Mision mensajes-tienda-online (28/9/2026), contrato C1: emite App\Events\TiendaChatActualizado
 * al canal privado `tienda-mensajes.{owner_id}` del DUENIO del comercio.
 *
 * Mismo patron que App\Jobs\BroadcastOrderCreated, y por los mismos motivos:
 *
 * - REGLA DE ORO: este Job NUNCA puede tirar una excepcion hacia arriba. Se despacha con
 *   dispatchAfterResponse(), o sea que corre en Application::terminate() del mismo proceso PHP,
 *   con el mensaje YA guardado. Ningun problema del aviso puede terminar en un error para el
 *   comprador ni cortar los demas callbacks de terminate (que corren en un while sin try/catch).
 * - El catch es de \Throwable y no de \Exception: un Pusher sin credenciales tira TypeError.
 * - El log del catch va en su propio try/catch: el catch no esta cubierto por el try de arriba.
 * - Recibe escalares y no modelos: nada de re-hidratacion de SerializesModels.
 *
 * El payload se arma A MANO (nunca $model->toArray()) y tiene que ser identico, clave por clave y
 * tipo por tipo, al que arma empresa-api para el mismo evento. Ver armarPayload().
 */
class BroadcastMensajeDelComprador implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tope del texto que viaja en el evento. Pusher corta en 10 KB por evento, y un texto de 5000
     * caracteres multibyte (el maximo que acepta store) lo pasaria. Si se recorta, el payload lo
     * avisa con `text_truncado: true` y la SPA va a buscar el mensaje completo por HTTP.
     */
    const LARGO_MAXIMO_TEXTO = 2000;

    /**
     * @var int
     */
    public $message_id;

    /**
     * @var int
     */
    public $buyer_id;

    /**
     * Id del duenio del comercio (buyers.user_id). Define el canal. Puede venir null si el
     * comprador no tiene comercio asignado: en ese caso no hay a quien avisarle.
     *
     * @var int|null
     */
    public $owner_id;

    /**
     * @var int
     */
    public $tries = 1;

    /**
     * @param int      $message_id
     * @param int      $buyer_id
     * @param int|null $owner_id
     */
    public function __construct($message_id, $buyer_id, $owner_id)
    {
        $this->message_id = $message_id;
        $this->buyer_id   = $buyer_id;
        $this->owner_id   = $owner_id;
    }

    /**
     * @return void
     */
    public function handle()
    {
        try {
            if (empty($this->owner_id)) {
                Log::warning('BroadcastMensajeDelComprador: el comprador no tiene comercio (buyers.user_id vacio), no se emite el aviso', [
                    'message_id' => $this->message_id,
                    'buyer_id'   => $this->buyer_id,
                ]);
                return;
            }

            // Se lee el mensaje de la base y no se usa el que devolvio Message::create(): ese solo
            // trae las columnas que se le pasaron, y el contrato necesita tambien `read`, `type`,
            // `article_id` y `order_id` con su valor real.
            $message = Message::find($this->message_id);

            if (is_null($message)) {
                Log::warning('BroadcastMensajeDelComprador: no se encontro el mensaje, no se emite el aviso', [
                    'message_id' => $this->message_id,
                    'buyer_id'   => $this->buyer_id,
                ]);
                return;
            }

            $buyer = Buyer::select('id', 'name', 'surname', 'email', 'phone')->find($this->buyer_id);

            if (is_null($buyer)) {
                Log::warning('BroadcastMensajeDelComprador: no se encontro el comprador, no se emite el aviso', [
                    'message_id' => $this->message_id,
                    'buyer_id'   => $this->buyer_id,
                ]);
                return;
            }

            // Una sola consulta COUNT: usa el indice de messages.buyer_id.
            $unread_count = Message::where('buyer_id', $this->buyer_id)
                                    ->where('from_buyer', 1)
                                    ->where('read', 0)
                                    ->count();

            event(new TiendaChatActualizado(
                $this->owner_id,
                self::armarPayload($message, $buyer, $unread_count)
            ));
        } catch (\Throwable $e) {
            // Mismo criterio que BroadcastOrderCreated: si ni el logger anda, el aviso muere aca
            // adentro y no toca al mensaje ni al resto del terminate.
            try {
                Log::error('BroadcastMensajeDelComprador: fallo el aviso al sistema de gestion, el mensaje igual se guardo bien', [
                    'message_id' => $this->message_id,
                    'buyer_id'   => $this->buyer_id,
                    'owner_id'   => $this->owner_id,
                    'error'      => $e->getMessage(),
                ]);
            } catch (\Throwable $eLog) {
                // Nada mas por hacer.
            }
        }
    }

    /**
     * Payload del contrato C1, clave por clave y tipo por tipo:
     *
     * - ids: int (los nullable de la base, int o null).
     * - `from_buyer`, `read`, `text_truncado`: bool. La base los devuelve como 0/1 y la SPA los
     *   compara como booleanos: por eso el cast explicito.
     * - fechas: `->toJSON()` de Carbon, la misma forma que la serializacion estandar de los
     *   modelos ("2026-09-28T14:05:11.000000Z").
     * - `text`: recortado a LARGO_MAXIMO_TEXTO caracteres con mb_substr (nunca substr: cortaria
     *   un caracter multibyte por la mitad y el json_encode del broadcaster fallaria).
     * - `buyer`: siempre que haya `message`, para que la SPA pueda armar la fila si ese comprador
     *   no esta en la bandeja que tiene cargada.
     *
     * @param \App\Message $message
     * @param \App\Buyer   $buyer
     * @param int          $unread_count
     * @return array
     */
    public static function armarPayload(Message $message, Buyer $buyer, $unread_count)
    {
        $texto          = (string) $message->text;
        $texto_truncado = mb_strlen($texto) > self::LARGO_MAXIMO_TEXTO;

        if ($texto_truncado) {
            $texto = mb_substr($texto, 0, self::LARGO_MAXIMO_TEXTO);
        }

        $creado = is_null($message->created_at) ? null : $message->created_at->toJSON();

        return [
            'buyer_id' => (int) $message->buyer_id,
            'chat'     => [
                'buyer_id'        => (int) $message->buyer_id,
                'unread_count'    => (int) $unread_count,
                'last_message_at' => $creado,
            ],
            'buyer'    => [
                'id'      => (int) $buyer->id,
                'name'    => self::textoONull($buyer->name),
                'surname' => self::textoONull($buyer->surname),
                'email'   => self::textoONull($buyer->email),
                'phone'   => self::textoONull($buyer->phone),
            ],
            'message'  => [
                'id'            => (int) $message->id,
                'buyer_id'      => (int) $message->buyer_id,
                'user_id'       => self::enteroONull($message->user_id),
                'text'          => $texto,
                'text_truncado' => $texto_truncado,
                'type'          => self::textoONull($message->type),
                'from_buyer'    => (bool) $message->from_buyer,
                'read'          => (bool) $message->read,
                'article_id'    => self::enteroONull($message->article_id),
                'order_id'      => self::enteroONull($message->order_id),
                'created_at'    => $creado,
            ],
        ];
    }

    /**
     * @param mixed $valor
     * @return int|null
     */
    private static function enteroONull($valor)
    {
        return is_null($valor) ? null : (int) $valor;
    }

    /**
     * @param mixed $valor
     * @return string|null
     */
    private static function textoONull($valor)
    {
        return is_null($valor) ? null : (string) $valor;
    }
}
