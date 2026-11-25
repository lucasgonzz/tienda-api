<?php

namespace Tests\Feature;

use App\Buyer;
use App\Cart;
use App\Jobs\AvisarPedidoAlAsistente;
use App\OnlineConfiguration;
use App\Order;
use App\OrderCommunicationDelivery as Delivery;
use App\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Aviso que la tienda le manda al sistema del comercio (empresa-api) cuando entra un pedido, para que
 * el asistente personal le escriba al dueño por WhatsApp (misión whatsapp-aviso-pedido, CA8).
 *
 * Qué se prueba, y qué no:
 *  - Se prueba el Job `App\Jobs\AvisarPedidoAlAsistente` (directo) y el camino real
 *    `POST /api/orders` → `OrderController@store` → callbacks de `terminate()`, con `Http::fake`
 *    (ningún test sale a la red; el freno de `Tests\TestCase` además hace fallar al que lo intente).
 *  - NO se prueba lo que hace empresa-api ni admin-api con el aviso: eso lo cubren sus propios tests.
 *
 * Sin DatabaseTransactions, a propósito (mismo criterio que `OrderEmailsTest`): el Job sale sin hacer
 * nada si hay una transacción abierta (`order_not_committed`), así que hay que observar commits reales.
 * Todo lo que se crea se borra en `tearDown()`: sólo las filas de esta fixture, nunca el esquema.
 *
 * Precondición de entorno: la columna `online_configurations.notificar_pedido_por_whatsapp` la crea la
 * migración de empresa-api (tienda-api no tiene migraciones). Los tests que necesitan la casilla
 * fallan con un mensaje explícito si la base de testing todavía no la tiene; los demás no la necesitan.
 */
class AvisoDePedidoAlAsistenteTest extends TestCase
{
    /** Motivo de log con el que el Job explica cada salida sin HTTP (y la respuesta de empresa). */
    const MENSAJE_DE_LOG = 'Aviso de pedido al asistente';

    /** Slug de la extensión "Agentes de IA y asistente personal" de la tabla `extencion_empresas`. */
    const SLUG_AGENTES_IA = 'agentes_ia';

    /** Host de empresa-api del comercio de prueba (.test: jamás resuelve a un sitio real). */
    const API_URL = 'https://empresa-aviso-pedido.test';

    /** Datos del comprador y del pedido que no pueden aparecer en ningún log. */
    const NOMBRE_COMPRADOR = 'Gabriel Domínguez';
    const TELEFONO_COMPRADOR = '1155550123';
    const MAIL_COMPRADOR = 'gabriel.dominguez@example.test';
    const TOTAL_DEL_PEDIDO = 188000;

    /** @var \App\User Comercio dueño de los pedidos de la prueba. */
    protected $comercio;

    /** @var \App\Buyer Comprador de la prueba. */
    protected $comprador;

    /** @var \App\OnlineConfiguration Configuración online del comercio. */
    protected $configuracion;

    /** @var array Ids de los usuarios creados, para borrarlos al terminar. */
    protected $usuarios_creados = [];

    /** @var int|null Id de la extensión agentes_ia si la creó este test (si ya existía queda null). */
    protected $extension_creada = null;

    /** @var array Pedidos HTTP al endpoint de aviso que hizo el código bajo prueba (se anotan antes de responder o fallar). */
    protected $avisos = [];

    /** @var array Línea de tiempo de lo que pasó: 'respuesta', 'mail', 'broadcast', 'aviso'. */
    protected $orden = [];

    /** @var array Registros del log (mensaje, nivel, contexto) capturados durante el test. */
    protected $logs = [];

