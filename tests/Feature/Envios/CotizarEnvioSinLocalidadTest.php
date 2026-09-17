<?php

namespace Tests\Feature\Envios;

use App\Cart;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use App\Services\Zipnova\ZipnovaCotizadorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `POST /api/envios/cotizar` con el código postal SOLO: el comprador escribe 2000 y ve el envío a
 * Rosario sin tipear nada más (misión tienda-boton-compra-y-envio, 17/9/2026).
 *
 * ── Qué arregla ───────────────────────────────────────────────────────────────────────────────
 *
 * Hasta hoy, un código postal sin localidad era `needs_location` SIEMPRE —con un CP válido igual
 * que con basura— y el comprador tenía que elegir provincia y escribir localidad en cada compra. El
 * paso que debía resolverlo solo usaba Google Maps, cuyo proyecto no tiene facturación habilitada y
 * devuelve `REQUEST_DENIED` para cualquier código postal.
 *
 * ── Qué fija esta clase ───────────────────────────────────────────────────────────────────────
 *
 * 1. Sin `city`/`state` viaja el CENTINELA (`ZipnovaCotizadorService::CENTINELA_UBICACION`) en los
 *    dos campos, nunca uno solo: para Zipnova son mutuamente obligatorios y con uno solo el
 *    request es un 400 de forma.
 * 2. Zipnova ignora una pareja que no matchea su padrón y resuelve por el código postal: la
 *    localidad que devuelve en `destination` es la que vuelve en el 200, en `city`/`state`. El
 *    `zipcode` sigue siendo el del comprador, no el eco.
 * 3. 🔴 La guarda: si Zipnova ECOA el centinela (o devuelve el destino vacío) no resolvió nada, y
 *    entonces NO se cotiza — sale el 422 `ubicacion` de siempre. Es lo que vuelve verificable un
 *    comportamiento que Zipnova no documenta; sin esto, la tienda mostraría el precio de un envío
 *    a ninguna parte. Vale también sobre una respuesta que salió de la caché.
 * 4. Un código postal que de verdad no existe (400 "Destino inexistente en base de datos") sigue
 *    llegando al comprador como `needs_location`.
 * 5. El camino con localidad del comprador no cambió en nada: lo que escribió es lo que viaja.
 * 6. Las DOS corridas del incremental (`articles_extra`: la base y el conjunto) llevan el
 *    centinela por igual, y eso no les hace compartir la clave de caché.
 *
 * ⚠️ Nada sale a la red: `Http::fake()` en todos los casos, y las respuestas falsas tienen la forma
 * REAL que se midió el 17/9/2026 contra la cuenta de Zipnova (`destination: {id, city, state,
 * country, zipcode, geolocation}` + `results`/`all_results`), que es la de la fixture compartida.
 */
class CotizarEnvioSinLocalidadTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConZipnova;

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

        $this->assertTrue(ZipnovaEsquemaHelper::disponible(), 'La base del slot tiene que tener las columnas de envío (migración 2026_09_14_100200 de empresa-api).');

        $this->comercio = $this->comercioConTienda();
        $this->articulo = $this->articuloConMedidas($this->comercio);
        $this->conectorZipnova($this->comercio);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El código postal solo alcanza
    |---------------------------------------------------------------------------------------------
    */

    public function test_el_codigo_postal_solo_cotiza_sin_pedirle_la_localidad_al_comprador()
    {
        $this->zipnovaCotiza();

        $respuesta = $this->cotizarSoloConElCp('5000');

        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('needs_location'), 'el comprador no tiene que escribir nada');
        $this->assertCount(3, $respuesta->json('opciones'));
    }

    public function test_lo_que_viaja_a_zipnova_lleva_el_centinela_en_city_y_en_state()
    {
        $this->zipnovaCotiza();

        $this->cotizarSoloConElCp('5000')->assertStatus(200);

        Http::assertSent(function ($request) {
            $destination = $request->data()['destination'];

            $this->assertSame('5000', $destination['zipcode']);
            $this->assertSame(ZipnovaCotizadorService::CENTINELA_UBICACION, $destination['city']);
            $this->assertSame(ZipnovaCotizadorService::CENTINELA_UBICACION, $destination['state']);

            return true;
        });
    }

    public function test_el_centinela_va_en_los_dos_campos_y_nunca_en_uno_solo()
    {
        $this->zipnovaCotiza();

        /* El comprador escribió la localidad pero no eligió provincia: mandar `city` sin `state`
           es un 400 de forma de Zipnova ("The destination.city field is required when
           destination.state is present"), así que se cotiza por código postal como si no hubiera
           escrito nada. */
        $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'city'        => 'Cordoba',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            $destination = $request->data()['destination'];

            $this->assertSame(ZipnovaCotizadorService::CENTINELA_UBICACION, $destination['city']);
            $this->assertSame(ZipnovaCotizadorService::CENTINELA_UBICACION, $destination['state']);

            return true;
        });
    }

    public function test_la_localidad_que_resolvio_zipnova_vuelve_en_el_200()
    {
        $this->zipnovaCotiza();

        $respuesta = $this->cotizarSoloConElCp('5000');

        $respuesta->assertStatus(200);
        /* La fixture resuelve a Cordoba / Cordoba, y el comprador no escribió ninguna de las dos. */
        $this->assertSame('Cordoba', $respuesta->json('city'));
        $this->assertSame('Cordoba', $respuesta->json('state'));
    }

    public function test_el_zipcode_del_200_sigue_siendo_el_del_comprador_y_no_el_eco_de_zipnova()
    {
        /* Zipnova ecoa el `zipcode` tal cual se lo mandan, sin normalizarlo ni verificarlo contra
           la localidad que resolvió (medido contra la cuenta real: X5000ABC vuelve como X5000ABC
           aunque el destino resuelto sea Cordoba, id 4307). El CP del carrito es el del comprador. */
        Http::fake([
            self::URL_QUOTE => Http::response($this->fixtureQueResuelveA('Cordoba', 'Cordoba', 'X5000ABC'), 200),
        ]);

        $respuesta = $this->cotizarSoloConElCp(' x5000-abc ');

        $respuesta->assertStatus(200);
        $this->assertSame('X5000ABC', $respuesta->json('zipcode'));
        $this->assertSame('Cordoba', $respuesta->json('city'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | La guarda: no se da por sentado que Zipnova resolvió
    |---------------------------------------------------------------------------------------------
    */

    public function test_si_zipnova_ecoa_el_centinela_no_se_cotiza_y_se_pide_la_localidad()
    {
        /* 🔴 El caso que hace falsificable todo el mecanismo: la respuesta es un 200 con las tres
           opciones y sus precios, pero el destino que volvió es el centinela que se mandó. Zipnova
           no resolvió nada y ese precio es el de un envío a ninguna parte. */
        Http::fake([
            self::URL_QUOTE => Http::response($this->fixtureQueResuelveA(
                ZipnovaCotizadorService::CENTINELA_UBICACION,
                ZipnovaCotizadorService::CENTINELA_UBICACION
            ), 200),
        ]);

        $respuesta = $this->cotizarSoloConElCp('5000');

        $respuesta->assertStatus(422);
        $this->assertSame('ubicacion', $respuesta->json('codigo'));
        $this->assertTrue($respuesta->json('needs_location'));
        $this->assertSame('No reconocimos ese código postal. Decinos la localidad y la provincia.', $respuesta->json('message'));
        $this->assertNull($respuesta->json('opciones'), 'ni una opción se le muestra al comprador');
    }

    public function test_el_eco_del_centinela_se_detecta_aunque_venga_con_otras_mayusculas()
    {
        Http::fake([
            self::URL_QUOTE => Http::response($this->fixtureQueResuelveA(
                strtoupper(ZipnovaCotizadorService::CENTINELA_UBICACION),
                ' ' . strtolower(ZipnovaCotizadorService::CENTINELA_UBICACION) . ' '
            ), 200),
        ]);

        $respuesta = $this->cotizarSoloConElCp('5000');

        $respuesta->assertStatus(422);
        $this->assertSame('ubicacion', $respuesta->json('codigo'));
    }

    public function test_un_destino_vacio_tampoco_pasa_la_guarda()
    {
        /* Un 200 con resultados pero sin `destination`: no hay con qué saber a dónde se cotizó. */
        $quote = $this->fixture('zipnova_quote.json');
        unset($quote['destination']);

        Http::fake([self::URL_QUOTE => Http::response($quote, 200)]);

        $respuesta = $this->cotizarSoloConElCp('5000');

        $respuesta->assertStatus(422);
        $this->assertSame('ubicacion', $respuesta->json('codigo'));
        $this->assertTrue($respuesta->json('needs_location'));
    }

    public function test_la_guarda_tambien_corre_sobre_una_respuesta_que_salio_de_la_cache()
    {
        /* 🔴 La respuesta con el eco del centinela es un 200 con resultados, o sea que SE CACHEA
           (`es_una_cotizacion()` la da por buena). Si la guarda viviera antes de la caché, la
           segunda cotización del mismo comprador se saltearía el chequeo y mostraría el envío a
           ninguna parte. Por eso las dos tienen que dar 422 con una sola llamada a Zipnova. */
        Http::fake([
            self::URL_QUOTE => Http::response($this->fixtureQueResuelveA(
                ZipnovaCotizadorService::CENTINELA_UBICACION,
                ZipnovaCotizadorService::CENTINELA_UBICACION
            ), 200),
        ]);

        $this->cotizarSoloConElCp('5000')->assertStatus(422);
        Http::assertSentCount(1);

        $segunda = $this->cotizarSoloConElCp('5000');

        $segunda->assertStatus(422);
        $this->assertSame('ubicacion', $segunda->json('codigo'));
        $this->assertTrue($segunda->json('needs_location'));
        Http::assertSentCount(1, 'la segunda salió de la caché, y la guarda corrió igual');
    }

    public function test_un_codigo_postal_que_no_existe_sigue_pidiendo_la_localidad()
    {
        /* El body REAL que devuelve Zipnova para un CP inexistente (medido el 17/9/2026 con 9999):
           400 con `message` y `errors` vacío. `ZipnovaException::esDeUbicacion()` lo clasifica por
           la palabra "destino" del mensaje. */
        Http::fake([
            self::URL_QUOTE => Http::response([
                'status'  => 'error',
                'message' => 'Destino inexistente en base de datos',
                'errors'  => [],
            ], 400),
        ]);

        $respuesta = $this->cotizarSoloConElCp('9999');

        $respuesta->assertStatus(422);
        $this->assertSame('ubicacion', $respuesta->json('codigo'));
        $this->assertTrue($respuesta->json('needs_location'));
    }

    public function test_un_400_de_forma_sobre_destination_tambien_pide_la_localidad()
    {
        /* El otro 400 real: el que devolvía Zipnova hasta hoy, cuando se le mandaba el `zipcode`
           solo. Se deja fijado porque es el que clasifica por la clave `destination.*` y no por el
           texto del mensaje. */
        Http::fake([
            self::URL_QUOTE => Http::response([
                'status'  => 'error',
                'message' => 'The given data was invalid.',
                'errors'  => [
                    'destination.city'  => ['The destination.city field is required when destination.id is not present.'],
                    'destination.state' => ['The destination.state field is required when destination.id is not present.'],
                ],
            ], 400),
        ]);

        $respuesta = $this->cotizarSoloConElCp('5000');

        $respuesta->assertStatus(422);
        $this->assertSame('ubicacion', $respuesta->json('codigo'));
        $this->assertTrue($respuesta->json('needs_location'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El camino con localidad del comprador no cambió
    |---------------------------------------------------------------------------------------------
    */

    public function test_con_localidad_y_provincia_del_comprador_no_va_ningun_centinela()
    {
        $this->zipnovaCotiza();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'city'        => 'Villa Allende',
            'state'       => 'Cordoba',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ]);

        $respuesta->assertStatus(200);

        Http::assertSent(function ($request) {
            $destination = $request->data()['destination'];

            $this->assertSame('Villa Allende', $destination['city']);
            $this->assertSame('Cordoba', $destination['state']);
            $this->assertStringNotContainsString(
                ZipnovaCotizadorService::CENTINELA_UBICACION,
                json_encode($destination),
                'con la localidad del comprador el centinela no aparece en ningún lado'
            );

            return true;
        });
    }

    public function test_con_localidad_del_comprador_la_guarda_no_corre()
    {
        /* Si el comprador escribió su localidad, lo que Zipnova devuelva en `destination` no puede
           hacer fracasar la cotización: es el camino de siempre y tiene que seguir andando igual.
           Se prueba con un destino que la guarda del centinela rechazaría. */
        $quote = $this->fixture('zipnova_quote.json');
        unset($quote['destination']);

        Http::fake([self::URL_QUOTE => Http::response($quote, 200)]);

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'city'        => 'Villa Allende',
            'state'       => 'Cordoba',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ]);

        $respuesta->assertStatus(200);
        $this->assertCount(3, $respuesta->json('opciones'));
        /* Sin eco de Zipnova quedan las que escribió el comprador, como antes de esta misión. */
        $this->assertSame('Villa Allende', $respuesta->json('city'));
        $this->assertSame('Cordoba', $respuesta->json('state'));
    }

    public function test_la_localidad_del_comprador_y_la_del_centinela_son_dos_cotizaciones_distintas()
    {
        /* No comparten la clave de caché: son dos destinos distintos para Zipnova (con la pareja
           que matchea gana la pareja, sin ella manda el código postal), así que la segunda no
           puede devolver la respuesta de la primera. */
        $this->zipnovaCotiza();

        $this->cotizarSoloConElCp('5000')->assertStatus(200);
        Http::assertSentCount(1);

        $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'city'        => 'Villa Allende',
            'state'       => 'Cordoba',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ])->assertStatus(200);

        Http::assertSentCount(2);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Las dos corridas del incremental
    |---------------------------------------------------------------------------------------------
    */

    public function test_las_dos_corridas_del_incremental_llevan_el_centinela_y_no_colisionan()
    {
        /* Precios distintos en cada respuesta: si las dos corridas compartieran la clave de caché
           la diferencia daría 0 y este test lo denunciaría. */
        Http::fake([
            self::URL_QUOTE => Http::sequence()
                ->push($this->fixtureQueResuelveA('Cordoba', 'Cordoba'), 200)
                ->push($this->fixtureConOtroPrecio(4993), 200),
        ]);

        $cart = Cart::create([
            'user_id'  => $this->comercio->id,
            'buyer_id' => null,
            'total'    => 2000,
            'deliver'  => 1,
        ]);
        $cart->articles()->attach($this->articulo->id, ['price' => 1000, 'amount' => 2]);

        $de_la_ficha = $this->articuloConMedidas($this->comercio, ['name' => 'Lo que esta mirando']);

        $respuesta = $this->withSession(['carritos_propios' => [$cart->id]])
            ->postJson(self::RUTA, [
                'commerce_id'    => $this->comercio->id,
                'zipcode'        => '5000',
                'cart_id'        => $cart->id,
                'articles_extra' => [['id' => $de_la_ficha->id, 'amount' => 1]],
            ]);

        $respuesta->assertStatus(200);
        $this->assertSame('Cordoba', $respuesta->json('city'), 'la localidad resuelta también viaja en el modo incremental');
        $this->assertTrue($respuesta->json('incremental.hay_base'));
        $this->assertSame(1000.0, (float) $respuesta->json('incremental.diferencia'), 'con las claves colisionando esto daría 0');

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $destination = $request->data()['destination'];

            $this->assertSame(ZipnovaCotizadorService::CENTINELA_UBICACION, $destination['city']);
            $this->assertSame(ZipnovaCotizadorService::CENTINELA_UBICACION, $destination['state']);

            return true;
        });
    }

    public function test_si_la_base_del_incremental_no_resuelve_el_destino_tampoco_se_cotiza()
    {
        /* Las dos corridas van al mismo destino: si la primera no lo resuelve, la segunda tampoco
           puede. Sale el 422 de siempre en vez de un incremental sobre un envío a ninguna parte. */
        Http::fake([
            self::URL_QUOTE => Http::response($this->fixtureQueResuelveA(
                ZipnovaCotizadorService::CENTINELA_UBICACION,
                ZipnovaCotizadorService::CENTINELA_UBICACION
            ), 200),
        ]);

        $cart = Cart::create([
            'user_id'  => $this->comercio->id,
            'buyer_id' => null,
            'total'    => 2000,
            'deliver'  => 1,
        ]);
        $cart->articles()->attach($this->articulo->id, ['price' => 1000, 'amount' => 2]);

        $de_la_ficha = $this->articuloConMedidas($this->comercio, ['name' => 'Lo que esta mirando']);

        $respuesta = $this->withSession(['carritos_propios' => [$cart->id]])
            ->postJson(self::RUTA, [
                'commerce_id'    => $this->comercio->id,
                'zipcode'        => '5000',
                'cart_id'        => $cart->id,
                'articles_extra' => [['id' => $de_la_ficha->id, 'amount' => 1]],
            ]);

        $respuesta->assertStatus(422);
        $this->assertSame('ubicacion', $respuesta->json('codigo'));
        $this->assertTrue($respuesta->json('needs_location'));
        $this->assertNull($respuesta->json('incremental'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures de esta clase
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Lo que manda la ficha del artículo cuando el comprador escribió solo su código postal.
     *
     * @param string $zipcode
     * @return \Illuminate\Testing\TestResponse
     */
    private function cotizarSoloConElCp($zipcode)
    {
        return $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => $zipcode,
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ]);
    }

    /**
     * La fixture de Zipnova con otro `destination`. Se conservan todas las claves que la respuesta
     * real trae (`id`, `country`, `geolocation`): lo único que cambia es a dónde dice que resolvió.
     *
     * @param string $city
     * @param string $state
     * @param string|null $zipcode Eco del código postal; por defecto el de la fixture.
     * @return array
     */
    private function fixtureQueResuelveA($city, $state, $zipcode = null)
    {
        $quote = $this->fixture('zipnova_quote.json');

        $quote['destination']['city'] = $city;
        $quote['destination']['state'] = $state;
        if (!is_null($zipcode)) {
            $quote['destination']['zipcode'] = $zipcode;
        }

        return $quote;
    }

    /**
     * La fixture con otro precio en la opción más barata (la de retiro): un paquete más pesado
     * cuesta más, y es lo que separa la corrida base de la del conjunto.
     *
     * @param float $precio `price_incl_tax` de la opción de retiro.
     * @return array
     */
    private function fixtureConOtroPrecio($precio)
    {
        $quote = $this->fixtureQueResuelveA('Cordoba', 'Cordoba');

        foreach ($quote['all_results'] as $i => $resultado) {
            if ($resultado['service_type']['code'] === 'pickup_point') {
                $quote['all_results'][$i]['amounts']['price_incl_tax'] = $precio;
            }
        }

        return $quote;
    }
}
