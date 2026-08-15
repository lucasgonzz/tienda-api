<?php

namespace Tests\Feature\BuyerTracking;

use App\Buyer;
use App\Http\Controllers\Helpers\BuyerTrackingHelper;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ingesta de eventos de comportamiento de compradores (mision tracking-buyers-tienda).
 *
 * Que modos de falla cubre esta clase, que es lo que justifica que exista:
 *
 *   - que un evento mal formado se lleve puesto el lote entero (los 49 buenos que venian al lado);
 *   - que el reloj de una maquina mal seteada ensucie el historico para siempre;
 *   - que un dato sensible del payload termine en la base, cuando el requisito es que NUNCA
 *     se guarde IP, user-agent, email, telefono, nombre, direccion ni nada de pago;
 *   - que alguien pueda atribuirle su navegacion a otro comprador mandando un buyer_id;
 *   - que la lista blanca de tipos se desincronice de la de `empresa-api`;
 *   - que una ruta wildcard sombree al endpoint, que en este repo NO es teorico:
 *     /articles/{slug}/{commerce_id} ya se come a /articles/set-viewed/{id}.
 *
 * El caso de la tabla ausente —que es LA compatibilidad hacia atras del contrato con
 * `empresa-api`— vive en ContratoConEmpresaApiTest, aparte: necesita DDL, y el DDL le hace
 * commit implicito a la transaccion de DatabaseTransactions.
 *
 * ⚠️ Sobre la base: `tienda-api` no tiene database/migrations (el esquema lo gobierna
 * `empresa-api`), asi que aca NO se usa RefreshDatabase ni migrate. Se corre contra la base
 * real del slot con DatabaseTransactions, y las tablas de esta mision se aplicaron ahi
 * corriendo las migraciones de `empresa-api` apuntadas a esta base.
 */
class IngestaDeEventosTest extends TestCase
{
    use DatabaseTransactions;

    const TABLA = 'buyer_tracking_events';
    const RUTA  = '/api/buyer-tracking/events';

    /** @var \App\User */
    private $comercio;

    /** @var string */
    private $visitor_id;

    /** @var string */
    private $session_id;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Las tablas las crea `empresa-api`. Si todavia no se aplicaron contra esta base, el
         * resultado honesto es "no se pudo medir", nunca un verde.
         */
        if (!Schema::hasTable(self::TABLA)) {
            $this->markTestSkipped(
                'Falta la tabla '.self::TABLA.'. Se crea con las migraciones de empresa-api '
                .'(2026_08_15_140000) apuntadas a esta base.'
            );
        }

        /* La suite corre todos los casos en el mismo proceso; el helper memoiza por request. */
        BuyerTrackingHelper::olvidarMemoria();

