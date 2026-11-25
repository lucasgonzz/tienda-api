<?php

namespace Tests\Feature\Envios;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `POST /api/envios/cotizar` con Andreani directo al lado de Zipnova (misión envios-andreani, 25/11/2026).
 *
 * ── Qué fija esta clase (CA1 a CA8 del plan; el pedido literal de Lucas del 25/11) ────────────────
 *
 * 1. CA1: un comercio con Zipnova y Andreani conectados ve UNA lista ordenada por precio con Andreani y los
 *    correos de Zipnova. La opción de Andreani trae `proveedor:'andreani'` y los bultos que se cotizaron.
 * 2. CA2: se oculta el «Andreani vía Zipnova» SÓLO cuando el directo dio precio (decisión de Lucas, P2 = sí).
 * 3. CA3: el cambio automático. Si Zipnova no puede cotizar el paquete (200 con todo `selectable:false`, 400 o 500),
 *    la respuesta es 200 con Andreani solo, no el 502 de hoy.
 * 4. CA4: sin Andreani la respuesta es la de siempre, clave por clave (sin `proveedores`, sin `proveedor`), y con
 *    Zipnova caído sigue siendo 502 `zipnova`.
 * 5. CA5: un comercio sólo con Andreani cotiza y publica `envios_correo`; sin ningún proveedor, 422 `sin_zipnova`.
 * 6. CA6: los topes de Andreani (50 kg, 165 cm por lado, suma 300 cm) se aplican del lado nuestro y, si el paquete
 *    los pasa, NO se consulta la tarifa y la respuesta explica `sin_opciones_motivo:'medidas'`.
 * 7. CA7: retiro en sucursal de Andreani con las sucursales que entregan y atienden.
 * 8. CA8: envío gratis de Andreani (precio 0, `precio_original` conservado).
 * 9. R7/R8: el número de cliente, los contratos y el token nunca salen en la API pública; Andreani caído no tumba
 *    la cotización de Zipnova.
 *
 * 🔴 Hoy estos tests están en ROJO a propósito: la tienda no conoce a Andreani (no lee el conector `andreani`, no hay
 * agregador de cotización, `GET /api/commerce` no publica `envios_andreani` ni `envios_correo`). Los casos
 * «negativos» (sin Andreani todo igual) ya pasan hoy y quedan como red de regresión.
 *
 * ⚠️ Nada sale a la red: un único fake atiende a Zipnova y a Andreani (`ArmaComercioConAndreani::fakearEnvios`).
 * Sobre la base: tienda-api no tiene migraciones; corre contra la base de testing de la misión.
 */
class CotizarConAndreaniTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConZipnova;
    use ArmaComercioConAndreani;

    const RUTA = '/api/envios/cotizar';

    /** Key del Andreani que ofrece Zipnova (carrier 56): el que se oculta cuando el directo cotiza. */
    const KEY_ANDREANI_VIA_ZIPNOVA = '56|standard_delivery|carrier_dropoff';

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article Caja chica de 20x20x20 cm y 1 kg, a $1000. */
    private $articulo;

    protected function setUp(): void
    {
        parent::setUp();

        ZipnovaEsquemaHelper::olvidar();
        ArticleHelper::olvidar_visibilidad_del_anonimo();

        config(['services.andreani.base_url' => 'https://apis.andreani.com']);

        $this->assertTrue(ZipnovaEsquemaHelper::disponible(), 'La base de testing tiene que tener las columnas de envío.');

        $this->comercio = $this->comercioConTienda();
        $this->articulo = $this->articuloConMedidas($this->comercio, ['peso' => 1, 'alto' => 20, 'ancho' => 20, 'profundidad' => 20]);
    }

    /**
     * Cotiza el artículo dado (o la caja chica) para el CP 5000.
     *
     * @param \App\Article|null $articulo
     * @param int $cantidad
     * @return \Illuminate\Testing\TestResponse
     */
    private function cotizar($articulo = null, $cantidad = 1)
    {
        $articulo = is_null($articulo) ? $this->articulo : $articulo;

        return $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $articulo->id, 'amount' => $cantidad]],
        ]);
    }

    /**
     * La caja grande del pedido de Lucas: 1 m3 (100x100x100 cm) y 10 kg.
     *
     * @return \App\Article
     */
    private function cajaGrande()
    {
        return $this->articuloConMedidas($this->comercio, ['name' => 'Caja grande de prueba', 'peso' => 10, 'alto' => 100, 'ancho' => 100, 'profundidad' => 100]);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA1 y CA2: las opciones juntas y sin Andreani duplicado
    |---------------------------------------------------------------------------------------------
    */

    public function test_suma_andreani_a_las_opciones_de_zipnova_en_una_sola_lista_ordenada()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios();

        $respuesta = $this->cotizar()->assertStatus(200);

        $opciones = $respuesta->json('opciones');

        $this->assertSame([
            self::KEY_ANDREANI_DOMICILIO,
            self::KEY_RETIRO_CORREO_ARG,
            self::KEY_DOMICILIO_CORREO_ARG,
        ], array_column($opciones, 'key'), 'una sola lista, ordenada por precio: Andreani 779.51, retiro 3993 y domicilio 4840');

        $andreani = $opciones[0];

        $this->assertSame('andreani', $andreani['proveedor']);
        $this->assertSame('Andreani', $andreani['carrier_name']);
        $this->assertNull($andreani['carrier_id']);
        $this->assertSame('domicilio', $andreani['service_type']);
        $this->assertFalse($andreani['es_punto_de_retiro']);
        $this->assertNull($andreani['point_id']);
        $this->assertSame(self::PRECIO_ANDREANI_DOMICILIO, (float) $andreani['precio']);
        $this->assertSame(self::PRECIO_ANDREANI_DOMICILIO, (float) $andreani['precio_original']);
        $this->assertFalse($andreani['envio_gratis']);
        $this->assertNull($andreani['dias_min'], 'La tarifa de Andreani no trae plazo: no se inventa.');
        $this->assertNull($andreani['dias_max']);
        $this->assertNull($andreani['estimated_delivery']);

        $this->assertSame('domicilio', $andreani['andreani']['tipo']);
        $this->assertCount(1, $andreani['andreani']['bultos']);
        $this->assertEquals(1, $andreani['andreani']['bultos'][0]['kilos']);
        $this->assertEquals(20, $andreani['andreani']['bultos'][0]['alto_cm']);
        $this->assertEquals(20, $andreani['andreani']['bultos'][0]['ancho_cm']);
        $this->assertEquals(20, $andreani['andreani']['bultos'][0]['largo_cm']);
        $this->assertEquals(8000, $andreani['andreani']['bultos'][0]['volumen_cm3']);

        // Las opciones de Zipnova son las de siempre: sin clave `proveedor`.
        $this->assertArrayNotHasKey('proveedor', $opciones[1]);
        $this->assertArrayNotHasKey('proveedor', $opciones[2]);

        $this->assertSame(['zipnova' => 'ok', 'andreani' => 'ok'], $respuesta->json('proveedores'));
        $this->assertArrayHasKey('sin_opciones_motivo', $respuesta->json());
        $this->assertNull($respuesta->json('sin_opciones_motivo'));
        $this->assertSame('5000', $respuesta->json('zipcode'));
        $this->assertSame('Cordoba', $respuesta->json('city'));

        // El número de cliente y el contrato son datos del comercio: no viajan en la opción.
        $crudo = $respuesta->getContent();
        $this->assertStringNotContainsString(self::ANDREANI_CLIENTE, $crudo);
        $this->assertStringNotContainsString(self::ANDREANI_CONTRATO_DOMICILIO, $crudo);

        // A Andreani se le consultó con el cliente y el contrato del conector, para el CP del comprador.
        $consultas = $this->consultasDeTarifa();
        $this->assertCount(1, $consultas);
        $this->assertSame(self::ANDREANI_CLIENTE, $consultas[0]['cliente']);
        $this->assertSame(self::ANDREANI_CONTRATO_DOMICILIO, $consultas[0]['contrato']);
        $this->assertSame('5000', (string) $consultas[0]['cpDestino']);
        $this->assertEquals(8000, $consultas[0]['bultos'][0]['volumen']);
        $this->assertEquals(1, $consultas[0]['bultos'][0]['kilos']);
    }

    public function test_oculta_el_andreani_de_zipnova_cuando_el_directo_cotiza()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios();

        $keys = array_column($this->cotizar()->assertStatus(200)->json('opciones'), 'key');

        $this->assertNotContains(self::KEY_ANDREANI_VIA_ZIPNOVA, $keys, 'Con Andreani directo cotizando, el Andreani de Zipnova se oculta (decisión P2).');
        $this->assertContains(self::KEY_ANDREANI_DOMICILIO, $keys);
    }

    public function test_si_andreani_directo_falla_queda_el_andreani_de_zipnova()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios(['andreani' => 400]);

        $respuesta = $this->cotizar()->assertStatus(200);

        $this->assertSame([
            self::KEY_RETIRO_CORREO_ARG,
            self::KEY_DOMICILIO_CORREO_ARG,
            self::KEY_DOMICILIO_ANDREANI,
        ], array_column($respuesta->json('opciones'), 'key'), 'Sin precio del directo, el Andreani de Zipnova no se oculta.');

        $this->assertSame(['zipnova' => 'ok', 'andreani' => 'sin_tarifa'], $respuesta->json('proveedores'));
    }

    public function test_andreani_caido_no_tumba_la_cotizacion_de_zipnova()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios(['andreani' => 500]);

        $respuesta = $this->cotizar()->assertStatus(200);

        $this->assertCount(3, $respuesta->json('opciones'), 'Las tres de Zipnova siguen.');
        $this->assertSame('error', $respuesta->json('proveedores.andreani'));
        $this->assertSame('ok', $respuesta->json('proveedores.zipnova'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA3: Zipnova no puede cotizar el paquete grande → se cambia sola a Andreani
    |---------------------------------------------------------------------------------------------
    */

    public function test_paquete_grande_sin_seleccionables_en_zipnova_muestra_solo_andreani()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios(['zipnova' => 'sin_seleccionables']);

        $respuesta = $this->cotizar($this->cajaGrande())->assertStatus(200);

        $this->assertSame([self::KEY_ANDREANI_DOMICILIO], array_column($respuesta->json('opciones'), 'key'));
        $this->assertSame(self::PRECIO_ANDREANI_DOMICILIO, (float) $respuesta->json('opciones.0.precio'));
        $this->assertSame('sin_opciones', $respuesta->json('proveedores.zipnova'));
        $this->assertSame('ok', $respuesta->json('proveedores.andreani'));

        // A Andreani le llegó el paquete de 1 m3: volumen 1.000.000 cm3 y 10 kilos.
        $consultas = $this->consultasDeTarifa();
        $this->assertCount(1, $consultas);
        $this->assertEquals(1000000, $consultas[0]['bultos'][0]['volumen']);
        $this->assertEquals(10, $consultas[0]['bultos'][0]['kilos']);
    }

    public function test_zipnova_400_con_andreani_responde_200_con_andreani()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios(['zipnova' => 400]);

        $respuesta = $this->cotizar($this->cajaGrande())->assertStatus(200);

        $this->assertSame([self::KEY_ANDREANI_DOMICILIO], array_column($respuesta->json('opciones'), 'key'));
        $this->assertSame('error', $respuesta->json('proveedores.zipnova'));
    }

    public function test_zipnova_500_con_andreani_responde_200_con_andreani()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios(['zipnova' => 500]);

        $respuesta = $this->cotizar($this->cajaGrande())->assertStatus(200);

        $this->assertSame([self::KEY_ANDREANI_DOMICILIO], array_column($respuesta->json('opciones'), 'key'));
        $this->assertSame('error', $respuesta->json('proveedores.zipnova'));
    }

    public function test_un_destino_que_zipnova_no_reconoce_con_andreani_responde_200_con_andreani()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios(['zipnova' => 'ubicacion']);

        $respuesta = $this->cotizar()->assertStatus(200);

        $this->assertSame([self::KEY_ANDREANI_DOMICILIO], array_column($respuesta->json('opciones'), 'key'));
        $this->assertSame('ubicacion', $respuesta->json('proveedores.zipnova'));
    }

    public function test_si_ninguno_de_los_dos_da_precio_se_sigue_devolviendo_el_error_de_zipnova()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios(['zipnova' => 500, 'andreani' => 400]);

        $this->cotizar()->assertStatus(502)->assertJson(['codigo' => 'zipnova']);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA4: sin Andreani, todo igual que hoy
    |---------------------------------------------------------------------------------------------
    */

    public function test_sin_andreani_la_respuesta_no_trae_claves_nuevas_y_zipnova_caido_sigue_en_502()
    {
        $this->conectorZipnova($this->comercio);
        $this->fakearEnvios();

        $respuesta = $this->cotizar()->assertStatus(200);

        $this->assertArrayNotHasKey('proveedores', $respuesta->json());
        $this->assertArrayNotHasKey('sin_opciones_motivo', $respuesta->json());
        $this->assertSame([
            self::KEY_RETIRO_CORREO_ARG,
            self::KEY_DOMICILIO_CORREO_ARG,
            self::KEY_DOMICILIO_ANDREANI,
        ], array_column($respuesta->json('opciones'), 'key'), 'Sin Andreani directo, el Andreani de Zipnova se queda.');

        foreach ($respuesta->json('opciones') as $opcion) {
            $this->assertArrayNotHasKey('proveedor', $opcion, 'Sin Andreani las opciones no llevan claves nuevas.');
        }

        $this->assertSame(0, $this->pedidosA('apis.andreani.com'), 'Sin conector de Andreani no se le pregunta nada a Andreani.');

    }

    public function test_sin_andreani_con_zipnova_caido_sigue_en_502()
    {
        $this->conectorZipnova($this->comercio);
        $this->fakearEnvios(['zipnova' => 500]);

        // Zipnova caído y sin Andreani: el mismo 502 de siempre.
        $this->cotizar()->assertStatus(502)->assertJson(['codigo' => 'zipnova']);
    }

    public function test_un_conector_de_andreani_desconectado_o_apagado_no_cambia_nada()
    {
        $this->conectorZipnova($this->comercio);
        $conector = $this->conectorAndreani($this->comercio, ['ofrecer_en_tienda' => false]);
        $this->fakearEnvios();

        $apagado = $this->cotizar()->assertStatus(200);

        $this->assertArrayNotHasKey('proveedores', $apagado->json(), '«Ofrecer Andreani en la tienda» destildado = como si no estuviera.');
        $this->assertCount(3, $apagado->json('opciones'));

        $this->desconectarAndreani($conector);

        $desconectado = $this->cotizar()->assertStatus(200);

        $this->assertArrayNotHasKey('proveedores', $desconectado->json());
        $this->assertSame(0, $this->pedidosA('apis.andreani.com'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA5: sólo Andreani / ningún proveedor
    |---------------------------------------------------------------------------------------------
    */

    public function test_solo_andreani_cotiza_y_publica_envios_correo()
    {
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios();

        $respuesta = $this->cotizar()->assertStatus(200);

        $this->assertSame([self::KEY_ANDREANI_DOMICILIO], array_column($respuesta->json('opciones'), 'key'));
        $this->assertSame('no_conectado', $respuesta->json('proveedores.zipnova'));
        $this->assertSame('5000', $respuesta->json('zipcode'));
        $this->assertSame(0, $this->pedidosA('api.zipnova.com.ar'), 'Sin conector de Zipnova no se le pregunta nada a Zipnova.');

        $publico = $this->json('GET', '/api/commerce/'.$this->comercio->id)->assertStatus(200);

        $this->assertFalse($publico->json('commerce.envios_zipnova'), 'envios_zipnova no cambia de significado.');
        $this->assertTrue($publico->json('commerce.envios_andreani'));
        $this->assertTrue($publico->json('commerce.envios_correo'));
        $this->assertArrayHasKey('envio_gratis_desde', $publico->json('commerce.envios_correo_config'));
    }

    public function test_sin_ningun_proveedor_responde_sin_zipnova()
    {
        Http::fake();

        $this->cotizar()->assertStatus(422)->assertJson(['codigo' => 'sin_zipnova']);

        Http::assertNothingSent();

        $publico = $this->json('GET', '/api/commerce/'.$this->comercio->id)->assertStatus(200);

        $this->assertFalse($publico->json('commerce.envios_zipnova'));
        $this->assertFalse($publico->json('commerce.envios_andreani'));
        $this->assertFalse($publico->json('commerce.envios_correo'));
    }

    public function test_el_envio_gratis_de_correo_publicado_es_el_menor_no_nulo_de_los_dos()
    {
        $this->conectorZipnova($this->comercio, ['envio_gratis_desde' => 15000]);
        $this->conectorAndreani($this->comercio, ['envio_gratis_desde' => 8000]);

        $publico = $this->json('GET', '/api/commerce/'.$this->comercio->id)->assertStatus(200);

        $this->assertTrue($publico->json('commerce.envios_correo'));
        $this->assertSame(8000.0, (float) $publico->json('commerce.envios_correo_config.envio_gratis_desde'));
        $this->assertSame(15000.0, (float) $publico->json('commerce.envios_zipnova_config.envio_gratis_desde'), 'envios_zipnova_config conserva el de Zipnova.');
    }

    public function test_el_endpoint_publico_del_comercio_no_filtra_el_conector_de_andreani()
    {
        $this->conectorAndreani($this->comercio, ['contrato_sucursal' => self::ANDREANI_CONTRATO_SUCURSAL, 'sucursal_origen' => 40476]);

        $crudo = $this->json('GET', '/api/commerce/'.$this->comercio->id)->assertStatus(200)->getContent();

        foreach ([self::ANDREANI_CLIENTE, self::ANDREANI_CONTRATO_DOMICILIO, self::ANDREANI_CONTRATO_SUCURSAL, 'access_token', 'platform_user_id', 'contrato_domicilio', 'usuario', 'password', 'sucursal_origen'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $crudo, 'la respuesta pública del comercio no puede incluir '.$prohibido);
        }
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA6: los topes de Andreani los aplicamos nosotros
    |---------------------------------------------------------------------------------------------
    */

    public function test_paquete_que_excede_andreani_y_zipnova_avisa_medidas_sin_llamar_a_tarifas()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios(['zipnova' => 'sin_seleccionables']);

        $largo = $this->articuloConMedidas($this->comercio, ['name' => 'Tirante de 170 cm', 'peso' => 5, 'alto' => 170, 'ancho' => 20, 'profundidad' => 20]);

        $respuesta = $this->cotizar($largo)->assertStatus(200);

        $this->assertSame([], $respuesta->json('opciones'));
        $this->assertSame('medidas', $respuesta->json('sin_opciones_motivo'));
        $this->assertSame('excede_medidas', $respuesta->json('proveedores.andreani'));
        $this->assertSame(0, $this->pedidosA(self::URL_ANDREANI_TARIFAS), 'Un paquete que Andreani no admite no se le consulta.');
    }

    public function test_el_paquete_de_un_metro_cubico_justo_en_el_tope_si_se_cotiza()
    {
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios();

        // 100x100x100 cm suma exactamente 300: entra. Es el paquete del pedido de Lucas.
        $respuesta = $this->cotizar($this->cajaGrande())->assertStatus(200);

        $this->assertSame([self::KEY_ANDREANI_DOMICILIO], array_column($respuesta->json('opciones'), 'key'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA7: retiro en sucursal de Andreani
    |---------------------------------------------------------------------------------------------
    */

    public function test_retiro_en_sucursal_andreani_con_solo_las_sucursales_que_entregan()
    {
        $this->conectorAndreani($this->comercio, ['contrato_sucursal' => self::ANDREANI_CONTRATO_SUCURSAL]);
        $this->fakearEnvios();

        $respuesta = $this->cotizar()->assertStatus(200);

        $this->assertSame([self::KEY_ANDREANI_SUCURSAL, self::KEY_ANDREANI_DOMICILIO], array_column($respuesta->json('opciones'), 'key'), 'por precio: 650 y 779.51');

        $sucursal = $respuesta->json('opciones.0');

        $this->assertSame(self::PRECIO_ANDREANI_SUCURSAL, (float) $sucursal['precio']);
        $this->assertTrue($sucursal['es_punto_de_retiro']);
        $this->assertSame('andreani', $sucursal['proveedor']);
        $this->assertCount(1, $sucursal['puntos_de_retiro'], 'De las 4 sucursales de Andreani para el CP 5000 sólo una entrega y atiende al público.');
        $this->assertSame(self::ANDREANI_SUCURSAL_ID, $sucursal['puntos_de_retiro'][0]['point_id']);
        $this->assertSame('Córdoba', $sucursal['puntos_de_retiro'][0]['description']);
        $this->assertSame('5000', (string) $sucursal['puntos_de_retiro'][0]['zipcode']);

        // La tarifa de sucursal se consultó con SU contrato.
        $contratos = array_column($this->consultasDeTarifa(), 'contrato');
        $this->assertContains(self::ANDREANI_CONTRATO_DOMICILIO, $contratos);
        $this->assertContains(self::ANDREANI_CONTRATO_SUCURSAL, $contratos);
        $this->assertGreaterThanOrEqual(1, $this->pedidosA(self::URL_ANDREANI_SUCURSALES));
    }

    public function test_sin_sucursales_no_ofrece_retiro()
    {
        $this->conectorAndreani($this->comercio, ['contrato_sucursal' => self::ANDREANI_CONTRATO_SUCURSAL]);
        $this->fakearEnvios(['sucursales' => 'vacias']);

        $respuesta = $this->cotizar()->assertStatus(200);

        $this->assertSame([self::KEY_ANDREANI_DOMICILIO], array_column($respuesta->json('opciones'), 'key'), 'Sin sucursales no hay retiro; el domicilio sigue.');
    }

    public function test_sin_contrato_de_sucursal_no_se_ofrece_retiro_aunque_haya_sucursales()
    {
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios();

        $keys = array_column($this->cotizar()->assertStatus(200)->json('opciones'), 'key');

        $this->assertNotContains(self::KEY_ANDREANI_SUCURSAL, $keys);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA8: envío gratis de Andreani
    |---------------------------------------------------------------------------------------------
    */

    public function test_envio_gratis_de_andreani_deja_precio_cero_y_conserva_el_original()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio, ['envio_gratis_desde' => 1000]);
        $this->fakearEnvios();

        // El subtotal de la caja chica ($1000 x 1) alcanza el umbral de $1000.
        $respuesta = $this->cotizar()->assertStatus(200);

        $andreani = $respuesta->json('opciones.0');

        $this->assertSame(self::KEY_ANDREANI_DOMICILIO, $andreani['key']);
        $this->assertSame(0.0, (float) $andreani['precio']);
        $this->assertSame(self::PRECIO_ANDREANI_DOMICILIO, (float) $andreani['precio_original']);
        $this->assertTrue($andreani['envio_gratis']);

        // Las de Zipnova siguen su propia regla (acá no tiene envío gratis).
        foreach (array_slice($respuesta->json('opciones'), 1) as $zipnova) {
            $this->assertFalse($zipnova['envio_gratis']);
            $this->assertGreaterThan(0, (float) $zipnova['precio']);
        }
    }

    public function test_por_debajo_del_umbral_andreani_cobra_su_precio()
    {
        $this->conectorAndreani($this->comercio, ['envio_gratis_desde' => 5000]);
        $this->fakearEnvios();

        $andreani = $this->cotizar()->assertStatus(200)->json('opciones.0');

        $this->assertSame(self::PRECIO_ANDREANI_DOMICILIO, (float) $andreani['precio']);
        $this->assertFalse($andreani['envio_gratis']);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Varios bultos
    |---------------------------------------------------------------------------------------------
    */

    public function test_dos_unidades_son_dos_bultos_en_la_consulta_a_andreani()
    {
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios();

        $respuesta = $this->cotizar($this->articulo, 2)->assertStatus(200);

        $this->assertCount(2, $respuesta->json('opciones.0.andreani.bultos'));

        $consultas = $this->consultasDeTarifa();
        $this->assertCount(2, $consultas[0]['bultos'], 'Andreani cotiza varios bultos en una consulta.');
    }
}
