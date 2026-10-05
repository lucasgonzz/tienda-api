<?php

namespace Tests\Feature\CatalogoPorLista;

use App\Cart;
use App\Http\Controllers\Helpers\CatalogoPorListaHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * (g) El contrato con `empresa-api`: la tienda NUEVA contra una base que todavia no tiene las dos
 * columnas del catalogo por lista (mision catalogo-por-lista-tienda, 5/10/2026). Fila 1 de la tabla
 * C4 del plan.
 *
 * 🔴 Es el escenario NORMAL, no el borde: las columnas llegan a la base de un cliente con el release
 * del ERP, y la tienda la despliega Lucas aparte. Sin guarda, el `whereExists` sobre
 * `visible_en_tienda` no seria "un filtro que no filtra": seria "Unknown column" en el camino de
 * TODOS los listados, o sea la tienda entera en 500.
 *
 * Lo que se fija, con el esquema escondido: todo responde 200 y SIN FILTRAR (el mayorista ve lo que
 * ve hoy, el catalogo completo), en los listados, la ficha, las categorias, las marcas y el carrito.
 * Y los dos esquemas a medias: sin la columna de la lista, y sin la del pivote (la que de verdad
 * reventaria).
 *
 * ── Por que esta clase NO usa DatabaseTransactions ────────────────────────────────────────────
 *
 * El unico modo honesto de probar "la columna no esta" es que no este: `ALTER TABLE ... RENAME
 * COLUMN`, que es DDL, y MySQL le hace commit implicito a la transaccion abierta. Asi que los datos
 * se crean afuera de toda transaccion y se borran a mano, por ids EXACTOS del comercio creado.
 * Molde: `CombosYRangos\SinEsquemaDeCombosYRangosTest`.
 *
 * 🔴 El restore va en el `finally`, en el `tearDown` Y en el `setUp` (por si una corrida anterior
 * murio a mitad). Esta base la comparten TODOS los tests del slot: si una columna quedara renombrada,
 * se llevaria puesta la suite entera — y con un error que no nombra la causa.
 */
class SinEsquemaDeCatalogoPorListaTest extends TestCase
{
    use ArmaCatalogoPorLista;

    /** Sufijo con el que se esconden las columnas. */
    const SUFIJO = '_escondida_test';

    /** @var array Ids de los comercios creados FUERA de transaccion, para borrarlos a mano. */
    private $comercios_creados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercios_creados = [];

        /* Por si una corrida anterior murio con el esquema escondido. */
        $this->restaurarElEsquema();

        $this->olvidarLasMemorias();

