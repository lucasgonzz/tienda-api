<?php

namespace Tests\Feature\Broadcast;

use App\Broadcasting\EspacioDeCanales;
use App\Broadcasting\PusherBroadcasterConEspacio;
use App\Buyer;
use App\Events\TiendaChatActualizado;
use App\Notifications\AddedModel;
use App\Notifications\OrderCreated;
use App\Order;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pusher\Pusher;
use Pusher\PusherException;
use Tests\TestCase;

/**
 * Canales en vivo por sistema (misión pusher-canales-por-sistema, 19/10/2026).
 *
 * Toda la flota usa UNA app de Pusher y los canales llevaban solo un id: demo3 y Ferretotal (los
 * dos con dueño 2600) se escuchaban entre sí. Desde esta misión cada canal sale con el sufijo
 * `@espacio` propio del sistema (App\Broadcasting\EspacioDeCanales), puesto en un solo punto: el
 * broadcaster (App\Broadcasting\PusherBroadcasterConEspacio).
 *
 * Qué fija este archivo:
 *  - los tres vectores del contrato, con los MISMOS literales que afirma empresa-api (PHP 7.4): si
 *    los dos repos no dan el mismo string, el ERP y la tienda del mismo cliente dejan de oírse;
 *  - que el espacio sale de la base, el entorno y el secret (y el override saneado);
 *  - la emisión REAL por el broadcaster registrado, con un doble de Pusher\Pusher que captura los
 *    canales del trigger y los pasa por la validación de nombres del propio SDK (sin red):
 *    transición = lógico + físico, estricto = solo físico;
 *  - que GET /api/user del comprador logueado trae `canales_en_vivo`;
 *  - el comando `canales:espacio`, sin el secret.
 *
 * DatabaseTransactions contra la base del slot (tienda-api no tiene migraciones propias): el
 * comercio y el comprador se crean adentro de la transacción y se deshacen al terminar.
 */
class CanalesPorSistemaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Secret de Pusher FALSO de todos los tests: es el mismo de los vectores del contrato.
     *
     * @var string
     */
    const SECRETO = 'secreto-de-prueba';

    /**
     * Key de Pusher falsa. Tiene un nombre que no puede aparecer por casualidad en una salida, para
     * afirmar que el comando no la imprime.
     *
     * @var string
     */
    const KEY_FALSA = 'key-falsa-que-no-se-imprime';

    /**
     * Cada test arranca con el secret falso, sin override y en transición (el default), sin
     * importar lo que traiga el .env.testing del slot.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.connections.pusher.secret' => self::SECRETO,
            'broadcasting.espacio'                   => null,
            'broadcasting.canales_viejos_de_tienda'  => true,
        ]);
    }

    /**
     * 🔴 Los tres vectores del contrato entre empresa-api y tienda-api. Los mismos literales están
     * en el test gemelo de empresa-api: NO se recalculan acá, se copian. Si este test se pone en
     * rojo, el algoritmo cambió de un lado y no del otro.
     *
     * @return void
     */
    public function test_los_tres_vectores_del_contrato()
    {
        $this->assertSame('26d5013a6846', EspacioDeCanales::calcular('u767360347_ferretotal', 'production', 'secreto-de-prueba'));
        $this->assertSame('b4b66b323190', EspacioDeCanales::calcular('demo3', 'production', 'secreto-de-prueba'));

        // Clave vacía (o null) → la constante de respaldo 'comerciocity-canales'.
        $this->assertSame('4734efc431ff', EspacioDeCanales::calcular('demo3', 'production', ''));
        $this->assertSame('4734efc431ff', EspacioDeCanales::calcular('demo3', 'production', null));
    }

    /**
     * Dos bases distintas dan espacios distintos (es lo que separa a demo3 de Ferretotal), y la
     * misma base en otro entorno también (la copia local de una base real no se cruza con la de
     * producción, aunque se llamen igual).
     *
     * @return void
     */
    public function test_dos_bases_distintas_dan_espacios_distintos()
    {
        $ferretotal = EspacioDeCanales::calcular('u767360347_ferretotal', 'production', self::SECRETO);
        $demo3 = EspacioDeCanales::calcular('demo3', 'production', self::SECRETO);
        $ferretotal_local = EspacioDeCanales::calcular('u767360347_ferretotal', 'local', self::SECRETO);

        $this->assertNotSame($ferretotal, $demo3);
        $this->assertNotSame($ferretotal, $ferretotal_local);

        foreach ([$ferretotal, $demo3, $ferretotal_local] as $espacio) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{12}\z/', $espacio);
        }

        // Y por espacio(), que es lo que usa el broadcaster: cambia con la base de la conexión.
        $conexion = DB::connection();
        $base_original = $conexion->getDatabaseName();

        try {
            $conexion->setDatabaseName('base_a');
            $espacio_a = EspacioDeCanales::espacio();

            $conexion->setDatabaseName('base_b');
            $espacio_b = EspacioDeCanales::espacio();
        } finally {
            $conexion->setDatabaseName($base_original);
        }

        $this->assertNotSame($espacio_a, $espacio_b, 'dos bases distintas no pueden compartir canales');
    }

    /**
     * El espacio del proceso es el del vector cuando la base, el entorno y el secret son los del
     * vector: ata espacio() al algoritmo del contrato (y no solo calcular()).
     *
     * @return void
     */
    public function test_el_espacio_del_proceso_sale_de_la_base_el_entorno_y_el_secret()
    {
        $conexion = DB::connection();
        $base_original = $conexion->getDatabaseName();

        try {
            config(['app.env' => 'production']);

            $conexion->setDatabaseName('demo3');
            $this->assertSame('b4b66b323190', EspacioDeCanales::espacio());

            // Sin secret: la constante de respaldo.
            config(['broadcasting.connections.pusher.secret' => '']);
            $this->assertSame('4734efc431ff', EspacioDeCanales::espacio());
            config(['broadcasting.connections.pusher.secret' => null]);
            $this->assertSame('4734efc431ff', EspacioDeCanales::espacio());

            $conexion->setDatabaseName('u767360347_ferretotal');
            config(['broadcasting.connections.pusher.secret' => self::SECRETO]);
            $this->assertSame('26d5013a6846', EspacioDeCanales::espacio());
        } finally {
            $conexion->setDatabaseName($base_original);
        }
    }

    /**
     * El override `broadcasting.espacio` gana sobre el calculado, saneado a minúscula, [a-z0-9] y
     * 32 caracteres. Si no sobrevive nada al saneo, se usa el calculado.
     *
     * @return void
     */
    public function test_el_override_gana_saneado()
    {
        config(['broadcasting.espacio' => 'Demo3-Prueba_01!']);
        $this->assertSame('demo3prueba01', EspacioDeCanales::espacio());
        $this->assertSame('order.created.5@demo3prueba01', EspacioDeCanales::con_espacio('order.created.5'));

        config(['broadcasting.espacio' => str_repeat('a', 40)]);
        $this->assertSame(str_repeat('a', 32), EspacioDeCanales::espacio());

        config(['broadcasting.espacio' => '!!!']);
        $this->assertSame($this->espacio_esperado(), EspacioDeCanales::espacio());

        config(['broadcasting.espacio' => '']);
        $this->assertSame($this->espacio_esperado(), EspacioDeCanales::espacio());

        // `BROADCAST_ESPACIO=true` llega como bool de env(): no es un espacio, como en empresa-api.
        config(['broadcasting.espacio' => true]);
        $this->assertSame($this->espacio_esperado(), EspacioDeCanales::espacio());

        // Un número sí cuenta (es texto en el .env), igual que allá.
        config(['broadcasting.espacio' => 123]);
        $this->assertSame('123', EspacioDeCanales::espacio());
    }

    /**
     * 🔴 La trampa del `.env` a medio editar, con la MISMA tabla que empresa-api:
     * `BROADCAST_CANALES_VIEJOS_DE_TIENDA=` (vacío), `null` escrito o un valor mal tipeado NO pueden
     * pasar a estricto en silencio; solo un falso explícito lo hace. Se lee el archivo de config de
     * verdad con cada valor en `$_SERVER`, que es de donde lo toma `env()`.
     *
     * @return void
     */
    public function test_el_modo_de_la_config_solo_pasa_a_estricto_con_un_falso_explicito()
    {
        /** Valor del .env => lo que tiene que quedar en la config. */
        $casos = [
            ''       => true,
            'null'   => true,
            '(null)' => true,
            'true'   => true,
            '1'      => true,
            'flase'  => true,
            'false'  => false,
            '0'      => false,
            'off'    => false,
            'no'     => false,
        ];

        /** Lo que había antes, para dejarlo como estaba. */
        $habia = array_key_exists('BROADCAST_CANALES_VIEJOS_DE_TIENDA', $_SERVER)
            ? $_SERVER['BROADCAST_CANALES_VIEJOS_DE_TIENDA']
            : null;

        try {
            foreach ($casos as $valor => $esperado) {
                $_SERVER['BROADCAST_CANALES_VIEJOS_DE_TIENDA'] = (string) $valor;

                $config = require base_path('config/broadcasting.php');

                $this->assertSame($esperado, $config['canales_viejos_de_tienda'], 'Con BROADCAST_CANALES_VIEJOS_DE_TIENDA=' . $valor);
            }

            unset($_SERVER['BROADCAST_CANALES_VIEJOS_DE_TIENDA']);

            $config = require base_path('config/broadcasting.php');

            $this->assertTrue($config['canales_viejos_de_tienda'], 'Sin la variable, el default es la transición.');
        } finally {
            if ($habia === null) {
                unset($_SERVER['BROADCAST_CANALES_VIEJOS_DE_TIENDA']);
            } else {
                $_SERVER['BROADCAST_CANALES_VIEJOS_DE_TIENDA'] = $habia;
            }
        }
    }

    /**
     * El sufijo va al final (el `private-` de Pusher queda adelante) y nunca se pone dos veces.
     * Transición: [lógico, físico]; estricto: [físico].
     *
     * @return void
     */
    public function test_el_nombre_fisico_y_los_nombres_para_emitir()
    {
        $espacio = $this->espacio_esperado();

        $this->assertSame('order.created.5@' . $espacio, EspacioDeCanales::con_espacio('order.created.5'));
        $this->assertSame('private-tienda-mensajes.5@' . $espacio, EspacioDeCanales::con_espacio('private-tienda-mensajes.5'));
        $this->assertSame('order.created.5@' . $espacio, EspacioDeCanales::con_espacio('order.created.5@' . $espacio),
            'un nombre que ya es físico no puede quedar con el sufijo dos veces');
        $this->assertSame('', EspacioDeCanales::con_espacio(''), 'sin nombre no se fabrica un @espacio suelto (como empresa-api)');

        $this->assertSame(
            ['message.from_commerce.9', 'message.from_commerce.9@' . $espacio],
            EspacioDeCanales::nombres_para_emitir('message.from_commerce.9')
        );
        $this->assertSame(
            ['message.from_commerce.9@' . $espacio],
            EspacioDeCanales::nombres_para_emitir('message.from_commerce.9@' . $espacio)
        );

        config(['broadcasting.canales_viejos_de_tienda' => false]);
        $this->assertSame(
            ['message.from_commerce.9@' . $espacio],
            EspacioDeCanales::nombres_para_emitir('message.from_commerce.9')
        );

        // El modo se lee con filter_var: un 'false' de texto también es estricto.
        config(['broadcasting.canales_viejos_de_tienda' => 'false']);
        $this->assertFalse(EspacioDeCanales::canales_viejos_de_tienda());
        config(['broadcasting.canales_viejos_de_tienda' => 'true']);
        $this->assertTrue(EspacioDeCanales::canales_viejos_de_tienda());
    }

    /**
     * El driver `pusher` registrado es el del espacio (BroadcastServiceProvider::boot).
     *
     * @return void
     */
    public function test_el_driver_pusher_es_el_del_espacio()
    {
        $this->armar_pusher_de_prueba();

        $this->assertInstanceOf(PusherBroadcasterConEspacio::class, Broadcast::driver('pusher'));
    }

    /**
     * Red de seguridad del doble: la validación de nombres del SDK corre de verdad. Sin esto, un
     * doble que no validara nada dejaría pasar un nombre que Pusher rechaza en producción.
     *
     * @return void
     */
    public function test_el_doble_valida_los_nombres_como_el_sdk()
    {
        $pusher = $this->doble_de_pusher();

        $this->expectException(PusherException::class);

        $pusher->trigger(['un canal con espacios'], 'evento', []);
    }

    /**
     * 🔴 El pedido nuevo (`order.created.{owner}`, lo escucha empresa-spa) sale en transición por
     * el lógico (un ERP viejo) Y por el físico (un ERP nuevo en estricto), en un solo trigger.
     *
     * @return void
     */
    public function test_order_created_sale_por_el_logico_y_por_el_fisico_en_transicion()
    {
        $pusher = $this->armar_pusher_de_prueba();
        $comercio = $this->comercio();

        $comercio->notify(new OrderCreated($this->pedido_en_memoria($comercio->id)));

        $this->assertSame(
            [['order.created.' . $comercio->id, 'order.created.' . $comercio->id . '@' . $this->espacio_esperado()]],
            $this->canales_de_cada_trigger($pusher)
        );
    }

    /**
     * 🔴 En estricto, SOLO por el físico: el canal sin espacio, el que oye cualquier sistema con el
     * mismo dueño, deja de existir.
     *
     * @return void
     */
    public function test_order_created_sale_solo_por_el_fisico_en_estricto()
    {
        config(['broadcasting.canales_viejos_de_tienda' => false]);

        $pusher = $this->armar_pusher_de_prueba();
        $comercio = $this->comercio();

        $comercio->notify(new OrderCreated($this->pedido_en_memoria($comercio->id)));

        $this->assertSame(
            [['order.created.' . $comercio->id . '@' . $this->espacio_esperado()]],
            $this->canales_de_cada_trigger($pusher)
        );
    }

    /**
     * El chat de la tienda (`private-tienda-mensajes.{owner}`, lo autoriza y lo escucha el ERP):
     * el sufijo va DESPUÉS del `private-`, y en transición sale también el lógico.
     *
     * @return void
     */
    public function test_tienda_chat_actualizado_sale_por_el_privado_con_espacio()
    {
        $pusher = $this->armar_pusher_de_prueba();
        $espacio = $this->espacio_esperado();

        event(new TiendaChatActualizado(2600, ['buyer_id' => 9]));

        config(['broadcasting.canales_viejos_de_tienda' => false]);

        event(new TiendaChatActualizado(2600, ['buyer_id' => 9]));

        $this->assertSame(
            [
                // Transición.
                ['private-tienda-mensajes.2600', 'private-tienda-mensajes.2600@' . $espacio],
                // Estricto.
                ['private-tienda-mensajes.2600@' . $espacio],
            ],
            $this->canales_de_cada_trigger($pusher)
        );

        $this->assertSame('TiendaChatActualizado', $pusher->envios[0]['evento'], 'el nombre del evento no cambia');
    }

    /**
     * Una notificación cuyo broadcastOn() devuelve un string y no un Channel (AddedModel:
     * `added_model.{owner}`, lo escucha el ERP) pasa por el mismo camino.
     *
     * @return void
     */
    public function test_added_model_con_canal_de_texto_tambien_lleva_el_espacio()
    {
        $pusher = $this->armar_pusher_de_prueba();
        $comercio = $this->comercio();

        $comercio->notify(new AddedModel('order', 77, false, $comercio->id));

        $this->assertSame(
            [['added_model.' . $comercio->id, 'added_model.' . $comercio->id . '@' . $this->espacio_esperado()]],
            $this->canales_de_cada_trigger($pusher)
        );
    }

    /**
     * 🔴 El contrato API → SPA: GET /api/user del comprador logueado trae `canales_en_vivo` con las
     * dos claves que lee tienda-spa (src/helpers/canales_en_vivo.js), con esos nombres exactos.
     *
     * @return void
     */
    public function test_get_api_user_trae_canales_en_vivo()
    {
        $comprador = $this->comprador($this->comercio());

        $respuesta = $this->actingAs($comprador, 'buyer')->getJson('/api/user');

        $respuesta->assertStatus(200);
        $this->assertSame(
            ['espacio' => $this->espacio_esperado(), 'canales_viejos_de_tienda' => true],
            $respuesta->json('buyer.canales_en_vivo')
        );
    }

    /**
     * En estricto, el SPA se entera por el mismo atributo.
     *
     * @return void
     */
    public function test_get_api_user_avisa_el_modo_estricto()
    {
        config(['broadcasting.canales_viejos_de_tienda' => false]);

        $comprador = $this->comprador($this->comercio());

        $this->actingAs($comprador, 'buyer')
            ->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonPath('buyer.canales_en_vivo.espacio', $this->espacio_esperado())
            ->assertJsonPath('buyer.canales_en_vivo.canales_viejos_de_tienda', false);
    }

    /**
     * `php artisan canales:espacio`: una línea JSON con base, entorno, espacio, modo y driver, en
     * ese orden, y NUNCA el secret ni la key de Pusher.
     *
     * @return void
     */
    public function test_el_comando_muestra_el_espacio_y_no_el_secret()
    {
        config([
            'broadcasting.default'                => 'pusher',
            'broadcasting.connections.pusher.key' => self::KEY_FALSA,
        ]);

        $codigo = Artisan::call('canales:espacio');
        $salida = trim(Artisan::output());

        $this->assertSame(0, $codigo);
        $this->assertStringNotContainsString("\n", $salida, 'tiene que ser una sola línea');
        $this->assertStringNotContainsString(self::SECRETO, $salida, 'el comando no puede imprimir el secret');
        $this->assertStringNotContainsString(self::KEY_FALSA, $salida, 'ni la key');

        $datos = json_decode($salida, true);

        $this->assertSame(['base', 'entorno', 'espacio', 'canales_viejos_de_tienda', 'driver'], array_keys($datos));
        $this->assertSame(DB::connection()->getDatabaseName(), $datos['base']);
        $this->assertSame(config('app.env'), $datos['entorno']);
        $this->assertSame($this->espacio_esperado(), $datos['espacio']);
        $this->assertTrue($datos['canales_viejos_de_tienda']);
        $this->assertSame('pusher', $datos['driver']);
    }

    /**
     * El espacio que tiene que dar ESTE proceso con el secret falso: la base del slot y el entorno
     * de los tests. Que el algoritmo es el del contrato lo fijan los tests de los vectores.
     *
     * @return string
     */
    private function espacio_esperado()
    {
        return EspacioDeCanales::calcular(DB::connection()->getDatabaseName(), config('app.env'), self::SECRETO);
    }

    /**
     * Pone el broadcast en Pusher con credenciales FALSAS y le cambia el cliente Pusher\Pusher del
     * broadcaster registrado por el doble que captura. Nunca sale a la red: el doble no llama a
     * la API, y el freno de internet de Tests\TestCase sigue puesto por si algo lo intentara.
     *
     * Se le cambia el cliente al broadcaster que resuelve la aplicación (y no se arma uno a mano)
     * a propósito: así el test prueba lo que está registrado en BroadcastServiceProvider, que es lo
     * que corre en producción.
     *
     * @return object El doble, con `envios` = [['canales' => [...], 'evento' => '...'], ...].
     */
    private function armar_pusher_de_prueba()
    {
        config([
            'broadcasting.default'                        => 'pusher',
            'broadcasting.connections.pusher.key'         => self::KEY_FALSA,
            'broadcasting.connections.pusher.secret'      => self::SECRETO,
            'broadcasting.connections.pusher.app_id'      => '123456',
            'broadcasting.connections.pusher.options.cluster' => 'sa1',
            // Las notificaciones por broadcast se encolan (BroadcastNotificationCreated es
            // ShouldBroadcast): con sync salen en el acto.
            'queue.default'                               => 'sync',
        ]);

        Broadcast::forgetDrivers();

        $doble = $this->doble_de_pusher();

        Broadcast::driver('pusher')->setPusher($doble);

        return $doble;
    }

    /**
     * Doble de Pusher\Pusher: `trigger()` anota los canales y el evento en vez de hacer el POST, y
     * antes los pasa por `make_trigger_body()` del SDK (privado), que valida cada nombre con la
     * misma expresión que usa Pusher en producción y el tope de 100 canales por trigger.
     *
     * @return object
     */
    private function doble_de_pusher()
    {
        return new class(self::KEY_FALSA, self::SECRETO, '123456', ['cluster' => 'sa1', 'useTLS' => true]) extends Pusher {
            /**
             * Lo que se habría mandado, un elemento por trigger.
             *
             * @var array
             */
            public $envios = [];

            /**
             * @param  array|string  $channels
             * @param  string        $event
             * @param  mixed         $data
             * @param  array         $params
             * @param  bool          $already_encoded
             * @return object
             */
            public function trigger($channels, string $event, $data, array $params = [], bool $already_encoded = false): object
            {
                $canales = is_array($channels) ? array_values($channels) : [$channels];

                // La validación real del SDK, sin red: arma el cuerpo del POST y lo descarta.
                $validar = \Closure::bind(function ($canales, $event, $data, $params, $already_encoded) {
                    return $this->make_trigger_body($canales, $event, $data, $params, $already_encoded);
                }, $this, Pusher::class);
                $validar($canales, $event, $data, $params, $already_encoded);

                $this->envios[] = ['canales' => $canales, 'evento' => $event];

                return (object) [];
            }
        };
    }

    /**
     * Los canales de cada trigger que recibió el doble.
     *
     * @param  object  $pusher
     * @return array
     */
    private function canales_de_cada_trigger($pusher)
    {
        $canales = [];

        foreach ($pusher->envios as $envio) {
            $canales[] = $envio['canales'];
        }

        return $canales;
    }

    /**
     * Comercio dueño (fila de `users`, la tabla del ERP), adentro de la transacción del test.
     *
     * @return \App\User
     */
    private function comercio()
    {
        return User::create([
            'name'     => 'Comercio Canales Test',
            'email'    => 'canales-' . Str::random(10) . '@test.local',
            'password' => bcrypt('secreto'),
            'status'   => 'commerce',
        ]);
    }

    /**
     * Comprador del comercio, adentro de la transacción del test.
     *
     * @param  \App\User  $comercio
     * @return \App\Buyer
     */
    private function comprador(User $comercio)
    {
        return Buyer::create([
            'name'    => 'Comprador Canales Test',
            'email'   => 'comprador-canales-' . Str::random(8) . '@test.local',
            'phone'   => '3511234567',
            'user_id' => $comercio->id,
        ]);
    }

    /**
     * Pedido sin guardar: OrderCreated solo lee id, num y user_id.
     *
     * @param  int  $user_id
     * @return \App\Order
     */
    private function pedido_en_memoria($user_id)
    {
        $pedido = new Order(['num' => 1234, 'user_id' => $user_id]);
        $pedido->id = 77;

        return $pedido;
    }
}
