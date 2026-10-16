<?php

namespace Tests\Feature\Home;

use App\Article;
use App\OnlineConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\CatalogoPorLista\ArmaCatalogoPorLista;
use Tests\TestCase;

/**
 * El numero del panel lateral de categorias (mision categorias-contador-publicado, 16/10/2026).
 *
 * `HomeController::categories()` contaba los articulos de cada categoria y subcategoria con
 * `withCount('articles')` pelado: el numero incluia articulos no publicados (`online = 0`, status no
 * activo), sin imagen cuando la tienda no los muestra y sin stock cuando tampoco. El listado real
 * (`articlesFromCategory`) filtra con `checkOnline()` + `checkStock()`, asi que el panel prometia mas
 * de lo que despues aparecia al abrir la categoria.
 *
 * Lo que fijan estos casos: `articles_count` es EXACTAMENTE la cantidad de articulos que devuelve el
 * listado de esa categoria / subcategoria, y lo que queda en 0 no se devuelve (decision de Lucas,
 * 16/10/2026). El criterio vive entero en los scopes de `Article`: aca no se lo reimplementa, se lo
 * compara contra el endpoint del listado.
 *
 * Se usa el fixture de `ArmaCatalogoPorLista` por lo que ya trae (comercio propio con su
 * configuracion, categorias, subcategorias, listas y articulos) y para el caso con lista restringida.
 */
class ContadorDeCategoriasTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCatalogoPorLista;

    /** @var \App\Category */
    private $mixta;

    /** @var \App\SubCategory */
    private $sub_mixta;

    /** @var \App\SubCategory */
    private $sub_borradores;

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
     * Un comercio sin lista restringida y una categoria "Mixta" con un articulo de cada tipo:
     *   - publicado        -> online, activo, con imagen, con stock.
     *   - no_online        -> `online = 0`.
     *   - inactivo         -> `status` distinto de `active`.
     *   - sin_imagen       -> publicado pero sin imagen.
     *   - sin_stock        -> publicado, con imagen, `stock = 0`.
     * La subcategoria "Mixta Sub" tiene el publicado y el no_online; "Solo Borradores" (de la misma
     * categoria) tiene unicamente un articulo `online = 0`. Y una segunda categoria, "Solo Borradores
     * Cat", tiene unicamente articulos que el listado no muestra.
     *
     * @param  int  $con_imagenes  `show_articles_without_images`.
     * @param  int  $con_stock  `show_articles_without_stock`.
     * @return void
     */
    private function armarCategoriaMixta($con_imagenes, $con_stock)
    {
        $this->comercio = $this->comercioConTienda();

        OnlineConfiguration::where('user_id', $this->comercio->id)->update([
            'show_articles_without_images' => $con_imagenes,
            'show_articles_without_stock'  => $con_stock,
        ]);

        $this->mixta = $this->categoria($this->comercio, 'Mixta');
        $this->sub_mixta = $this->subCategoria($this->mixta, 'Mixta Sub');
        $this->sub_borradores = $this->subCategoria($this->mixta, 'Solo Borradores');

        $en_mixta = ['category_id' => $this->mixta->id, 'sub_category_id' => $this->sub_mixta->id];

        $this->conImagen($this->articulo($this->comercio, $en_mixta + ['name' => 'Publicado']));
        $this->conImagen($this->articulo($this->comercio, $en_mixta + ['name' => 'No online', 'online' => 0]));
        $this->conImagen($this->articulo($this->comercio, ['category_id' => $this->mixta->id, 'name' => 'Inactivo', 'status' => 'inactive']));
        $this->articulo($this->comercio, ['category_id' => $this->mixta->id, 'name' => 'Sin imagen']);
        $this->conImagen($this->articulo($this->comercio, ['category_id' => $this->mixta->id, 'name' => 'Sin stock', 'stock' => 0]));

        $this->conImagen($this->articulo($this->comercio, [
            'category_id'     => $this->mixta->id,
            'sub_category_id' => $this->sub_borradores->id,
            'name'            => 'Borrador de la sub',
            'online'          => 0,
        ]));

        $solo_borradores = $this->categoria($this->comercio, 'Solo Borradores Cat');
        $this->conImagen($this->articulo($this->comercio, ['category_id' => $solo_borradores->id, 'name' => 'Borrador de la cat', 'online' => 0]));
        $this->conImagen($this->articulo($this->comercio, ['category_id' => $solo_borradores->id, 'name' => 'Otro inactivo', 'status' => 'inactive']));
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
     * 🔴 El caso del pedido: una categoria con articulos publicados y no publicados cuenta SOLO los
     * que el listado muestra. La tienda no muestra sin imagen ni sin stock: de los cinco articulos de
     * "Mixta" (mas el de la subcategoria), cuenta uno.
     */
    public function test_la_categoria_cuenta_solo_lo_que_el_listado_muestra()
    {
        $this->armarCategoriaMixta(0, 0);

        $categorias = $this->categorias();

        $this->assertSame(['Mixta'], array_keys($categorias),
            '"Solo Borradores Cat" no tiene nada para mostrar: no se devuelve');

        $this->assertSame(1, (int) $categorias['Mixta']['articles_count'],
            'online=0, inactivo, sin imagen y sin stock no cuentan');
        $this->assertSame($this->cantidadDelListado($this->mixta->id, 0), (int) $categorias['Mixta']['articles_count'],
            'el numero del panel es el del listado de la categoria');

        $subs = $this->porNombre($categorias['Mixta']['sub_categories']);
        $this->assertSame(['Mixta Sub'], array_keys($subs), '"Solo Borradores" quedo en 0: no se devuelve');
        $this->assertSame(1, (int) $subs['Mixta Sub']['articles_count']);
        $this->assertSame($this->cantidadDelListado(0, $this->sub_mixta->id), (int) $subs['Mixta Sub']['articles_count'],
            'el numero de la subcategoria es el del listado de la subcategoria');
    }

    /**
     * La cuenta sigue a la configuracion de la tienda, igual que el listado: si la tienda muestra
     * articulos sin imagen y sin stock, esos cuentan; lo no publicado (`online = 0`, inactivo) sigue
     * sin contar nunca.
     */
    public function test_la_cuenta_sigue_la_configuracion_de_imagenes_y_stock_de_la_tienda()
    {
        $this->armarCategoriaMixta(1, 1);

        $categorias = $this->categorias();

        $this->assertSame(['Mixta'], array_keys($categorias));
        $this->assertSame(3, (int) $categorias['Mixta']['articles_count'],
            'publicado + sin imagen + sin stock; online=0 e inactivo no');
        $this->assertSame($this->cantidadDelListado($this->mixta->id, 0), (int) $categorias['Mixta']['articles_count']);
    }

    /** Solo se muestra lo que tiene stock, pero una tienda que no exige imagen no las filtra. */
    public function test_cada_filtro_de_la_configuracion_cuenta_por_separado()
    {
        $this->armarCategoriaMixta(1, 0);

        $categorias = $this->categorias();

        $this->assertSame(2, (int) $categorias['Mixta']['articles_count'], 'publicado + sin imagen; sin stock no');
        $this->assertSame($this->cantidadDelListado($this->mixta->id, 0), (int) $categorias['Mixta']['articles_count']);
    }

    /**
     * Con lista restringida (el mayorista) el numero tambien es el del listado: el criterio de la
     * lista siempre estuvo, y ahora se suma lo publicado. Al `habilitado` del fixture se le agrega un
     * articulo habilitado pero `online = 0`: es de la lista, pero no esta publicado.
     */
    public function test_con_lista_restringida_el_numero_tambien_es_el_del_listado()
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

        $categorias = $this->categorias();

        $this->assertSame(['Herramientas'], array_keys($categorias));
        $this->assertSame(1, (int) $categorias['Herramientas']['articles_count'],
            'solo el habilitado y publicado; el sin marcar no es de la lista y el otro no esta publicado');
        $this->assertSame($this->cantidadDelListado($this->herramientas->id, 0), (int) $categorias['Herramientas']['articles_count']);

        $subs = $this->porNombre($categorias['Herramientas']['sub_categories']);
        $this->assertSame(['Taladros'], array_keys($subs));
        $this->assertSame(1, (int) $subs['Taladros']['articles_count']);
        $this->assertSame($this->cantidadDelListado(0, $this->taladros->id), (int) $subs['Taladros']['articles_count']);
    }

    /**
     * Sin lista restringida y con todo publicado, el numero no cambia: es el de siempre. (Y una
     * categoria publicada no se pierde.)
     */
    public function test_el_visitante_con_todo_publicado_ve_el_numero_de_siempre()
    {
        $this->armarFerretotal();

        $this->comoVisitante();

        $categorias = $this->categorias();

        $this->assertSame(['Herramientas', 'Solo Minorista'], array_keys($categorias));
        $this->assertSame(2, (int) $categorias['Herramientas']['articles_count']);
        $this->assertSame(1, (int) $categorias['Solo Minorista']['articles_count']);
        $this->assertSame($this->cantidadDelListado($this->herramientas->id, 0), (int) $categorias['Herramientas']['articles_count']);
    }

    /** @return array Las categorias de la respuesta, por nombre. */
    private function categorias()
    {
        return $this->porNombre($this->json('GET', '/api/categories/'.$this->comercio->id)->assertStatus(200)->json('categories'));
    }

    /**
     * Cuantos articulos devuelve el listado real de una categoria o subcategoria, pidiendo una
     * pagina de 12 (los casos de esta clase tienen menos de 12).
     *
     * @param  int  $category_id  0 si se pide por subcategoria.
     * @param  int  $sub_category_id  0 si se pide por categoria.
     * @return int
     */
    private function cantidadDelListado($category_id, $sub_category_id)
    {
        $url = '/api/articles/from-category/'.$category_id.'/'.$sub_category_id.'/0/0/a-z/'.$this->comercio->id;

        $articulos = $this->json('GET', $url)->assertStatus(200)->json('articles.data');

        return count($articulos);
    }

    /**
     * @param  array|null  $modelos
     * @return array
     */
    private function porNombre($modelos)
    {
        $por_nombre = [];

        foreach ((array) $modelos as $modelo) {
            $por_nombre[$modelo['name']] = $modelo;
        }

        ksort($por_nombre);

        return $por_nombre;
    }
}
