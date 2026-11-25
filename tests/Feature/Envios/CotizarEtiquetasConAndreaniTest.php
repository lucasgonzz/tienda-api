<?php

namespace Tests\Feature\Envios;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Etiquetas «Más barato» / «Más rápido» (`tags` `cheapest` / `fastest`) de la lista combinada de envíos
 * (misión envios-andreani, ronda 3, 25/11/2026).
 *
 * Zipnova las calcula sólo entre sus correos; con Andreani directo en la lista quedaban mal (medido: «Más barato» en
 * Correo Argentino $15.315 con Andreani a $200,92). Se recalculan sobre la lista final:
 *   - `cheapest`: la(s) opción(es) de menor precio cobrado al comprador;
 *   - `fastest`: la(s) de menor plazo, sólo entre las que informan plazo (Andreani no informa: no puede ser la más
 *     rápida ni le quita la etiqueta a quien sí informa);
 *   - sin Andreani, la respuesta es la de Zipnova tal cual.
 *
 * Todo con fakes: nada sale a la red.
 */
class CotizarEtiquetasConAndreaniTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConZipnova;
    use ArmaComercioConAndreani;

    const RUTA = '/api/envios/cotizar';

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article */
    private $articulo;

    protected function setUp(): void
    {
        parent::setUp();

        ZipnovaEsquemaHelper::olvidar();
        ArticleHelper::olvidar_visibilidad_del_anonimo();

        config(['services.andreani.base_url' => 'https://apis.andreani.com']);

        $this->comercio = $this->comercioConTienda();
        $this->articulo = $this->articuloConMedidas($this->comercio, ['peso' => 1, 'alto' => 20, 'ancho' => 20, 'profundidad' => 20]);
    }

    /**
     * Cotiza la caja chica para el CP 5000.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function cotizar()
    {
        return $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ]);
    }

    /**
     * Fake de Zipnova (fixture con la etiqueta «más barato» en el retiro de $3993, la realmente más barata de las
     * que quedan visibles) y de Andreani con el total indicado en las dos tarifas.
     *
     * @param float $total_andreani Total con IVA de cualquier tarifa de Andreani.
     * @return void
     */
    private function fakearConPrecioDeAndreani($total_andreani)
    {
        $quote = $this->fixture('zipnova_quote.json');
        foreach ($quote['all_results'] as $i => $resultado) {
            $es_retiro = $resultado['service_type']['code'] === 'pickup_point' && $resultado['carrier']['id'] === 12;
            $es_domicilio = $resultado['service_type']['code'] === 'standard_delivery' && $resultado['carrier']['id'] === 12;
            if ($es_retiro) {
                $quote['all_results'][$i]['tags'] = ['cheapest'];
            } elseif ($es_domicilio) {
                $quote['all_results'][$i]['tags'] = [];
            }
        }

        $tarifa = $this->fixture('andreani_tarifa.json');
        $tarifa['tarifaConIva']['total'] = number_format($total_andreani, 2, '.', '');
        $sucursales = $this->fixture('andreani_sucursales.json');

        Http::fake(function ($request) use ($quote, $tarifa, $sucursales) {
            $url = $request->url();

            if (strpos($url, self::URL_QUOTE) !== false) {
                return Http::response($quote, 200);
            }
            if (strpos($url, self::URL_ANDREANI_TARIFAS) !== false) {
                return Http::response($tarifa, 200);
            }
            if (strpos($url, self::URL_ANDREANI_SUCURSALES) !== false) {
                return Http::response($sucursales, 200);
            }

            return Http::response(['message' => 'ruta no fakeada en el test'], 599);
        });
    }

    /**
     * Las etiquetas por key de opción.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta
     * @return array<string, string[]>
     */
    private function etiquetas($respuesta)
    {
        $etiquetas = [];
        foreach ($respuesta->json('opciones') as $opcion) {
            $etiquetas[$opcion['key']] = $opcion['tags'];
        }

        return $etiquetas;
    }

    public function test_si_andreani_es_mas_barato_la_etiqueta_mas_barato_pasa_a_andreani_y_sale_de_correo_argentino()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearConPrecioDeAndreani(200.92);

        $etiquetas = $this->etiquetas($this->cotizar()->assertStatus(200));

        $this->assertContains('cheapest', $etiquetas[self::KEY_ANDREANI_DOMICILIO], 'Andreani a $200,92 es la más barata.');
        $this->assertNotContains('cheapest', $etiquetas[self::KEY_RETIRO_CORREO_ARG], 'Correo Argentino ya no es la más barata.');
        $this->assertNotContains('cheapest', $etiquetas[self::KEY_DOMICILIO_CORREO_ARG]);
    }

    public function test_si_andreani_es_mas_caro_la_etiqueta_mas_barato_queda_donde_estaba()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearConPrecioDeAndreani(9999.00);

        $etiquetas = $this->etiquetas($this->cotizar()->assertStatus(200));

        $this->assertContains('cheapest', $etiquetas[self::KEY_RETIRO_CORREO_ARG]);
        $this->assertNotContains('cheapest', $etiquetas[self::KEY_ANDREANI_DOMICILIO]);
        $this->assertNotContains('cheapest', $etiquetas[self::KEY_DOMICILIO_CORREO_ARG]);
    }

    public function test_andreani_sin_plazo_nunca_es_la_mas_rapida_ni_le_quita_la_etiqueta_a_quien_informa_plazo()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearConPrecioDeAndreani(200.92);

        $respuesta = $this->cotizar()->assertStatus(200);
        $etiquetas = $this->etiquetas($respuesta);

        foreach ($respuesta->json('opciones') as $opcion) {
            if (strpos($opcion['key'], 'andreani|') === 0) {
                $this->assertNotContains('fastest', $opcion['tags'], 'Andreani no informa plazo.');
            }
        }

        $this->assertContains('fastest', $etiquetas[self::KEY_RETIRO_CORREO_ARG], 'Entre las que informan plazo (3 días) alguna tiene que ser la más rápida.');
    }

    public function test_sin_andreani_las_etiquetas_son_las_de_zipnova_tal_cual()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $etiquetas = $this->etiquetas($this->cotizar()->assertStatus(200));

        $this->assertSame(['cheapest'], $etiquetas[self::KEY_DOMICILIO_CORREO_ARG], 'La fixture de Zipnova la marca ahí y sin Andreani no se recalcula.');
        $this->assertSame([], $etiquetas[self::KEY_RETIRO_CORREO_ARG]);
        $this->assertSame(['fastest'], $etiquetas[self::KEY_DOMICILIO_ANDREANI]);
    }
}
