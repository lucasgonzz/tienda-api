<?php

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * GET /api/seo/sitemap/{commerce_id} (mision seo-tiendas): XML valido del protocolo 0.9, con
 * las fichas online y sin las que la tienda no muestra.
 */
class SitemapTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaTiendaParaSeo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->armarComercio();
        Cache::flush();
    }

    /**
     * El sitemap parseado, validando status y content-type.
     *
     * @param  string  $sitio
     * @return \SimpleXMLElement
     */
    private function sitemap($sitio = 'https://mitienda.test')
    {
        $respuesta = $this->get('/api/seo/sitemap/'.$this->comercio->id.'?sitio='.urlencode($sitio));
        $respuesta->assertStatus(200);
        $this->assertStringStartsWith('application/xml', $respuesta->headers->get('Content-Type'));

        $previo = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($respuesta->getContent());
        $errores = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        $this->assertNotFalse($xml, 'El sitemap tiene que ser XML valido: '.json_encode($errores));
        $this->assertSame('urlset', $xml->getName());
        $this->assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $xml->getNamespaces()['']);

        return $xml;
    }

    /**
     * @param  \SimpleXMLElement  $xml
     * @return array<int, string>
     */
    private function locs($xml)
    {
        $locs = [];
        foreach ($xml->url as $url) {
            $locs[] = (string) $url->loc;
        }
        return $locs;
    }

    public function test_el_sitemap_trae_la_ficha_online_y_no_la_offline()
    {
        $categoria = $this->categoria('Blancos Ñandú');
        $marca = $this->marca('Marca Sitemap');
        $online = $this->articulo('Chardonnay Online', ['category_id' => $categoria->id, 'brand_id' => $marca->id]);
        $this->imagen($online, 'https://cdn.test/chardonnay.jpg');
        $offline = $this->articulo('Chardonnay Offline', ['category_id' => $categoria->id, 'online' => 0]);

        $xml = $this->sitemap();
        $locs = $this->locs($xml);

        $url_online = 'https://mitienda.test/articulos/'.$online->slug.'/'.$this->comercio->id;
        $this->assertContains('https://mitienda.test/', $locs);
        $this->assertContains($url_online, $locs);
        $this->assertNotContains('https://mitienda.test/articulos/'.$offline->slug.'/'.$this->comercio->id, $locs);

        /* La categoria con eñe va percent-encoded, como pide el protocolo (RFC 3986). */
        $this->assertContains('https://mitienda.test/inicio/'.rawurlencode($this->slugRuta($categoria->name)), $locs);
        $this->assertContains('https://mitienda.test/inicio/marca/'.$this->slugRuta($marca->name), $locs);

        /* La ficha lleva lastmod e image:image. */
        foreach ($xml->url as $url) {
            if ((string) $url->loc === $url_online) {
                $this->assertNotSame('', (string) $url->lastmod);
                $imagen = $url->children('http://www.google.com/schemas/sitemap-image/1.1')->image;
                $this->assertSame('https://cdn.test/chardonnay.jpg', (string) $imagen->loc);
            }
        }
    }

    /** Una categoria sin nada online no entra al sitemap. */
    public function test_una_categoria_sin_articulos_online_no_entra()
    {
        $vacia = $this->categoria('Vacia Sitemap');
        $this->articulo('Solo Offline', ['category_id' => $vacia->id, 'online' => 0]);

        $this->assertNotContains(
            'https://mitienda.test/inicio/'.$this->slugRuta($vacia->name),
            $this->locs($this->sitemap())
        );
    }

    /** Un sitio malicioso se ignora: las URLs salen con el `online` del comercio. */
    public function test_un_sitio_malicioso_se_ignora()
    {
        $locs = $this->locs($this->sitemap('javascript:alert(1)'));

        $this->assertContains('https://mitienda.test/', $locs);
        foreach ($locs as $loc) {
            $this->assertStringStartsWith('https://mitienda.test/', $loc);
        }
    }

    /** Sin ningun sitio valido sale un urlset vacio pero valido (un sitemap no admite URLs relativas). */
    public function test_sin_sitio_sale_un_urlset_vacio_y_valido()
    {
        \App\User::where('id', $this->comercio->id)->update(['online' => null]);

        $this->assertSame(0, count($this->locs($this->sitemap(''))));
    }

    /** Un comercio que no existe es un 404 HTTP. */
    public function test_un_comercio_inexistente_es_404()
    {
        $this->get('/api/seo/sitemap/999999999')->assertStatus(404);
    }
}
