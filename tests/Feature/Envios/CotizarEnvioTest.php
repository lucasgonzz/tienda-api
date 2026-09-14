<?php

namespace Tests\Feature\Envios;

use App\Cart;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `POST /api/envios/cotizar`: lo que el comprador ve al escribir su código postal
 * (misión zipnova-envios, 14/9/2026).
 *
 * ── Qué fija esta clase ───────────────────────────────────────────────────────────────────────
 *
 * 1. La respuesta se normaliza desde la MISMA fixture de Zipnova que usa `empresa-api`
 *    (`zipnova_quote.json`: dos correos, cuatro resultados, uno `selectable = false`, una opción
 *    de retiro con sucursales): solo las seleccionables, ordenadas por precio, con las claves de
 *    §2.4 del plan.
 * 2. Lo que se le manda a Zipnova: la credencial Basic del conector, la cuenta, `source`, el
 *    depósito, los ítems en gramos y centímetros ENTEROS repetidos por cantidad, y el valor
 *    declarado igual al subtotal.
 * 3. Los dos modos: `articles` (la ficha, precio público) y `cart_id` (el carrito guardado, que
 *    tiene que ser de la sesión: 403 si no).
 * 4. Las salidas de error con su `codigo`: sin conector (422 `sin_zipnova`, y NO se llama a
 *    Zipnova), nada que enviar (422 `sin_articulos`), destino no reconocido (422 `ubicacion` con
 *    `needs_location`), Zipnova caído (502 `zipnova`), datos inválidos (422 `validacion`).
 * 5. La regla de envío gratis, por `free_shipping` en todos los artículos y por
 *    `envio_gratis_desde`: precio 0 y `precio_original` conservado.
 *
 * ⚠️ Nada sale a la red: `Http::fake()` en todos los casos. Sobre la base: tienda-api no tiene
 * migraciones (el esquema es de empresa-api); se corre contra `tienda_testing_s12`, que ya tiene
 * las columnas de envío, con DatabaseTransactions.
 */