        $this->comercio   = $this->crearComercio();
        $this->visitor_id = (string) Str::uuid();
        $this->session_id = (string) Str::uuid();
    }

    /**
     * El camino feliz del caso NORMAL de una tienda: un visitante sin loguear.
     */
    public function test_escribe_el_lote_de_un_visitante_anonimo()
    {
        $this->prenderLaExtencion();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'events'      => [
                $this->evento('product_view', ['article_id' => 77, 'dwell_ms' => 4200]),
                $this->evento('search', ['search_term' => '  BUJÍAS NGK  ', 'results_count' => 0]),
            ],
        ]);

        $respuesta->assertStatus(204);

        $filas = $this->filas();
        $this->assertCount(2, $filas);

        $vista = $filas[0];
        $this->assertSame('product_view', $vista->event_type);
        $this->assertNull($vista->buyer_id, 'el visitante anonimo va con buyer_id null');
        $this->assertSame($this->visitor_id, $vista->visitor_id);
        $this->assertSame($this->session_id, $vista->session_id);
        $this->assertSame(77, (int) $vista->article_id);
        $this->assertSame(4200, (int) $vista->dwell_ms);
        $this->assertSame((int) $this->comercio->id, (int) $vista->user_id);

        $busqueda = $filas[1];
        $this->assertSame('search', $busqueda->event_type);

        /* Minusculas y recortado, igual que last_searches.body hoy: los dos historicos comparables. */
        $this->assertSame('bujías ngk', $busqueda->search_term);

        /*
         * 🔴 El 0 se preserva y no se confunde con null: "busco y no encontro nada" es el dato
         * mas valioso de la tabla para el motor de ofertas.
         */
        $this->assertNotNull($busqueda->results_count, 'results_count 0 no puede volverse null');
        $this->assertSame(0, (int) $busqueda->results_count);
    }

    /**
     * El buyer sale de la SESION y nunca del payload.
     *
     * La segunda mitad es la que importa y es de seguridad: si el buyer_id se leyera del
     * cuerpo, cualquiera podria atribuirle su navegacion a otro comprador.
     */
    public function test_le_pone_el_buyer_de_la_sesion_y_jamas_el_del_payload()
    {
        $this->prenderLaExtencion();

        $comprador = Buyer::create([
            'name'    => 'Comprador de prueba',
            'email'   => 'comprador-'.Str::random(8).'@test.local',
            'user_id' => $this->comercio->id,
        ]);

        $otro = Buyer::create([
            'name'    => 'Comprador ajeno',
            'email'   => 'ajeno-'.Str::random(8).'@test.local',
            'user_id' => $this->comercio->id,
        ]);

        $respuesta = $this->actingAs($comprador, 'buyer')->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'events'      => [
                /* El payload miente a proposito: dice ser el otro comprador. */
                $this->evento('cart_add', ['article_id' => 5, 'quantity' => 2, 'buyer_id' => $otro->id]),
            ],
        ]);

        $respuesta->assertStatus(204);

        $filas = $this->filas();
        $this->assertCount(1, $filas);
        $this->assertSame((int) $comprador->id, (int) $filas[0]->buyer_id, 'tiene que ser el de la sesion');
        $this->assertNotSame((int) $otro->id, (int) $filas[0]->buyer_id, 'nunca el que vino en el payload');
        $this->assertSame(2, (int) $filas[0]->quantity);
    }

    /**
     * El interruptor: sin la extension del comercio no se escribe una sola fila, y el SPA no
     * se entera de nada.
     */
    public function test_sin_la_extencion_no_escribe_nada_y_responde_204()
    {
        /* A proposito NO se llama a prenderLaExtencion(). */

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'events'      => [$this->evento('product_view', ['article_id' => 77])],
        ]);

        $respuesta->assertStatus(204);
        $this->assertCount(0, $this->filas());
    }

    /**
     * Un evento con un tipo inventado se descarta SOLO A EL.
     *
     * Es el modo de falla que mas caro sale: si el lote se tirara entero, un solo evento mal
     * formado del SPA borraria los 49 buenos que venian al lado, y nadie lo notaria.
     */
    public function test_un_evento_con_tipo_inventado_no_se_lleva_puesto_al_resto_del_lote()
    {
        $this->prenderLaExtencion();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'events'      => [
                $this->evento('product_view', ['article_id' => 1]),
                $this->evento('robar_la_base', ['article_id' => 2]),
                $this->evento('checkout_start', ['article_id' => 3]),
            ],
        ]);

        $respuesta->assertStatus(204);

        $filas = $this->filas();
        $this->assertCount(2, $filas, 'los dos validos tienen que haber entrado');
        $this->assertSame(['product_view', 'checkout_start'], $filas->pluck('event_type')->all());
    }

    /**
     * Sin uuid con forma de uuid no hay recorrido que reconstruir, asi que el evento no entra
     * — pero tampoco tira el lote.
     */
    public function test_los_eventos_sin_uuid_valido_se_descartan_sin_tirar_el_lote()
    {
        $this->prenderLaExtencion();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'events'      => [
                ['event_type' => 'product_view', 'visitor_id' => 'no-soy-un-uuid', 'session_id' => $this->session_id],
                ['event_type' => 'product_view', 'visitor_id' => $this->visitor_id, 'session_id' => ''],
                /* Y uno con el tipo como array, que sin la guarda de is_scalar seria un 500. */
                ['event_type' => ['product_view'], 'visitor_id' => $this->visitor_id, 'session_id' => $this->session_id],
                $this->evento('product_view', ['article_id' => 9]),
            ],
        ]);

        $respuesta->assertStatus(204);

        $filas = $this->filas();
        $this->assertCount(1, $filas);
        $this->assertSame(9, (int) $filas[0]->article_id);
    }

    /**
     * Tope de 50 eventos por lote: un cliente hostil no puede mandar un lote gigante.
     */
    public function test_un_lote_de_51_eventos_se_recorta_a_50()
    {
        $this->prenderLaExtencion();

        $eventos = [];

        for ($i = 1; $i <= 51; $i++) {
            $eventos[] = $this->evento('product_view', ['article_id' => $i]);
        }

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'events'      => $eventos,
        ]);

        $respuesta->assertStatus(204);

        $filas = $this->filas();
        $this->assertCount(50, $filas);

        /* Se queda con los primeros 50, no con 50 cualesquiera. */
        $this->assertSame(1, (int) $filas[0]->article_id);
        $this->assertSame(50, (int) $filas[49]->article_id);
    }

    /**
     * El reloj del cliente y el tiempo en pantalla se acotan antes de escribir.
     *
     * Sin esto, una maquina con la fecha adelantada escribe en el futuro y cualquier consulta
     * por rango de fechas queda mintiendo para siempre.
     */
    public function test_normaliza_el_reloj_del_cliente_y_el_tiempo_en_pantalla()
    {
        $this->prenderLaExtencion();

        $futuro = Carbon::now()->addHours(3);
        $viejo  = Carbon::now()->subDays(9);

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'events'      => [
                /* Reloj adelantado + una pestaña abierta 3 horas. */
                $this->evento('product_view', [
                    'article_id'  => 1,
                    'occurred_at' => $futuro->format('Y-m-d H:i:s'),
                    'dwell_ms'    => 10800000,
                ]),
                /* Reloj atrasado nueve dias. */
                $this->evento('product_view', [
                    'article_id'  => 2,
                    'occurred_at' => $viejo->format('Y-m-d H:i:s'),
                    'dwell_ms'    => 4200,
                ]),
                /* Y una fecha que directamente no es una fecha. */
                $this->evento('product_view', ['article_id' => 3, 'occurred_at' => 'anteayer a la tarde']),
            ],
        ]);

        $respuesta->assertStatus(204);

        $filas  = $this->filas();
        $piso   = Carbon::now()->subMinutes(5);
        $techo  = Carbon::now()->addMinute();

        $this->assertCount(3, $filas);

        foreach ($filas as $fila) {
            $ocurrido = Carbon::parse($fila->occurred_at);

            $this->assertTrue(
                $ocurrido->betweenIncluded($piso, $techo),
                'occurred_at fuera de rango se reemplaza por el instante del servidor, y quedo: '.$fila->occurred_at
            );
        }

        /*
         * El dwell de 3 horas pierde el VALOR, no el evento: que alguien haya dejado la pestaña
         * abierta toda la noche no invalida que haya visto el producto.
         */
        $this->assertNull($filas[0]->dwell_ms, 'un dwell de 3 horas no es tiempo de lectura');
        $this->assertSame(4200, (int) $filas[1]->dwell_ms, 'el dwell razonable se conserva');
    }

    /**
     * 🔴 Requisito de Lucas, no una preferencia: nada sensible llega a la base.
     *
     * La defensa no es una lista negra sino la lista blanca de columnas de armarFila(), asi que
     * este test tambien cubre cualquier clave futura que a alguien se le ocurra mandar.
     */
    public function test_una_clave_sensible_del_payload_no_llega_a_la_base()
    {
        $this->prenderLaExtencion();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'events'      => [
                $this->evento('product_view', [
                    'article_id'  => 42,
                    'email'       => 'comprador@gmail.com',
                    'ip'          => '190.55.1.20',
                    'user_agent'  => 'Mozilla/5.0',
                    'telefono'    => '3814000000',
                    'nombre'      => 'Juan Perez',
                    'direccion'   => 'San Martin 100',
                    'card_number' => '4509000000000000',
                ]),
            ],
        ]);

        $respuesta->assertStatus(204);

        $filas = $this->filas();
        $this->assertCount(1, $filas);

        /* Ni como columna nueva ni escondido en alguna de las que si existen. */
        $columnas = Schema::getColumnListing(self::TABLA);

        foreach (['email', 'ip', 'user_agent', 'telefono', 'nombre', 'direccion', 'card_number'] as $prohibida) {
            $this->assertNotContains($prohibida, $columnas);
        }

        $serializada = json_encode($filas[0]);

        foreach (['comprador@gmail.com', '190.55.1.20', 'Mozilla', '3814000000', 'Juan Perez', 'San Martin 100', '4509000000000000'] as $dato) {
            $this->assertStringNotContainsString($dato, $serializada, 'se filtro un dato sensible a la fila');
        }
    }

    /**
     * La ruta no esta sombreada por ningun wildcard.
     *
     * No es teorico en este repo: routes/api.php registra /articles/{slug}/{commerce_id} antes
     * que /articles/set-viewed/{id} —los dos de 3 segmentos— y por eso ArticleController@setViewed
     * es inalcanzable desde que existe. Laravel matchea en orden de registro.
     *
     * Se resuelve con el router de verdad y no leyendo el archivo: lo que importa es cual gana.
     */
    public function test_la_ruta_no_esta_sombreada_por_ningun_wildcard()
    {
        $ruta = app('router')->getRoutes()->match(Request::create(self::RUTA, 'POST'));

        $this->assertSame(
            'App\Http\Controllers\BuyerTrackingController@store',
            $ruta->getActionName(),
            'otra ruta registrada antes le esta ganando el match'
        );

        /* Y sigue con el throttle puesto: es un endpoint publico de escritura. */
        $this->assertContains('throttle:60,1', $ruta->gatherMiddleware());
    }

    /**
     * La lista blanca de tipos es el contrato con `empresa-api`.
     *
     * Este test es de fijacion a proposito: si alguien agrega un tipo de este lado sin agregarlo
     * en BuyerTrackingEvent::TIPOS, se pone en rojo y lo obliga a mirar los dos lados. Sin el, el
     * mismo invariante termina decidido con dos criterios distintos, que es la clase de error que
     * el plan de esta mision nombra explicitamente.
     */
    public function test_los_tipos_de_evento_son_el_espejo_exacto_de_empresa_api()
    {
        $this->assertSame(
            [
                'product_view',
                'search',
                'cart_add',
                'cart_remove',
                'checkout_start',
                'checkout_complete',
            ],
            BuyerTrackingHelper::TIPOS,
            'si esto cambia, tiene que cambiar tambien BuyerTrackingEvent::TIPOS en empresa-api'
        );
    }

    /**
     * Un lote vacio, sin commerce_id o con basura en vez de un array no escribe nada ni rompe.
     */
    public function test_un_pedido_mal_armado_no_rompe_ni_escribe()
    {
        $this->prenderLaExtencion();

        $this->postJson(self::RUTA, [])->assertStatus(204);
        $this->postJson(self::RUTA, ['commerce_id' => $this->comercio->id])->assertStatus(204);
        $this->postJson(self::RUTA, ['commerce_id' => $this->comercio->id, 'events' => []])->assertStatus(204);
        $this->postJson(self::RUTA, ['commerce_id' => $this->comercio->id, 'events' => 'nada'])->assertStatus(204);
        $this->postJson(self::RUTA, ['commerce_id' => 0, 'events' => [$this->evento('product_view')]])->assertStatus(204);
        $this->postJson(self::RUTA, ['commerce_id' => 'abc', 'events' => [$this->evento('product_view')]])->assertStatus(204);

        $this->assertCount(0, $this->filas());
    }

    // ---------------------------------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------------------------------

    /**
     * Comercio dueño de la instancia. La base del slot viene con el esquema pero sin datos, asi
     * que cada caso se arma el suyo (y DatabaseTransactions lo revierte).
     *
     * @return \App\User
     */
    private function crearComercio()
    {
        return User::create([
            'name'     => 'Comercio de prueba tracking',
            'email'    => 'tracking-'.Str::random(10).'@test.local',
            'password' => bcrypt('secreto'),
            'status'   => 'commerce',
        ]);
    }

    /**
     * Prende la extension `tracking_buyers` para el comercio del caso.
     *
     * @return int Id de la extension.
     */
    private function prenderLaExtencion()
    {
        $extencion_id = DB::table('extencion_empresas')
            ->where('slug', BuyerTrackingHelper::SLUG_EXTENCION)
            ->value('id');

        if (is_null($extencion_id)) {
            $extencion_id = DB::table('extencion_empresas')->insertGetId([
                'name'       => 'Seguimiento de compradores en la tienda',
                'slug'       => BuyerTrackingHelper::SLUG_EXTENCION,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        DB::table('extencion_empresa_user')->insert([
            'extencion_empresa_id' => $extencion_id,
            'user_id'              => $this->comercio->id,
            'created_at'           => Carbon::now(),
            'updated_at'           => Carbon::now(),
        ]);

        return $extencion_id;
    }

    /**
     * Evento crudo con lo minimo que la ingesta pide, mas lo que le agregue el caso.
     *
     * @param string $event_type
     * @param array $extra
     * @return array
     */
    private function evento($event_type, array $extra = [])
    {
        return array_merge([
            'event_type' => $event_type,
            'visitor_id' => $this->visitor_id,
            'session_id' => $this->session_id,
        ], $extra);
    }

    /**
     * Filas escritas por el comercio del caso, en orden de insercion.
     *
     * @return \Illuminate\Support\Collection
     */
    private function filas()
    {
        return DB::table(self::TABLA)
            ->where('user_id', $this->comercio->id)
            ->orderBy('id')
            ->get();
    }
}
