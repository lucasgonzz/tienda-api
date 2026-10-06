<?php

namespace Tests\Feature\CatalogoPorLista;

use App\Article;
use App\Buyer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (h) 🔴 EL INVARIANTE DE COSTO del catalogo por lista (mision catalogo-por-lista-tienda,
 * 5/10/2026).
 *
 * ── Por que esta clase existe ────────────────────────────────────────────────────────────────
 *
 * Hoy NINGUN cliente tiene una lista restringida, y el enganche vive en `checkOnline()`, que esta en
 * el camino de todos los listados de todas las tiendas. O sea que el costo de esta funcionalidad no
 * lo paga el que la usa: lo paga el que no. La primera tentacion —poner la guarda de esquema primero,
 * "por las dudas"— le habria costado dos consultas a information_schema a cada request de cada
 * tienda para descubrir que no habia nada que hacer.
 *
 * Lo que se fija, con un comercio que tiene listas y ninguna restringida:
 *
 *   1. Ninguna consulta nombra `visible_en_tienda`: la consulta del listado es la de antes.
 *   2. Ninguna consulta va a information_schema por las columnas del catalogo: la guarda de esquema
 *      solo se pregunta cuando una lista YA dijo que es restringida.
 *   3. La consulta de las listas del comercio corre UNA vez por request, aunque `checkPriceTypes()`
 *      se llame varias veces (la home la llama hasta cinco). Antes de esta mision corria una vez por
 *      llamada; ahora el catalogo y el precio comparten la misma eleccion memoizada. Es la prueba de
 *      que el catalogo no suma esa consulta: la que necesita es la que el precio ya hacia.
 *   4. Para el comprador con lista propia (cliente del ERP), esa consulta ni siquiera corre: la lista
 *      sale de las relaciones del comprador, que `checkPriceTypes()` ya cargaba.
 *
 * 🔴 Y la contracara va en la misma clase: un test que prueba que algo NO aparece se vuelve vacuo en
 * silencio el dia que el marcador deja de matchear. Con la lista restringida, los mismos marcadores
 * TIENEN que encenderse.
 *
 * ── 🔴 Pero NO es "cero queries de mas": lo que cuesta de verdad (hallazgo B3 de la revision) ─────
 *
 * La version anterior de este docblock decia que solo los endpoints sin `checkPriceTypes()` suman "+1" y
 * que el camino caliente no suma nada. Era corto. Medido el 5/10/2026 contra `origin/master`, sobre la
 * misma base, con un comercio de dos listas y NINGUNA restringida (consultas totales por request,
 * master -> rama):
 *
 *   - Listados que ya resolvian la lista para el precio: sin cambio (busqueda 26 -> 26, categoria 23 -> 23,
 *     ficha 19 -> 19, similares 26 -> 26, carrito POST 52 -> 52). La home BAJA una por cada coleccion con
 *     articulos mas alla de la primera (70 -> 69 con dos colecciones).
 *   - Endpoints que NUNCA resolvian una lista (nombres, marcas, categorias, subcategorias y seleccion
 *     especial: las requests de arranque del SPA): visitante +1 (nombres 8 -> 9, marcas 5 -> 6, categorias
 *     2 -> 3, subcategorias 1 -> 2, seleccion 39 -> 40); logueado sin cliente +2 (la sesion `buyers` y la
 *     consulta de las listas); logueado con cliente con lista +3 (`buyers`, `clients` y `price_types`).
 *   - Con la extension de rangos por cantidad vendida (el caso 1 de `checkPriceTypes()` no mira la lista
 *     del comprador): home 92 -> 93 (visitante), 90 -> 91 (sin cliente), 104 -> 104 (con cliente);
 *     busqueda 38 -> 38, 36 -> 37 y 42 -> 44.
 *   - El visitante de una tienda que exige registro para ver precios (`register_to_buy`): +1 en todo
 *     listado (home 70 -> 71, busqueda 26 -> 27).
 *
 * No hay forma simple y segura de evitarlas: hace falta la lista del comprador para armar el SQL del
 * listado, y saber si el comercio tiene alguna lista restringida sin leer las listas pide otra consulta
 * sobre una columna que puede no existir (la guarda de esquema que justamente se evita) o cambiar la
 * consulta de las listas, que `checkPriceTypes()` comparte y cuyo desempate por `position` es parte del
 * precio que ve cada comprador.
 *
 * ── Que fija esta clase, y que NO (N5 de la revision de cierre) ────────────────────────────────────
 *
 * FIJA los totales de consultas de los cinco endpoints de arranque (nombres, marcas, categorias,
 * subcategorias y seleccion especial) para los tres perfiles: visitante 9, 6, 3, 2 y 40; logueado sin
 * cliente 10, 7, 4, 3 y 41; logueado con cliente con lista 11, 8, 5, 4 y 42. Fija tambien que haya UNA
 * consulta de las listas del comercio en la home del visitante (a lo sumo una por request en la busqueda y
 * el carrito) y exactamente una en la home y la busqueda con la extension de rangos y con `register_to_buy`,
 * que la busqueda del logueado con cliente y rangos cargue `clients`, que la seleccion especial de un
 * comprador restringido haga una sola consulta del pivote, y que sin lista restringida ninguna consulta
 * nombre el pivote ni vaya a information_schema.
 *
 * NO FIJA los totales de los listados que no cambian (busqueda 26, categoria 23, ficha 19, similares 26,
 * carrito 52), ni la baja de la home (70 -> 69: solo exige que la consulta de las listas corra una vez), ni
 * los totales con la extension de rangos (home 92 -> 93, 90 -> 91, 104 -> 104; busqueda 38 -> 38, 36 -> 37,
 * 42 -> 44) ni con `register_to_buy` (home 70 -> 71, busqueda 26 -> 27): eso es medicion documentada arriba,
 * no un numero que un test defienda. El SEO y el carrito con rangos tampoco estan medidos.
 */
class CostoDelCatalogoPorListaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCatalogoPorLista;

    /** La consulta de las listas del comercio, tal cual la compila `eleccion_de_lista()`. */
    const CONSULTA_DE_LAS_LISTAS = 'select * from `price_types` where `user_id` = ? and `position` is not null order by `position` desc';

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();
        $this->armarFerretotal();

        /* El escenario de esta clase es "nadie restringe": se apaga el interruptor de la Mayorista
           del escenario estandar. Los casos de contracara lo vuelven a prender. */
        DB::table('price_types')->where('id', $this->mayorista->id)->update(['catalogo_restringido_en_tienda' => null]);

        /* Novedades e in_offer suman llamadas a checkPriceTypes() en la home: con un articulo en
           oferta hay una coleccion no vacia mas que comparte la eleccion. */
        Article::where('id', $this->habilitado->id)->update(['in_offer' => 1]);
    }

    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * 🔴 EL CASO DE LA CLASE: la home del visitante sin listas restringidas no nombra las columnas,
     * no pregunta por el esquema y consulta las listas una sola vez.
     */
    public function test_sin_lista_restringida_la_home_del_visitante_no_suma_consultas()
    {
        $this->comoVisitante();

        $queries = $this->queriesDurante(function () {
            $this->home()->assertStatus(200);
        });

        $this->assertSame([], $this->queNombranElPivote($queries),
            'sin lista restringida ninguna consulta puede nombrar visible_en_tienda');

        $this->assertSame([], $this->alEsquemaDelCatalogo($queries),
            'sin lista restringida no se pregunta por el esquema del catalogo');

        $this->assertCount(1, $this->consultasDeLasListas($queries),
            'la eleccion de lista se hace UNA vez por request y la comparten el catalogo y los precios');
    }

    /** Lo mismo en la busqueda y en el carrito, los otros dos caminos calientes. */
    public function test_sin_lista_restringida_la_busqueda_y_el_carrito_tampoco()
    {
        $this->comoVisitante();

        $queries = $this->queriesDurante(function () {
            $this->json('GET', '/api/articles/search/Catalogo/'.$this->comercio->id)->assertStatus(200);
            $this->postJson('/api/carts', [
                'commerce_id' => $this->comercio->id,
                'cart'        => [
                    'articles'             => [[
                        'id' => $this->sin_marcar->id, 'user_id' => $this->comercio->id, 'name' => $this->sin_marcar->name,
                        'final_price' => 2500, 'cost' => null, 'amount' => 1,
                        'pivot' => ['amount' => 1, 'notes' => null, 'variant_id' => null],
                    ]],
                    'promociones_vinoteca' => [],
                ],
            ])->assertStatus(201);
        });

        $this->assertSame([], $this->queNombranElPivote($queries));
        $this->assertSame([], $this->alEsquemaDelCatalogo($queries));
        $this->assertLessThanOrEqual(2, count($this->consultasDeLasListas($queries)),
            'a lo sumo una por request (son dos requests)');
    }

    /**
     * El comprador con lista propia: la eleccion sale de las relaciones del comprador, que
     * `checkPriceTypes()` ya cargaba para su caso 3. La consulta de las listas del comercio no corre.
     */
    public function test_el_comprador_con_lista_propia_no_consulta_las_listas_del_comercio()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $queries = $this->queriesDurante(function () {
            $this->home()->assertStatus(200);
        });

        $this->assertSame([], $this->consultasDeLasListas($queries));
        $this->assertSame([], $this->queNombranElPivote($queries));
        $this->assertSame([], $this->alEsquemaDelCatalogo($queries));
    }

    /**
     * 🔴 LA CONTRACARA. Con la lista restringida los marcadores TIENEN que encenderse; si alguno deja
     * de matchear (un backtick, un `select *` que Laravel compile distinto), se pone rojo acá y no en
     * silencio arriba. Y la guarda de esquema se paga UNA vez por request, no una por listado.
     */
    public function test_con_lista_restringida_los_marcadores_se_encienden()
    {
        $this->restringirLista($this->mayorista);

        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $queries = $this->queriesDurante(function () {
            $this->home()->assertStatus(200);
        });

        $this->assertNotEmpty($this->queNombranElPivote($queries),
            'el marcador del pivote dejo de matchear: el caso de costo quedo vacuo');

        $esquema = $this->alEsquemaDelCatalogo($queries);
        $this->assertNotEmpty($esquema,
            'el marcador del esquema dejo de matchear: el caso de costo quedo vacuo');
        $this->assertLessThanOrEqual(2, count($esquema),
            'la guarda (dos columnas) se pregunta una vez por request: '.implode(' | ', $esquema));
    }

    /** Y con el visitante y la lista publica restringida, la consulta de las listas sigue siendo una. */
    public function test_con_la_lista_publica_restringida_la_eleccion_sigue_siendo_una()
    {
        $this->restringirLista($this->minorista);

        $this->comoVisitante();

        $queries = $this->queriesDurante(function () {
            $this->home()->assertStatus(200);
        });

        $this->assertCount(1, $this->consultasDeLasListas($queries));
        $this->assertNotEmpty($this->queNombranElPivote($queries));
    }

    /**
     * 🔴 La seleccion especial chequea la lista con UNA sola consulta para toda la seleccion, y no una por
     * articulo (hallazgo B7 de la revision independiente). Antes el comprador restringido pagaba una
     * consulta de `visible_en_tienda` por cada id de la URL, con el `withAll()` ya cargado; ahora se
     * cargan los articulos y se decide una vez (por comercio: una seleccion rara que mezcle comercios
     * paga una por comercio, y la comun es de uno solo).
     *
     * El resultado no cambia (lo no habilitado queda `null` en su lugar), y sin lista restringida no se
     * nombra el pivote.
     */
    public function test_la_seleccion_especial_chequea_la_lista_con_una_sola_consulta()
    {
        $ids = $this->habilitado->id.'-'.$this->sin_marcar->id.'-'.$this->deshabilitado->id.'-'.$this->habilitado->id;

        /* Sin lista restringida: no se nombra el pivote. */
        $this->comoVisitante();

        $queries = $this->queriesDurante(function () use ($ids) {
            $this->json('GET', '/api/articles-seleccion-especial/'.$ids)->assertStatus(200);
        });

        $this->assertSame([], $this->queNombranElPivote($queries));

        /* Con lista restringida: una sola, aunque la seleccion tenga cuatro ids. */
        $this->restringirLista($this->mayorista);
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $modelos = [];

        $queries = $this->queriesDurante(function () use ($ids, &$modelos) {
            $modelos = $this->json('GET', '/api/articles-seleccion-especial/'.$ids)->assertStatus(200)->json('models');
        });

        $this->assertCount(1, $this->queNombranElPivote($queries),
            'una sola consulta de la lista para los cuatro ids: '.implode(' | ', $this->queNombranElPivote($queries)));

        $this->assertCount(4, $modelos);
        $this->assertSame($this->habilitado->id, (int) $modelos[0]['id']);
        $this->assertNull($modelos[1], 'sin_marcar');
        $this->assertNull($modelos[2], 'deshabilitado');
        $this->assertSame($this->habilitado->id, (int) $modelos[3]['id'], 'el repetido se conserva, como siempre');
    }

    /**
     * 🔴 EL COSTO MEDIDO de los endpoints que antes NO resolvian ninguna lista (B3): el visitante paga
     * UNA consulta de las listas del comercio (master: ninguna), y nada mas. Las cifras son las del
     * docblock de la clase: nombres 8 -> 9, marcas 5 -> 6, categorias 2 -> 3, subcategorias 1 -> 2,
     * seleccion 39 -> 40.
     */
    public function test_el_visitante_paga_una_consulta_de_listas_en_los_endpoints_que_antes_no_la_hacian()
    {
        $this->comoVisitante();

        $totales = $this->medirLosEndpointsDeArranque(1, 0, 0);

        $this->assertSame(
            ['nombres' => 9, 'marcas' => 6, 'categorias' => 3, 'subcategorias' => 2, 'seleccion' => 40],
            $totales
        );
    }

    /**
     * El logueado SIN cliente del ERP paga +2: la sesion (`buyers`) y la consulta de las listas del
     * comercio. Cada request arranca sin el comprador en memoria, como en produccion.
     */
    public function test_el_logueado_sin_cliente_paga_la_sesion_y_la_consulta_de_listas()
    {
        $this->abrirLaSesionDe($this->compradorSinCliente($this->comercio));

        $totales = $this->medirLosEndpointsDeArranque(1, 1, 0);

        $this->assertSame(
            ['nombres' => 10, 'marcas' => 7, 'categorias' => 4, 'subcategorias' => 3, 'seleccion' => 41],
            $totales
        );
    }

    /**
     * El logueado CON cliente con lista paga +3: la sesion (`buyers`), su cliente (`clients`) y su lista
     * (`price_types`). La consulta de las listas del comercio no corre: la lista sale de su cliente.
     */
    public function test_el_logueado_con_cliente_con_lista_paga_la_sesion_el_cliente_y_su_lista()
    {
        $this->abrirLaSesionDe($this->compradorConLista($this->comercio, $this->mayorista->id));

        $totales = $this->medirLosEndpointsDeArranque(0, 1, 1);

        $this->assertSame(
            ['nombres' => 11, 'marcas' => 8, 'categorias' => 5, 'subcategorias' => 4, 'seleccion' => 42],
            $totales
        );
    }

    /**
     * Con la extension de rangos por cantidad vendida master NO resolvia la lista en los listados (el
     * caso 1 de `checkPriceTypes()` no la mira): ahora la home y la busqueda del visitante pagan UNA
     * consulta de las listas, y nada mas (ni el pivote ni la guarda de esquema).
     */
    public function test_con_la_extension_de_rangos_los_listados_pagan_una_consulta_de_listas()
    {
        $this->activarExtensionDeRangos();

        $this->comoVisitante();

        foreach (['home' => '/api/articles/featured-last-uploads/'.$this->comercio->id.'?page=1',
                  'busqueda' => '/api/articles/search/Catalogo/'.$this->comercio->id] as $nombre => $uri) {

            $queries = $this->queriesDurante(function () use ($uri) {
                $this->pedirComoEnProduccion($uri);
            });

            $this->assertCount(1, $this->consultasDeLasListas($queries), $nombre.': una consulta de las listas');
            $this->assertSame([], $this->queNombranElPivote($queries), $nombre);
            $this->assertSame([], $this->alEsquemaDelCatalogo($queries), $nombre);
        }

        /* El logueado con cliente con lista: la lista sale de su cliente (+ `clients`, que el caso 1 no
           cargaba), y la consulta de las listas del comercio no corre. */
        $this->abrirLaSesionDe($this->compradorConLista($this->comercio, $this->mayorista->id));

        $queries = $this->queriesDurante(function () {
            $this->pedirComoEnProduccion('/api/articles/search/Catalogo/'.$this->comercio->id);
        });

        $this->assertSame([], $this->consultasDeLasListas($queries));
        $this->assertCount(1, $this->consultasDeLosClientes($queries), 'carga su cliente para saber su lista');
        $this->assertSame([], $this->queNombranElPivote($queries));
    }

    /**
     * El visitante de una tienda que exige registro para ver precios (`register_to_buy` con
     * `only_registered`) tampoco recibe precios, pero el catalogo sigue a la lista: ahora paga UNA
     * consulta de las listas por listado (master: ninguna, porque le escondia los precios antes de
     * resolver nada).
     */
    public function test_el_visitante_sin_precios_paga_una_consulta_de_listas()
    {
        $this->exigirRegistroParaVerPrecios();

        $this->comoVisitante();

        foreach (['home' => '/api/articles/featured-last-uploads/'.$this->comercio->id.'?page=1',
                  'busqueda' => '/api/articles/search/Catalogo/'.$this->comercio->id] as $nombre => $uri) {

            $queries = $this->queriesDurante(function () use ($uri) {
                $respuesta = $this->pedirComoEnProduccion($uri);

                /* El caso no es vacuo: de verdad no viajan precios. */
                $articulos = $respuesta->json('articles.data');
                $this->assertNotEmpty($articulos);
                $this->assertNull($articulos[0]['final_price'], 'el visitante no recibe precios');
            });

            $this->assertCount(1, $this->consultasDeLasListas($queries), $nombre);
            $this->assertSame([], $this->queNombranElPivote($queries), $nombre);
        }
    }

    /**
     * Pide los endpoints de arranque del SPA, uno por uno, y comprueba lo que cuesta la eleccion de
     * lista en cada uno: cuantas consultas de las listas del comercio, de la sesion (`buyers`) y de
     * `clients`, y que NINGUNA nombre el pivote ni vaya a information_schema.
     *
     * @param  int  $listas  Consultas de las listas del comercio esperadas por request.
     * @param  int  $sesion  Consultas de la sesion (`buyers`) esperadas por request.
     * @param  int  $clientes  Consultas de `clients` esperadas por request.
     * @return array<string, int>  Las consultas totales de cada endpoint, para fijarlas.
     */
    private function medirLosEndpointsDeArranque($listas, $sesion, $clientes)
    {
        $c = $this->comercio->id;

        $endpoints = [
            'nombres'       => '/api/articles/names/'.$c,
            'marcas'        => '/api/brands/'.$c,
            'categorias'    => '/api/categories/'.$c,
            'subcategorias' => '/api/sub-categories/'.$this->herramientas->id,
            'seleccion'     => '/api/articles-seleccion-especial/'.$this->habilitado->id.'-'.$this->sin_marcar->id.'-'.$this->deshabilitado->id,
        ];

        $totales = [];

        foreach ($endpoints as $nombre => $uri) {
            $queries = $this->queriesDurante(function () use ($uri) {
                $this->pedirComoEnProduccion($uri);
            });

            $this->assertCount($listas, $this->consultasDeLasListas($queries), $nombre.': consultas de las listas del comercio');
            $this->assertCount($sesion, $this->consultasDeLaSesion($queries), $nombre.': consultas de la sesion');
            $this->assertCount($clientes, $this->consultasDeLosClientes($queries), $nombre.': consultas de clients');
            $this->assertSame([], $this->queNombranElPivote($queries), $nombre);
            $this->assertSame([], $this->alEsquemaDelCatalogo($queries), $nombre);

            $totales[$nombre] = count($queries);
        }

        return $totales;
    }

    /**
     * Abre la sesion del comprador como en produccion: el guard guarda el id y lo lee de la base en cada
     * request (`pedirComoEnProduccion()` le suelta el usuario antes de cada uno). `actingAs()` deja la
     * MISMA instancia con sus relaciones cacheadas y escondia justo lo que se mide.
     *
     * @param  \App\Buyer  $buyer
     * @return void
     */
    private function abrirLaSesionDe(Buyer $buyer)
    {
        $this->withSession([$this->app['auth']->guard('buyer')->getName() => $buyer->id]);
    }

    /**
     * Un GET que arranca sin el comprador en memoria, como un request nuevo.
     *
     * @param  string  $uri
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedirComoEnProduccion($uri)
    {
        $this->app['auth']->guard('buyer')->forgetUser();

        return $this->json('GET', $uri)->assertStatus(200);
    }

    /** @return \Illuminate\Testing\TestResponse */
    private function home()
    {
        return $this->json('GET', '/api/articles/featured-last-uploads/'.$this->comercio->id.'?page=1');
    }

    /**
     * @param  array  $queries
     * @return array
     */
    private function queNombranElPivote($queries)
    {
        return array_values(array_filter($queries, function ($sql) {
            return strpos($sql, 'visible_en_tienda') !== false;
        }));
    }

    /**
     * Las consultas a information_schema por las tablas del catalogo. En este Laravel `hasColumn()`
     * compila con el nombre de la tabla adentro del SQL, entre comillas simples.
     *
     * @param  array  $queries
     * @return array
     */
    private function alEsquemaDelCatalogo($queries)
    {
        return array_values(array_filter($queries, function ($sql) {
            return strpos($sql, 'information_schema.columns') !== false
                && (strpos($sql, "'price_types'") !== false || strpos($sql, "'article_price_type'") !== false);
        }));
    }

    /**
     * La lectura del comprador de la sesion (`SessionGuard::retrieveById`).
     *
     * @param  array  $queries
     * @return array
     */
    private function consultasDeLaSesion($queries)
    {
        return array_values(array_filter($queries, function ($sql) {
            return preg_match('/^select \* from `buyers` where (`buyers`\.)?`id` = \?/', $sql) === 1;
        }));
    }

    /**
     * La lectura del cliente del ERP del comprador (`Buyer::comercio_city_client`).
     *
     * @param  array  $queries
     * @return array
     */
    private function consultasDeLosClientes($queries)
    {
        return array_values(array_filter($queries, function ($sql) {
            return preg_match('/^select \* from `clients` where (`clients`\.)?`id` = \?/', $sql) === 1;
        }));
    }

    /**
     * @param  array  $queries
     * @return array
     */
    private function consultasDeLasListas($queries)
    {
        return array_values(array_filter($queries, function ($sql) {
            return $sql === self::CONSULTA_DE_LAS_LISTAS;
        }));
    }

    /**
     * Todas las queries que dispara la accion. El listener lleva su propio interruptor porque en
     * Laravel no se puede desregistrar. Molde textual de `CombosYRangos\CostoDeLaGuardaDeTramosTest`.
     *
     * @param  callable  $accion
     * @return array
     */
    private function queriesDurante(callable $accion)
    {
        $queries  = [];
        $midiendo = true;

        DB::listen(function ($query) use (&$queries, &$midiendo) {
            if ($midiendo) {
                $queries[] = $query->sql;
            }
        });

        $accion();

        $midiendo = false;

        return $queries;
    }
}
