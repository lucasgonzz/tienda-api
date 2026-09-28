<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\StringHelper;
use App\Jobs\BroadcastMensajeDelComprador;
use App\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller
{
    /**
     * Largo maximo del texto de un mensaje del comprador. Es el mismo tope que aplica empresa-api
     * a los mensajes que manda el comercio (contrato C3 de la mision mensajes-tienda-online).
     */
    const LARGO_MAXIMO_TEXTO = 5000;

    function index() {
        $messages = Message::where('buyer_id', $this->buyerId())
                            ->with('article.images')
                            ->with('article.colors')
                            ->with('article.sizes')
                            ->with(['article.questions' => function($query) {
                                $query->whereHas('answer')->with('answer');
                            }])
                            ->get();
        return response()->json(['messages' => $messages], 200);
    }

    /**
     * Marca como leidos los mensajes del comercio a este comprador.
     *
     * Un solo UPDATE con el mismo filtro que tenia el loop de save() que habia antes (que hacia
     * un SELECT y despues un UPDATE por cada mensaje). El resultado en la base es el mismo:
     * `read = 1` y `updated_at` al dia en esas filas, y ninguna otra.
     */
    function setRead() {
        Message::where('buyer_id', $this->buyerId())
                ->where('read', 0)
                ->where('from_buyer', 0)
                ->update(['read' => 1]);
        return response(null, 200);
    }

    /**
     * Mensaje del comprador al comercio (contrato C4 de la mision mensajes-tienda-online).
     *
     * 🔴 `user_id` sale del comprador AUTENTICADO (buyers.user_id), nunca del request. El request
     * sigue trayendo `commerce_id` (la tienda-spa lo manda desde siempre y una version vieja lo va a
     * seguir mandando) y se acepta, pero se ignora: antes el navegador decidia a que comercio iba el
     * mensaje, y con eso un comprador podia dejarle mensajes a cualquier comercio de una base
     * compartida.
     *
     * La respuesta no cambia (201 `{ message }`). Lo unico nuevo para el que llama es que un texto
     * vacio o solo con espacios ahora es 422 y no un 500 de la base (`messages.text` es NOT NULL).
     */
    function store(Request $request) {
        $texto = $request->input('text');
        if (is_string($texto)) {
            $texto = trim($texto);
        }

        $validacion = Validator::make(
            ['text' => $texto],
            ['text' => 'required|string|max:'.self::LARGO_MAXIMO_TEXTO],
            [
                'text.required' => 'Escribí un mensaje antes de enviarlo.',
                'text.string'   => 'El mensaje tiene que ser un texto.',
                'text.max'      => 'El mensaje no puede tener más de '.self::LARGO_MAXIMO_TEXTO.' caracteres.',
            ]
        );

        if ($validacion->fails()) {
            return response()->json([
                'message' => $validacion->errors()->first(),
                'errors'  => $validacion->errors(),
            ], 422);
        }

        $buyer = $this->buyer();

        $message = Message::create([
            'buyer_id'      => $buyer->id,
            'user_id'       => $buyer->user_id,
            'from_buyer'    => true,
            'text'          => StringHelper::onlyFirstWordUpperCase($texto),
        ]);

        // Aviso en vivo al sistema de gestion del comercio (evento TiendaChatActualizado, contrato
        // C1). Corre DESPUES de la respuesta: el comprador ve su mensaje enviado al instante y un
        // Pusher lento o caido no le demora ni le rompe nada. La proteccion real vive en el catch
        // de BroadcastMensajeDelComprador::handle(); este try/catch cubre solo el registro del
        // callback, porque el mensaje YA esta guardado a esta altura.
        try {
            BroadcastMensajeDelComprador::dispatchAfterResponse($message->id, $buyer->id, $buyer->user_id);
        } catch (\Throwable $e) {
            Log::error('MessageController@store: fallo el despacho del aviso al sistema de gestion, el mensaje igual se guardo bien', [
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => $message], 201);
    }

}
