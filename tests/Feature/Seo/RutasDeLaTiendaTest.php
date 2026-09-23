<?php

namespace Tests\Feature\Seo;

use App\Article;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Las reglas de ruta del contrato A (mision seo-tiendas): que es cada `ruta`, con que `estado`,
 * que `robots` y que canonica. La canonica tiene que dar IGUAL que la que arma la SPA, que es
 * `routeString(name)` = minusculas + espacios a guiones, sin tocar tildes.
 */
class RutasDeLaTiendaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaTiendaParaSeo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->armarComercio();
    }

    /** `/`, `/inicio` y `/inicio/ultimos-ingresados` son la home, con Store + WebSite. */
    public function test_la_home_con_sus_tres_rutas()
    {
        $articulo = $this->articulo('Cabernet Home '.$this->sufijo);

        foreach (['/', '/inicio', '/inicio/ultimos-ingresados'] as $ruta) {
            $pagina = $this->pagina($ruta);

            $this->assertSame(200, $pagina['estado'], $ruta);
            $this->assertSame('index,follow', $pagina['robots'], $ruta);
            $this->assertSame('https://mitienda.test/', $pagina['canonica'], $ruta);
            $this->assertSame('Vinoteca Test', $pagina['titulo'], $ruta);
            $this->assertSame('website', $pagina['og_type']);
        }

        $store = $this->jsonLd($pagina, 'Store');
        $this->assertNotNull($store, 'La home lleva JSON-LD Store.');
        $this->assertSame('Vinoteca Test', $store['name']);
        $this->assertSame('https://mitienda.test/', $store['url']);
        $this->assertSame('+54 9 342 555-1234', $store['telephone']);
        $this->assertSame('ventas@mitienda.test', $store['email']);
        $this->assertNotNull($this->jsonLd($pagina, 'WebSite'), 'La home lleva JSON-LD WebSite.');

        $this->assertStringContainsString('<h1>Vinoteca Test</h1>', $pagina['cuerpo_html']);
        $this->assertStringContainsString(
            'href="https://mitienda.test/articulos/'.$articulo->slug.'/'.$this->comercio->id.'"',
            $pagina['cuerpo_html'],
            'La home tiene que linkear las fichas con <a href> reales: es lo que hoy Google no encuentra.'
        );
        $this->assertStringContainsString('href="tel:+5493425551234"', $pagina['cuerpo_html']);
        $this->assertStringContainsString('href="mailto:ventas@mitienda.test"', $pagina['cuerpo_html']);
        $this->assertStringContainsString('href="https://mitienda.test/terminos-y-condiciones"', $pagina['cuerpo_html']);
    }

    /** La meta_description cargada en el admin manda sobre la descripcion armada. */
    public function test_la_home_usa_la_meta_description_del_comercio_si_esta_cargada()
    {
        $this->configurar(['meta_description' => '<p>Los mejores vinos de Santa Fe.</p>']);

        $this->assertSame('Los mejores vinos de Santa Fe.', $this->pagina('/')['descripcion']);
    }

    /**
     * Categoria existente: 200, index, canonica con slugRuta del nombre (aunque la ruta llegue
     * en mayusculas o percent-encoded), BreadcrumbList + ItemList, links a las fichas.
     */
    public function test_una_categoria_existente()
    {
        $categoria = $this->categoria('Vinos Tintos Añejos');
        $online = $this->articulo('Malbec Reserva', ['category_id' => $categoria->id]);
        $offline = $this->articulo('Malbec Oculto', ['category_id' => $categoria->id, 'online' => 0]);

        $slug = $this->slugRuta($categoria->name);
        $canonica = 'https://mitienda.test/inicio/'.$slug;

        foreach (['/inicio/'.$slug, '/inicio/'.rawurlencode($slug), '/inicio/'.mb_strtoupper($slug)] as $ruta) {
            $pagina = $this->pagina($ruta);

            $this->assertSame(200, $pagina['estado'], $ruta);
            $this->assertSame('index,follow', $pagina['robots'], $ruta);
            $this->assertSame($canonica, $pagina['canonica'], $ruta);
        }

        $this->assertSame($categoria->name.' | Vinoteca Test', $pagina['titulo']);
        $this->assertStringContainsString('1 producto', $pagina['descripcion']);
        $this->assertStringContainsString('<h1>'.e($categoria->name).'</h1>', $pagina['cuerpo_html']);
        $this->assertStringContainsString('/articulos/'.$online->slug.'/', $pagina['cuerpo_html']);
        $this->assertStringNotContainsString($offline->slug, $pagina['cuerpo_html'], 'Un articulo offline no se lista.');

        $migas = $this->jsonLd($pagina, 'BreadcrumbList');
        $this->assertNotNull($migas);
        $this->assertSame($canonica, end($migas['itemListElement'])['item']);

        $lista = $this->jsonLd($pagina, 'ItemList');
        $this->assertNotNull($lista);
        $this->assertSame(
            ['https://mitienda.test/articulos/'.$online->slug.'/'.$this->comercio->id],
            array_column($lista['itemListElement'], 'url')
        );
    }

    /** Categoria que no existe (o que es de otro comercio): estado 404, sin canonica. */
    public function test_una_categoria_inexistente_es_404()
    {
        $pagina = $this->pagina('/inicio/no-existe-'.$this->sufijo);

        $this->assertSame(404, $pagina['estado']);
        $this->assertSame('noindex,follow', $pagina['robots']);
        $this->assertNull($pagina['canonica']);
        $this->assertSame([], $pagina['json_ld']);
    }

    /** "La de siempre" es un filtro interno que la tienda no muestra: 404. */
    public function test_la_categoria_la_de_siempre_no_existe_para_la_tienda()
    {
        \App\Category::create(['name' => 'La de siempre', 'user_id' => $this->comercio->id]);

        $this->assertSame(404, $this->pagina('/inicio/la-de-siempre')['estado']);
    }

    /** Una categoria que existe pero no tiene nada online: 200 (la SPA la muestra) pero noindex. */
    public function test_una_categoria_vacia_existe_pero_no_se_indexa()
    {
        $categoria = $this->categoria('Categoria Vacia');

        $pagina = $this->pagina('/inicio/'.$this->slugRuta($categoria->name));

        $this->assertSame(200, $pagina['estado']);
        $this->assertSame('noindex,follow', $pagina['robots']);
    }

    /** La categoria linkea a sus subcategorias con articulos, y la subcategoria resuelve. */
    public function test_una_subcategoria()
    {
        $categoria = $this->categoria('Espumantes');
        $sub = $this->subcategoria($categoria, 'Extra Brut');
        $articulo = $this->articulo('Champagne X', ['category_id' => $categoria->id, 'sub_category_id' => $sub->id]);

        $cat_slug = $this->slugRuta($categoria->name);
        $sub_slug = $this->slugRuta($sub->name);
        $canonica = 'https://mitienda.test/inicio/'.$cat_slug.'/'.$sub_slug;

        $de_la_categoria = $this->pagina('/inicio/'.$cat_slug);
        $this->assertStringContainsString('href="'.$canonica.'"', $de_la_categoria['cuerpo_html'],
            'La categoria linkea a su subcategoria.');

        $pagina = $this->pagina('/inicio/'.$cat_slug.'/'.$sub_slug);
        $this->assertSame(200, $pagina['estado']);
        $this->assertSame('index,follow', $pagina['robots']);
        $this->assertSame($canonica, $pagina['canonica']);
        $this->assertStringContainsString('/articulos/'.$articulo->slug.'/', $pagina['cuerpo_html']);

        $nombres = array_column($this->jsonLd($pagina, 'BreadcrumbList')['itemListElement'], 'name');
        $this->assertSame(['Inicio', $categoria->name, $sub->name], $nombres);

        /* Una subcategoria que existe pero cuelga de OTRA categoria no es esta ruta. */
        $otra = $this->categoria('Otra Cat');
        $this->assertSame(404, $this->pagina('/inicio/'.$this->slugRuta($otra->name).'/'.$sub_slug)['estado']);
    }

    /** Marca: /inicio/marca/{x}. */
    public function test_una_marca()
    {
        $marca = $this->marca('Bodega Catena');
        $articulo = $this->articulo('Angelica Zapata', ['brand_id' => $marca->id]);

        $pagina = $this->pagina('/inicio/marca/'.$this->slugRuta($marca->name));

        $this->assertSame(200, $pagina['estado']);
        $this->assertSame('https://mitienda.test/inicio/marca/'.$this->slugRuta($marca->name), $pagina['canonica']);
        $this->assertSame($marca->name.' | Vinoteca Test', $pagina['titulo']);
        $this->assertStringContainsString('/articulos/'.$articulo->slug.'/', $pagina['cuerpo_html']);

        $this->assertSame(404, $this->pagina('/inicio/marca/no-existe-'.$this->sufijo)['estado']);
    }

    /** Las privadas existen (200) pero van con noindex y su propia ruta como canonica. */
    public function test_las_rutas_privadas_van_con_noindex()
    {
        foreach (['/carrito', '/login', '/registro/cliente', '/pago-exitoso', '/buscar', '/auth/google/callback', '/seleccion-especial/1-2'] as $ruta) {
            $pagina = $this->pagina($ruta);

            $this->assertSame(200, $pagina['estado'], $ruta);
            $this->assertSame('noindex,follow', $pagina['robots'], $ruta);
            $this->assertSame('https://mitienda.test'.$ruta, $pagina['canonica'], $ruta);
            $this->assertSame([], $pagina['json_ld'], $ruta);
        }
    }

    /** Las institucionales se indexan. */
    public function test_las_institucionales_se_indexan()
    {
        foreach (['/contacto', '/quienes-somos', '/terminos-y-condiciones', '/politica-de-privacidad', '/promociones', '/catalogo', '/ayuda'] as $ruta) {
            $pagina = $this->pagina($ruta);

            $this->assertSame(200, $pagina['estado'], $ruta);
            $this->assertSame('index,follow', $pagina['robots'], $ruta);
            $this->assertSame('https://mitienda.test'.$ruta, $pagina['canonica'], $ruta);
        }
    }

    /** Cualquier otra ruta: estado 404 (hoy es un soft 404 con 200 y el index). */
    public function test_una_ruta_desconocida_es_404()
    {
        foreach (['/cualquier-cosa', '/wp-admin', '/inicio/a/b/c', '/articulos/solo-slug', '/carrito/extra', '/sitemap.xml'] as $ruta) {
            $pagina = $this->pagina($ruta);

            $this->assertSame(404, $pagina['estado'], $ruta);
            $this->assertSame('noindex,follow', $pagina['robots'], $ruta);
            $this->assertNull($pagina['canonica'], $ruta);
        }
    }

    /**
     * 🔴 Todo lo que viene de la base sale escapado en cuerpo_html: un nombre con <script>
     * cargado en el ERP no puede convertirse en un XSS servido desde el dominio de la tienda.
     */
    public function test_los_nombres_con_html_salen_escapados()
    {
        $categoria = $this->categoria('<script>alert("cat")</script>');
        $articulo = $this->articulo('<script>alert("art")</script> <img src=x onerror=alert(1)>', ['category_id' => $categoria->id]);
        $this->descripcion($articulo, '<b>titulo</b>', '<script>alert("desc")</script><p>Texto</p>');

        $paginas = [
            $this->pagina('/'),
            $this->pagina('/inicio/'.rawurlencode($this->slugRuta($categoria->name))),
            $this->pagina('/articulos/'.$articulo->slug.'/'.$this->comercio->id),
        ];

        foreach ($paginas as $pagina) {
            $this->assertStringNotContainsString('<script>', $pagina['cuerpo_html']);
            $this->assertStringNotContainsString('<img src=x', $pagina['cuerpo_html']);
            $this->assertStringContainsString('&lt;script&gt;', $pagina['cuerpo_html']);
        }

        $ficha = $paginas[2];
        $this->assertSame(200, $ficha['estado']);
        $this->assertStringNotContainsString('<', $ficha['descripcion'], 'La descripcion es texto plano.');
    }
}
