<?php

namespace Tests\Feature\Seo;

use App\Article;
use App\Bodega;
use App\PriceType;
use App\PromocionVinoteca;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La ficha en /api/seo/pagina: `/articulos/{slug}/{commerce_id}` (mision seo-tiendas).
 *
 * Las dos reglas que esta clase clava y que no se pueden aflojar:
 *   - Solo sale la ficha de un articulo que pasa checkOnline() de ESTE comercio. Offline o de
 *     otro negocio de la misma base → estado 404.
 *   - 🔴 El precio (offers.price del JSON-LD, y el importe del cuerpo y la descripcion) solo
 *     si es publico para un visitante ANONIMO, con la misma regla que ya aplica la API
 *     (ArticleHelper::anonimo_puede_ver_precios, ver tests/Feature/VisibilidadDePrecios).
 *     Publicarlo en Google cuando la tienda lo esconde es filtrarlo.
 */
class FichaDelArticuloTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaTiendaParaSeo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->armarComercio();
    }

    private function rutaDe($articulo)
    {
        return '/articulos/'.$articulo->slug.'/'.$this->comercio->id;
    }

    /** Ficha online con precio publico: Product con offers, BreadcrumbList, h1, imagen con alt. */
    public function test_ficha_online_con_precio_publico()
    {
        $categoria = $this->categoria('Tintos');
        $marca = $this->marca('Zuccardi');
        $bodega = Bodega::create(['name' => 'Familia Zuccardi '.$this->sufijo, 'user_id' => $this->comercio->id]);
        $articulo = $this->articulo('Malbec Serie A', [
            'category_id' => $categoria->id,
            'brand_id'    => $marca->id,
            'bodega_id'   => $bodega->id,
            'bar_code'    => '7790000000011',
            'final_price' => 15999.9,
        ]);
        $this->imagen($articulo, 'https://cdn.test/malbec-1.jpg');
        $this->imagen($articulo, 'https://cdn.test/malbec-2.jpg');
        $this->descripcion($articulo, 'Notas de cata', '<p>Rojo violáceo, con&nbsp;notas de <b>ciruela</b>.</p>');

        $canonica = 'https://mitienda.test/articulos/'.$articulo->slug.'/'.$this->comercio->id;
        $pagina = $this->pagina($this->rutaDe($articulo));

        $this->assertSame(200, $pagina['estado']);
        $this->assertSame('index,follow', $pagina['robots']);
        $this->assertSame('product', $pagina['og_type']);
        $this->assertSame($canonica, $pagina['canonica']);
        $this->assertSame('Malbec Serie A - '.$bodega->name.' | Vinoteca Test', $pagina['titulo']);
        $this->assertSame('https://cdn.test/malbec-1.jpg', $pagina['imagen']);
        $this->assertSame('Notas de cata Rojo violáceo, con notas de ciruela.', $pagina['descripcion']);

        $producto = $this->jsonLd($pagina, 'Product');
        $this->assertNotNull($producto);
        $this->assertSame('Malbec Serie A', $producto['name']);
        $this->assertSame(['https://cdn.test/malbec-1.jpg', 'https://cdn.test/malbec-2.jpg'], $producto['image']);
        $this->assertSame('7790000000011', $producto['sku']);
        $this->assertSame(['@type' => 'Brand', 'name' => $marca->name], $producto['brand']);
        $this->assertSame('15999.90', $producto['offers']['price']);
        $this->assertSame('ARS', $producto['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $producto['offers']['availability']);
        $this->assertSame($canonica, $producto['offers']['url']);

        $migas = $this->jsonLd($pagina, 'BreadcrumbList');
        $this->assertSame(['Inicio', $categoria->name, 'Malbec Serie A'], array_column($migas['itemListElement'], 'name'));

        $this->assertStringContainsString('<h1>Malbec Serie A</h1>', $pagina['cuerpo_html']);
        $this->assertStringContainsString('<img src="https://cdn.test/malbec-1.jpg" alt="Malbec Serie A">', $pagina['cuerpo_html']);
        $this->assertStringContainsString('$ 15.999,90', $pagina['cuerpo_html']);
    }

    /** El precio del anonimo es el de la lista de position mas alta, igual que en la tienda. */
    public function test_el_precio_es_el_de_la_lista_del_anonimo_y_suma_el_recargo_online()
    {
        $lista = new PriceType;
        $lista->name = 'Lista Publica SEO';
        $lista->position = 99999;
        $lista->user_id = $this->comercio->id;
        $lista->save();

        $articulo = $this->articulo('Syrah Lista', ['final_price' => 999]);
        $articulo->price_types()->attach($lista->id, ['final_price' => 1000]);
        $this->configurar(['online_price_surchage' => 10]);

        $producto = $this->jsonLd($this->pagina($this->rutaDe($articulo)), 'Product');

        $this->assertSame('1100.00', $producto['offers']['price'],
            'Lista publica (1000) + 10% de recargo online, redondeado como Math.round de la SPA.');
    }

    /**
     * 🔴 Con la tienda en "solo registrados", el anonimo no ve precios: el Product sale SIN
     * offers y el importe no aparece ni en el cuerpo ni en la descripcion.
     */
    public function test_ficha_con_precio_no_publico_no_publica_el_precio()
    {
        $this->visibilidad('only_registered', 1);
        $articulo = $this->articulo('Malbec Secreto', ['final_price' => 4321]);

        $pagina = $this->pagina($this->rutaDe($articulo));

        $this->assertSame(200, $pagina['estado']);
        $producto = $this->jsonLd($pagina, 'Product');
        $this->assertNotNull($producto);
        $this->assertArrayNotHasKey('offers', $producto, 'Sin precio publico, el Product va sin offers.');
        $this->assertStringNotContainsString('4.321', $pagina['cuerpo_html']);
        $this->assertStringNotContainsString('4321', $pagina['cuerpo_html']);
        $this->assertStringNotContainsString('4.321', $pagina['descripcion']);
        $this->assertStringNotContainsString('Precio', $pagina['cuerpo_html']);
    }

    /** Idem con la lista de position mas alta oculta al publico y ninguna otra visible. */
    public function test_con_todas_las_listas_ocultas_no_hay_precio()
    {
        $lista = new PriceType;
        $lista->name = 'Lista Oculta SEO';
        $lista->position = 99999;
        $lista->user_id = $this->comercio->id;
        $lista->ocultar_al_publico = 1;
        $lista->save();

        $otras = PriceType::where('user_id', $this->comercio->id)->where('id', '!=', $lista->id)->whereNotNull('position');
        $otras->update(['ocultar_al_publico' => 1]);

        $articulo = $this->articulo('Malbec Lista Oculta', ['final_price' => 777]);
        $articulo->price_types()->attach($lista->id, ['final_price' => 888]);

        $producto = $this->jsonLd($this->pagina($this->rutaDe($articulo)), 'Product');

        $this->assertArrayNotHasKey('offers', $producto);
    }

    /** Con precio pausado la tienda muestra un texto, no un importe: sin offers. */
    public function test_con_precio_pausado_no_hay_offers()
    {
        $articulo = $this->articulo('Malbec Pausado', ['precio_pausado' => 1]);

        $this->assertArrayNotHasKey('offers', $this->jsonLd($this->pagina($this->rutaDe($articulo)), 'Product'));
    }

    /** Sin stock la ficha existe igual (la tienda la muestra) y el Offer dice OutOfStock. */
    public function test_sin_stock_la_ficha_existe_con_out_of_stock()
    {
        $articulo = $this->articulo('Malbec Agotado', ['stock' => 0]);

        $pagina = $this->pagina($this->rutaDe($articulo));

        $this->assertSame(200, $pagina['estado']);
        $this->assertSame('https://schema.org/OutOfStock', $this->jsonLd($pagina, 'Product')['offers']['availability']);
    }

    /** 🔴 Un articulo offline no tiene ficha en SEO, aunque ArticleController@show lo devuelva. */
    public function test_articulo_offline_es_404()
    {
        $offline = $this->articulo('Malbec Offline', ['online' => 0]);
        $inactivo = $this->articulo('Malbec Inactivo', ['status' => 'inactive']);

        $this->assertSame(404, $this->pagina($this->rutaDe($offline))['estado']);
        $this->assertSame(404, $this->pagina($this->rutaDe($inactivo))['estado']);
    }

    /** Si la tienda exige imagenes, un articulo sin imagen no esta online (checkOnline). */
    public function test_sin_imagen_con_la_tienda_exigiendo_imagenes_es_404()
    {
        $this->configurar(['show_articles_without_images' => 0]);
        $sin_imagen = $this->articulo('Malbec Sin Foto');
        $con_imagen = $this->articulo('Malbec Con Foto');
        $this->imagen($con_imagen, 'https://cdn.test/foto.jpg');

        $this->assertSame(404, $this->pagina($this->rutaDe($sin_imagen))['estado']);
        $this->assertSame(200, $this->pagina($this->rutaDe($con_imagen))['estado']);
    }

    /**
     * 🔴 En una base compartida, el articulo de OTRO comercio no es ficha de esta tienda: ni
     * pidiendolo con el id de esta tienda en la URL, ni con el id del otro.
     */
    public function test_articulo_de_otro_comercio_es_404()
    {
        $otro = User::where('id', '!=', $this->comercio->id)->first();
        $this->assertNotNull($otro, 'La base del slot tiene que tener un segundo usuario.');

        $ajeno = Article::create([
            'name' => 'Malbec Ajeno', 'slug' => 'seo-ajeno-'.uniqid(), 'user_id' => $otro->id,
            'status' => 'active', 'online' => 1, 'stock' => 5, 'final_price' => 100,
        ]);

        $this->assertSame(404, $this->pagina('/articulos/'.$ajeno->slug.'/'.$this->comercio->id)['estado']);
        $this->assertSame(404, $this->pagina('/articulos/'.$ajeno->slug.'/'.$otro->id)['estado']);

        /* Y el propio, pedido con el id de otro comercio en la URL, tampoco. */
        $propio = $this->articulo('Malbec Propio');
        $this->assertSame(404, $this->pagina('/articulos/'.$propio->slug.'/'.$otro->id)['estado']);
    }

    /** Sin descripcion: la generica, con el precio si es publico. */
    public function test_sin_descripcion_usa_la_generica_con_precio()
    {
        $articulo = $this->articulo('Torrontes Joven', ['final_price' => 5000]);

        $this->assertSame(
            'Torrontes Joven a $ 5.000 en Vinoteca Test. Comprá online con envío o retiro en el local.',
            $this->pagina($this->rutaDe($articulo))['descripcion']
        );
    }

    /**
     * 🔴 Solo con el campo "Descripcion" del articulo (`articles.descripcion`), sin descripciones
     * con titulo: es el respaldo. Meta description, JSON-LD y la seccion "Descripcion" del cuerpo
     * tienen que salir de ese texto y NO de la generica (que era lo que veia Google).
     */
    public function test_el_campo_descripcion_es_el_respaldo_si_no_hay_descripciones_con_titulo()
    {
        $articulo = $this->articulo('Cesto Gris', [
            'final_price' => 5000,
            'descripcion' => "<p>Cesto de basura con porta papel.</p>\nMedidas: 30 x 20&nbsp;cm.",
        ]);

        $pagina = $this->pagina($this->rutaDe($articulo));

        $this->assertSame('Cesto de basura con porta papel. Medidas: 30 x 20 cm.', $pagina['descripcion']);
        $this->assertStringNotContainsString('Comprá online', $pagina['descripcion']);

        $producto = $this->jsonLd($pagina, 'Product');
        $this->assertSame('Cesto de basura con porta papel. Medidas: 30 x 20 cm.', $producto['description']);

        $this->assertStringContainsString('<h2>Descripción</h2>', $pagina['cuerpo_html']);
        $this->assertStringContainsString('Cesto de basura con porta papel.', $pagina['cuerpo_html']);
    }

    /** Con descripciones con titulo, el texto suelto NO se usa (igual que en la pagina visible). */
    public function test_con_descripciones_con_titulo_el_campo_descripcion_no_se_usa()
    {
        $articulo = $this->articulo('Cesto Negro', ['descripcion' => 'TEXTO SUELTO QUE NO DEBE SALIR']);
        $this->descripcion($articulo, 'Material', 'Plástico de alta densidad.');

        $pagina = $this->pagina($this->rutaDe($articulo));

        $this->assertSame('Material Plástico de alta densidad.', $pagina['descripcion']);
        $this->assertStringNotContainsString('TEXTO SUELTO', json_encode($pagina, JSON_UNESCAPED_UNICODE));
    }

    /** Una descripcion con titulo vacia no cuenta: cae al campo "Descripcion". */
    public function test_una_descripcion_vacia_cae_al_campo_descripcion()
    {
        $articulo = $this->articulo('Cesto Blanco', ['descripcion' => 'Respaldo del campo descripcion.']);
        $this->descripcion($articulo, '', '<p> </p>');

        $this->assertSame(
            'Respaldo del campo descripcion.',
            $this->pagina($this->rutaDe($articulo))['descripcion']
        );
    }

    /** Un campo "Descripcion" en blanco no pisa la generica; y uno largo se corta en palabra. */
    public function test_el_campo_descripcion_en_blanco_deja_la_generica_y_el_largo_se_corta()
    {
        $en_blanco = $this->articulo('Cesto Rojo', ['final_price' => 5000, 'descripcion' => '   ']);
        $this->assertStringContainsString(
            'Comprá online con envío o retiro en el local.',
            $this->pagina($this->rutaDe($en_blanco))['descripcion']
        );

        $largo = $this->articulo('Cesto Largo', ['descripcion' => str_repeat('palabra ', 60)]);
        $descripcion = $this->pagina($this->rutaDe($largo))['descripcion'];
        $this->assertLessThanOrEqual(160, mb_strlen($descripcion));
        $this->assertStringEndsWith('palabra…', $descripcion);
    }

    /** Una descripcion larga se corta en palabra, con '…', sin pasar de 160. */
    public function test_la_descripcion_larga_se_corta_en_palabra()
    {
        $articulo = $this->articulo('Blend Largo');
        $this->descripcion($articulo, null, str_repeat('palabra ', 60));

        $descripcion = $this->pagina($this->rutaDe($articulo))['descripcion'];

        $this->assertLessThanOrEqual(160, mb_strlen($descripcion));
        $this->assertStringEndsWith('palabra…', $descripcion);
    }

    /** Sin imagen propia, `imagen` cae a la imagen por defecto de la tienda. */
    public function test_sin_imagen_propia_usa_la_imagen_por_defecto()
    {
        $this->configurar(['default_article_image_url' => 'https://cdn.test/default.jpg']);
        $articulo = $this->articulo('Rosado Sin Foto');

        $pagina = $this->pagina($this->rutaDe($articulo));

        $this->assertSame('https://cdn.test/default.jpg', $pagina['imagen']);
        $this->assertSame(['https://cdn.test/default.jpg'], $this->jsonLd($pagina, 'Product')['image']);
    }

    /** Una PromocionVinoteca online es ficha valida por la misma ruta; offline, 404. */
    public function test_promocion_vinoteca_online_es_ficha()
    {
        $promo = PromocionVinoteca::create([
            'name' => 'Promo 3 Malbec', 'slug' => 'seo-promo-'.uniqid(), 'user_id' => $this->comercio->id,
            'online' => 1, 'final_price' => 30000, 'stock' => 3, 'description' => 'Tres botellas.',
        ]);
        $promo_offline = PromocionVinoteca::create([
            'name' => 'Promo Oculta', 'slug' => 'seo-promo-off-'.uniqid(), 'user_id' => $this->comercio->id,
            'online' => 0, 'final_price' => 1,
        ]);

        $pagina = $this->pagina('/articulos/'.$promo->slug.'/'.$this->comercio->id);

        $this->assertSame(200, $pagina['estado']);
        $this->assertSame('product', $pagina['og_type']);
        $this->assertSame('Promo 3 Malbec | Vinoteca Test', $pagina['titulo']);
        $this->assertSame('30000.00', $this->jsonLd($pagina, 'Product')['offers']['price']);

        $this->assertSame(404, $this->pagina('/articulos/'.$promo_offline->slug.'/'.$this->comercio->id)['estado']);
    }
}
