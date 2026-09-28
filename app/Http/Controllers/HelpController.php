<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\StringHelper;
use App\Jobs\BroadcastMensajeDelComprador;
use App\Message;
use App\Notifications\MessageSend;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HelpController extends Controller
{
    /**
     * Mensaje de "Ayuda → Escribinos" (type = 'help').
     *
     * Con comprador AUTENTICADO (mision mensajes-tienda-online, mismo criterio que
     * MessageController@store): `user_id` sale del comprador (buyers.user_id) y no del
     * `commerce_id` que manda el navegador, y despues de guardar se avisa en vivo al sistema de
     * gestion con TiendaChatActualizado (contrato C1).
     *
     * ⚠️ El camino del INVITADO queda exactamente como estaba, a proposito: sin comprador,
     * `buyer_id` va null y el insert revienta (messages.buyer_id es NOT NULL). Arreglarlo es una
     * decision de producto pendiente, anotada en routes/api.php junto a esta ruta.
     */
    function message(Request $request) {
        $buyer = $this->buyer();

        $message = Message::create([
            'buyer_id'      => $this->buyerId(),
            'user_id'       => is_null($buyer) ? $request->commerce_id : $buyer->user_id,
            'from_buyer'    => true,
            'type'          => 'help',
            'text'          => StringHelper::onlyFirstWordUpperCase($request->message),
        ]);

        // Aviso en vivo al sistema de gestion, despues de la respuesta. Se registra ACA, antes del
        // notify de abajo, a proposito: ese notify corre en el mismo request (MessageSend es
        // ShouldQueue pero la cola es sync) y si tira, lo que sigue ya no se ejecuta. Registrado
        // antes, el aviso sale igual aunque el notify viejo falle, porque el terminate del kernel
        // corre tambien despues de una respuesta de error.
        if (!is_null($buyer)) {
            try {
                BroadcastMensajeDelComprador::dispatchAfterResponse($message->id, $buyer->id, $buyer->user_id);
            } catch (\Throwable $e) {
                Log::error('HelpController@message: fallo el despacho del aviso al sistema de gestion, el mensaje igual se guardo bien', [
                    'message_id' => $message->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $commerce = User::find($request->commerce_id);
        $commerce->notify(new MessageSend($message));
        return response()->json(['message' => $message], 201);
    }
}
