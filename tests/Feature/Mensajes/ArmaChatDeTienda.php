<?php

namespace Tests\Feature\Mensajes;

use App\Buyer;
use App\Message;
use App\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Pusher\Pusher;

/**
 * Datos y herramientas compartidas por los tests de mensajes de la tienda (mision
 * mensajes-tienda-online, 28/9/2026). Corre contra la base real del slot con DatabaseTransactions:
 * tienda-api no tiene migraciones propias.
 */
trait ArmaChatDeTienda
{
    protected function comercio()
    {
        return User::create([
            'name'     => 'Comercio Mensajes Test',
            'email'    => 'mensajes-'.Str::random(10).'@test.local',
            'password' => bcrypt('secreto'),
            'status'   => 'commerce',
        ]);
    }

    /**
     * @param \App\User|null $comercio  null = comprador sin comercio (buyers.user_id vacio).
     */
    protected function comprador($comercio, array $datos = [])
    {
        return Buyer::create(array_merge([
            'name'    => 'Ana',
            'surname' => 'Pérez',
            'email'   => 'comprador-mensajes-'.Str::random(8).'@test.local',
            'phone'   => '3511234567',
            'user_id' => is_null($comercio) ? null : $comercio->id,
        ], $datos));
    }

    protected function mensaje(Buyer $comprador, array $datos = [])
    {
        return Message::create(array_merge([
            'buyer_id'   => $comprador->id,
            'user_id'    => $comprador->user_id,
            'text'       => 'Mensaje previo',
            'from_buyer' => 1,
            'read'       => 0,
        ], $datos));
    }

    /**
     * Reemplaza el broadcaster por uno que ANOTA lo que saldria por el cable, en vez de mockear el
     * evento. Asi se prueba el camino real completo: el Job, el evento, el BroadcastEvent de
     * Laravel (que es el que llama a broadcastOn/broadcastAs/broadcastWith y formatea los canales)
     * y el despacho por dispatchAfterResponse + terminate.
     *
     * Del payload se saca `socket` igual que hace PusherBroadcaster antes de enviar (Arr::pull):
     * lo que queda es exactamente lo que recibe la SPA.
     *
     * Con $evento_que_falla, ese evento (y solo ese) se anota y despues tira BroadcastException,
     * que es lo que hace PusherBroadcaster cuando Pusher lo rechaza. Sirve para romper un aviso
     * sin romper los demas que salen en el mismo pedido.
     *
     * @param string|null $evento_que_falla
     * @return \ArrayObject  cada elemento: ['canales' => string[], 'evento' => string, 'payload' => array]
     */
    protected function capturarBroadcasts($evento_que_falla = null)
    {
        $capturados = new \ArrayObject();

        Broadcast::extend('captura', function () use ($capturados, $evento_que_falla) {
            return new class($capturados, $evento_que_falla) extends Broadcaster {
                private $capturados;
                private $evento_que_falla;

                public function __construct(\ArrayObject $capturados, $evento_que_falla)
                {
                    $this->capturados       = $capturados;
                    $this->evento_que_falla = $evento_que_falla;
                }

                public function auth($request)
                {
                    return null;
                }

                public function validAuthenticationResponse($request, $result)
                {
                    return null;
                }

                public function broadcast(array $channels, $event, array $payload = [])
                {
                    Arr::pull($payload, 'socket');

                    $this->capturados->append([
                        'canales' => $this->formatChannels($channels),
                        'evento'  => $event,
                        'payload' => $payload,
                    ]);

                    if ($event === $this->evento_que_falla) {
                        throw new BroadcastException('Pusher error: rechazo simulado de '.$event.'.');
                    }
                }
            };
        });

        config([
            'broadcasting.connections.captura' => ['driver' => 'captura'],
            'broadcasting.default'             => 'captura',
        ]);

        return $capturados;
    }

    /**
     * Arma el camino de Pusher REAL hasta el ultimo paso antes de la red: el PusherBroadcaster de
     * Laravel y el SDK pusher-php-server de verdad, con credenciales de mentira y un cliente HTTP
     * de Guzzle con respuestas enlatadas. Lo unico falso es la red.
     *
     * Sirve para medir los bytes que le llegan a Pusher tal cual los codifica el SDK (que es lo que
     * Pusher compara contra su tope de 10.240 bytes), sin reimplementar esa codificacion en el test.
     *
     * @return \ArrayObject  historial de Guzzle: cada elemento trae ['request' => ..., 'response' => ...]
     */
    protected function capturarLoQueLeLlegaAPusher()
    {
        $historial = new \ArrayObject();

        $pila = HandlerStack::create(new MockHandler(array_fill(0, 5, new Response(200, [], '{}'))));
        $pila->push(Middleware::history($historial));

        $pusher = new Pusher(
            'key-de-prueba',
            'secreto-de-prueba',
            'app-de-prueba',
            ['cluster' => 'sa1', 'useTLS' => true],
            new Client(['handler' => $pila])
        );

        Broadcast::extend('pusher-medido', function () use ($pusher) {
            return new PusherBroadcaster($pusher);
        });

        config([
            'broadcasting.connections.pusher-medido' => ['driver' => 'pusher-medido'],
            'broadcasting.default'                   => 'pusher-medido',
        ]);

        return $historial;
    }
}
