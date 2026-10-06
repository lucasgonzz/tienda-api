<?php

namespace Tests\Feature\CatalogoPorLista;

use App\Article;
use App\Http\Controllers\ArticleController;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (b) Los tres caminos que NO pasan por `checkOnline()` y tambien respetan la lista del comprador
 * (mision catalogo-por-lista-tienda, 5/10/2026): la ficha por slug, los favoritos y la seleccion
 * especial. Van aparte porque el enganche de `checkOnline()` no los cubre: si alguien saca el scope
 * de uno de estos, ningun otro test se entera.
 */
class FichaFavoritosYSeleccionTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCatalogoPorLista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();
        $this->armarFerretotal();
    }

    protected function tearDown(): void
    {
        /* Por si un caso de favoritos murio antes de su `finally`. Es TEMPORARY: no le hace commit a
           la transaccion del trait ni deja nada en la base compartida del slot. */
        DB::statement('DROP TEMPORARY TABLE IF EXISTS `likeable_likes`');
        DB::statement('DROP TEMPORARY TABLE IF EXISTS `likeable_like_counters`');

        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * 🔴 La ficha de un articulo que el mayorista no puede ver responde IGUAL que un slug que no
     * existe: `{"article": null}` con 200 (contrato C3). Ni 403 ni 404: un codigo propio le
     * confirmaria a quien prueba links que ese articulo existe.
     */
    public function test_la_ficha_de_un_no_habilitado_es_igual_a_la_de_un_slug_inexistente()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $no_habilitado = $this->json('GET', '/api/articles/'.$this->sin_marcar->slug.'/'.$this->comercio->id);
        $inexistente = $this->json('GET', '/api/articles/slug-que-no-existe-'.uniqid().'/'.$this->comercio->id);

        $no_habilitado->assertStatus(200);
        $inexistente->assertStatus(200);

        $this->assertSame(['article' => null], $no_habilitado->json());
        $this->assertSame($inexistente->json(), $no_habilitado->json(),
            'desde afuera no se puede distinguir un articulo no habilitado de uno que no existe');

        $this->json('GET', '/api/articles/'.$this->deshabilitado->slug.'/'.$this->comercio->id)
            ->assertStatus(200)
            ->assertExactJson(['article' => null]);

        /* Y el habilitado se abre, con el precio de su lista. */
        $habilitado = $this->json('GET', '/api/articles/'.$this->habilitado->slug.'/'.$this->comercio->id)->assertStatus(200);
        $this->assertSame($this->habilitado->id, (int) $habilitado->json('article.id'));
        $this->assertEquals(1000, $habilitado->json('article.final_price'));
    }

    /** El visitante abre cualquiera de los tres: su lista no es restringida. */
    public function test_el_visitante_abre_la_ficha_de_cualquiera()
    {
        $this->comoVisitante();

        foreach ([$this->habilitado, $this->sin_marcar, $this->deshabilitado] as $articulo) {
            $respuesta = $this->json('GET', '/api/articles/'.$articulo->slug.'/'.$this->comercio->id)->assertStatus(200);
            $this->assertSame($articulo->id, (int) $respuesta->json('article.id'), $articulo->name);
        }
    }

    /**
     * La seleccion especial (`/articles-seleccion-especial/A-B-C`) deja `null` en el lugar de lo que
     * el mayorista no puede ver, igual que con un id que no existe, y conserva el orden pedido. La
     * ruta no trae comercio: sale del articulo.
     */
    public function test_la_seleccion_especial_deja_null_lo_no_habilitado_y_conserva_el_orden()
    {
        $ids = $this->sin_marcar->id.'-'.$this->habilitado->id.'-'.$this->deshabilitado->id;

        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $modelos = $this->json('GET', '/api/articles-seleccion-especial/'.$ids)->assertStatus(200)->json('models');

        $this->assertCount(3, $modelos);
        $this->assertNull($modelos[0], 'sin_marcar: null en su lugar');
        $this->assertSame($this->habilitado->id, (int) $modelos[1]['id']);
        $this->assertNull($modelos[2], 'deshabilitado: null en su lugar');

        $this->comoVisitante();

        $modelos = $this->json('GET', '/api/articles-seleccion-especial/'.$ids)->assertStatus(200)->json('models');

        $this->assertSame(
            [$this->sin_marcar->id, $this->habilitado->id, $this->deshabilitado->id],
            array_map(function ($modelo) { return (int) $modelo['id']; }, $modelos),
            'el visitante ve los tres, en el orden pedido'
        );
    }

    /**
     * Los favoritos del mayorista no listan lo que su lista ya no le deja ver (un favorito marcado
     * antes de que el comerciante restringiera la lista, por ejemplo). Los del minorista, todos.
     *
     * ⚠️ La tabla `likeable_likes` (paquete rtconner/laravel-likeable) NO la crea ninguna migracion
     * de `empresa-api` y la base del slot no la tiene: hoy `GET /favorites` responde 500 en una base
     * asi (hallazgo aparte, va al informe). Para poder medir el scope, el caso la crea TEMPORARY con
     * la forma de la migracion del paquete: una tabla temporal vive solo en esta conexion, no le
     * hace commit implicito a la transaccion y no deja nada en la base compartida.
     */
    public function test_los_favoritos_del_mayorista_solo_traen_los_habilitados()
    {
        DB::statement('CREATE TEMPORARY TABLE `likeable_likes` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `likeable_id` varchar(36) NOT NULL,
            `likeable_type` varchar(255) NOT NULL,
            `user_id` varchar(36) NOT NULL,
            `created_at` timestamp NULL,
            `updated_at` timestamp NULL
        )');

        try {
            $mayorista = $this->compradorConLista($this->comercio, $this->mayorista->id);
            $minorista = $this->compradorConLista($this->comercio, $this->minorista->id);

            foreach ([$mayorista, $minorista] as $comprador) {
                foreach ([$this->habilitado, $this->sin_marcar, $this->deshabilitado] as $articulo) {
                    $this->favorito($comprador->id, $articulo);
                }
            }

            $this->comoComprador($mayorista);
            $this->assertSame(
                [$this->habilitado->id],
                $this->idsDe($this->json('GET', '/api/favorites')->assertStatus(200)->json('articles.data')),
                'mayorista: solo el habilitado'
            );

            $this->comoComprador($minorista);
            $this->assertSame(
                $this->losTres(),
                $this->idsDe($this->json('GET', '/api/favorites')->assertStatus(200)->json('articles.data')),
                'minorista: los tres'
            );
        } finally {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS `likeable_likes`');
        }
    }

    /**
     * 🔴 `ArticleController@favorite` —marcar o desmarcar un favorito— devolvia CUALQUIER articulo por
     * id (hallazgo B1 de la revision independiente): un mayorista restringido, enumerando ids, recibia
     * el nombre, el slug, las columnas de precio, las imagenes y las preguntas de articulos que su
     * lista no habilita, y podia marcarlos como favoritos. Ahora responde como la ficha: `{"article":
     * null}` con 200 y sin tocar nada, igual que un id que no existe.
     *
     * ⚠️ Se invoca al controlador DIRECTO y no por HTTP a proposito: la ruta `GET /articles/favorite/{id}`
     * esta registrada despues de `GET /articles/{slug}/{commerce_id}`, que la sombrea, y hoy
     * `/articles/favorite/12` lo contesta `show('favorite', 12)` (hallazgo aparte, va al informe). Por
     * HTTP este caso daria verde sin pasar nunca por `favorite()`. El dia que se arregle el orden de las
     * rutas, el filtro ya esta.
     *
     * Con contracara: el habilitado se marca y se desmarca como siempre, y el minorista (lista sin
     * restriccion) marca cualquiera. `likeable_likes` TEMPORARY, por lo que explica el caso de arriba.
     */
    public function test_marcar_como_favorito_un_no_habilitado_responde_null_y_no_lo_marca()
    {
        DB::statement('CREATE TEMPORARY TABLE `likeable_likes` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `likeable_id` varchar(36) NOT NULL,
            `likeable_type` varchar(255) NOT NULL,
            `user_id` varchar(36) NOT NULL,
            `created_at` timestamp NULL,
            `updated_at` timestamp NULL
        )');

        /* `like()` tambien incrementa un contador en `likeable_like_counters` (la otra tabla del paquete,
           que tampoco existe en la base del slot). */
        DB::statement('CREATE TEMPORARY TABLE `likeable_like_counters` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `likeable_id` varchar(36) NOT NULL,
            `likeable_type` varchar(255) NOT NULL,
            `count` bigint unsigned NOT NULL DEFAULT 0
        )');

        try {
            $controlador = $this->app->make(ArticleController::class);

            $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

            foreach ([$this->sin_marcar, $this->deshabilitado] as $no_habilitado) {
                $respuesta = $controlador->favorite($no_habilitado->id);

                $this->assertSame(200, $respuesta->getStatusCode());
                $this->assertSame(['article' => null], $respuesta->getData(true), $no_habilitado->name);
            }

            $this->assertSame(0, DB::table('likeable_likes')->count(), 'no se marco ningun favorito');

            /* Un id que no existe responde lo mismo: desde afuera no se distingue de un no habilitado. */
            $this->assertSame(['article' => null], $controlador->favorite(2147483000)->getData(true));

            /* El habilitado se marca y se desmarca, y vuelve con sus imagenes y preguntas como siempre. */
            $marcado = $controlador->favorite($this->habilitado->id)->getData(true);
            $this->assertSame($this->habilitado->id, (int) $marcado['article']['id']);
            $this->assertTrue($marcado['article']['is_favorite']);
            $this->assertIsArray($marcado['article']['images'], 'sigue trayendo las imagenes');
            $this->assertSame(1, DB::table('likeable_likes')->count());

            $desmarcado = $controlador->favorite($this->habilitado->id)->getData(true);
            $this->assertFalse($desmarcado['article']['is_favorite']);
            $this->assertSame(0, DB::table('likeable_likes')->count());

            /* El minorista no tiene restriccion: marca cualquiera. */
            $this->comoComprador($this->compradorConLista($this->comercio, $this->minorista->id));

            $cualquiera = $controlador->favorite($this->deshabilitado->id)->getData(true);
            $this->assertSame($this->deshabilitado->id, (int) $cualquiera['article']['id']);
            $this->assertTrue($cualquiera['article']['is_favorite']);
        } finally {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS `likeable_likes`');
            DB::statement('DROP TEMPORARY TABLE IF EXISTS `likeable_like_counters`');
        }
    }

    /**
     * 🔴 FIJA UN HALLAZGO, no un deseo: `GET /articles/favorite/{id}` hoy lo atiende `show()` y NO
     * `favorite()`, porque `GET /articles/{slug}/{commerce_id}` esta registrada antes (routes/api.php) y
     * Laravel resuelve por orden de registro (E-8 de la revision de cierre; hallazgo B1).
     *
     * Consecuencia: el corazon de la ficha (`NameHeart.vue`) no marca nada en el servidor, y por eso el caso
     * de B1 invoca a `ArticleController@favorite` directo. Este test existe para que el dia que alguien
     * mueva la ruta (lo que arregla los favoritos y le da vida a `favorite()`, que ya filtra por la lista)
     * lo haga a proposito: cambia el comportamiento de todas las tiendas y es decision de Lucas. Como es de
     * ruteo, es de CONTRACARA: pasa con el orden de hoy y se pone rojo si cambia (demostrado en una copia
     * del arbol, registrando la ruta de favorite antes de la de `show`).
     *
     * NO se cambia el orden de las rutas aca.
     */
    public function test_la_ruta_de_favorite_hoy_la_atiende_show_porque_la_sombrea()
    {
        $rutas = $this->app['router']->getRoutes();

        $atendida_por = $rutas->match(Request::create('/api/articles/favorite/12', 'GET'))->getActionName();

        $this->assertSame(
            ArticleController::class.'@show',
            $atendida_por,
            'CAMBIO EL ORDEN DE LAS RUTAS: GET /api/articles/favorite/{id} ya no lo atiende ArticleController@show '
            .'(que lo sombreaba por estar registrada antes la ruta /articles/{slug}/{commerce_id}) y ahora lo atiende '
            .$atendida_por.'. Si fue a proposito, el corazon de la ficha (NameHeart.vue) por fin llega a '
            .'ArticleController@favorite, que ya filtra por la lista del comprador (B1), y cambia el comportamiento de '
            .'todas las tiendas: actualiza este test, el docblock de favorite() y avisale a Lucas. Si no, restauralo.'
        );

        /* La ruta de favorite sigue registrada: solo esta sombreada. Si alguien la borra, tambien se entera. */
        $propia = $rutas->getByAction(ArticleController::class.'@favorite');

        $this->assertNotNull($propia, 'la ruta de ArticleController@favorite tiene que seguir registrada');
        $this->assertSame('api/articles/favorite/{article_id}', $propia->uri());
    }

    /**
     * Un favorito como lo guarda `Likeable::like()`.
     *
     * @param  int  $buyer_id
     * @param  \App\Article  $articulo
     * @return void
     */
    private function favorito($buyer_id, Article $articulo)
    {
        DB::table('likeable_likes')->insert([
            'likeable_id'   => (string) $articulo->id,
            'likeable_type' => $articulo->getMorphClass(),
            'user_id'       => (string) $buyer_id,
            'created_at'    => Carbon::now(),
            'updated_at'    => Carbon::now(),
        ]);
    }
}
