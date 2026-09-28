<?php

namespace Tests\Feature\Mensajes;

use App\Jobs\BroadcastMensajeDelComprador;
use App\Message;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * `POST /api/messages`: mensaje del comprador al comercio, y el aviso en vivo al sistema de
 * gestion (evento TiendaChatActualizado, contrato C1 de la mision mensajes-tienda-online).
 *
 * El aviso se despacha con dispatchAfterResponse(), y el cliente HTTP de los tests llama al
 * terminate() del kernel despues de cada pedido: o sea que en estos tests el Job corre DE VERDAD,
 * en el mismo orden que en produccion (primero la respuesta, despues el aviso).
 *
 * ⚠️ Un solo POST por test, a proposito: Application::terminate() no vacia la lista de callbacks,
 * asi que un segundo pedido en el mismo test volveria a correr el aviso del primero y los conteos
 * de eventos darian de mas.
 */
class EnviarMensajeDelCompradorTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaChatDeTienda;

    const RUTA = '/api/messages';

    public function test_sin_sesion_devuelve_401()
    {
        $this->postJson(self::RUTA, ['text' => 'hola'])->assertStatus(401);
    }

    /**
     * El corazon del cambio de C4: el canal lo decide el comprador autenticado, no el
     * `commerce_id` que manda el navegador.
     */
    public function test_el_evento_sale_al_canal_del_duenio_del_comprador_aunque_commerce_id_diga_otro()
    {
        $capturados = $this->capturarBroadcasts();

        $duenio    = $this->comercio();
        $otro      = $this->comercio();
        $comprador = $this->comprador($duenio);

        $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => 'hola', 'commerce_id' => $otro->id])
            ->assertStatus(201);

        $this->assertCount(1, $capturados, 'tiene que salir un solo evento');
        $this->assertSame(['private-tienda-mensajes.'.$duenio->id], $capturados[0]['canales']);
        $this->assertSame('TiendaChatActualizado', $capturados[0]['evento']);

        foreach ($capturados as $capturado) {
            $this->assertNotContains('private-tienda-mensajes.'.$otro->id, $capturado['canales'], 'nada puede salir al canal del commerce_id del request');
        }
    }

    public function test_guarda_el_user_id_del_comprador_y_no_el_commerce_id_del_request()
    {
        $this->capturarBroadcasts();

        $duenio    = $this->comercio();
        $otro      = $this->comercio();
        $comprador = $this->comprador($duenio);

        $respuesta = $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => 'hola', 'commerce_id' => $otro->id])
            ->assertStatus(201);

        $fila = Message::find($respuesta->json('message.id'));

        $this->assertNotNull($fila);
        $this->assertSame((int) $duenio->id, (int) $fila->user_id);
        $this->assertSame((int) $comprador->id, (int) $fila->buyer_id);
        $this->assertSame(1, (int) $fila->from_buyer);
        $this->assertSame(0, (int) $fila->read);
    }

    /**
     * Compatibilidad (C4): la respuesta es la misma de siempre, 201 `{ message }` con el modelo tal
     * como lo devuelve Message::create(). Sin `commerce_id` tambien anda (la tienda-spa nueva puede
     * dejar de mandarlo).
     */
    public function test_la_respuesta_sigue_siendo_201_con_el_mensaje_creado()
    {
        $this->capturarBroadcasts();

        $duenio    = $this->comercio();
        $comprador = $this->comprador($duenio);

        $respuesta = $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => '  hola, ¿tienen talle 42?  '])
            ->assertStatus(201);

        $mensaje = $respuesta->json('message');

        $this->assertSame(['message'], array_keys($respuesta->json()));
        $this->assertEqualsCanonicalizing(
            ['buyer_id', 'user_id', 'from_buyer', 'text', 'updated_at', 'created_at', 'id'],
            array_keys($mensaje)
        );
        $this->assertSame((int) $comprador->id, $mensaje['buyer_id']);
        $this->assertSame((int) $duenio->id, $mensaje['user_id']);
        $this->assertTrue($mensaje['from_buyer']);
        // El texto se recorta y se guarda "como hoy" (StringHelper::onlyFirstWordUpperCase).
        $this->assertSame('Hola, ¿tienen talle 42?', $mensaje['text']);
    }

    /**
     * El contrato C1 al pie de la letra: mismas claves, en el mismo orden, con los mismos tipos.
     * assertSame sobre el array entero: un '123' donde va 123, o un 1 donde va true, lo pone rojo.
     */
    public function test_el_payload_tiene_exactamente_las_claves_y_los_tipos_del_contrato()
    {
        $capturados = $this->capturarBroadcasts();

        $duenio    = $this->comercio();
        $comprador = $this->comprador($duenio, ['name' => 'Ana', 'surname' => 'Pérez', 'phone' => '1155554444']);

        $respuesta = $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => 'hola, ¿tienen talle 42?', 'commerce_id' => $duenio->id])
            ->assertStatus(201);

        $fila   = Message::find($respuesta->json('message.id'));
        $creado = $fila->created_at->toJSON();

        $this->assertCount(1, $capturados);

        $this->assertSame([
            'buyer_id' => (int) $comprador->id,
            'chat'     => [
                'buyer_id'        => (int) $comprador->id,
                'unread_count'    => 1,
                'last_message_at' => $creado,
            ],
            'buyer'    => [
                'id'      => (int) $comprador->id,
                'name'    => 'Ana',
                'surname' => 'Pérez',
                'email'   => $comprador->email,
                'phone'   => '1155554444',
            ],
            'message'  => [
                'id'            => (int) $fila->id,
                'buyer_id'      => (int) $comprador->id,
                'user_id'       => (int) $duenio->id,
                'text'          => 'Hola, ¿tienen talle 42?',
                'text_truncado' => false,
                'type'          => null,
                'from_buyer'    => true,
                'read'          => false,
                'article_id'    => null,
                'order_id'      => null,
                'created_at'    => $creado,
            ],
        ], $capturados[0]['payload']);

        // La forma de la fecha es la de la serializacion estandar de los modelos.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $creado);
    }

    /**
     * `chat.unread_count` = mensajes del comprador sin leer (from_buyer=1 AND read=0) de ESE
     * comprador, contando el que se acaba de mandar.
     */
    public function test_unread_count_cuenta_solo_los_no_leidos_del_comprador()
    {
        $capturados = $this->capturarBroadcasts();

        $duenio    = $this->comercio();
        $comprador = $this->comprador($duenio);
        $vecino    = $this->comprador($duenio);

        $this->mensaje($comprador, ['from_buyer' => 1, 'read' => 0]);
        $this->mensaje($comprador, ['from_buyer' => 1, 'read' => 0]);
        $this->mensaje($comprador, ['from_buyer' => 1, 'read' => 1]);
        $this->mensaje($comprador, ['from_buyer' => 0, 'read' => 0]);
        $this->mensaje($vecino, ['from_buyer' => 1, 'read' => 0]);

        $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => 'otro mas'])
            ->assertStatus(201);

        $this->assertCount(1, $capturados);
        $this->assertSame(3, $capturados[0]['payload']['chat']['unread_count']);
    }

    /**
     * Pusher corta en 10 KB por evento: el texto viaja recortado a 2000 caracteres (no bytes) y lo
     * avisa. En la base queda completo. Con caracteres multibyte, para que un substr por bytes se
     * note (partiria una ñ y el json_encode del broadcaster fallaria).
     */
    public function test_un_texto_largo_viaja_recortado_a_2000_caracteres_y_avisa()
    {
        $capturados = $this->capturarBroadcasts();

        $duenio    = $this->comercio();
        $comprador = $this->comprador($duenio);
        $texto     = str_repeat('ñ', 2500);

        $respuesta = $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => $texto])
            ->assertStatus(201);

        $this->assertSame($texto, Message::find($respuesta->json('message.id'))->text, 'en la base el texto queda entero');

        $this->assertCount(1, $capturados);
        $mensaje = $capturados[0]['payload']['message'];
        $this->assertSame(str_repeat('ñ', 2000), $mensaje['text']);
        $this->assertTrue($mensaje['text_truncado']);
        $this->assertNotFalse(json_encode($capturados[0]['payload']), 'el payload tiene que poder viajar como JSON');
    }

    public function test_un_texto_de_2000_caracteres_viaja_entero()
    {
        $capturados = $this->capturarBroadcasts();

        $comprador = $this->comprador($this->comercio());

        $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => str_repeat('a', 2000)])
            ->assertStatus(201);

        $this->assertCount(1, $capturados);
        $this->assertSame(2000, mb_strlen($capturados[0]['payload']['message']['text']));
        $this->assertFalse($capturados[0]['payload']['message']['text_truncado']);
    }

    /**
     * @dataProvider textosVacios
     */
    public function test_un_texto_vacio_devuelve_422_y_no_guarda_ni_avisa($cuerpo)
    {
        $capturados = $this->capturarBroadcasts();

        $comprador = $this->comprador($this->comercio());

        $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, $cuerpo)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['text']);

        $this->assertSame(0, Message::where('buyer_id', $comprador->id)->count());
        $this->assertCount(0, $capturados);
    }

    public static function textosVacios()
    {
        return [
            'vacio'              => [['text' => '']],
            'solo espacios'      => [['text' => '    ']],
            'espacios y saltos'  => [['text' => " \n\t  \r\n "]],
            'sin la clave text'  => [['commerce_id' => 1]],
            'text null'          => [['text' => null]],
        ];
    }

    public function test_mas_de_5000_caracteres_devuelve_422()
    {
        $comprador = $this->comprador($this->comercio());

        $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => str_repeat('a', 5001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['text']);

        $this->assertSame(0, Message::where('buyer_id', $comprador->id)->count());
    }

    public function test_5000_caracteres_justos_se_aceptan()
    {
        $this->capturarBroadcasts();

        $comprador = $this->comprador($this->comercio());

        $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => str_repeat('a', 5000)])
            ->assertStatus(201);

        $this->assertSame(1, Message::where('buyer_id', $comprador->id)->count());
    }

    /**
     * El aviso se despacha DESPUES de la respuesta y con escalares (nada de modelos serializados).
     */
    public function test_el_aviso_se_despacha_despues_de_la_respuesta_con_escalares()
    {
        Bus::fake([BroadcastMensajeDelComprador::class]);

        $duenio    = $this->comercio();
        $otro      = $this->comercio();
        $comprador = $this->comprador($duenio);

        $respuesta = $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => 'hola', 'commerce_id' => $otro->id])
            ->assertStatus(201);

        Bus::assertDispatchedAfterResponse(BroadcastMensajeDelComprador::class, function ($job) use ($respuesta, $comprador, $duenio) {
            return (int) $job->message_id === (int) $respuesta->json('message.id')
                && (int) $job->buyer_id === (int) $comprador->id
                && (int) $job->owner_id === (int) $duenio->id;
        });
    }

    /**
     * Pusher configurado y sin credenciales (lo que pasa si el .env de produccion no las tiene):
     * tira TypeError, no Exception. El comprador igual recibe su 201 y el mensaje queda guardado.
     */
    public function test_si_pusher_tira_igual_devuelve_201_y_el_mensaje_queda_guardado()
    {
        config([
            'broadcasting.default'                   => 'pusher',
            'broadcasting.connections.pusher.key'    => null,
            'broadcasting.connections.pusher.secret' => null,
            'broadcasting.connections.pusher.app_id' => null,
        ]);

        Log::spy();

        $comprador = $this->comprador($this->comercio());

        $respuesta = $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => 'hola igual'])
            ->assertStatus(201);

        $this->assertNotNull(Message::find($respuesta->json('message.id')), 'el mensaje tiene que quedar en la base');

        // Y la falla quedo registrada: sin esto el test daria verde aunque el aviso nunca se
        // hubiera intentado (por ejemplo, si el Job no se despachara).
        Log::shouldHaveReceived('error')
            ->withArgs(function ($mensaje, $contexto = []) {
                return strpos($mensaje, 'BroadcastMensajeDelComprador: fallo el aviso') !== false;
            })
            ->once();
    }

    public function test_con_un_driver_de_broadcast_inexistente_tampoco_se_rompe()
    {
        config(['broadcasting.default' => 'driver-que-no-existe']);

        $comprador = $this->comprador($this->comercio());

        $respuesta = $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => 'hola igual'])
            ->assertStatus(201);

        $this->assertNotNull(Message::find($respuesta->json('message.id')));
    }

    /**
     * Con el driver del entorno de testing (`log`), que es el que escribe en storage/logs el
     * evento tal como lo arma BroadcastEvent de Laravel: el camino por defecto tampoco se rompe.
     */
    public function test_con_el_driver_del_entorno_guarda_y_responde_201()
    {
        $comprador = $this->comprador($this->comercio());

        $respuesta = $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => 'hola, ¿tienen talle 42?'])
            ->assertStatus(201);

        $this->assertNotNull(Message::find($respuesta->json('message.id')));
    }

    /**
     * Un comprador sin comercio (buyers.user_id vacio) no tiene a quien avisarle: el mensaje se
     * guarda igual (con user_id vacio, que es lo que dice su ficha) y no sale ningun evento.
     */
    public function test_comprador_sin_comercio_guarda_y_no_emite()
    {
        $capturados = $this->capturarBroadcasts();

        $comprador = $this->comprador(null);
        $otro      = $this->comercio();

        $respuesta = $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['text' => 'hola', 'commerce_id' => $otro->id])
            ->assertStatus(201);

        $this->assertNull(Message::find($respuesta->json('message.id'))->user_id);
        $this->assertCount(0, $capturados);
    }

    /**
     * El catch del Job tiene su propio try para el logger: esto corre en Application::terminate(),
     * que no tiene ningun try/catch por encima.
     */
    public function test_ni_un_logger_roto_hace_escapar_nada_del_job()
    {
        $comprador = $this->comprador($this->comercio());
        $mensaje   = $this->mensaje($comprador);

        config(['broadcasting.default' => 'driver-que-no-existe']);

        $loggerRoto = new class {
            public $invocaciones = [];

            public function __call($metodo, $argumentos)
            {
                $this->invocaciones[] = $metodo;

                throw new \RuntimeException('el logger tambien esta roto');
            }
        };

        $loggerOriginal = Log::getFacadeRoot();
        Log::swap($loggerRoto);

        try {
            (new BroadcastMensajeDelComprador($mensaje->id, $comprador->id, $comprador->user_id))->handle();
        } finally {
            Log::swap($loggerOriginal);
        }

        $this->assertContains('error', $loggerRoto->invocaciones, 'el catch tiene que haber intentado loguear');
    }
}