        $this->assertTrue(CatalogoPorListaHelper::hay_columnas(),
            'La base del slot tiene que ARRANCAR con las dos columnas del catalogo por lista (las crea empresa-api).');
    }

    /**
     * Primero el esquema —sin condiciones, porque una columna escondida se lleva puesta la suite— y
     * despues los datos.
     */
    protected function tearDown(): void
    {
        $this->restaurarElEsquema();
        $this->limpiarLoCreado();
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * 🔴 Sin las dos columnas, la tienda entera responde y el mayorista ve el catalogo completo, como
     * hoy. Con contraprueba: CON las columnas, el mismo comprador ve solo el habilitado (si no, el
     * caso daria verde contra un helper que no filtrara nunca).
     */
    public function test_sin_las_columnas_todo_responde_200_y_sin_filtrar()
    {
        $this->armarFerretotalCreado();

        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $this->assertSame([$this->habilitado->id], $this->idsDeLaHome(),
            'el escenario arranca filtrando: si no, el caso seria vacuo');

        $this->esconderColumnaDeLaLista();
        $this->esconderColumnaDelPivote();

        try {
            $this->olvidarLasMemorias();

            $this->assertFalse(CatalogoPorListaHelper::hay_columnas(), 'con las columnas escondidas la guarda da false');

            $this->assertTodoRespondeSinFiltrar();

            /* La red del pedido tampoco puede nombrar la columna: sin esquema no hay nada que
               restringir y no lee ni las lineas. */
            $cart = Cart::create(['user_id' => $this->comercio->id, 'buyer_id' => null, 'total' => 0]);
            DB::table('article_cart')->insert([
                'cart_id' => $cart->id, 'article_id' => $this->sin_marcar->id, 'amount' => 1,
                'price' => 2000, 'cost' => 0, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ]);
            $this->assertSame([], CatalogoPorListaHelper::no_visibles_del_carrito($cart));
        } finally {
            $this->restaurarElEsquema();
            $this->limpiarLoCreado();
        }

        $this->assertTrue(CatalogoPorListaHelper::hay_columnas(), 'la base volvio a tener las dos columnas');
    }

    /**
     * 🔴 El esquema A MEDIO APLICAR que de verdad reventaria: la lista dice "restringida" pero el
     * pivote no tiene la columna. `hay_columnas()` exige las dos y la tienda no filtra ni tira.
     */
    public function test_con_la_lista_restringida_pero_sin_la_columna_del_pivote_no_filtra_ni_tira()
    {
        $this->armarFerretotalCreado();

        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $this->esconderColumnaDelPivote();

        try {
            $this->olvidarLasMemorias();

            $this->assertTrue(Schema::hasColumn('price_types', CatalogoPorListaHelper::COLUMNA_LISTA),
                'el escenario es CON la columna de la lista (y la lista restringida)');
            $this->assertFalse(CatalogoPorListaHelper::hay_columnas());

            $this->assertTodoRespondeSinFiltrar();
        } finally {
            $this->restaurarElEsquema();
            $this->limpiarLoCreado();
        }
    }

    /**
     * Y al reves: sin la columna de la lista, ninguna lista es restringida (el atributo que no existe
     * se lee null), asi que ni se llega a preguntar por el pivote.
     */
    public function test_sin_la_columna_de_la_lista_no_filtra_ni_tira()
    {
        $this->armarFerretotalCreado();

        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $this->esconderColumnaDeLaLista();

        try {
            $this->olvidarLasMemorias();

            $this->assertTrue(Schema::hasColumn('article_price_type', CatalogoPorListaHelper::COLUMNA_PIVOTE),
                'el escenario es CON la columna del pivote');
            $this->assertNull(CatalogoPorListaHelper::lista_restringida($this->comercio->id));

            $this->assertTodoRespondeSinFiltrar();
        } finally {
            $this->restaurarElEsquema();
            $this->limpiarLoCreado();
        }
    }

    /**
     * 🔴 EL ULTIMO CASO, Y NO ES DECORATIVO: la base quedo exactamente como estaba. Si un caso de
     * arriba dejara una columna renombrada, la suite entera se cae despues; este lo denuncia acá.
     */
    public function test_la_base_quedo_como_estaba()
    {
        $this->assertTrue(Schema::hasColumn('price_types', CatalogoPorListaHelper::COLUMNA_LISTA));
        $this->assertFalse(Schema::hasColumn('price_types', CatalogoPorListaHelper::COLUMNA_LISTA.self::SUFIJO));

        $this->assertTrue(Schema::hasColumn('article_price_type', CatalogoPorListaHelper::COLUMNA_PIVOTE));
        $this->assertFalse(Schema::hasColumn('article_price_type', CatalogoPorListaHelper::COLUMNA_PIVOTE.self::SUFIJO));

        $this->olvidarLasMemorias();

        $this->assertTrue(CatalogoPorListaHelper::hay_columnas());
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Lo que se recorre con el esquema escondido
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Los caminos del catalogo, todos con 200 y sin filtrar para el mayorista.
     *
     * @return void
     */
    private function assertTodoRespondeSinFiltrar()
    {
        $c = $this->comercio->id;

        $this->assertSame($this->losTres(), $this->idsDeLaHome(), 'home');

        $this->assertSame($this->losTres(), $this->idsDe(
            $this->json('GET', '/api/articles/search/Catalogo/'.$c)->assertStatus(200)->json('articles.data')
        ), 'busqueda');

        $this->assertSame($this->losTres(), $this->idsDe(
            $this->json('GET', '/api/articles/names/'.$c)->assertStatus(200)->json('articles_names')
        ), 'nombres');

        $this->assertSame($this->sin_marcar->id, (int) $this->json('GET', '/api/articles/'.$this->sin_marcar->slug.'/'.$c)
            ->assertStatus(200)->json('article.id'), 'ficha');

        $categorias = $this->json('GET', '/api/categories/'.$c)->assertStatus(200)->json('categories');
        $this->assertCount(2, $categorias, 'categorias: las dos, como hoy');

        $this->json('GET', '/api/sub-categories/'.$this->herramientas->id)->assertStatus(200)->assertJsonCount(2, 'sub_categories');

        $this->json('GET', '/api/brands/'.$c)->assertStatus(200)->assertJsonCount(2, 'brands');

        $this->json('GET', '/api/articles-seleccion-especial/'.$this->sin_marcar->id)->assertStatus(200)
            ->assertJsonPath('models.0.id', $this->sin_marcar->id);

        $carrito = $this->postJson('/api/carts', [
            'commerce_id' => $c,
            'cart'        => [
                'articles'             => [[
                    'id' => $this->sin_marcar->id, 'user_id' => $c, 'name' => $this->sin_marcar->name,
                    'final_price' => 2000, 'cost' => null, 'amount' => 1,
                    'pivot' => ['amount' => 1, 'notes' => null, 'variant_id' => null],
                ]],
                'promociones_vinoteca' => [],
            ],
        ]);

        $carrito->assertStatus(201);
        $this->assertSame(['cart'], array_keys($carrito->json()), 'carrito: sin descartes ni clave nueva');
        $this->assertSame([$this->sin_marcar->id], $this->idsDe($carrito->json('cart.articles')));
    }

    /** @return array */
    private function idsDeLaHome()
    {
        return $this->idsDe($this->json('GET', '/api/articles/featured-last-uploads/'.$this->comercio->id.'?page=1')
                    ->assertStatus(200)->json('articles.data'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Datos fuera de transaccion
    |---------------------------------------------------------------------------------------------
    */

    /**
     * El escenario estandar del trait, anotando el comercio para borrar todo lo suyo despues. Se arma
     * ANTES de esconder nada: las filas del pivote nombran `visible_en_tienda`.
     *
     * @return void
     */
    private function armarFerretotalCreado()
    {
        $this->armarFerretotal();

        $this->comercios_creados[] = $this->comercio->id;
    }

    /**
     * Borra lo que esta clase escribio, por los ids EXACTOS de los comercios que creo — nunca por un
     * `like` sobre los mails de las fixtures, que matchearia lo de otras clases. Idempotente: se llama
     * en el `finally` Y en el `tearDown`.
     *
     * @return void
     */
    private function limpiarLoCreado()
    {
        if (empty($this->comercios_creados)) {
            return;
        }

        $comercios = $this->comercios_creados;

        /* ⚠️ El MySQL local de wamp tiene `table_definition_cache = 600` contra cientos de tablas por
           base y decenas de bases de testing, y despues de los RENAME COLUMN de esta clase un
           prepared statement puede caer con `1615 Prepared statement needs to be re-prepared` (le
           pasa igual al molde, `SinEsquemaDeCombosYRangosTest`, en la linea base). Es del entorno, no
           del codigo — pero si la limpieza muere a mitad, los datos quedan en la base que comparten
           TODOS los tests del slot. Por eso, y SOLO ante ese codigo, la limpieza se reintenta: es
           idempotente (borra por los ids de los comercios creados) y un reintento prepara de nuevo. */
        $this->reintentandoSi1615(function () use ($comercios) {
            $this->borrarLoDeLosComercios($comercios);
        });

        $this->comercios_creados = [];
    }

    /**
     * Corre el borrado y lo reintenta (hasta tres veces) SOLO si MySQL contesta 1615. Cualquier otro
     * error sube tal cual.
     *
     * @param  callable  $borrado
     * @return void
     */
    private function reintentandoSi1615(callable $borrado)
    {
        for ($intento = 1; ; $intento++) {
            try {
                $borrado();

                return;
            } catch (\Illuminate\Database\QueryException $e) {
                $codigo = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;

                if ($codigo !== 1615 || $intento >= 3) {
                    throw $e;
                }
            }
        }
    }

    /**
     * El borrado en si, por los ids exactos de los comercios. Ver `limpiarLoCreado()`.
     *
     * @param  array  $comercios
     * @return void
     */
    private function borrarLoDeLosComercios(array $comercios)
    {

        $carritos = DB::table('carts')->whereIn('user_id', $comercios)->pluck('id')->all();

        if (!empty($carritos)) {
            DB::table('article_cart')->whereIn('cart_id', $carritos)->delete();
            DB::table('cart_promocion_vinoteca')->whereIn('cart_id', $carritos)->delete();
            DB::table('carts')->whereIn('id', $carritos)->delete();
        }

        $articulos = DB::table('articles')->whereIn('user_id', $comercios)->pluck('id')->all();

        if (!empty($articulos)) {
            DB::table('article_price_type')->whereIn('article_id', $articulos)->delete();
            DB::table('articles')->whereIn('id', $articulos)->delete();
        }

        $clientes = DB::table('clients')->whereIn('user_id', $comercios)->pluck('id')->all();

        $compradores = DB::table('buyers')
                            ->where(function ($query) use ($comercios, $clientes) {
                                $query->whereIn('user_id', $comercios);

                                if (!empty($clientes)) {
                                    $query->orWhereIn('comercio_city_client_id', $clientes);
                                }
                            })
                            ->pluck('id')
                            ->all();

        if (!empty($compradores)) {
            /* La busqueda de un comprador logueado guarda su texto en `last_searches`, con FK a
               `buyers`: va antes que los compradores o el borrado tira 1451. */
            DB::table('last_searches')->whereIn('buyer_id', $compradores)->delete();
            DB::table('buyers')->whereIn('id', $compradores)->delete();
        }

        if (!empty($clientes)) {
            DB::table('clients')->whereIn('id', $clientes)->delete();
        }
        DB::table('price_types')->whereIn('user_id', $comercios)->delete();
        DB::table('sub_categories')->whereIn('user_id', $comercios)->delete();
        DB::table('categories')->whereIn('user_id', $comercios)->delete();
        DB::table('brands')->whereIn('user_id', $comercios)->delete();
        DB::table('online_configurations')->whereIn('user_id', $comercios)->delete();
        DB::table('users')->whereIn('id', $comercios)->delete();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Esconder y restaurar
    |---------------------------------------------------------------------------------------------
    */

    private function esconderColumnaDeLaLista()
    {
        $this->renombrar('price_types', CatalogoPorListaHelper::COLUMNA_LISTA, CatalogoPorListaHelper::COLUMNA_LISTA.self::SUFIJO);
    }

    private function esconderColumnaDelPivote()
    {
        $this->renombrar('article_price_type', CatalogoPorListaHelper::COLUMNA_PIVOTE, CatalogoPorListaHelper::COLUMNA_PIVOTE.self::SUFIJO);
    }

    /** Devuelve las dos columnas a su nombre. Idempotente. */
    private function restaurarElEsquema()
    {
        $this->renombrar('price_types', CatalogoPorListaHelper::COLUMNA_LISTA.self::SUFIJO, CatalogoPorListaHelper::COLUMNA_LISTA);
        $this->renombrar('article_price_type', CatalogoPorListaHelper::COLUMNA_PIVOTE.self::SUFIJO, CatalogoPorListaHelper::COLUMNA_PIVOTE);
    }

    /**
     * `RENAME COLUMN` solo si el origen existe y el destino no: idempotente en las dos direcciones.
     *
     * @param  string  $tabla
     * @param  string  $de
     * @param  string  $a
     * @return void
     */
    private function renombrar($tabla, $de, $a)
    {
        if (Schema::hasColumn($tabla, $de) && !Schema::hasColumn($tabla, $a)) {
            DB::statement('ALTER TABLE `'.$tabla.'` RENAME COLUMN `'.$de.'` TO `'.$a.'`');
        }
    }
}
