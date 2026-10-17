<?php

namespace Tests\Feature\Home;

use App\Article;
use App\Bodega;
use App\Cepa;
use App\OnlineConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\CatalogoPorLista\ArmaCatalogoPorLista;
use Tests\TestCase;

/**
 * Los numeros de bodegas, cepas, marcas y subcategorias (mision contadores-bodegas-cepas-marcas-
 * subcategorias, 17/10/2026), de la misma clase que `ContadorDeCategoriasTest`.
 *
 * `BodegaController` y `CepaController` contaban con `withCount('articles')` pelado, la rama de
 * `brands()` sin lista restringida tambien, y `subCategories()` decidia con `whereHas('articles')`
 * sin publicado ni stock. El listado real filtra con `checkOnline()` + `checkStock()`.
 *
 * Lo que fijan estos casos: cada `articles_count` es EXACTAMENTE la cantidad que devuelve el listado
 * de esa bodega / cepa / marca / subcategoria, y las que quedan en 0 no se devuelven (decision de
 * Lucas, 17/10/2026).
 */
class ContadorDeBodegasCepasMarcasTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCatalogoPorLista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();
    }

    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * Comercio sin lista restringida. Para cada agrupador (bodega, cepa, marca, subcategoria) se
     * siembra una "mixta" con un articulo de cada tipo (publicado, no online, inactivo, sin imagen,
     * sin stock) y una "vacia" que solo tiene un borrador que el listado no muestra.
     *
     * @param  int  $con_imagenes  `show_articles_without_images`.
     * @param  int  $con_stock  `show_articles_without_stock`.
     * @return array  ids por tipo: [mixta, vacia], y 'categoria'.
     */
    private function armar($con_imagenes, $con_stock)
    {
        $this->comercio = $this->comercioConTienda();

        OnlineConfiguration::where('user_id', $this->comercio->id)->update([
            'show_articles_without_images' => $con_imagenes,
            'show_articles_without_stock'  => $con_stock,
        ]);

        $categoria = $this->categoria($this->comercio, 'Cat');

        $datos = [
            'bodega' => [
                Bodega::create(['name' => 'Bodega Mixta', 'user_id' => $this->comercio->id])->id,
                Bodega::create(['name' => 'Bodega Vacia', 'user_id' => $this->comercio->id])->id,
            ],
            'cepa' => [
                Cepa::create(['name' => 'Cepa Mixta', 'user_id' => $this->comercio->id])->id,
                Cepa::create(['name' => 'Cepa Vacia', 'user_id' => $this->comercio->id])->id,
            ],
            'marca' => [
                $this->marca($this->comercio, 'Marca Mixta'),
                $this->marca($this->comercio, 'Marca Vacia'),
            ],
            'sub' => [
                $this->subCategoria($categoria, 'Sub Mixta')->id,
                $this->subCategoria($categoria, 'Sub Vacia')->id,
            ],
        ];

        $columnas = ['bodega' => 'bodega_id', 'cepa' => 'cepa_id', 'marca' => 'brand_id', 'sub' => 'sub_category_id'];

        foreach ($columnas as $tipo => $columna) {
            $base = ['category_id' => $categoria->id, $columna => $datos[$tipo][0]];

            $this->conImagen($this->articulo($this->comercio, $base + ['name' => 'Publicado '.$tipo]));
            $this->conImagen($this->articulo($this->comercio, $base + ['name' => 'No online '.$tipo, 'online' => 0]));
            $this->conImagen($this->articulo($this->comercio, $base + ['name' => 'Inactivo '.$tipo, 'status' => 'inactive']));
            $this->articulo($this->comercio, $base + ['name' => 'Sin imagen '.$tipo]);
            $this->conImagen($this->articulo($this->comercio, $base + ['name' => 'Sin stock '.$tipo, 'stock' => 0]));

            $vacia = ['category_id' => $categoria->id, $columna => $datos[$tipo][1]];
            $this->conImagen($this->articulo($this->comercio, $vacia + ['name' => 'Borrador '.$tipo, 'online' => 0]));
        }

        $datos['categoria'] = $categoria->id;

        return $datos;
    }

    /**
     * @param  \App\Article  $articulo
     * @return \App\Article
     */
    private function conImagen(Article $articulo)
    {
        $articulo->images()->create(['hosting_url' => 'https://img.test/'.$articulo->id.'.jpg']);

        return $articulo;
    }

    /**
     * @param  string  $url
     * @param  string  $clave  Clave de la respuesta que trae la lista.
     * @return array  [nombre => fila]
     */
    private function filasDe($url, $clave)
    {
        $respuesta = $this->json('GET', $url);
        $this->assertSame(200, $respuesta->getStatusCode(), $url);
        $filas = $respuesta->json($clave);

        $por_nombre = [];
        foreach ((array) $filas as $fila) {
            $por_nombre[$fila['name']] = $fila;
        }

        return $por_nombre;
    }

    /**
     * @param  string  $url  Un listado de articulos (pagina de 12).
     * @return int
     */
    private function cantidadDelListado($url)
    {
        $respuesta = $this->json('GET', $url);
        $this->assertSame(200, $respuesta->getStatusCode(), $url);

        return count($respuesta->json('articles.data'));
    }

    /** @return string */
    private function listadoDeCategoria($category_id, $sub_id, $bodega_id, $cepa_id)
    {
        return '/api/articles/from-category/'.$category_id.'/'.$sub_id.'/'.$bodega_id.'/'.$cepa_id.'/a-z/'.$this->comercio->id;
    }

    public function test_la_bodega_cuenta_solo_lo_que_el_listado_muestra()
    {
        $d = $this->armar(0, 0);

        $bodegas = $this->filasDe('/api/bodegas/'.$this->comercio->id, 'models');

        $this->assertSame(['Bodega Mixta'], array_keys($bodegas), 'la que solo tiene borradores no se devuelve');
        $this->assertSame(1, (int) $bodegas['Bodega Mixta']['articles_count']);
        $this->assertSame(
            $this->cantidadDelListado($this->listadoDeCategoria(0, 0, $d['bodega'][0], 0)),
            (int) $bodegas['Bodega Mixta']['articles_count']
        );
    }

    public function test_la_cepa_cuenta_solo_lo_que_el_listado_muestra()
    {
        $d = $this->armar(0, 0);

        $cepas = $this->filasDe('/api/cepas/'.$this->comercio->id, 'models');

        $this->assertSame(['Cepa Mixta'], array_keys($cepas));
        $this->assertSame(1, (int) $cepas['Cepa Mixta']['articles_count']);
        $this->assertSame(
            $this->cantidadDelListado($this->listadoDeCategoria(0, 0, 0, $d['cepa'][0])),
            (int) $cepas['Cepa Mixta']['articles_count']
        );
    }

    public function test_la_marca_cuenta_solo_lo_que_el_listado_muestra()
    {
        $d = $this->armar(0, 0);

        $marcas = $this->filasDe('/api/brands/'.$this->comercio->id, 'brands');

        $this->assertSame(['Marca Mixta'], array_keys($marcas));
        $this->assertSame(1, (int) $marcas['Marca Mixta']['articles_count']);
        $this->assertSame(
            $this->cantidadDelListado('/api/articles/from-brand/'.$d['marca'][0].'/a-z/'.$this->comercio->id),
            (int) $marcas['Marca Mixta']['articles_count']
        );
    }

    public function test_la_subcategoria_solo_viene_si_tiene_algo_para_mostrar_y_con_su_numero()
    {
        $d = $this->armar(0, 0);

        $subs = $this->filasDe('/api/sub-categories/'.$d['categoria'], 'sub_categories');

        $this->assertSame(['Sub Mixta'], array_keys($subs), 'la que solo tiene borradores no se devuelve');
        $this->assertSame(1, (int) $subs['Sub Mixta']['articles_count']);
        $this->assertSame(
            $this->cantidadDelListado($this->listadoDeCategoria(0, $d['sub'][0], 0, 0)),
            (int) $subs['Sub Mixta']['articles_count']
        );
    }

    /** Con la tienda mostrando sin imagen y sin stock, esos cuentan; lo no publicado, nunca. */
    public function test_los_numeros_siguen_la_configuracion_de_imagenes_y_stock()
    {
        $d = $this->armar(1, 1);

        $esperado = 3; // publicado + sin imagen + sin stock

        $bodegas = $this->filasDe('/api/bodegas/'.$this->comercio->id, 'models');
        $cepas = $this->filasDe('/api/cepas/'.$this->comercio->id, 'models');
        $marcas = $this->filasDe('/api/brands/'.$this->comercio->id, 'brands');
        $subs = $this->filasDe('/api/sub-categories/'.$d['categoria'], 'sub_categories');

        $this->assertSame($esperado, (int) $bodegas['Bodega Mixta']['articles_count']);
        $this->assertSame($esperado, (int) $cepas['Cepa Mixta']['articles_count']);
        $this->assertSame($esperado, (int) $marcas['Marca Mixta']['articles_count']);
        $this->assertSame($esperado, (int) $subs['Sub Mixta']['articles_count']);
        $this->assertSame($esperado, $this->cantidadDelListado($this->listadoDeCategoria(0, $d['sub'][0], 0, 0)));
    }

    /** Solo se exige stock: la imagen no filtra. Cada filtro cuenta por separado. */
    public function test_cada_filtro_de_la_configuracion_cuenta_por_separado()
    {
        $d = $this->armar(1, 0);

        $marcas = $this->filasDe('/api/brands/'.$this->comercio->id, 'brands');
        $bodegas = $this->filasDe('/api/bodegas/'.$this->comercio->id, 'models');

        $this->assertSame(2, (int) $marcas['Marca Mixta']['articles_count'], 'publicado + sin imagen; sin stock no');
        $this->assertSame(2, (int) $bodegas['Bodega Mixta']['articles_count']);
        $this->assertSame(
            $this->cantidadDelListado('/api/articles/from-brand/'.$d['marca'][0].'/a-z/'.$this->comercio->id),
            (int) $marcas['Marca Mixta']['articles_count']
        );
    }

    /** La forma de la respuesta sigue siendo una lista JSON ordenada por nombre. */
    public function test_las_respuestas_siguen_siendo_listas_ordenadas()
    {
        $this->armar(1, 1);

        $zeta = Bodega::create(['name' => 'Zeta', 'user_id' => $this->comercio->id]);
        $alfa = Bodega::create(['name' => 'Alfa', 'user_id' => $this->comercio->id]);
        foreach ([$zeta, $alfa] as $bodega) {
            $this->conImagen($this->articulo($this->comercio, ['name' => 'Art '.$bodega->name, 'bodega_id' => $bodega->id]));
        }

        $crudo = $this->json('GET', '/api/bodegas/'.$this->comercio->id)->assertStatus(200)->json('models');

        $this->assertSame(['Alfa', 'Bodega Mixta', 'Zeta'], array_column($crudo, 'name'));
        $this->assertSame(range(0, count($crudo) - 1), array_keys($crudo), 'una lista, no un objeto');
    }

    /**
     * Con lista restringida (el mayorista) los numeros de marcas y subcategorias son los del listado:
     * cuentan lo habilitado para su lista y publicado.
     */
    public function test_con_lista_restringida_marcas_y_subcategorias_son_las_del_listado()
    {
        $this->armarFerretotal();

        $habilitado_no_publicado = $this->articulo($this->comercio, [
            'name'            => 'Catalogo Taladro Habilitado Sin Publicar',
            'category_id'     => $this->herramientas->id,
            'sub_category_id' => $this->taladros->id,
            'online'          => 0,
        ]);
        $this->precioEnLista($habilitado_no_publicado, $this->mayorista, 1200, 1);

        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $subs = $this->filasDe('/api/sub-categories/'.$this->herramientas->id, 'sub_categories');
        $this->assertSame(['Taladros'], array_keys($subs));
        $this->assertSame(
            $this->cantidadDelListado($this->listadoDeCategoria(0, $this->taladros->id, 0, 0)),
            (int) $subs['Taladros']['articles_count']
        );

        $marcas = $this->filasDe('/api/brands/'.$this->comercio->id, 'brands');
        $this->assertSame(['Habilitada'], array_keys($marcas));
        $this->assertSame(1, (int) $marcas['Habilitada']['articles_count']);
    }

    /** Una categoria sin subcategorias sigue contestando una lista vacia (no un error). */
    public function test_una_categoria_sin_subcategorias_da_lista_vacia()
    {
        $this->comercio = $this->comercioConTienda();
        $categoria = $this->categoria($this->comercio, 'Sin subs');

        $this->json('GET', '/api/sub-categories/'.$categoria->id)
            ->assertStatus(200)
            ->assertExactJson(['sub_categories' => []]);
    }
}
