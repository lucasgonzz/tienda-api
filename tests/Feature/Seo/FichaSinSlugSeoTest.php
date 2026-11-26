<?php

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El SEO del servidor para articulos SIN slug (mision tienda-articulos-sin-slug, 26/11/2026).
 *
 * Contrato (PLAN §2.1): la ficha de un articulo sin slug vive en `/articulos/~<id>/<comercio>`.
 * `/api/seo/pagina` tiene que:
 *   - responder 200 con canonica `~<id>` para un articulo online sin slug;
 *   - responder 200 con la canonica del SLUG para uno que tiene slug abierto por `~<id>`;
 *   - 404 si esta offline o es de otro comercio;
 *   - nunca armar `/articulos//<comercio>` en los links de los listados (CA8).
 */
class FichaSinSlugSeoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaTiendaParaSeo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->armarComercio();
    }

    /** CA8: un online sin slug (NULL) se abre por `~<id>` con su canonica `~<id>`. */
    public function test_ca8_ficha_online_sin_slug_por_tilde_id()
    {
        $articulo = $this->articulo('Reel Sin Slug '.$this->sufijo, ['slug' => null]);

        $pagina = $this->pagina('/articulos/~'.$articulo->id.'/'.$this->comercio->id);

        $this->assertSame(200, $pagina['estado']);
        $this->assertSame('product', $pagina['og_type']);
        $this->assertSame('https://mitienda.test/articulos/~'.$articulo->id.'/'.$this->comercio->id, $pagina['canonica']);
        $this->assertNotNull($this->jsonLd($pagina, 'Product'));
    }

    /** CA8: igual con slug '' (cadena vacia). */
    public function test_ca8_ficha_online_con_slug_vacio_por_tilde_id()
    {
        $articulo = $this->articulo('Reel Slug Vacio '.$this->sufijo, ['slug' => '']);

        $pagina = $this->pagina('/articulos/~'.$articulo->id.'/'.$this->comercio->id);

        $this->assertSame(200, $pagina['estado']);
        $this->assertSame('https://mitienda.test/articulos/~'.$articulo->id.'/'.$this->comercio->id, $pagina['canonica']);
    }

    /** CA8: uno CON slug abierto por `~<id>` es 200 y su canonica es la del slug (una sola URL canonica por ficha). */
    public function test_ca8_ficha_con_slug_abierta_por_tilde_id_canoniza_al_slug()
    {
        $articulo = $this->articulo('Reel Con Slug '.$this->sufijo);

        $pagina = $this->pagina('/articulos/~'.$articulo->id.'/'.$this->comercio->id);

        $this->assertSame(200, $pagina['estado']);
        $this->assertSame('https://mitienda.test/articulos/'.$articulo->slug.'/'.$this->comercio->id, $pagina['canonica']);
    }

    /** CA8: offline por `~<id>` es 404 (misma regla de checkOnline que por slug). */
    public function test_ca8_ficha_offline_por_tilde_id_es_404()
    {
        $articulo = $this->articulo('Reel Offline '.$this->sufijo, ['slug' => null, 'online' => 0]);

        $this->assertSame(404, $this->pagina('/articulos/~'.$articulo->id.'/'.$this->comercio->id)['estado']);
    }

    /** CA8: el articulo de OTRO comercio por `~<id>` es 404. */
    public function test_ca8_ficha_de_otro_comercio_por_tilde_id_es_404()
    {
        $otro = \App\User::where('id', '!=', $this->comercio->id)->first();
        $this->assertNotNull($otro, 'La base del slot tiene que tener un segundo usuario.');
        $ajeno = \App\Article::create([
            'name' => 'Reel Ajeno '.$this->sufijo, 'slug' => null, 'user_id' => $otro->id,
            'status' => 'active', 'online' => 1, 'stock' => 5, 'final_price' => 100,
        ]);

        $this->assertSame(404, $this->pagina('/articulos/~'.$ajeno->id.'/'.$this->comercio->id)['estado']);
    }

    /** CA8: la home lista el link de un online sin slug como `~<id>`, nunca como `/articulos//<comercio>`. */
    public function test_ca8_la_home_lista_el_link_con_tilde_id_y_nunca_con_slug_vacio()
    {
        $articulo = $this->articulo('Reel Home Sin Slug '.$this->sufijo, ['slug' => null]);

        $pagina = $this->pagina('/');

        $this->assertStringContainsString(
            'href="https://mitienda.test/articulos/~'.$articulo->id.'/'.$this->comercio->id.'"',
            $pagina['cuerpo_html']
        );
        $this->assertStringNotContainsString('/articulos//', $pagina['cuerpo_html']);
    }
}
