<?php

namespace Tests\Feature\BuyerTracking;

use App\Http\Controllers\Helpers\BuyerTrackingHelper;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El TRANSPORTE del lote: como llega el cuerpo y con que middlewares corre la ruta
 * (mision tracking-buyers-tienda, pasada de arreglos del 15/8/2026).
 *
 * Que modos de falla cubre esta clase, todos encontrados por verificadores independientes y
 * ninguno visible desde IngestaDeEventosTest, que mira lo que pasa DESPUES de que el lote llego:
 *
 *   - que el cuerpo del beacon (text/plain) no se parsee y se pierda el lote entero;
 *   - que un cuerpo que no sea JSON valido reviente en vez de descartarse;
 *   - que un amount fuera de rango se lleve puesto el LOTE ENTERO, porque el insert es una
 *     sola sentencia multi-fila;
 *   - que el tracking le coma el rate limit compartido al comprador, y los 429 terminen
 *     cayendo en los requests que si importan.
 *
 * ⚠️ EL PUNTO CIEGO QUE ESTA CLASE NO PUEDE CUBRIR, Y HAY QUE SABERLO.
 * Ningun test de esta suite puede probar el arreglo del CSRF (el $except de
 * App\Http\Middleware\VerifyCsrfToken). Por dos motivos independientes, y los dos alcanzan:
 *   1. los tests pegan sin Origin ni Referer, asi que EnsureFrontendRequestsAreStateful no
 *      reconoce el request como del SPA y NO monta el pipeline stateful;
 *   2. VerifyCsrfToken se cortocircuita solo con $this->runningUnitTests().
 * O sea que aca el 419 no se puede reproducir ni aunque se saque el arreglo. Esa verificacion
 * se hace con curl contra `artisan serve` levantado, y esta en el informe de la mision.
 *
 * ⚠️ Sobre la base: `tienda-api` no tiene database/migrations (el esquema lo gobierna
 * `empresa-api`), asi que no hay RefreshDatabase ni migrate. Se corre contra la base real del
 * slot con DatabaseTransactions.
 */
class TransporteDelLoteTest extends TestCase
{
    use DatabaseTransactions;

    const TABLA = 'buyer_tracking_events';
    const RUTA  = '/api/buyer-tracking/events';

    /** Content-type del beacon. Ver el docblock de BuyerTrackingController::loteDelRequest. */
    const TIPO_BEACON = 'text/plain;charset=UTF-8';

    /** @var \App\User */
    private $comercio;

    /** @var string */
    private $visitor_id;