    /**
     * Arma el comercio con Agentes de IA, la configuración online y un comprador; engancha los
     * oyentes que anotan el orden de los hechos y cierra la red.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mismas barreras que OrderMailFixture: base real de testing y nunca otra.
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertStringContainsString('testing', DB::connection()->getDatabaseName());

        // Mail en memoria: se anota cuando el mail del pedido llega al transporte.
        config(['mail.default' => 'array']);

        $this->comercio = User::create([
            'name'     => 'Comercio aviso pedido',
            'company_name' => 'Comercio aviso pedido',
            'email'    => uniqid('comercio-').'@example.test',
            'status'   => 'commerce',
            'password' => bcrypt('synthetic-only'),
            'api_url'  => self::API_URL,
        ]);
        $this->usuarios_creados[] = $this->comercio->id;

        $this->configuracion = OnlineConfiguration::create([
            'user_id'                      => $this->comercio->id,
            'notificar_pedido_al_negocio'  => 1,
            'notificar_pedido_al_cliente'  => 1,
            'mail_notificacion_pedidos'    => 'duenio@example.test',
        ]);

        $this->comprador = Buyer::create([
            'name'    => self::NOMBRE_COMPRADOR,
            'email'   => self::MAIL_COMPRADOR,
            'phone'   => self::TELEFONO_COMPRADOR,
            'user_id' => $this->comercio->id,
        ]);

        DB::table('order_statuses')->updateOrInsert(['name' => 'Sin confirmar'], ['updated_at' => now()]);

        $this->dar_agentes_ia();
        $this->enganchar_oyentes();
    }

    /**
     * Borra únicamente lo que creó la fixture (pedidos, carritos, compradores, configuración,
     * usuarios, relación con la extensión y la extensión si la creó el test).
     */
    protected function tearDown(): void
    {
        try {
            if ($this->usuarios_creados) {
                Delivery::whereIn('owner_id', $this->usuarios_creados)->delete();
                Order::whereIn('user_id', $this->usuarios_creados)->delete();
                Cart::whereIn('user_id', $this->usuarios_creados)->delete();
                Buyer::whereIn('user_id', $this->usuarios_creados)->delete();
                OnlineConfiguration::whereIn('user_id', $this->usuarios_creados)->delete();
                DB::table('extencion_empresa_user')->whereIn('user_id', $this->usuarios_creados)->delete();
                User::whereIn('id', $this->usuarios_creados)->delete();
            }

            if ($this->extension_creada) {
                DB::table('extencion_empresas')->where('id', $this->extension_creada)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    // ---------------------------------------------------------------------------------------------
    // CA8 — camino feliz, orden y momento
    // ---------------------------------------------------------------------------------------------

    /**
     * Con Agentes de IA y la casilla prendida (valor por defecto), crear el pedido por el endpoint
     * real manda UN POST al sistema del comercio, con la forma del contrato C2.
     */
    public function test_con_agentes_ia_y_casilla_prendida_avisa_a_empresa_una_sola_vez()
    {
        $this->exigir_la_columna_de_la_casilla();
        $this->empresa_responde(Http::response(['motivo' => 'se_avisa_al_admin'], 202));

        $pedido = $this->hacer_el_pedido();

        $this->assertCount(1, $this->avisos, 'tiene que salir exactamente un aviso a empresa');

        $aviso = $this->avisos[0];
        $this->assertSame('POST', $aviso->method());
        $this->assertSame(
            self::API_URL.'/api/tienda/pedidos/'.$pedido->id.'/aviso-al-asistente',
            $aviso->url()
        );
        // Contrato C2: sin clave ni credenciales (la ruta de empresa es pública con throttle).
        $this->assertFalse($aviso->hasHeader('X-Admin-Api-Key'));
        $this->assertFalse($aviso->hasHeader('Authorization'));

        $this->assert_log_con_motivo('empresa_respondio', $pedido->id, 202);
    }

    /**
     * Una `api_url` con barra final no duplica la barra en la ruta del aviso.
     */
    public function test_la_barra_final_de_api_url_no_duplica_la_barra()
    {
        $this->exigir_la_columna_de_la_casilla();
        $this->comercio->update(['api_url' => self::API_URL.'/']);
        $this->empresa_responde(Http::response(['motivo' => 'ya_avisado'], 202));
        $pedido = $this->crear_pedido_sin_endpoint();

        (new AvisarPedidoAlAsistente($pedido->id))->handle();

        $this->assertCount(1, $this->avisos);
        $this->assertSame(
            self::API_URL.'/api/tienda/pedidos/'.$pedido->id.'/aviso-al-asistente',
            $this->avisos[0]->url()
        );
    }

    /**
     * 🔴 El orden de los callbacks de `terminate()` importa: primero el mail del comprador, después
     * el aviso por broadcast a la pantalla del comercio, y el aviso al asistente ÚLTIMO. Y nada de
     * eso corre antes de que la respuesta HTTP esté armada (no demora al comprador).
     */
    public function test_va_despues_de_la_respuesta_del_mail_y_del_broadcast()
    {
        $this->exigir_la_columna_de_la_casilla();
        $this->empresa_responde(Http::response(['motivo' => 'se_avisa_al_admin'], 202));

        $this->hacer_el_pedido();

        // Los tres hechos previos existen: si no, el orden no prueba nada.
        $this->assertContains('respuesta', $this->orden);
        $this->assertContains('mail', $this->orden, 'el mail del pedido tiene que haber llegado al transporte');
        $this->assertContains('broadcast', $this->orden, 'el aviso por broadcast tiene que haberse emitido');
        $this->assertContains('aviso', $this->orden, 'el aviso al asistente tiene que haber salido');

        $pos_respuesta = array_search('respuesta', $this->orden, true);
        $pos_ultimo_mail = array_keys($this->orden, 'mail', true);
        $pos_ultimo_mail = end($pos_ultimo_mail);
        $pos_ultimo_broadcast = array_keys($this->orden, 'broadcast', true);
        $pos_ultimo_broadcast = end($pos_ultimo_broadcast);
        $pos_aviso = array_search('aviso', $this->orden, true);

        $this->assertLessThan($pos_aviso, $pos_respuesta, 'el aviso sale DESPUÉS de la respuesta al comprador');
        $this->assertLessThan($pos_aviso, $pos_ultimo_mail, 'el aviso sale después de los mails');
        $this->assertLessThan($pos_aviso, $pos_ultimo_broadcast, 'el aviso sale después del broadcast');
    }

    // ---------------------------------------------------------------------------------------------
    // CA8 — cuándo NO se manda nada (cero HTTP y log con el motivo)
    // ---------------------------------------------------------------------------------------------

    /**
     * Sin la extensión Agentes de IA no hay HTTP: log `sin_agentes_ia`.
     */
    public function test_sin_agentes_ia_no_manda_nada_y_deja_el_motivo()
    {
        $this->quitar_agentes_ia();
        $this->empresa_responde(Http::response(['motivo' => 'se_avisa_al_admin'], 202));

        $pedido = $this->hacer_el_pedido();

        $this->assertCount(0, $this->avisos);
        $this->assert_log_con_motivo('sin_agentes_ia', $pedido->id);
    }

    /**
     * Con la casilla apagada por el dueño no hay HTTP: log `apagado_por_el_dueno`.
     */
    public function test_con_la_casilla_apagada_no_manda_nada_y_deja_el_motivo()
    {
        $this->exigir_la_columna_de_la_casilla();
        DB::table('online_configurations')->where('user_id', $this->comercio->id)
            ->update(['notificar_pedido_por_whatsapp' => 0]);
        $this->empresa_responde(Http::response(['motivo' => 'se_avisa_al_admin'], 202));

        $pedido = $this->hacer_el_pedido();

        $this->assertCount(0, $this->avisos);
        $this->assert_log_con_motivo('apagado_por_el_dueno', $pedido->id);
    }

    /**
     * Base de una empresa vieja (sin la columna de la casilla): no hay HTTP y el motivo es
     * `empresa_sin_la_funcion`. Se simula la ausencia de la columna sin tocar el esquema real.
     */
    public function test_sin_la_columna_de_empresa_no_manda_nada_y_deja_el_motivo()
    {
        $this->empresa_responde(Http::response(['motivo' => 'se_avisa_al_admin'], 202));
        $pedido = $this->crear_pedido_sin_endpoint();

        // Sólo la pregunta por la columna de la casilla da "no existe"; todo lo demás es el esquema real.
        $real = DB::connection()->getSchemaBuilder();
        \Illuminate\Support\Facades\Schema::shouldReceive('hasColumn')->andReturnUsing(
            function ($tabla, $columna) use ($real) {
                if ($tabla === 'online_configurations' && $columna === 'notificar_pedido_por_whatsapp') {
                    return false;
                }
                return $real->hasColumn($tabla, $columna);
            }
        );
        \Illuminate\Support\Facades\Schema::shouldReceive('hasTable')->andReturnUsing(
            function ($tabla) use ($real) {
                return $real->hasTable($tabla);
            }
        );

        (new AvisarPedidoAlAsistente($pedido->id))->handle();

        $this->assertCount(0, $this->avisos);
        $this->assert_log_con_motivo('empresa_sin_la_funcion', $pedido->id);
    }

    /**
     * Sin `api_url` (el comercio todavía no tiene sistema publicado) no hay HTTP: log `sin_api_url`.
     */
    public function test_sin_api_url_no_manda_nada_y_deja_el_motivo()
    {
        $this->exigir_la_columna_de_la_casilla();
        $this->comercio->update(['api_url' => null]);
        $this->empresa_responde(Http::response(['motivo' => 'se_avisa_al_admin'], 202));

        $pedido = $this->hacer_el_pedido();

        $this->assertCount(0, $this->avisos);
        $this->assert_log_con_motivo('sin_api_url', $pedido->id);
    }

    /**
     * Con una transacción abierta el pedido puede no estar commiteado (empresa no lo vería): el Job
     * no manda nada y deja `order_not_committed`.
     */
    public function test_dentro_de_una_transaccion_abierta_no_manda_nada()
    {
        $this->exigir_la_columna_de_la_casilla();
        $this->empresa_responde(Http::response(['motivo' => 'se_avisa_al_admin'], 202));
        $pedido = $this->crear_pedido_sin_endpoint();

        DB::beginTransaction();
        try {
            (new AvisarPedidoAlAsistente($pedido->id))->handle();
        } finally {
            DB::rollBack();
        }

        $this->assertCount(0, $this->avisos);
        $this->assert_log_con_motivo('order_not_committed', $pedido->id);
    }

    /**
     * Un pedido que ya no existe no tira ninguna excepción ni manda nada.
     */
    public function test_un_pedido_inexistente_no_tira_ni_manda_nada()
    {
        $this->exigir_la_columna_de_la_casilla();
        $this->empresa_responde(Http::response(['motivo' => 'se_avisa_al_admin'], 202));

        (new AvisarPedidoAlAsistente(987654321))->handle();

        $this->assertCount(0, $this->avisos);
    }

    // ---------------------------------------------------------------------------------------------
    // CA8 — un fallo del aviso no rompe el pedido ni cambia la respuesta
    // ---------------------------------------------------------------------------------------------

    /**
     * Proveedor de fallos de empresa: respuesta 404, 500, 429, timeout y excepción cualquiera.
     *
     * @return array
     */
    public static function fallos_de_empresa(): array
    {
        // Se devuelven datos planos (no Http::response): el proveedor corre antes de que arranque la aplicación.
        return [
            'empresa responde 404' => ['status', 404],
            'empresa responde 500' => ['status', 500],
            'empresa responde 429' => ['status', 429],
            'timeout de conexión'  => ['timeout', null],
            'excepción cualquiera' => ['excepcion', null],
        ];
    }

    /**
     * @dataProvider fallos_de_empresa
     *
     * Con empresa fallando de cualquier forma: el pedido queda creado, el comprador recibe el mismo
     * 201 con su `order_id`, y el mail y el broadcast corrieron igual (antes del aviso).
     */
    public function test_empresa_caida_no_cambia_la_respuesta_ni_rompe_el_pedido($tipo, $status)
    {
        $this->exigir_la_columna_de_la_casilla();

        if ($tipo === 'status') {
            $this->empresa_responde(Http::response(['message' => 'falla de empresa'], $status));
        } elseif ($tipo === 'timeout') {
            $this->empresa_responde(new ConnectionException('cURL error 28: timeout'));
        } else {
            $this->empresa_responde(new \RuntimeException('algo explotó'));
        }

        $respuesta = $this->hacer_el_pedido_con_respuesta();

        $respuesta->assertStatus(201);
        $respuesta->assertExactJson(['order_id' => (int) $respuesta->json('order_id')]);
        $this->assertNotNull(Order::find($respuesta->json('order_id')), 'el pedido tiene que seguir existiendo');

        // El intento se hizo (si no, esto no probaría que el fallo se tragó)...
        $this->assertCount(1, $this->avisos);
        // ...y lo anterior al aviso corrió completo.
        $this->assertContains('mail', $this->orden);
        $this->assertContains('broadcast', $this->orden);
    }

    // ---------------------------------------------------------------------------------------------
    // CA8 — logs sin datos personales
    // ---------------------------------------------------------------------------------------------

    /**
     * Todo log del aviso tiene sólo las claves `order_id`, `owner_id`, `motivo` y `http_status`, y
     * ningún valor contiene el nombre, teléfono o mail del comprador, el importe ni la URL de empresa.
     * Se recorre el camino feliz y un camino de fallo.
     */
    public function test_los_logs_no_llevan_datos_personales()
    {
        $this->exigir_la_columna_de_la_casilla();

        // Camino feliz.
        $this->empresa_responde(Http::response(['motivo' => 'se_avisa_al_admin', 'nombre' => self::NOMBRE_COMPRADOR], 202));
        $this->hacer_el_pedido();

        // Camino de fallo (la respuesta de error trae datos personales en el cuerpo, que no deben loguearse).
        $this->avisos = [];
        $this->empresa_responde(Http::response(['error' => self::NOMBRE_COMPRADOR.' '.self::TELEFONO_COMPRADOR], 500));
        $this->hacer_el_pedido();

        $registros = $this->registros_del_aviso();
        $this->assertNotEmpty($registros, 'el aviso tiene que dejar al menos un registro en el log');

        $claves_permitidas = ['order_id', 'owner_id', 'motivo', 'http_status'];
        $prohibidos = [
            self::NOMBRE_COMPRADOR, 'Domínguez', self::TELEFONO_COMPRADOR, self::MAIL_COMPRADOR,
            (string) self::TOTAL_DEL_PEDIDO, '188.000', 'empresa-aviso-pedido', 'https://', 'http://',
        ];

        foreach ($registros as $registro) {
            $this->assertSame(
                [],
                array_diff(array_keys($registro['contexto']), $claves_permitidas),
                'el log del aviso sólo puede tener claves de la lista cerrada'
            );

            $volcado = $registro['mensaje'].' '.json_encode($registro['contexto'], JSON_UNESCAPED_UNICODE);
            foreach ($prohibidos as $dato) {
                $this->assertStringNotContainsString($dato, $volcado, "el log no puede contener «{$dato}»");
            }
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Ayudas
    // ---------------------------------------------------------------------------------------------

    /**
     * Falla con un mensaje claro si la base de testing todavía no tiene la columna de la casilla
     * (la crea la migración de empresa-api; tienda-api no tiene migraciones y los tests no hacen DDL).
     */
    protected function exigir_la_columna_de_la_casilla(): void
    {
        $this->assertTrue(
            Schema::hasColumn('online_configurations', 'notificar_pedido_por_whatsapp'),
            'La base de testing no tiene online_configurations.notificar_pedido_por_whatsapp: '
            .'hay que correr la migración de empresa-api de la misión whatsapp-aviso-pedido sobre esa base.'
        );
    }

    /**
     * Le da al comercio la extensión Agentes de IA (la crea si la base de testing no la tiene).
     */
    protected function dar_agentes_ia(): void
    {
        // El modelo no tiene $fillable (la tabla la siembra empresa-api): se inserta directo.
        $extension_id = DB::table('extencion_empresas')->where('slug', self::SLUG_AGENTES_IA)->value('id');

        if (!$extension_id) {
            $extension_id = DB::table('extencion_empresas')->insertGetId([
                'name'       => 'Agentes de IA y asistente personal',
                'slug'       => self::SLUG_AGENTES_IA,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->extension_creada = $extension_id;
        }

        DB::table('extencion_empresa_user')->insert([
            'extencion_empresa_id' => $extension_id,
            'user_id'              => $this->comercio->id,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    /**
     * Le saca la extensión Agentes de IA al comercio.
     */
    protected function quitar_agentes_ia(): void
    {
        DB::table('extencion_empresa_user')->where('user_id', $this->comercio->id)->delete();
    }

    /**
     * Configura `Http::fake`: el aviso al asistente (URL con `/aviso-al-asistente`) anota el pedido
     * en `$this->avisos` y en la línea de tiempo, y devuelve la respuesta dada o lanza la excepción
     * dada. Cualquier otro pedido HTTP recibe un 200 vacío (nada real sale a la red).
     *
     * @param \GuzzleHttp\Promise\PromiseInterface|\Throwable $respuesta Lo que contesta empresa.
     */
    protected function empresa_responde($respuesta): void
    {
        Http::fake(function (Request $request) use ($respuesta) {
            if (strpos($request->url(), '/aviso-al-asistente') === false) {
                return Http::response('', 200);
            }

            $this->avisos[] = $request;
            $this->orden[] = 'aviso';

            if ($respuesta instanceof \Throwable) {
                throw $respuesta;
            }

            return $respuesta;
        });
    }

    /**
     * Engancha los oyentes que arman la línea de tiempo (respuesta lista, mail enviado, broadcast
     * emitido) y capturan el log.
     */
    protected function enganchar_oyentes(): void
    {
        $eventos = app('events');

        $eventos->listen(RequestHandled::class, function () {
            $this->orden[] = 'respuesta';
        });
        $eventos->listen(MessageSending::class, function () {
            $this->orden[] = 'mail';
        });
        $eventos->listen(BroadcastNotificationCreated::class, function () {
            $this->orden[] = 'broadcast';
        });
        $eventos->listen(MessageLogged::class, function (MessageLogged $evento) {
            $this->logs[] = ['mensaje' => $evento->message, 'nivel' => $evento->level, 'contexto' => $evento->context];
        });
    }

    /**
     * Crea el pedido por el endpoint real (como el SPA) y devuelve el modelo.
     *
     * @return \App\Order
     */
    protected function hacer_el_pedido(): Order
    {
        $respuesta = $this->hacer_el_pedido_con_respuesta();
        $respuesta->assertStatus(201);

        return Order::findOrFail($respuesta->json('order_id'));
    }

    /**
     * Crea el pedido por `POST /api/orders` con la sesión que arma el SPA y devuelve la respuesta.
     * `postJson` termina corriendo `terminate()`, o sea los callbacks de "después de la respuesta".
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function hacer_el_pedido_con_respuesta()
    {
        $cart = Cart::create([
            'user_id'  => $this->comercio->id,
            'total'    => self::TOTAL_DEL_PEDIDO,
            'deliver'  => 0,
        ]);

        return $this->withSession([
                'carritos_propios'  => [$cart->id],
                'checkout_buyer_id' => $this->comprador->id,
            ])
            ->postJson('/api/orders', [
                'cart_id'     => $cart->id,
                'commerce_id' => $this->comercio->id,
                'address'     => 'Dirección sintética 123',
            ]);
    }

    /**
     * Crea un pedido directo en la base (sin pasar por el endpoint) para los tests que corren el Job a mano.
     *
     * @return \App\Order
     */
    protected function crear_pedido_sin_endpoint(): Order
    {
        $status_id = DB::table('order_statuses')->where('name', 'Sin confirmar')->value('id');

        return Order::create([
            'user_id'         => $this->comercio->id,
            'buyer_id'        => $this->comprador->id,
            'num'             => 1,
            'order_status_id' => $status_id,
            'deliver'         => 0,
        ]);
    }

    /**
     * Registros del log que son del aviso al asistente.
     *
     * @return array Lista de ['mensaje', 'nivel', 'contexto'].
     */
    protected function registros_del_aviso(): array
    {
        $registros = [];

        foreach ($this->logs as $registro) {
            if ($registro['mensaje'] === self::MENSAJE_DE_LOG) {
                $registros[] = $registro;
            }
        }

        return $registros;
    }

    /**
     * Exige que exista un log del aviso con ese motivo para ese pedido (y, si se pide, ese status HTTP).
     *
     * @param string   $motivo      Motivo esperado.
     * @param int      $order_id    Pedido del aviso.
     * @param int|null $http_status Status HTTP de empresa, si aplica.
     */
    protected function assert_log_con_motivo(string $motivo, int $order_id, ?int $http_status = null): void
    {
        foreach ($this->registros_del_aviso() as $registro) {
            $contexto = $registro['contexto'];

            if (($contexto['motivo'] ?? null) !== $motivo || (int) ($contexto['order_id'] ?? 0) !== $order_id) {
                continue;
            }

            if (!is_null($http_status)) {
                $this->assertSame($http_status, (int) ($contexto['http_status'] ?? 0));
            }

            $this->addToAssertionCount(1);
            return;
        }

        $this->fail("No hay log «".self::MENSAJE_DE_LOG."» con motivo «{$motivo}» para el pedido {$order_id}. Logs del aviso: "
            .json_encode($this->registros_del_aviso(), JSON_UNESCAPED_UNICODE));
    }
}
