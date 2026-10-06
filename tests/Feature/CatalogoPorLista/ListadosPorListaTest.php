<?php

namespace Tests\Feature\CatalogoPorLista;

use App\Http\Controllers\Helpers\ClientOfferHelper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (a) Los LISTADOS respetan la lista del comprador (mision catalogo-por-lista-tienda, 5/10/2026).
 *
 * Todo lo que pasa por `Article::scopeCheckOnline()` —home, busqueda, categoria, marca, nombres del
 * buscador, similares, ofertas personalizadas y recomendaciones— queda cubierto por un solo enganche.
 * Esta clase lo recorre entero con los cinco compradores que importan:
 *
 *   - el MAYORISTA (lista restringida): ve solo los habilitados;
 *   - el VISITANTE y el MINORISTA: ven todo, como hoy;
 *   - el LOGUEADO SIN CLIENTE y el cliente con `price_type_id = 0`: la lista de position mas alta;
 *   - el comercio SIN LISTAS: no hay lista efectiva y no se filtra nada.
 *
 * Ver el escenario en `ArmaCatalogoPorLista`.
 */
class ListadosPorListaTest extends TestCase
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
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * 🔴 EL CASO DEL PEDIDO: el mayorista ve SOLO lo habilitado para su lista, en todos los listados.
     *
     * NULL (`sin_marcar`) y 0 (`deshabilitado`) quedan afuera los dos: la regla es `= 1`.
     */
    public function test_el_mayorista_ve_solo_los_habilitados_en_todos_los_listados()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $solo_el_habilitado = [$this->habilitado->id];

        $this->assertSame($solo_el_habilitado, $this->idsDeLaHome(), 'home: ultimos ingresos');
        $this->assertSame($solo_el_habilitado, $this->idsDe($this->home()->json('featured')), 'home: destacados');
        $this->assertSame($solo_el_habilitado, $this->idsDeLaBusqueda('Catalogo'), 'busqueda');
        $this->assertSame($solo_el_habilitado, $this->idsDeLaCategoria($this->herramientas->id), 'categoria');
        $this->assertSame([], $this->idsDeLaCategoria($this->solo_minorista->id), 'categoria sin habilitados');
        $this->assertSame($solo_el_habilitado, $this->idsDeLaMarca($this->marca_habilitada), 'marca');
        $this->assertSame([], $this->idsDeLaMarca($this->marca_solo_minorista), 'marca sin habilitados');
        $this->assertSame($solo_el_habilitado, $this->idsDeLosNombres(), 'nombres del buscador');

        /* Los similares del habilitado son los otros de su categoria: el unico candidato es
           `sin_marcar`, que el mayorista no ve. */
        $this->assertSame([], $this->idsDeLosSimilares($this->habilitado->id), 'similares');

        /* Las recomendaciones de la ficha de `sin_marcar` (la lista de ids viene de la cache: lo que
           se mide es la hidratacion, que pasa por checkOnline()). */
        $this->recomendacionesCacheadas($this->sin_marcar->id, [$this->habilitado->id, $this->deshabilitado->id]);
        $this->assertSame($solo_el_habilitado, $this->idsDeLasRecomendaciones($this->sin_marcar->id), 'recomendaciones');
    }

    /**
     * El visitante y el minorista ven el catalogo COMPLETO: su lista (la Minorista) no es restringida.
     * Es el comportamiento de hoy y no puede cambiar.
     */
    public function test_el_visitante_y_el_minorista_ven_todo()
    {
        foreach (['visitante', 'minorista'] as $quien) {

            if ($quien == 'minorista') {
                $this->comoComprador($this->compradorConLista($this->comercio, $this->minorista->id));
            } else {
                $this->comoVisitante();
            }

            $this->assertSame($this->losTres(), $this->idsDeLaHome(), $quien.': home');
            $this->assertSame($this->losTres(), $this->idsDeLaBusqueda('Catalogo'), $quien.': busqueda');
            $this->assertSame(
                $this->ordenados([$this->habilitado->id, $this->sin_marcar->id]),
                $this->idsDeLaCategoria($this->herramientas->id),
                $quien.': categoria'
            );
            $this->assertSame([$this->deshabilitado->id], $this->idsDeLaMarca($this->marca_solo_minorista), $quien.': marca');
            $this->assertSame($this->losTres(), $this->idsDeLosNombres(), $quien.': nombres');
            $this->assertSame([$this->sin_marcar->id], $this->idsDeLosSimilares($this->habilitado->id), $quien.': similares');
        }
    }

    /**
     * Logueado sin cliente del ERP, y cliente con `price_type_id = 0`: los dos caen a la lista de
     * position mas alta (la Minorista, sin restriccion) y ven todo. El 0 es "sin lista", no "la lista
     * 0": si se tratara distinto que el null, ese comprador se quedaria sin catalogo.
     */
    public function test_el_logueado_sin_cliente_y_el_cliente_con_lista_cero_ven_la_lista_mas_alta()
    {
        $this->comoComprador($this->compradorSinCliente($this->comercio));
        $this->assertSame($this->losTres(), $this->idsDeLaHome(), 'logueado sin cliente');

        $this->comoComprador($this->compradorConLista($this->comercio, 0));
        $this->assertSame($this->losTres(), $this->idsDeLaHome(), 'cliente con price_type_id = 0');
    }

    /**
     * Si la lista de position mas alta es restringida, el VISITANTE tambien queda acotado a lo
     * habilitado para ella: el catalogo sigue a la lista efectiva, sea quien sea el comprador.
     */
    public function test_si_la_lista_publica_es_restringida_el_visitante_tambien_queda_acotado()
    {
        $this->restringirLista($this->minorista);

        DB::table('article_price_type')
            ->where('article_id', $this->sin_marcar->id)
            ->where('price_type_id', $this->minorista->id)
            ->update(['visible_en_tienda' => 1]);

        $this->comoVisitante();

        $this->assertSame([$this->sin_marcar->id], $this->idsDeLaHome());
        $this->assertSame([$this->sin_marcar->id], $this->idsDeLosNombres());
    }

    /**
     * Un comercio sin listas con `position` no tiene lista efectiva: nadie queda filtrado, aunque
     * el comprador tenga un cliente del ERP sin lista.
     */
    public function test_un_comercio_sin_listas_no_filtra_nada()
    {
        $comercio = $this->comercioConTienda();
        $uno = $this->articulo($comercio, ['name' => 'Catalogo Sin Listas Uno']);
        $dos = $this->articulo($comercio, ['name' => 'Catalogo Sin Listas Dos']);

        $esperados = $this->ordenados([$uno->id, $dos->id]);

        $this->comoVisitante();
        $this->assertSame($esperados, $this->idsDe($this->json('GET', '/api/articles/featured-last-uploads/'.$comercio->id.'?page=1')->assertStatus(200)->json('articles.data')));

        $this->comoComprador($this->compradorConLista($comercio, null));
        $this->assertSame($esperados, $this->idsDe($this->json('GET', '/api/articles/featured-last-uploads/'.$comercio->id.'?page=1')->assertStatus(200)->json('articles.data')));
    }

    /**
     * El precio que ve el mayorista sigue siendo el de SU lista, y el del visitante el de la
     * publica: la restriccion no toca el precio, y los dos salen de la misma eleccion de lista.
     */
    public function test_el_precio_sigue_saliendo_de_la_lista_de_cada_uno()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));
        $this->assertEquals(1000, $this->precioEnLaHome($this->habilitado->id), 'mayorista: precio de la Mayorista');

        $this->comoVisitante();
        $this->assertEquals(1500, $this->precioEnLaHome($this->habilitado->id), 'visitante: precio de la Minorista');
    }

    /**
     * El pivote no tiene indice unico: puede haber dos filas para el mismo (articulo, lista). Alcanza
     * con que UNA este habilitada para que el articulo se vea, y el listado no lo duplica.
     */
    public function test_con_filas_duplicadas_alcanza_con_una_habilitada_y_no_se_duplica()
    {
        $this->precioEnLista($this->sin_marcar, $this->mayorista, 2000, 1);

        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $respuesta = $this->home()->json('articles.data');

        $this->assertSame($this->ordenados([$this->habilitado->id, $this->sin_marcar->id]), $this->idsDe($respuesta));
        $this->assertCount(2, $respuesta, 'un EXISTS no duplica filas como lo haria un join');
    }

    /**
     * Las ofertas personalizadas del mayorista (`GET /client-offers`) pasan por checkOnline(): una
     * oferta sobre un articulo no habilitado no se lista. Sin esto el mensaje de la oferta lo
     * llevaria a una ficha que su lista no le deja ver.
     */
    public function test_las_ofertas_personalizadas_del_mayorista_solo_traen_los_habilitados()
    {
        $mayorista = $this->compradorConLista($this->comercio, $this->mayorista->id);

        foreach ([$this->habilitado, $this->sin_marcar] as $articulo) {
            DB::table(ClientOfferHelper::TABLA)->insert([
                'user_id'        => $this->comercio->id,
                'client_id'      => $mayorista->comercio_city_client_id,
                'article_id'     => $articulo->id,
                'tipo_descuento' => ClientOfferHelper::TIPO_UNIDAD,
                'porcentaje'     => 10,
                'desde'          => Carbon::today()->subDays(1)->toDateString(),
                'hasta'          => Carbon::today()->addDays(1)->toDateString(),
                'estado'         => ClientOfferHelper::ESTADO_ACTIVA,
                'created_at'     => Carbon::now(),
                'updated_at'     => Carbon::now(),
            ]);
        }

        $this->comoComprador($mayorista);

        $ofertas = $this->json('GET', '/api/client-offers/'.$this->comercio->id)->assertStatus(200)->json('articles');

        $this->assertSame([$this->habilitado->id], $this->idsDe($ofertas));
    }

    /**
     * 🔴 Que la lista del articulo sea restringida NO viaja en el payload (INFO de la revision
     * independiente): `article.price_types[]` serializaba `catalogo_restringido_en_tienda` para todos, el
     * visitante incluido, y revelaba cual de las listas es la restringida. El SPA no lo lee; el helper lo
     * sigue leyendo como atributo (`$hidden` solo toca la serializacion), y la restriccion sigue andando:
     * el mayorista ve solo lo habilitado y el visitante ve las listas sin la clave.
     */
    public function test_la_marca_de_lista_restringida_no_viaja_en_el_payload_de_los_articulos()
    {
        foreach (['visitante', 'mayorista'] as $quien) {

            if ($quien == 'mayorista') {
                $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));
            } else {
                $this->comoVisitante();
            }

            $articulos = (array) $this->home()->json('articles.data');

            $this->assertNotEmpty($articulos, $quien);

            $listas = 0;

            foreach ($articulos as $articulo) {
                foreach ((array) $articulo['price_types'] as $lista) {
                    $listas++;

                    $this->assertArrayHasKey('position', $lista, $quien.': la lista sigue viajando');
                    $this->assertArrayNotHasKey('catalogo_restringido_en_tienda', $lista, $quien.': pero sin la marca de restringida');
                }
            }

            $this->assertGreaterThan(0, $listas, $quien.': el caso mira listas de verdad');
        }

        /* Y el helper la sigue leyendo: el mayorista de arriba solo ve el habilitado. */
        $this->assertSame([$this->habilitado->id], $this->idsDeLaHome());
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Lectores
    |---------------------------------------------------------------------------------------------
    */

    /** @return \Illuminate\Testing\TestResponse */
    private function home()
    {
        return $this->json('GET', '/api/articles/featured-last-uploads/'.$this->comercio->id.'?page=1')->assertStatus(200);
    }

    private function idsDeLaHome()
    {
        return $this->idsDe($this->home()->json('articles.data'));
    }

    private function precioEnLaHome($article_id)
    {
        foreach ($this->home()->json('articles.data') as $articulo) {
            if ((int) $articulo['id'] === (int) $article_id) {
                return $articulo['final_price'];
            }
        }

        $this->fail('el articulo '.$article_id.' no esta en la home');
    }

    private function idsDeLaBusqueda($texto)
    {
        return $this->idsDe($this->json('GET', '/api/articles/search/'.$texto.'/'.$this->comercio->id)->assertStatus(200)->json('articles.data'));
    }

    private function idsDeLaCategoria($category_id)
    {
        return $this->idsDe($this->json('GET', '/api/articles/from-category/'.$category_id.'/0/0/0/a-z/'.$this->comercio->id)->assertStatus(200)->json('articles.data'));
    }

    private function idsDeLaMarca($brand_id)
    {
        return $this->idsDe($this->json('GET', '/api/articles/from-brand/'.$brand_id.'/a-z/'.$this->comercio->id)->assertStatus(200)->json('articles.data'));
    }

    private function idsDeLosNombres()
    {
        return $this->idsDe($this->json('GET', '/api/articles/names/'.$this->comercio->id)->assertStatus(200)->json('articles_names'));
    }

    private function idsDeLosSimilares($article_id)
    {
        return $this->idsDe($this->json('GET', '/api/articles/similars/'.$article_id.'/'.$this->comercio->id)->assertStatus(200)->json('models.data'));
    }

    private function idsDeLasRecomendaciones($article_id)
    {
        return $this->idsDe($this->json('GET', '/api/articles/tambien-compraron/compras/'.$article_id.'/'.$this->comercio->id)->assertStatus(200)->json('models'));
    }

    /**
     * Deja en la cache la lista de ids de "quienes compraron esto tambien compraron", con la clave
     * exacta de `RecomendacionesHelper::idsCacheados()`. Asi el caso no necesita armar pedidos: lo
     * que se mide es la hidratacion, que es donde vive el filtro.
     */
    private function recomendacionesCacheadas($article_id, array $ids)
    {
        Cache::put('recomendaciones:compras:'.$this->comercio->id.':'.$article_id, $ids, 600);
    }
}