    /** @var string */
    private $session_id;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable(self::TABLA)) {
            $this->markTestSkipped(
                'Falta la tabla '.self::TABLA.'. Se crea con las migraciones de empresa-api '
                .'(2026_08_15_140000) apuntadas a esta base.'
            );
        }

        BuyerTrackingHelper::olvidarMemoria();

        $this->comercio   = $this->crearComercio();
        $this->visitor_id = (string) Str::uuid();
        $this->session_id = (string) Str::uuid();
    }

    // -------------------------------------------------------------------------------------
    // B — el cuerpo del beacon viene como text/plain
    // -------------------------------------------------------------------------------------

    /**
     * El camino PRINCIPAL de produccion: el lote llega como text/plain con JSON adentro.
     *
     * El SPA manda el beacon con ese content-type a proposito: 'application/json' no esta en la
     * lista segura de CORS, asi que convierte el envio en un request con preflight, y durante
     * pagehide/beforeunload el preflight puede no completarse — el navegador descarta el beacon
     * y se pierde justo el ultimo product_view de cada sesion, que es el caso por el que se
     * eligio sendBeacon. Con text/plain no hay preflight; el costo es que Laravel no parsea el
     * cuerpo y hay que leer el crudo a mano.
     *
     * La primera asercion es la que impide que este test de verde por el motivo equivocado: si
     * Laravel parseara text/plain, el request entraria por el camino de siempre y esto no
     * probaria nada del arreglo.
     */
    public function test_el_cuerpo_en_text_plain_se_parsea_igual_que_el_json()
    {
        $this->prenderLaExtencion();

        $cuerpo = [
            'commerce_id' => $this->comercio->id,
            'events'      => [
                $this->evento('product_view', ['article_id' => 77, 'dwell_ms' => 4200]),
                $this->evento('checkout_complete', ['order_id' => 31, 'amount' => 15400.5]),
            ],
        ];

        /* El escenario se ejercita de verdad: con este content-type Laravel NO parsea el cuerpo. */
        $crudo = Request::create(
            self::RUTA,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => self::TIPO_BEACON],
            json_encode($cuerpo)
        );

        $this->assertNull(
            $crudo->input('events'),
            'si Laravel parseara text/plain, este test no estaria probando el camino nuevo'
        );

        $respuesta = $this->beacon($cuerpo);

        $respuesta->assertStatus(204);

        $filas = $this->filas();
        $this->assertCount(2, $filas, 'el lote del beacon tiene que escribirse igual que el de axios');

        $this->assertSame('product_view', $filas[0]->event_type);
        $this->assertSame(77, (int) $filas[0]->article_id);
        $this->assertSame(4200, (int) $filas[0]->dwell_ms);
        $this->assertSame($this->visitor_id, $filas[0]->visitor_id);
        $this->assertSame((int) $this->comercio->id, (int) $filas[0]->user_id);

        $this->assertSame('checkout_complete', $filas[1]->event_type);
        $this->assertSame(31, (int) $filas[1]->order_id);
        $this->assertSame('15400.50', (string) $filas[1]->amount);
    }

    /**
     * Los dos formatos conviven, porque los dos existen en produccion: el beacon manda
     * text/plain y el fallback por axios sigue mandando application/json. El arreglo de uno no
     * puede haber roto al otro.
     */
    public function test_el_json_de_toda_la_vida_sigue_andando_igual()
    {
        $this->prenderLaExtencion();

        $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'events'      => [$this->evento('search', ['search_term' => 'filtro de aceite', 'results_count' => 3])],
        ])->assertStatus(204);

        $filas = $this->filas();
        $this->assertCount(1, $filas);
        $this->assertSame('filtro de aceite', $filas[0]->search_term);
        $this->assertSame(3, (int) $filas[0]->results_count);
    }

    /**
     * Un cuerpo que no es JSON no puede explotar: lo manda un navegador, y este endpoint promete
     * no devolverle nunca un error al comprador.
     *
     * Se prueban las formas que de verdad aparecen: basura, vacio, un JSON que no es objeto y un
     * objeto sin la clave `events`. Ninguna escribe y ninguna rompe.
     */
    public function test_un_cuerpo_que_no_es_json_valido_se_descarta_sin_explotar()
    {
        $this->prenderLaExtencion();

        $cuerpos = [
            'esto no es json',
            '',
            '{"events": ',          // JSON cortado a la mitad
            '[1,2,3]',              // JSON valido pero no es un objeto
            '"solo un string"',     // JSON valido pero escalar
            'null',
            '{"commerce_id": 1}',   // objeto sin events
        ];

        foreach ($cuerpos as $cuerpo) {
            $respuesta = $this->call(
                'POST',
                self::RUTA,
                [],
                [],
                [],
                ['CONTENT_TYPE' => self::TIPO_BEACON],
                $cuerpo
            );

            $this->assertSame(
                204,
                $respuesta->getStatusCode(),
                'este cuerpo tendria que descartarse en silencio y devolvio otra cosa: '.$cuerpo
            );
        }

        $this->assertCount(0, $this->filas(), 'ninguno de esos cuerpos puede haber escrito nada');
    }

    // -------------------------------------------------------------------------------------
    // C — el techo de amount
    // -------------------------------------------------------------------------------------

    /**
     * Un amount fuera de rango se descarta SOLO A EL y no se lleva puesto el lote.
     *
     * 🔴 Este es el modo de falla mas caro de todos los del helper, porque no se nota: el insert
     * es UNA SOLA sentencia multi-fila, asi que si MySQL la rechaza (`ERROR 1264 Out of range`)
     * se pierden los hasta 50 eventos del lote, no el que estaba mal.
     *
     * Y el bug era justamente que la guarda no lo prevenia: MAX_AMOUNT valia
     * 99999999999999999999.99, un literal que NO entra en un double y que PHP parsea como
     * 1.0E+20 — MAYOR que el tope real de decimal(22,2). O sea que dejaba pasar exactamente el
     * valor que la base rechaza. Por eso el caso de abajo usa 1e20: es el que rompia.
     */
    public function test_un_amount_fuera_de_rango_no_tira_el_lote()
    {
        $this->prenderLaExtencion();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'events'      => [
                $this->evento('checkout_complete', ['order_id' => 1, 'amount' => 1000.0]),
                /* El valor que la guarda vieja dejaba pasar y MySQL rechaza. */
                $this->evento('checkout_complete', ['order_id' => 2, 'amount' => 1.0E+20]),
                /* Y el literal tal cual estaba escrito en la constante vieja. */
                $this->evento('checkout_complete', ['order_id' => 3, 'amount' => 99999999999999999999.99]),
                $this->evento('checkout_complete', ['order_id' => 4, 'amount' => 2000.0]),
            ],
        ]);

        $respuesta->assertStatus(204);

        $filas = $this->filas();

        $this->assertCount(4, $filas, 'ningun evento se descarta entero por tener un amount de mas');
        $this->assertSame('1000.00', (string) $filas[0]->amount);
        $this->assertNull($filas[1]->amount, 'el amount fuera de rango pierde el VALOR, no el evento');
        $this->assertNull($filas[2]->amount);
        $this->assertSame('2000.00', (string) $filas[3]->amount);
    }

    /**
     * El borde exacto del techo nuevo: MAX_AMOUNT entra y un peso mas no.
     *
     * Que entre es la mitad que importa, y no es obvia: si el techo no fuera representable en un
     * double —que es de donde salia el bug— esta asercion se caeria sola.
     */
    public function test_el_amount_justo_en_el_borde_entra_y_el_de_arriba_no()
    {
        $this->prenderLaExtencion();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'events'      => [
                $this->evento('checkout_complete', ['order_id' => 1, 'amount' => BuyerTrackingHelper::MAX_AMOUNT]),
                $this->evento('checkout_complete', ['order_id' => 2, 'amount' => BuyerTrackingHelper::MAX_AMOUNT + 1]),
            ],
        ]);

        $respuesta->assertStatus(204);

        $filas = $this->filas();
        $this->assertCount(2, $filas);
        $this->assertSame('999999999999.99', (string) $filas[0]->amount, 'el borde tiene que entrar tal cual');
        $this->assertNull($filas[1]->amount, 'un peso por encima del techo se descarta');
    }

    /**
     * Fijacion del techo, con el porque escrito en la asercion.
     *
     * 🔴 Es a proposito que sea MUCHO mas chico que el maximo de decimal(22,2), y es lo que
     * alguien va a querer "corregir". El maximo de la columna no es representable en un double
     * (se convierte en 1e20, que la columna rechaza), y ademas un techo bajo le saca combustible
     * al desborde del SUM(amount) del rollup diario de `empresa-api`.
     */
    public function test_el_techo_de_amount_es_representable_y_menor_al_de_la_columna()
    {
        $this->assertSame(999999999999.99, BuyerTrackingHelper::MAX_AMOUNT);

        /* Representable: pasar por string y volver no lo mueve. */
        $this->assertSame(
            BuyerTrackingHelper::MAX_AMOUNT,
            (float) '999999999999.99',
            'el techo tiene que ser exactamente representable en un double'
        );

        /* Y sigue por debajo del maximo real de decimal(22,2), que es lo que la base acepta. */
        $this->assertTrue(
            BuyerTrackingHelper::MAX_AMOUNT < 1.0E+20,
            'el techo no puede volver a ser el maximo de la columna: ese literal se parsea como 1e20'
        );
    }

    // -------------------------------------------------------------------------------------
    // D — el rate limit
    // -------------------------------------------------------------------------------------

    /**
     * La ruta NO corre bajo el limitador compartido de la API.
     *
     * El grupo `api` trae 'throttle:api', que limita por IP (el $request->user() de
     * RouteServiceProvider usa el guard `web`, siempre null para un comprador) y comparte un
     * cubo de 60 por minuto con TODA la API de la tienda: articulos, carrito, categorias,
     * pedidos. Sin la exclusion, un comprador navegando activo —o varios detras de un mismo
     * NAT— se acercan al 429 en los requests que si importan, que es exactamente lo contrario
     * del requisito de no degradar la navegacion.
     *
     * El control con /api/carts es la mitad que hace que esto pruebe algo: demuestra que el
     * limitador compartido sigue puesto en el resto de la API, o sea que lo que se midio fue la
     * exclusion de esta ruta y no que 'throttle:api' hubiera desaparecido de todos lados.
     */
    public function test_la_ruta_de_tracking_no_corre_bajo_el_throttle_compartido()
    {
        $router = app('router');

        $tracking = $router->getRoutes()->match(Request::create(self::RUTA, 'POST'));
        $middlewares = $router->gatherRouteMiddleware($tracking);

        $this->assertNotContains(
            ThrottleRequests::class.':api',
            $middlewares,
            'el tracking no puede compartir el cubo de rate limit con la navegacion del comprador'
        );

        /* Pero sigue acotada: tiene su propio cubo, con su propio presupuesto. */
        $this->assertContains(
            ThrottleRequests::class.':60,1',
            $middlewares,
            'la ruta tiene que conservar su throttle propio: es un endpoint publico de escritura'
        );

        /* Control: el resto de la API sigue con el limitador compartido. */
        $carrito = $router->getRoutes()->match(Request::create('/api/carts', 'POST'));

        $this->assertContains(
            ThrottleRequests::class.':api',
            $router->gatherRouteMiddleware($carrito),
            'la exclusion tiene que ser SOLO de la ruta de tracking'
        );
    }

    // ---------------------------------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------------------------------

    /**
     * Pega al endpoint como lo hace navigator.sendBeacon: cuerpo JSON con content-type
     * text/plain, y sin ningun header que un beacon no pueda mandar.
     *
     * @param array $cuerpo
     * @return \Illuminate\Testing\TestResponse
     */
    private function beacon(array $cuerpo)
    {
        return $this->call(
            'POST',
            self::RUTA,
            [],
            [],
            [],
            ['CONTENT_TYPE' => self::TIPO_BEACON],
            json_encode($cuerpo)
        );
    }

    /**
     * Comercio dueño de la instancia.
     *
     * @return \App\User
     */
    private function crearComercio()
    {
        return User::create([
            'name'     => 'Comercio de prueba transporte',
            'email'    => 'transporte-'.Str::random(10).'@test.local',
            'password' => bcrypt('secreto'),
            'status'   => 'commerce',
        ]);
    }

    /**
     * Prende la extension `tracking_buyers` para el comercio del caso.
     *
     * @return void
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
