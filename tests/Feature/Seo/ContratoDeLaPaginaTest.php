<?php

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El contrato A de la mision seo-tiendas: la forma de GET /api/seo/pagina/{commerce_id}.
 *
 * 🔴 seo.php (tienda-spa) lee EXACTAMENTE estas nueve claves y no depende de ninguna otra. Si
 * este test se pone rojo porque cambio un nombre o un tipo, lo que se rompe no es un test: es
 * el head de todas las tiendas, sin que nada avise (seo.php cae al index.html de siempre).
 */
class ContratoDeLaPaginaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaTiendaParaSeo;

    const CLAVES = ['estado', 'titulo', 'descripcion', 'canonica', 'imagen', 'robots', 'og_type', 'json_ld', 'cuerpo_html'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->armarComercio();
    }

    /**
     * Las nueve claves, ni una mas ni una menos, con sus tipos, en cada clase de pagina.
     */
    public function test_las_nueve_claves_exactas_con_sus_tipos_en_cada_clase_de_pagina()
    {
        $articulo = $this->articulo('Malbec Contrato');

        $rutas = [
            '/',
            '/inicio/no-existe-'.$this->sufijo,
            '/articulos/'.$articulo->slug.'/'.$this->comercio->id,
            '/carrito',
            '/contacto',
        ];

        foreach ($rutas as $ruta) {
            $pagina = $this->pagina($ruta);

            $this->assertSame(self::CLAVES, array_keys($pagina), 'Claves de la respuesta para '.$ruta);
            $this->assertIsInt($pagina['estado']);
            $this->assertContains($pagina['estado'], [200, 404]);
            $this->assertIsString($pagina['titulo']);
            $this->assertNotSame('', $pagina['titulo']);
            $this->assertIsString($pagina['descripcion']);
            $this->assertLessThanOrEqual(160, mb_strlen($pagina['descripcion']));
            $this->assertSame(strip_tags($pagina['descripcion']), $pagina['descripcion'], 'La descripcion es texto plano.');
            $this->assertTrue(is_null($pagina['canonica']) || is_string($pagina['canonica']));
            $this->assertTrue(is_null($pagina['imagen']) || is_string($pagina['imagen']));
            $this->assertContains($pagina['robots'], ['index,follow', 'noindex,follow']);
            $this->assertContains($pagina['og_type'], ['website', 'product']);
            $this->assertIsArray($pagina['json_ld']);
            $this->assertTrue(array_is_list($pagina['json_ld']), 'json_ld es una lista de objetos.');
            $this->assertIsString($pagina['cuerpo_html']);
            $this->assertStringContainsString('<header>', $pagina['cuerpo_html']);
            $this->assertStringContainsString('<main>', $pagina['cuerpo_html']);
            $this->assertStringContainsString('<footer>', $pagina['cuerpo_html']);

            if ($pagina['estado'] === 404) {
                $this->assertNull($pagina['canonica'], 'Una 404 no tiene canonica.');
            }
        }
    }

    /** Las URLs salen sin barras escapadas (JSON_UNESCAPED_SLASHES) y los acentos sin \u. */
    public function test_el_json_sale_sin_barras_ni_acentos_escapados()
    {
        $crudo = $this->getJson('/api/seo/pagina/'.$this->comercio->id.'?ruta=/contacto&sitio=https://mitienda.test')
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('"canonica":"https://mitienda.test/contacto"', $crudo);
        $this->assertStringContainsString('Política de privacidad', $crudo);
    }

    /**
     * 🔴 Un `sitio` que no pasa la regex del contrato se ignora y se usa el `online` del
     * comercio. Nunca puede terminar adentro de un href ni de la canonica.
     */
    public function test_un_sitio_malicioso_se_ignora_y_se_usa_el_del_comercio()
    {
        $maliciosos = [
            'javascript:alert(1)',
            'https://mitienda.test" onmouseover="alert(1)',
            'https://otro.test/ruta',
            'https://otro.test?x=1',
        ];

        foreach ($maliciosos as $sitio) {
            $pagina = $this->pagina('/contacto', $sitio);

            $this->assertSame('https://mitienda.test/contacto', $pagina['canonica'], 'sitio='.$sitio);
            $this->assertStringNotContainsString('javascript:', $pagina['cuerpo_html']);
            $this->assertStringNotContainsString('onmouseover', $pagina['cuerpo_html']);
            $this->assertStringNotContainsString('otro.test', $pagina['cuerpo_html']);
        }
    }

    /** Un `sitio` valido (con puerto) manda sobre el del comercio. */
    public function test_un_sitio_valido_manda()
    {
        $pagina = $this->pagina('/', 'https://www.otra-tienda.test:8443');

        $this->assertSame('https://www.otra-tienda.test:8443/', $pagina['canonica']);
    }

    /** Sin `sitio` valido y sin `online` en el comercio, las URLs salen relativas. */
    public function test_sin_ningun_sitio_las_urls_salen_relativas()
    {
        \App\User::where('id', $this->comercio->id)->update(['online' => null]);

        $pagina = $this->pagina('/contacto', null);

        $this->assertSame('/contacto', $pagina['canonica']);
        $this->assertStringContainsString('href="/"', $pagina['cuerpo_html']);
    }

    /** Una `ruta` sin barra inicial se trata como si la tuviera, y el query string se corta. */
    public function test_la_ruta_se_normaliza()
    {
        $this->assertSame('https://mitienda.test/contacto', $this->pagina('contacto')['canonica']);
        $this->assertSame('https://mitienda.test/contacto', $this->pagina('/contacto?utm_source=x')['canonica']);
        $this->assertSame('https://mitienda.test/contacto', $this->pagina('/contacto/')['canonica']);
    }

    /**
     * Un comercio que no existe NO es `estado: 404` adentro de un 200: seria decirle a Google
     * que TODAS las paginas de la tienda no existen. Es un 404 HTTP, que seo.php trata como
     * "la API no pudo contestar" y sirve el index.html de siempre.
     */
    public function test_un_comercio_inexistente_es_un_404_http_y_no_un_estado_404()
    {
        $this->getJson('/api/seo/pagina/999999999?ruta=/')
            ->assertStatus(404)
            ->assertJsonMissing(['estado' => 404]);
    }
}
