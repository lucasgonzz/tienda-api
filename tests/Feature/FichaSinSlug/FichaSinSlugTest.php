<?php

namespace Tests\Feature\FichaSinSlug;

use App\Article;
use App\PromocionVinoteca;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\CatalogoPorLista\ArmaCatalogoPorLista;
use Tests\TestCase;

/**
 * La ficha de un articulo SIN slug por `~<id>` (mision tienda-articulos-sin-slug, 26/11/2026).
 *
 * Contexto: en la tienda de un cliente hay articulos con `slug` NULL o '' (altas que nunca lo
 * generaron). La SPA no puede armar `/articulos/<slug>/<comercio>` para ellos y la ficha mostraba
 * "No pudimos cargar este producto". El contrato nuevo (PLAN §2.1): el segmento de la ficha es el
 * slug si existe y, si no, `~<id>`. Estos tests clavan el lado de tienda-api (`GET /api/articles/{segmento}/{comercio}`):
 *
 *   - CA5: `~<id>` abre el articulo del comercio con slug NULL, con slug '' y con slug propio.
 *   - CA6: sin regresion ni ambiguedad (slug numerico, promos, segmentos invalidos, otro comercio, borrados).
 *   - CA7: la restriccion de la lista del comprador vale igual por `~<id>`.
 *
 * Mismo criterio de aislamiento que `FichaFavoritosYSeleccionTest`: un comercio PROPIO por caso y
 * todo se va con DatabaseTransactions (tienda-api no tiene migraciones: el esquema es del slot).
 */
class FichaSinSlugTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCatalogoPorLista;

    /** Respuesta de "no existe / no se puede ver": exactamente esto, con 200. */
    const NO_EXISTE = ['article' => null];

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();
        $this->comercio = $this->comercioConTienda();
        $this->comoVisitante();
    }

    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * Pide la ficha por un segmento crudo.
     *
     * @param  string  $segmento  Lo que va en la posicion del slug.
     * @param  int|null  $comercio_id  Por defecto el comercio del caso.
     * @return \Illuminate\Testing\TestResponse
     */
    private function ficha($segmento, $comercio_id = null)
    {
        return $this->json('GET', '/api/articles/'.$segmento.'/'.($comercio_id ?: $this->comercio->id));
    }

    /** CA5: un articulo con slug NULL se abre por `~<id>`. */
    public function test_ca5_abre_por_tilde_id_un_articulo_con_slug_null()
    {
        $articulo = $this->articulo($this->comercio, ['name' => 'ZZ Reel Sin Slug Null', 'slug' => null]);

        $respuesta = $this->ficha('~'.$articulo->id)->assertStatus(200);

        $this->assertSame($articulo->id, (int) $respuesta->json('article.id'));
    }

    /** CA5: igual con slug '' (cadena vacia). */
    public function test_ca5_abre_por_tilde_id_un_articulo_con_slug_vacio()
    {
        $articulo = $this->articulo($this->comercio, ['name' => 'ZZ Reel Sin Slug Vacio', 'slug' => '']);

        $respuesta = $this->ficha('~'.$articulo->id)->assertStatus(200);

        $this->assertSame($articulo->id, (int) $respuesta->json('article.id'));
    }

    /** CA5: `~<id>` tambien abre un articulo que SI tiene slug (un link compartido hoy sigue andando despues del backfill). */
    public function test_ca5_abre_por_tilde_id_un_articulo_que_si_tiene_slug()
    {
        $articulo = $this->articulo($this->comercio, ['name' => 'ZZ Reel Con Slug', 'slug' => 'zz-reel-con-slug-'.uniqid()]);

        $respuesta = $this->ficha('~'.$articulo->id)->assertStatus(200);

        $this->assertSame($articulo->id, (int) $respuesta->json('article.id'));
    }

    /**
     * CA6: un slug NUMERICO real sigue resolviendose por el paso 1 (por slug), y `~<id de otro>`
     * devuelve el otro: el numero a secas y el `~id` no se confunden.
     */
    public function test_ca6_slug_numerico_sigue_siendo_slug_y_tilde_id_es_otro_articulo()
    {
        $slug_numerico = (string) random_int(900000000, 999999999);
        $con_slug_numerico = $this->articulo($this->comercio, ['name' => 'ZZ Slug Numerico', 'slug' => $slug_numerico]);
        $otro = $this->articulo($this->comercio, ['name' => 'ZZ Otro Sin Slug', 'slug' => null]);

        $por_slug = $this->ficha($slug_numerico)->assertStatus(200);
        $this->assertSame($con_slug_numerico->id, (int) $por_slug->json('article.id'));

        $por_tilde = $this->ficha('~'.$otro->id)->assertStatus(200);
        $this->assertSame($otro->id, (int) $por_tilde->json('article.id'));
    }

    /** CA6: una promo con slug sigue devolviendo la promo (paso 2, como hoy). */
    public function test_ca6_la_promo_por_slug_sigue_igual()
    {
        $promo = PromocionVinoteca::create([
            'name' => 'ZZ Promo', 'slug' => 'zz-promo-'.uniqid(), 'user_id' => $this->comercio->id,
            'online' => 1, 'final_price' => 3000, 'stock' => 3,
        ]);

        $respuesta = $this->ficha($promo->slug)->assertStatus(200);

        $this->assertSame($promo->id, (int) $respuesta->json('article.id'));
        $this->assertSame($promo->slug, $respuesta->json('article.slug'));
    }

    /** CA6: `~<id>` de un articulo de OTRO comercio no se abre (ni con el id del otro ni con el propio en la URL). */
    public function test_ca6_tilde_id_de_otro_comercio_responde_null()
    {
        $otro_comercio = $this->comercioConTienda();
        $ajeno = $this->articulo($otro_comercio, ['name' => 'ZZ Ajeno', 'slug' => null]);

        $this->ficha('~'.$ajeno->id)->assertStatus(200)->assertExactJson(self::NO_EXISTE);
    }

    /** CA6: el id de una promo no resuelve nada por `~` (nunca se buscan promos por id). */
    public function test_ca6_tilde_id_de_una_promo_sin_articulo_con_ese_id_responde_null()
    {
        /* Comercio sin ningun articulo: el unico registro con ese id es la promo. */
        $promo = PromocionVinoteca::create([
            'name' => 'ZZ Promo Por Id', 'slug' => 'zz-promo-id-'.uniqid(), 'user_id' => $this->comercio->id,
            'online' => 1, 'final_price' => 3000, 'stock' => 3,
        ]);

        $this->ficha('~'.$promo->id)->assertStatus(200)->assertExactJson(self::NO_EXISTE);
    }

    /** CA6: segmentos con tilde que no son `~<entero positivo>` no abren nada. */
    public function test_ca6_segmentos_invalidos_responden_null()
    {
        $this->articulo($this->comercio, ['name' => 'ZZ Hay Articulos', 'slug' => null]);

        foreach (['~abc', '~', '~0', '~12x', '~-5', '~01x', '~1.5'] as $segmento) {
            $this->ficha($segmento)->assertStatus(200)->assertExactJson(self::NO_EXISTE);
        }
    }

    /** CA6: un articulo borrado (soft delete) no se abre por `~<id>`. */
    public function test_ca6_tilde_id_de_un_borrado_responde_null()
    {
        $articulo = $this->articulo($this->comercio, ['name' => 'ZZ Borrado', 'slug' => null]);
        /* Borrado logico directo en la base (marca `deleted_at`, igual que el SoftDeletes del ERP): evita los
           eventos de `delete()` del paquete de likes, cuya tabla no vive en la base del slot. */
        Article::where('id', $articulo->id)->update(['deleted_at' => now()]);

        $this->ficha('~'.$articulo->id)->assertStatus(200)->assertExactJson(self::NO_EXISTE);
    }

    /**
     * CA7: un articulo que el comprador no puede ver por su lista responde por `~<id>` IGUAL que un
     * slug inexistente; el habilitado se abre. Mismo armado de `FichaFavoritosYSeleccionTest`.
     */
    public function test_ca7_la_lista_del_comprador_aplica_tambien_por_tilde_id()
    {
        $this->armarFerretotal();
        /* Los tres del escenario, sin slug: asi solo se pueden abrir por `~<id>`. */
        Article::whereIn('id', $this->losTres())->update(['slug' => null]);

        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $inexistente = $this->ficha('slug-que-no-existe-'.uniqid());
        $no_habilitado = $this->ficha('~'.$this->sin_marcar->id);
        $deshabilitado = $this->ficha('~'.$this->deshabilitado->id);

        $no_habilitado->assertStatus(200)->assertExactJson(self::NO_EXISTE);
        $deshabilitado->assertStatus(200)->assertExactJson(self::NO_EXISTE);
        $this->assertSame($inexistente->json(), $no_habilitado->json());

        $habilitado = $this->ficha('~'.$this->habilitado->id)->assertStatus(200);
        $this->assertSame($this->habilitado->id, (int) $habilitado->json('article.id'));
        $this->assertEquals(1000, $habilitado->json('article.final_price'));
    }
}
