<?php

namespace Tests\Feature\Envios;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión envios-andreani, ronda 2 (hallazgo 3): con Andreani lento o caído (timeout o conexión, status 0) no se
 * hacen en serie tarifa, sucursales y tarifa de sucursal (8 s cada una) ni se repite en la segunda cotización de la
 * ficha. Si la tarifa a domicilio falla sin respuesta: no se piden sucursales ni la tarifa de sucursal, y esa falla
 * se recuerda 60 s por comercio para que la siguiente cotización ni llame a Andreani. Zipnova sigue cotizando.
 */
class CotizarConAndreaniLentoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConZipnova;
    use ArmaComercioConAndreani;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article */
    private $articulo;

    /** @var array<string, int> Pedidos que llegaron al fake, por servicio (los que lanzan no quedan en `Http::recorded`). */
    private $intentos = [];

    protected function setUp(): void
    {
        parent::setUp();

        ZipnovaEsquemaHelper::olvidar();
        ArticleHelper::olvidar_visibilidad_del_anonimo();
        Cache::flush();

        config(['services.andreani.base_url' => 'https://apis.andreani.com']);

        $this->comercio = $this->comercioConTienda();
        $this->articulo = $this->articuloConMedidas($this->comercio, ['peso' => 1, 'alto' => 20, 'ancho' => 20, 'profundidad' => 20]);
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio, ['contrato_sucursal' => self::ANDREANI_CONTRATO_SUCURSAL]);
    }

    /**
     * Zipnova responde bien y Andreani no atiende: lanza una `ConnectionException` (timeout de cURL).
     *
     * @return void
     */
    private function andreaniSinConexion()
    {
        $this->intentos = ['tarifas' => 0, 'sucursales' => 0, 'zipnova' => 0];
        $zipnova_quote = $this->fixture('zipnova_quote.json');

        Http::fake(function ($request) use ($zipnova_quote) {
            $url = $request->url();

            if (strpos($url, self::URL_QUOTE) !== false) {
                $this->intentos['zipnova']++;

                return Http::response($zipnova_quote, 200);
            }

            if (strpos($url, self::URL_ANDREANI_TARIFAS) !== false) {
                $this->intentos['tarifas']++;

                throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds');
            }

            if (strpos($url, self::URL_ANDREANI_SUCURSALES) !== false) {
                $this->intentos['sucursales']++;

                throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds');
            }

            return Http::response(['message' => 'ruta no fakeada en el test'], 599);
        });
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function cotizar()
    {
        return $this->postJson('/api/envios/cotizar', [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ]);
    }

    public function test_sin_conexion_con_andreani_no_pide_sucursales_ni_la_segunda_tarifa()
    {
        $this->andreaniSinConexion();

        $respuesta = $this->cotizar()->assertStatus(200);

        $this->assertSame(1, $this->intentos['tarifas'], 'Una sola tarifa: si el domicilio no tuvo respuesta, la de sucursal tampoco la va a tener.');
        $this->assertSame(0, $this->intentos['sucursales'], 'Sin conexión no se piden sucursales.');
        $this->assertSame(0, $this->pedidosA(self::URL_ANDREANI_SUCURSALES));

        // Zipnova cotiza igual y el estado de Andreani es "error".
        $keys = array_column($respuesta->json('opciones'), 'key');
        $this->assertNotContains(self::KEY_ANDREANI_DOMICILIO, $keys);
        $this->assertContains(self::KEY_RETIRO_CORREO_ARG, $keys);
        $this->assertSame('error', $respuesta->json('proveedores.andreani'));
    }

    public function test_la_segunda_cotizacion_dentro_de_los_60_segundos_no_llama_a_andreani()
    {
        $this->andreaniSinConexion();

        $this->cotizar()->assertStatus(200);
        $this->assertSame(1, $this->intentos['tarifas']);

        $segunda = $this->cotizar()->assertStatus(200);

        $this->assertSame(1, $this->intentos['tarifas'], 'La falla de conexión se recuerda: la ficha no vuelve a esperar a Andreani.');
        $this->assertSame(0, $this->intentos['sucursales']);
        $this->assertSame('error', $segunda->json('proveedores.andreani'));
        $this->assertContains(self::KEY_RETIRO_CORREO_ARG, array_column($segunda->json('opciones'), 'key'));
    }

    public function test_pasados_los_60_segundos_vuelve_a_intentar()
    {
        $this->andreaniSinConexion();

        $this->cotizar()->assertStatus(200);

        // Se vence la memoria de la falla (no se espera de verdad).
        Cache::flush();

        $this->cotizar()->assertStatus(200);

        $this->assertSame(2, $this->intentos['tarifas']);
    }

    public function test_la_falla_recordada_es_de_ese_comercio_y_no_guarda_datos_sensibles()
    {
        $this->andreaniSinConexion();
        $this->cotizar()->assertStatus(200);

        $clave = \App\Services\Andreani\AndreaniCotizadorService::PREFIJO_CACHE_CAIDA . $this->comercio->id;
        $this->assertTrue(Cache::has($clave));
        $this->assertStringNotContainsString(self::ANDREANI_CLIENTE, json_encode(Cache::get($clave)));

        // Otro comercio no hereda la falla.
        $this->assertFalse(Cache::has(\App\Services\Andreani\AndreaniCotizadorService::PREFIJO_CACHE_CAIDA . ($this->comercio->id + 1)));
    }

    public function test_un_rechazo_de_andreani_con_respuesta_no_se_recuerda_como_caida()
    {
        $this->fakearEnvios(['andreani' => 500]);

        $this->cotizar()->assertStatus(200);
        $this->assertFalse(Cache::has(\App\Services\Andreani\AndreaniCotizadorService::PREFIJO_CACHE_CAIDA . $this->comercio->id), 'Un 500 con respuesta no es una falla de conexión.');
    }

    public function test_el_timeout_de_andreani_por_defecto_es_de_5_segundos()
    {
        $this->assertSame(5, (int) (require base_path('config/services.php'))['andreani']['timeout']);
    }
}
