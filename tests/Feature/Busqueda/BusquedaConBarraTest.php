<?php

namespace Tests\Feature\Busqueda;

use App\Article;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La busqueda de la tienda con una barra "/" en el texto (mision tienda-busqueda-con-barra,
 * 11/11/2026).
 *
 * ── El bug que esta clase clava ───────────────────────────────────────────────────────────────
 *
 * El texto buscado viaja DENTRO del path (`/articles/search/{query}/{commerce_id}`). En la tienda
 * de Mayorista de Pesca un visitante buscaba "Wader Surfish nylon/pvc engomado c/botas ..." y cada
 * "/" creaba un segmento de mas: la ruta no matcheaba y respondia 404 (que Laravel ni loguea).
 * Los tests de barra cruda y de %2F estan rojos sobre el codigo anterior.
 *
 * ⚠️ tienda-api no tiene database/migrations (el esquema lo gobierna empresa-api): se corre contra
 * la base real del slot con DatabaseTransactions, igual que el resto de la suite.
 */
class BusquedaConBarraTest extends TestCase
{
    use DatabaseTransactions;

    /** Nombre real de un producto con barras, tal como lo buscaron en produccion. */
    const NOMBRE = 'Wader Surfish nylon/pvc engomado c/botas talle 45';

    /** @var \App\User */
    private $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        /* Que checkOnline() no exija imagenes: el articulo se crea pelado. */
        $this->comercio->online_configuration()->update(['show_articles_without_images' => 1]);

        Article::create([
            'name'    => self::NOMBRE,
            'slug'    => 'busqueda-barra-test-'.uniqid(),
            'user_id' => $this->comercio->id,
            'status'  => 'active',
            'online'  => 1,
            'stock'   => 10,
        ]);
    }

    /**
     * Nombres de los articulos que devolvio la busqueda.
     *
     * @param  \Illuminate\Testing\TestResponse  $response
     * @return array
     */
    private function nombres($response)
    {
        return collect($response->json('articles.data'))->pluck('name')->all();
    }

    /** Sin barra anda igual que siempre (no se rompe lo que ya funcionaba). */
    public function test_busqueda_sin_barra_sigue_andando()
    {
        $response = $this->getJson('/api/articles/search/Wader/'.$this->comercio->id);

        $response->assertStatus(200);
        $this->assertContains(self::NOMBRE, $this->nombres($response));
    }

    /** La barra cruda en el path (lo que mandaba la SPA vieja) ya no da 404. */
    public function test_busqueda_con_barra_cruda_no_da_404()
    {
        $response = $this->getJson('/api/articles/search/nylon/pvc/'.$this->comercio->id);

        $response->assertStatus(200);
        $this->assertContains(self::NOMBRE, $this->nombres($response));
    }

    /** Varias barras y espacios: el caso exacto de produccion. */
    public function test_busqueda_con_varias_barras_y_espacios()
    {
        $texto = rawurlencode('Wader Surfish nylon').'/pvc%20engomado%20c/botas';
        $response = $this->getJson('/api/articles/search/'.$texto.'/'.$this->comercio->id);

        $response->assertStatus(200);
        $this->assertContains(self::NOMBRE, $this->nombres($response));
    }

    /** La barra codificada como %2F (lo que manda la SPA nueva con encodeURIComponent). */
    public function test_busqueda_con_barra_codificada_como_2f()
    {
        $response = $this->getJson('/api/articles/search/'.rawurlencode('nylon/pvc').'/'.$this->comercio->id);

        $response->assertStatus(200);
        $this->assertContains(self::NOMBRE, $this->nombres($response));
    }

    /** La paginacion de la busqueda (?page=2) sigue matcheando con barras en el texto. */
    public function test_busqueda_con_barra_y_paginacion()
    {
        $response = $this->getJson('/api/articles/search/'.rawurlencode('nylon/pvc').'/'.$this->comercio->id.'?page=1');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('articles.current_page'));
    }

    /** buyer/search tiene el mismo patron: sin sesion responde 401/403, nunca 404 de ruta. */
    public function test_buyer_search_con_barra_matchea_la_ruta()
    {
        $response = $this->getJson('/api/buyer/search/'.rawurlencode('a/b').'/'.$this->comercio->id);

        $this->assertNotSame(404, $response->getStatusCode(), 'La ruta no matcheo: 404.');
    }
}
