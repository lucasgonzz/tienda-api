<?php

namespace Tests\Feature\Envios;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use App\Services\Zipnova\ZipnovaCotizadorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión envios-andreani, ronda 2 (hallazgo 1): el CPA de 8 caracteres que devuelve Google Places ("X5000ABC",
 * "C1425ABC") tiene que llegar a Andreani como el CP numérico de 4 dígitos (tarifa y sucursales), porque Andreani
 * responde 400 al CPA y la opción desaparecía de la lista. A Zipnova le sigue llegando el CPA tal cual (lo acepta).
 */
class CotizarConAndreaniCpaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConZipnova;
    use ArmaComercioConAndreani;

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
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio, ['contrato_sucursal' => self::ANDREANI_CONTRATO_SUCURSAL]);
    }

    /**
     * Los valores de un parámetro del query en los pedidos que salieron hacia Andreani.
     *
     * @param string $fragmento_url
     * @param string $parametro
     * @return array<int, string>
     */
    private function enviadosA($fragmento_url, $parametro)
    {
        $valores = [];

        foreach (Http::recorded() as $par) {
            $url = $par[0]->url();

            if (strpos($url, $fragmento_url) !== false) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
                $valores[] = (string) $consulta[$parametro];
            }
        }

        return $valores;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function codigosPostales()
    {
        return [
            'CPA de Córdoba'    => ['X5000ABC', '5000'],
            'CPA de la capital' => ['C1425ABC', '1425'],
            'CPA sucio'         => [' x5000-abc ', '5000'],
            'letra y número'    => ['X5000', '5000'],
        ];
    }

    /**
     * @dataProvider codigosPostales
     */
    public function test_andreani_recibe_el_cp_numerico_y_zipnova_el_cpa($zipcode, $numerico)
    {
        $this->fakearEnvios();

        $respuesta = $this->postJson('/api/envios/cotizar', [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => $zipcode,
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ])->assertStatus(200);

        $keys = array_column($respuesta->json('opciones'), 'key');
        $this->assertContains(self::KEY_ANDREANI_DOMICILIO, $keys, 'Andreani tiene que seguir en la lista con un CPA.');
        $this->assertContains(self::KEY_ANDREANI_SUCURSAL, $keys);

        // Tarifa (domicilio y sucursal) y sucursales: siempre los 4 dígitos.
        $cp_de_tarifa = $this->enviadosA(self::URL_ANDREANI_TARIFAS, 'cpDestino');
        $this->assertCount(2, $cp_de_tarifa);
        $this->assertSame([$numerico, $numerico], $cp_de_tarifa);
        $this->assertSame([$numerico], $this->enviadosA(self::URL_ANDREANI_SUCURSALES, 'codigoPostal'));

        // Zipnova no cambia: el CPA limpio de siempre (sin la letra sola ni el ruido).
        $cuerpos_de_zipnova = [];
        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), self::URL_QUOTE) !== false) {
                $cuerpos_de_zipnova[] = $par[0]->body();
            }
        }
        $this->assertCount(1, $cuerpos_de_zipnova);
        $esperado_zipnova = ZipnovaCotizadorService::zipcode_limpio($zipcode);
        $this->assertStringContainsString('"zipcode":"' . $esperado_zipnova . '"', $cuerpos_de_zipnova[0]);
    }
}
