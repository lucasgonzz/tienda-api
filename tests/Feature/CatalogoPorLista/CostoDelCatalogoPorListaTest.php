<?php

namespace Tests\Feature\CatalogoPorLista;

use App\Article;
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
 * Numeros medidos el 5/10/2026 sobre esta base (queries totales por request, visitante, comercio con
 * dos listas sin restringir y tres articulos): home pagina 1, master 87 -> rama 85; busqueda 26 -> 26;
 * categoria 23 -> 23; ficha 19 -> 19; carrito POST 53 -> 53. Los endpoints que no pasan por
 * `checkPriceTypes()` (nombres, categorias, subcategorias, marcas, una pagina vacia) suman la
 * consulta de la lista: +1. Estan en el informe de la mision.
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
