<?php

namespace Tests\Feature\CatalogoPorLista;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (k) EL COMERCIO DE LA PAGINA LO DICE LA RUTA, NO LA QUERY STRING (mision catalogo-por-lista-tienda,
 * 5/10/2026, revision independiente: hallazgo B2).
 *
 * ── El defecto que fija ──────────────────────────────────────────────────────────────────────────
 *
 * Los scopes `checkOnline()` y `checkStock()` leen el comercio de `request()->commerce_id`, y
 * `Request::__get` le da prioridad al INPUT sobre el parametro de la ruta: con
 * `/api/articles/search/x/500?commerce_id=501` el listado era de 500 (la busqueda filtra por el
 * parametro de la ruta) pero la restriccion por lista se calculaba con las listas de 501. Cuando la
 * restriccion sale de la `position` (visitante, o logueado sin cliente del ERP, con la lista mas alta
 * restringida) bastaba con elegir un comercio sin listas para ver el catalogo completo.
 *
 * Lo que se fija: en cada ruta que lleva `{commerce_id}` y no lo usa por argumento, el comercio de la
 * restriccion es el de la ruta aunque la query string diga otro. Escenario: el comercio del escenario
 * estandar con SU lista mas alta (la Minorista, la del visitante) restringida y solo el `habilitado`
 * habilitado para ella; y un comercio ajeno con su configuracion online y SIN listas, al que apunta el
 * `?commerce_id=`.
 *
 * Tambien: `similars` con un comercio de la ruta que NO es el del articulo (el caso que ninguna
 * normalizacion de la query string tapa, porque ahi la ruta misma miente).
 *
 * ⚠️ Lo que NO se cubre, y queda dicho en el informe: `from-category` no filtra por comercio (filtra por
 * categoria) y no tiene con que comparar la ruta sin leer la categoria, que seria una consulta de mas en
 * todos los listados. Con un `{commerce_id}` de la RUTA que no es el de la categoria la restriccion se
 * calcula con el comercio de la ruta. Ver `HomeController::articlesFromCategory()`.
 */
class ComercioDeLaPaginaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCatalogoPorLista;

    /** @var \App\User El comercio ajeno: otro `users.id`, con su configuracion online y sin listas. */
    private $ajeno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();
        $this->armarFerretotal();

        $this->restringirLista($this->minorista);

        DB::table('article_price_type')
            ->where('article_id', $this->habilitado->id)
            ->where('price_type_id', $this->minorista->id)
            ->update(['visible_en_tienda' => 1]);

        $this->ajeno = $this->comercioConTienda();

        $this->comoVisitante();
    }

    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * 🔴 Los nombres del buscador y la busqueda: el comercio de la restriccion es el de la ruta.
     */
    public function test_los_nombres_y_la_busqueda_siguen_al_comercio_de_la_ruta()
    {
        $solo_el_habilitado = [$this->habilitado->id];

        $nombres = '/api/articles/names/'.$this->comercio->id;

        $this->assertSame($solo_el_habilitado, $this->idsDe($this->pedir($nombres)->json('articles_names')),
            'control: sin query string');
        $this->assertSame($solo_el_habilitado, $this->idsDe($this->pedir($this->conOtroComercio($nombres))->json('articles_names')),
            'names con ?commerce_id= de otro comercio');

        $busqueda = '/api/articles/search/Catalogo/'.$this->comercio->id;

        $this->assertSame($solo_el_habilitado, $this->idsDe($this->pedir($busqueda)->json('articles.data')),
            'control: sin query string');
        $this->assertSame($solo_el_habilitado, $this->idsDe($this->pedir($this->conOtroComercio($busqueda))->json('articles.data')),
            'search con ?commerce_id= de otro comercio');
    }

    /**
     * 🔴 La categoria (que no filtra por comercio: lo unico que dice cual es el comercio es la ruta) y
     * la marca (que ya fijaba el comercio de la ruta y es el control de que el resto lo hace igual).
     */
    public function test_la_categoria_y_la_marca_siguen_al_comercio_de_la_ruta()
    {
        $solo_el_habilitado = [$this->habilitado->id];

        $categoria = '/api/articles/from-category/'.$this->herramientas->id.'/0/0/0/a-z/'.$this->comercio->id;

        $this->assertSame($solo_el_habilitado, $this->idsDe($this->pedir($categoria)->json('articles.data')),
            'control: sin query string');
        $this->assertSame($solo_el_habilitado, $this->idsDe($this->pedir($this->conOtroComercio($categoria))->json('articles.data')),
            'from-category con ?commerce_id= de otro comercio');

        $marca = '/api/articles/from-brand/'.$this->marca_habilitada.'/a-z/'.$this->comercio->id;

        $this->assertSame($solo_el_habilitado, $this->idsDe($this->pedir($this->conOtroComercio($marca))->json('articles.data')),
            'from-brand ya fijaba el comercio de la ruta');
    }

    /**
     * 🔴 Similares y recomendaciones de la ficha.
     */
    public function test_similares_y_recomendaciones_siguen_al_comercio_de_la_ruta()
    {
        /* Los similares del habilitado son los otros de su categoria: el unico candidato es
           `sin_marcar`, que esta lista no habilita. */
        $similares = '/api/articles/similars/'.$this->habilitado->id.'/'.$this->comercio->id;

        $this->assertSame([], $this->idsDe($this->pedir($similares)->json('models.data')),
            'control: sin query string');
        $this->assertSame([], $this->idsDe($this->pedir($this->conOtroComercio($similares))->json('models.data')),
            'similars con ?commerce_id= de otro comercio');

        /* Las dos recomendaciones: la lista de ids sale de la cache (lo que se mide es la hidratacion). */
        foreach (['compras', 'vistas'] as $seccion) {
            Cache::put('recomendaciones:'.$seccion.':'.$this->comercio->id.':'.$this->sin_marcar->id,
                [$this->habilitado->id, $this->deshabilitado->id], 600);

            $ruta = '/api/articles/tambien-compraron/'.$seccion.'/'.$this->sin_marcar->id.'/'.$this->comercio->id;

            $this->assertSame([$this->habilitado->id], $this->idsDe($this->pedir($ruta)->json('models')),
                $seccion.': control, sin query string');
            $this->assertSame([$this->habilitado->id], $this->idsDe($this->pedir($this->conOtroComercio($ruta))->json('models')),
                $seccion.' con ?commerce_id= de otro comercio');
        }
    }

    /**
     * 🔴 Los similares de un articulo con un comercio de la RUTA que no es el suyo: la ruta misma
     * miente, asi que el comercio de la restriccion no puede salir solo de ella. El comercio del
     * articulo tambien manda: no se ven los similares no habilitados para SU lista eligiendo un
     * comercio sin listas en la URL.
     */
    public function test_los_similares_respetan_la_lista_del_comercio_del_articulo()
    {
        $ruta_con_otro_comercio = '/api/articles/similars/'.$this->habilitado->id.'/'.$this->ajeno->id;

        $this->assertSame([], $this->idsDe($this->pedir($ruta_con_otro_comercio)->json('models.data')),
            'el unico similar (sin_marcar) no esta habilitado para la lista de SU comercio');
    }

    /**
     * La home restringe con las listas del comercio CUYOS articulos lista (N4 de la revision de cierre).
     *
     * `featuredLastUploads` filtra por `$request->commerce_id` (el input gana sobre la ruta) y la
     * restriccion sale del mismo valor: no hay desacuerdo que explotar, y por eso no fija el comercio de
     * la ruta (ver `CatalogoPorListaHelper::fijar_el_comercio()`). Es un test de INVARIANTE: el codigo
     * anterior a B2 tambien lo cumplia, asi que NO falla contra el. Protege contra la regresion que B2
     * evito a proposito: si alguien arregla el comercio de la restriccion preguntando por la RUTA adentro
     * de `Article::checkOnline()`, sin tocar la home, la home pasaria a listar los articulos de un comercio
     * restringidos con las listas de OTRO.
     *
     * El caso esta armado para que pueda fallar de verdad (la version anterior miraba una home que no
     * tenia articulos y no podia): el comercio de la RUTA no restringe nada, y el de la query string SI,
     * con un articulo habilitado y otro no. Por la home del segundo no puede salir su no habilitado.
     * Demostrado con una mutacion temporal en una copia del arbol: con la restriccion preguntando por la
     * ruta, el no habilitado ajeno aparece y el caso se pone rojo.
     */
    public function test_la_home_restringe_con_las_listas_del_comercio_que_lista()
    {
        /* El comercio de la ruta deja de restringir. */
        DB::table('price_types')->where('id', $this->minorista->id)->update(['catalogo_restringido_en_tienda' => null]);

        /* El ajeno SI: su lista mas alta es restringida, con un articulo habilitado y otro que no. */
        $lista_ajena = $this->lista($this->ajeno, 'Minorista Ajena', 2);
        $this->restringirLista($lista_ajena);

        $habilitado_ajeno = $this->articulo($this->ajeno, ['name' => 'Catalogo Ajeno Habilitado']);
        $this->precioEnLista($habilitado_ajeno, $lista_ajena, 100, 1);

        $no_habilitado_ajeno = $this->articulo($this->ajeno, ['name' => 'Catalogo Ajeno No Habilitado']);
        $this->precioEnLista($no_habilitado_ajeno, $lista_ajena, 200, null);

        $ruta = '/api/articles/featured-last-uploads/'.$this->comercio->id.'?page=1';

        $this->assertSame($this->losTres(), $this->idsDe($this->pedir($ruta)->json('articles.data')),
            'control: sin query string es la home del comercio de la ruta, que no restringe nada');

        $ids = $this->idsDe($this->pedir($this->conOtroComercio($ruta))->json('articles.data'));

        $this->assertNotEmpty($ids, 'el caso no es vacuo: la home devuelve articulos');
        $this->assertNotContains($no_habilitado_ajeno->id, $ids,
            'el no habilitado del comercio que se lista no sale, restrinja o no el comercio de la ruta');
    }

    /**
     * La misma uri apuntando, por query string, a otro comercio.
     *
     * @param  string  $uri
     * @return string
     */
    private function conOtroComercio($uri)
    {
        return $uri.(strpos($uri, '?') === false ? '?' : '&').'commerce_id='.$this->ajeno->id;
    }

    /**
     * Un GET con el status chequeado.
     *
     * @param  string  $uri
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedir($uri)
    {
        return $this->json('GET', $uri)->assertStatus(200);
    }
}