class CotizarEnvioTest extends TestCase
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
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Modo `articles`
    |---------------------------------------------------------------------------------------------
    */

    public function test_devuelve_solo_las_opciones_seleccionables_ordenadas_por_precio_y_con_la_forma_del_contrato()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 2]],
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonStructure(['zipcode', 'city', 'state', 'envio_gratis', 'opciones']);

        $this->assertSame('5000', $respuesta->json('zipcode'));
        $this->assertSame('Cordoba', $respuesta->json('city'));
        $this->assertFalse($respuesta->json('envio_gratis'));

        $opciones = $respuesta->json('opciones');

        /* OCA viene con selectable = false en la fixture: no se le ofrece al comprador. */
        $this->assertCount(3, $opciones, 'tres seleccionables de cuatro resultados');
        $this->assertSame([
            self::KEY_RETIRO_CORREO_ARG,
            self::KEY_DOMICILIO_CORREO_ARG,
            self::KEY_DOMICILIO_ANDREANI,
        ], array_column($opciones, 'key'), 'ordenadas por precio ascendente');

        $retiro = $opciones[0];
        $this->assertSame(12, $retiro['carrier_id']);
        $this->assertSame('Correo Argentino', $retiro['carrier_name']);
        $this->assertSame('pickup_point', $retiro['service_type']);
        $this->assertSame('Retiro en sucursal', $retiro['service_name']);
        $this->assertTrue($retiro['es_punto_de_retiro']);
        $this->assertNull($retiro['point_id']);
        $this->assertSame(self::PRECIO_RETIRO_CORREO_ARG, (float) $retiro['precio']);
        $this->assertSame(self::PRECIO_RETIRO_CORREO_ARG, (float) $retiro['precio_original']);
        $this->assertFalse($retiro['envio_gratis']);
        $this->assertSame(3, $retiro['dias_min']);
        $this->assertSame(4, $retiro['dias_max']);
        $this->assertCount(2, $retiro['puntos_de_retiro']);
        $this->assertSame(501, $retiro['puntos_de_retiro'][0]['point_id']);
        $this->assertSame('Sucursal Cordoba Centro', $retiro['puntos_de_retiro'][0]['description']);

        $andreani = $opciones[2];
        $this->assertSame(self::PRECIO_DOMICILIO_ANDREANI, (float) $andreani['precio']);
        $this->assertSame(['fastest'], $andreani['tags']);
        $this->assertSame('2026-09-18T23:59:59+00:00', $andreani['estimated_delivery']);
        $this->assertSame([], $andreani['puntos_de_retiro']);

        foreach ($opciones as $opcion) {
            foreach (['key', 'carrier_id', 'carrier_name', 'carrier_logo', 'service_type', 'service_name', 'logistic_type', 'es_punto_de_retiro', 'point_id', 'precio', 'precio_original', 'envio_gratis', 'estimated_delivery', 'dias_min', 'dias_max', 'tags', 'puntos_de_retiro'] as $clave) {
                $this->assertArrayHasKey($clave, $opcion, 'cada opción lleva la clave '.$clave.' del contrato');
            }
        }
    }

    public function test_le_manda_a_zipnova_la_credencial_del_conector_los_items_en_gramos_y_cm_enteros_y_el_valor_declarado()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        /* 1,5 kg y 20×15×30 cm en el artículo; 2 unidades → dos ítems iguales de 1500 g. */
        $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 2]],
        ])->assertStatus(200);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertSame('https://'.self::URL_QUOTE, $request->url());
            $this->assertSame('Basic '.base64_encode(self::API_TOKEN.':'.self::API_SECRET), $request->header('Authorization')[0]);
            $this->assertSame((int) self::ACCOUNT_ID, $body['account_id']);
            $this->assertSame('comerciocity', $body['source']);
            $this->assertSame(self::ORIGIN_ID, $body['origin_id']);
            $this->assertSame('5000', $body['destination']['zipcode']);
            $this->assertSame('price', $body['sort_by']);

            /* declared_value = subtotal = 2 × 1000 (declarar_valor = true en la config). */
            $this->assertSame(2000.0, (float) $body['declared_value']);

            $this->assertCount(2, $body['items'], 'un ítem por unidad');
            foreach ($body['items'] as $item) {
                $this->assertSame(1500, $item['weight']);
                $this->assertSame(20, $item['height']);
                $this->assertSame(15, $item['width']);
                $this->assertSame(30, $item['length']);
                $this->assertSame('Articulo Envios Test', $item['description']);
                $this->assertArrayNotHasKey('sku', $item, 'sin sku: Zipnova lo buscaría en su catálogo');
            }

            return true;
        });
    }

    public function test_un_articulo_sin_medidas_usa_el_bulto_por_defecto_del_conector()
    {
        $this->conectorZipnova($this->comercio, [
            'bulto_default' => ['peso' => 0.25, 'alto' => 5, 'ancho' => 6, 'profundidad' => 7],
        ]);
        $this->zipnovaCotiza();

        $sin_medidas = $this->articuloConMedidas($this->comercio, [
            'peso' => null, 'alto' => 0, 'ancho' => null, 'profundidad' => null,
        ]);

        $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $sin_medidas->id, 'amount' => 1]],
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            $item = $request->data()['items'][0];
            $this->assertSame(250, $item['weight']);
            $this->assertSame(5, $item['height']);
            $this->assertSame(6, $item['width']);
            $this->assertSame(7, $item['length']);

            return true;
        });
    }

    public function test_ignora_los_articulos_de_otro_comercio()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $otro_comercio = $this->comercioConTienda();
        $ajeno = $this->articuloConMedidas($otro_comercio, ['peso' => 9]);

        $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [
                ['id' => $this->articulo->id, 'amount' => 1],
                ['id' => $ajeno->id, 'amount' => 1],
            ],
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            $this->assertCount(1, $request->data()['items'], 'solo el artículo del comercio');

            return true;
        });
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Modo `cart_id`
    |---------------------------------------------------------------------------------------------
    */

    public function test_con_cart_id_cotiza_las_lineas_del_carrito_y_declara_su_total()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $cart = Cart::create(['user_id' => $this->comercio->id, 'buyer_id' => null, 'total' => 3000, 'deliver' => 1]);
        $cart->articles()->attach($this->articulo->id, ['price' => 1000, 'amount' => 3]);

        $respuesta = $this->withSession(['carritos_propios' => [$cart->id]])
            ->postJson(self::RUTA, [
                'commerce_id' => $this->comercio->id,
                'zipcode'     => '5000',
                'cart_id'     => $cart->id,
            ]);

        $respuesta->assertStatus(200);
        $this->assertCount(3, $respuesta->json('opciones'));

        Http::assertSent(function ($request) {
            $body = $request->data();
            $this->assertCount(3, $body['items'], 'tres unidades del carrito');
            $this->assertSame(3000.0, (float) $body['declared_value'], 'el total del carrito, no el del body');

            return true;
        });
    }

    public function test_el_carrito_de_otro_da_403_y_no_se_consulta_a_zipnova()
    {
        $this->conectorZipnova($this->comercio);
        Http::fake();

        $ajeno = Cart::create(['user_id' => $this->comercio->id, 'buyer_id' => null, 'total' => 3000]);

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'cart_id'     => $ajeno->id,
        ]);

        $respuesta->assertStatus(403);
        $this->assertSame('carrito', $respuesta->json('codigo'));
        Http::assertNothingSent();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Errores con `codigo`
    |---------------------------------------------------------------------------------------------
    */

    public function test_sin_conector_responde_sin_zipnova_y_no_llama_a_zipnova()
    {
        Http::fake();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame('sin_zipnova', $respuesta->json('codigo'));
        $this->assertSame('Este negocio no tiene envíos por correo configurados.', $respuesta->json('message'));
        Http::assertNothingSent();
    }

    public function test_un_conector_desconectado_cuenta_como_sin_zipnova()
    {
        $conector = $this->conectorZipnova($this->comercio);
        $conector->access_token = null;
        $conector->status = 'sin_conectar';
        $conector->save();

        Http::fake();

        $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ])->assertStatus(422)->assertJson(['codigo' => 'sin_zipnova']);

        Http::assertNothingSent();
    }

    public function test_articulos_que_no_requieren_envio_responden_sin_articulos()
    {
        $this->conectorZipnova($this->comercio);
        Http::fake();

        $digital = $this->articuloConMedidas($this->comercio, ['requires_shipping' => 0]);

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $digital->id, 'amount' => 1]],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame('sin_articulos', $respuesta->json('codigo'));
        Http::assertNothingSent();
    }

    public function test_una_lista_vacia_de_articulos_responde_sin_articulos()
    {
        $this->conectorZipnova($this->comercio);
        Http::fake();

        $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
        ])->assertStatus(422)->assertJson(['codigo' => 'sin_articulos']);

        Http::assertNothingSent();
    }

    public function test_un_destino_que_zipnova_no_reconoce_pide_localidad_y_provincia()
    {
        $this->conectorZipnova($this->comercio);

        Http::fake([
            self::URL_QUOTE => Http::response(['message' => 'No se pudo determinar la ubicacion de destino'], 400),
        ]);

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '9999',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame('ubicacion', $respuesta->json('codigo'));
        $this->assertTrue($respuesta->json('needs_location'));
        $this->assertSame('No reconocimos ese código postal. Decinos la localidad y la provincia.', $respuesta->json('message'));
    }

    public function test_localidad_y_provincia_viajan_a_zipnova_cuando_el_comprador_las_indica()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'city'        => 'Cordoba',
            'state'       => 'Cordoba',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            $destination = $request->data()['destination'];
            $this->assertSame('Cordoba', $destination['city']);
            $this->assertSame('Cordoba', $destination['state']);

            return true;
        });
    }

    public function test_si_zipnova_falla_responde_502_sin_el_detalle_tecnico()
    {
        $this->conectorZipnova($this->comercio);

        Http::fake([
            self::URL_QUOTE => Http::response(['message' => 'Internal Server Error: stack trace secreto'], 500),
        ]);

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ]);

        $respuesta->assertStatus(502);
        $this->assertSame('zipnova', $respuesta->json('codigo'));
        $this->assertSame('No pudimos cotizar el envío en este momento. Probá de nuevo en un rato.', $respuesta->json('message'));
        $this->assertStringNotContainsString('stack trace', $respuesta->getContent(), 'el detalle de Zipnova va al log, no al comprador');
    }

    public function test_un_codigo_postal_invalido_responde_validacion()
    {
        $this->conectorZipnova($this->comercio);
        Http::fake();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '12',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('codigo'));
        Http::assertNothingSent();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Envío gratis
    |---------------------------------------------------------------------------------------------
    */

    public function test_con_todos_los_articulos_con_envio_gratis_el_precio_es_cero_y_se_conserva_el_original()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $gratis = $this->articuloConMedidas($this->comercio, ['free_shipping' => 1]);

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $gratis->id, 'amount' => 1]],
        ]);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('envio_gratis'));

        foreach ($respuesta->json('opciones') as $opcion) {
            $this->assertSame(0.0, (float) $opcion['precio']);
            $this->assertTrue($opcion['envio_gratis']);
            $this->assertGreaterThan(0, (float) $opcion['precio_original'], 'lo que Zipnova le cobra al comercio se conserva');
        }
    }

    public function test_con_un_articulo_sin_envio_gratis_en_el_carrito_se_cobra_el_envio()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $gratis = $this->articuloConMedidas($this->comercio, ['free_shipping' => 1]);

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [
                ['id' => $gratis->id, 'amount' => 1],
                ['id' => $this->articulo->id, 'amount' => 1],
            ],
        ]);

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('envio_gratis'));
        $this->assertSame(self::PRECIO_RETIRO_CORREO_ARG, (float) $respuesta->json('opciones.0.precio'));
    }

    public function test_envio_gratis_desde_aplica_cuando_el_subtotal_llega_al_monto()
    {
        $this->conectorZipnova($this->comercio, ['envio_gratis_desde' => 2000]);
        $this->zipnovaCotiza();

        /* 2 × 1000 = 2000 ≥ 2000: aplica. */
        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 2]],
        ]);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('envio_gratis'));
        $this->assertSame(0.0, (float) $respuesta->json('opciones.0.precio'));
    }

    public function test_envio_gratis_desde_no_aplica_por_debajo_del_monto()
    {
        $this->conectorZipnova($this->comercio, ['envio_gratis_desde' => 2000]);
        $this->zipnovaCotiza();

        /* 1 × 1000 < 2000: no aplica. */
        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ]);

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('envio_gratis'));
        $this->assertSame(self::PRECIO_RETIRO_CORREO_ARG, (float) $respuesta->json('opciones.0.precio'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Lo que publica el comercio
    |---------------------------------------------------------------------------------------------
    */

    public function test_el_comercio_publica_envios_zipnova_segun_tenga_o_no_el_conector_y_nunca_un_secreto()
    {
        $sin = $this->json('GET', '/api/commerce/'.$this->comercio->id)->assertStatus(200);
        $this->assertFalse($sin->json('commerce.envios_zipnova'));
        $this->assertNull($sin->json('commerce.envios_zipnova_config.envio_gratis_desde'));

        $this->conectorZipnova($this->comercio, ['envio_gratis_desde' => 15000]);

        $con = $this->json('GET', '/api/commerce/'.$this->comercio->id)->assertStatus(200);
        $this->assertTrue($con->json('commerce.envios_zipnova'));
        $this->assertSame(15000.0, (float) $con->json('commerce.envios_zipnova_config.envio_gratis_desde'));
        $this->assertSame(['envio_gratis_desde'], array_keys($con->json('commerce.envios_zipnova_config')), 'nada más que el monto de envío gratis');

        $crudo = $con->getContent();
        foreach ([self::API_TOKEN, self::API_SECRET, base64_encode(self::API_TOKEN.':'.self::API_SECRET), 'access_token', 'platform_user_id', 'account_name', 'origin_id'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $crudo, 'la respuesta pública del comercio no puede incluir '.$prohibido);
        }
    }
}
