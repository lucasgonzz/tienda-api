<?php

namespace Tests\Feature\Envios;

use App\Cart;
use App\Envio;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\EnvioCartHelper;
use App\Http\Controllers\Helpers\OrderMailDataHelper;
use App\Http\Controllers\Helpers\OrderTotalsHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use App\Http\Controllers\MercadoPagoController;
use App\Order;
use App\PaymentMethod;
use App\PaymentMethodType;
use App\PromocionVinoteca;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El envío por correo de punta a punta dentro del checkout: carrito → pedido → cobro → "Mis
 * pedidos" (misión zipnova-envios, 14/9/2026).
 *
 * ── Qué fija esta clase ───────────────────────────────────────────────────────────────────────
 *
 * 1. `POST/PUT /api/carts` con `envio.opcion_key` re-cotiza EN EL SERVIDOR y guarda
 *    `envio_precio` con el precio de Zipnova, no con nada que mande el body. Con la misma key,
 *    el mismo código postal y las mismas líneas NO vuelve a llamar a Zipnova
 *    (`Http::assertSentCount`); si cambia la cantidad, sí.
 * 2. Una key que ya no está en la cotización nueva es 422 `opcion_envio`; un destino a medias es
 *    422 `destino` con `errors` por campo; y ninguno de los dos deja el carrito escrito.
 * 3. Pasar a retiro por el local, o elegir una zona propia, limpia las columnas de envío; elegir
 *    Zipnova anula la zona.
 * 4. `POST /api/orders` copia envio_cotizacion/opcion/destino/precio al pedido, con
 *    `envio_proveedor = zipnova` y `address` en texto (EnvioDestinoHelper::como_texto), y corta
 *    con 422 `destino` si el carrito no tiene la dirección.
 * 5. La preferencia de Mercado Pago cobra el envío con `carts.envio_precio` aunque el body
 *    traiga otra zona con otro precio.
 * 6. El desglose del mail suma la línea del correo y no la de zona; `entrega` nombra al correo.
 * 7. "Mis pedidos" trae el envío generado por el ERP (estado, seguimiento) sin el payload crudo.
 *
 * ⚠️ Nada sale a la red (`Http::fake()`); base real del slot con DatabaseTransactions.
 */
class CarritoConEnvioTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConZipnova;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article */
    private $articulo;

    protected function setUp(): void
    {
        parent::setUp();

        ZipnovaEsquemaHelper::olvidar();
        ArticleHelper::olvidar_visibilidad_del_anonimo();

        $this->assertTrue(ZipnovaEsquemaHelper::disponible(), 'La base del slot tiene que tener las columnas de envío.');
        $this->assertTrue(ZipnovaEsquemaHelper::tabla_envios(), 'La base del slot tiene que tener la tabla envios.');

        $this->comercio = $this->comercioConTienda();
        $this->articulo = $this->articuloConMedidas($this->comercio);
        $this->conectorZipnova($this->comercio);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures del carrito
    |---------------------------------------------------------------------------------------------
    */

    /**
     * El payload de `envio` que manda el SPA al elegir una opción.
     *
     * @param array $atributos
     * @return array
     */
    private function envioDelPayload(array $atributos = [])
    {
        return array_merge([
            'zipcode'    => '5000',
            'city'       => null,
            'state'      => null,
            'opcion_key' => self::KEY_DOMICILIO_CORREO_ARG,
            'point_id'   => null,
            'destino'    => $this->destinoCompleto(),
        ], $atributos);
    }

    /**
     * Crea el carrito por el endpoint, como hace el SPA la primera vez, y devuelve el modelo.
     *
     * @param array $cart Overrides del payload `cart`.
     * @return \App\Cart
     */
    private function crearCarritoPorApi(array $cart = [])
    {
        $respuesta = $this->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart'        => array_merge([
                'articles'             => [$this->lineaDelPayload($this->articulo, 2)],
                'promociones_vinoteca' => [],
                'deliver'              => 1,
                'envio'                => $this->envioDelPayload(),
            ], $cart),
        ]);

        $respuesta->assertStatus(201);

        return Cart::find($respuesta->json('cart.id'));
    }

    /**
     * Payload de `PUT /api/carts` a partir de un carrito existente.
     *
     * @param \App\Cart $cart
     * @param array $overrides
     * @return array
     */
    private function payloadDeUpdate(Cart $cart, array $overrides = [])
    {
        return array_merge([
            'id'                   => $cart->id,
            'articles'             => [$this->lineaDelPayload($this->articulo, 2)],
            'promociones_vinoteca' => [],
            'deliver'              => 1,
            'envio'                => $this->envioDelPayload(),
        ], $overrides);
    }

    /**
     * PUT con la sesión dueña del carrito.
     *
     * @param \App\Cart $cart
     * @param array $overrides
     * @return \Illuminate\Testing\TestResponse
     */
    private function actualizarCarrito(Cart $cart, array $overrides = [])
    {
        return $this->withSession(['carritos_propios' => [$cart->id]])
            ->putJson('/api/carts', $this->payloadDeUpdate($cart, $overrides));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 1. La cotización la hace el servidor
    |---------------------------------------------------------------------------------------------
    */

    public function test_al_crear_el_carrito_con_una_opcion_el_servidor_cotiza_y_guarda_el_precio_de_zipnova()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi();

        Http::assertSentCount(1);

        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $cart->envio_precio);
        $this->assertSame(self::KEY_DOMICILIO_CORREO_ARG, $cart->envio_opcion['key']);
        $this->assertSame('Correo Argentino', $cart->envio_opcion['carrier_name']);
        $this->assertFalse($cart->envio_opcion['es_punto_de_retiro']);
        $this->assertNull($cart->envio_opcion['point_id']);

        $this->assertSame('5000', $cart->envio_cotizacion['zipcode']);
        $this->assertNotEmpty($cart->envio_cotizacion['items_hash']);
        $this->assertNotEmpty($cart->envio_cotizacion['quoted_at']);
        $this->assertCount(3, $cart->envio_cotizacion['opciones']);

        $this->assertSame('Juan', $cart->envio_destino['nombre']);
        $this->assertSame('30111222', $cart->envio_destino['documento']);
        $this->assertSame('5000', $cart->envio_destino['codigo_postal']);
        $this->assertNull($cart->delivery_zone_id);
        $this->assertSame(2000.0, (float) $cart->total, 'el total de artículos no incluye el envío');

        /* Y el carrito que vuelve al SPA trae las columnas nuevas, con los casts aplicados. */
        $respuesta = $this->withSession(['carritos_propios' => [$cart->id]])
            ->json('GET', '/api/carts/last-cart/'.$this->comercio->id)
            ->assertStatus(200);
        $this->assertSame(self::KEY_DOMICILIO_CORREO_ARG, $respuesta->json('last_cart.envio_opcion.key'));
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $respuesta->json('last_cart.envio_precio'));
    }

    public function test_el_precio_lo_fija_el_servidor_aunque_el_body_traiga_otro()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi([
            'envio'        => $this->envioDelPayload(['precio' => 1, 'opcion' => ['precio' => 1]]),
            'envio_precio' => 1,
            'envio_opcion' => ['key' => self::KEY_DOMICILIO_CORREO_ARG, 'precio' => 1],
        ]);

        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $cart->envio_precio, 'lo que dijo Zipnova, no el 1 del body');
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $cart->envio_opcion['precio']);
    }

    public function test_con_la_misma_key_el_mismo_cp_y_las_mismas_lineas_no_vuelve_a_llamar_a_zipnova()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi();
        Http::assertSentCount(1);

        /* Segundo guardado: solo cambia la dirección. */
        $this->actualizarCarrito($cart, [
            'envio' => $this->envioDelPayload(['destino' => $this->destinoCompleto(['calle' => 'Belgrano', 'numero' => '456'])]),
        ])->assertStatus(200);

        Http::assertSentCount(1);

        $cart->refresh();
        $this->assertSame('Belgrano', $cart->envio_destino['calle']);
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $cart->envio_precio);
    }

    public function test_si_cambia_la_cantidad_re_cotiza()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi();
        Http::assertSentCount(1);

        $this->actualizarCarrito($cart, [
            'articles' => [$this->lineaDelPayload($this->articulo, 5)],
        ])->assertStatus(200);

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $body = $request->data();

            /* La última cotización lleva las cinco unidades y el subtotal nuevo. */
            return count($body['items']) === 5 && (float) $body['declared_value'] === 5000.0;
        });

        $cart->refresh();
        $this->assertSame(5000.0, (float) $cart->total);
    }

    public function test_si_cambia_el_codigo_postal_re_cotiza()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi();
        Http::assertSentCount(1);

        $this->actualizarCarrito($cart, [
            'envio' => $this->envioDelPayload(['zipcode' => '3260', 'destino' => $this->destinoCompleto(['codigo_postal' => '3260'])]),
        ])->assertStatus(200);

        Http::assertSentCount(2);
    }

    public function test_al_elegir_otra_opcion_re_cotiza_y_guarda_su_precio()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi();

        $this->actualizarCarrito($cart, [
            'envio' => $this->envioDelPayload(['opcion_key' => self::KEY_DOMICILIO_ANDREANI]),
        ])->assertStatus(200);

        Http::assertSentCount(2);

        $cart->refresh();
        $this->assertSame(self::KEY_DOMICILIO_ANDREANI, $cart->envio_opcion['key']);
        $this->assertSame('Andreani', $cart->envio_opcion['carrier_name']);
        $this->assertSame(self::PRECIO_DOMICILIO_ANDREANI, (float) $cart->envio_precio);
    }

    public function test_una_opcion_de_retiro_guarda_la_sucursal_elegida()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi([
            'envio' => $this->envioDelPayload([
                'opcion_key' => self::KEY_RETIRO_CORREO_ARG,
                'point_id'   => 502,
                'destino'    => $this->destinoCompleto(['calle' => null, 'numero' => null]),
            ]),
        ]);

        $this->assertTrue($cart->envio_opcion['es_punto_de_retiro']);
        $this->assertSame(502, $cart->envio_opcion['point_id']);
        $this->assertSame(502, $cart->envio_destino['point_id']);
        $this->assertNull($cart->envio_destino['calle'], 'en una sucursal la calle es opcional');
        $this->assertSame(self::PRECIO_RETIRO_CORREO_ARG, (float) $cart->envio_precio);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 2. Los 422 con `codigo`, y que no dejan nada escrito
    |---------------------------------------------------------------------------------------------
    */

    public function test_una_key_que_ya_no_esta_en_la_cotizacion_es_422_opcion_envio_con_las_opciones_nuevas()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi();

        $respuesta = $this->actualizarCarrito($cart, [
            'envio' => $this->envioDelPayload(['opcion_key' => '3|standard_delivery|carrier_pickup']),
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame('opcion_envio', $respuesta->json('codigo'));
        $this->assertSame('Esa forma de envío ya no está disponible, volvé a cotizar.', $respuesta->json('message'));
        $this->assertCount(3, $respuesta->json('opciones'), 'trae la cotización fresca para que el SPA la muestre');

        /* Nada se escribió: el carrito conserva la opción anterior. */
        $cart->refresh();
        $this->assertSame(self::KEY_DOMICILIO_CORREO_ARG, $cart->envio_opcion['key']);
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $cart->envio_precio);
    }

    public function test_un_destino_a_medias_es_422_destino_con_los_campos_que_faltan()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi();

        $respuesta = $this->actualizarCarrito($cart, [
            'envio' => $this->envioDelPayload(['destino' => $this->destinoCompleto([
                'documento' => '12',
                'email'     => 'no-es-un-mail',
                'numero'    => '',
            ])]),
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame('destino', $respuesta->json('codigo'));

        $errores = $respuesta->json('errors');
        $this->assertArrayHasKey('documento', $errores);
        $this->assertArrayHasKey('email', $errores);
        $this->assertArrayHasKey('numero', $errores);
        $this->assertArrayNotHasKey('nombre', $errores);

        /* No se re-cotizó (misma key, CP y líneas) y el destino anterior sigue intacto. */
        Http::assertSentCount(1);
        $cart->refresh();
        $this->assertSame('123', $cart->envio_destino['numero']);
    }

    public function test_un_destino_vacio_no_es_error_todavia()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi([
            'envio' => $this->envioDelPayload(['destino' => [
                'nombre' => '', 'apellido' => '', 'documento' => '', 'email' => '', 'telefono' => '',
                'calle' => '', 'numero' => '', 'localidad' => '', 'provincia' => '', 'codigo_postal' => '',
            ]]),
        ]);

        $this->assertNull($cart->envio_destino, 'el SPA guarda el carrito antes de llegar al formulario de dirección');
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $cart->envio_precio, 'la opción y el precio sí quedan');
    }

    public function test_un_codigo_postal_de_la_direccion_distinto_del_cotizado_es_422_destino()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi();

        $respuesta = $this->actualizarCarrito($cart, [
            'envio' => $this->envioDelPayload(['destino' => $this->destinoCompleto(['codigo_postal' => '3260'])]),
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame('destino', $respuesta->json('codigo'));
        $this->assertArrayHasKey('codigo_postal', $respuesta->json('errors'));
    }

    public function test_si_zipnova_falla_al_re_cotizar_el_carrito_responde_502_sin_escribir()
    {
        /* Primera cotización bien, la segunda con Zipnova caído. Es una secuencia y no dos
           `Http::fake()` porque el segundo no pisa al primero: gana el primer stub que matchea. */
        Http::fake([
            self::URL_QUOTE => Http::sequence()
                ->push($this->fixture('zipnova_quote.json'), 200)
                ->push(['message' => 'boom'], 503),
        ]);

        $cart = $this->crearCarritoPorApi();

        $respuesta = $this->actualizarCarrito($cart, [
            'articles' => [$this->lineaDelPayload($this->articulo, 3)],
        ]);

        $respuesta->assertStatus(502);
        $this->assertSame('zipnova', $respuesta->json('codigo'));

        $cart->refresh();
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $cart->envio_precio, 'sigue el precio anterior');
        $this->assertSame(2000.0, (float) $cart->total, 'las líneas tampoco se tocaron');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 3. Retiro y zona propia limpian; Zipnova anula la zona
    |---------------------------------------------------------------------------------------------
    */

    public function test_pasar_a_retiro_por_el_local_limpia_el_envio()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi();

        $this->actualizarCarrito($cart, ['deliver' => 0, 'envio' => null])->assertStatus(200);

        $cart->refresh();
        $this->assertNull($cart->envio_opcion);
        $this->assertNull($cart->envio_cotizacion);
        $this->assertNull($cart->envio_destino);
        $this->assertNull($cart->envio_precio);
    }

    public function test_elegir_una_zona_propia_limpia_el_envio_y_elegir_zipnova_anula_la_zona()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi();

        /* Zona propia: el SPA anula opcion_key y manda delivery_zone_id. */
        $this->actualizarCarrito($cart, ['delivery_zone_id' => 777, 'envio' => null])->assertStatus(200);

        $cart->refresh();
        $this->assertSame(777, (int) $cart->delivery_zone_id);
        $this->assertNull($cart->envio_opcion);
        $this->assertNull($cart->envio_precio);

        /* Vuelve a Zipnova (con la zona todavía en el payload, como un store viejo): gana el correo. */
        $this->actualizarCarrito($cart, ['delivery_zone_id' => 777])->assertStatus(200);

        $cart->refresh();
        $this->assertNull($cart->delivery_zone_id);
        $this->assertSame(self::KEY_DOMICILIO_CORREO_ARG, $cart->envio_opcion['key']);
    }

    public function test_un_payload_sin_envio_como_el_de_un_spa_viejo_no_cambia_nada()
    {
        Http::fake();

        $cart = $this->crearCarritoPorApi(['envio' => null, 'deliver' => 0]);

        Http::assertNothingSent();
        $this->assertNull($cart->envio_opcion);
        $this->assertNull($cart->envio_precio);
        $this->assertSame(2000.0, (float) $cart->total);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 3 bis. El precio queda atado a las líneas cotizadas
    |---------------------------------------------------------------------------------------------
    */

    public function test_update_article_amount_invalida_la_cotizacion_y_el_proximo_save_vuelve_a_cotizar()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi();
        Http::assertSentCount(1);

        /* El botón "Actualizar" de la ficha: cambia el pivot sin pasar por sync_checkout_fields. */
        $this->withSession(['carritos_propios' => [$cart->id]])
            ->putJson('/api/carts/update-article-amount/'.$cart->id, ['id' => $this->articulo->id, 'amount' => 7])
            ->assertStatus(200);

        $cart->refresh();
        $this->assertNull($cart->envio_opcion, 'la opción cotizada para 2 unidades ya no vale');
        $this->assertNull($cart->envio_cotizacion);
        $this->assertNull($cart->envio_precio);
        $this->assertSame('Juan', $cart->envio_destino['nombre'], 'la dirección se conserva');
        $this->assertSame(7000.0, (float) $cart->total);

        /* El próximo cart/save del SPA manda la key de nuevo: se cotiza con las 7 unidades. */
        $this->actualizarCarrito($cart, [
            'articles' => [$this->lineaDelPayload($this->articulo, 7)],
        ])->assertStatus(200);

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return count($request->data()['items']) === 7;
        });

        $cart->refresh();
        $this->assertSame(self::KEY_DOMICILIO_CORREO_ARG, $cart->envio_opcion['key']);
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $cart->envio_precio);
    }

    public function test_el_pedido_no_se_crea_si_las_lineas_del_carrito_no_son_las_cotizadas()
    {
        $this->zipnovaCotiza();
        $this->asegurarEstadoSinConfirmar();

        $cart = $this->crearCarritoPorApi();

        /* Cualquier camino que toque las líneas por afuera del helper: acá, directo en la base. */
        DB::table('article_cart')->where('cart_id', $cart->id)->update(['amount' => 1]);

        $comprador = $this->compradorDe($this->comercio);
        $pedidos_antes = Order::where('user_id', $this->comercio->id)->count();

        $respuesta = $this->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $comprador->id])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id]);

        $respuesta->assertStatus(422);
        $this->assertSame('opcion_envio', $respuesta->json('codigo'));
        $this->assertSame('Cambió el carrito: volvé a elegir la forma de envío', $respuesta->json('message'));
        $this->assertSame($pedidos_antes, Order::where('user_id', $this->comercio->id)->count(), 'no se creó ningún pedido');

        /* Sin re-cotizar acá: en el flujo de Mercado Pago la preferencia ya viajó con el precio. */
        Http::assertSentCount(1);
    }

    public function test_el_envio_gratis_por_umbral_no_sobrevive_a_bajar_la_cantidad()
    {
        /* El abuso: 100 unidades superan el umbral → envío gratis; bajar a 1 con "Actualizar";
           confirmar. Antes el pedido salía con envío gratis para una sola unidad. */
        $this->conectorZipnova($this->comercio, ['envio_gratis_desde' => 50000]);
        $this->zipnovaCotiza();
        $this->asegurarEstadoSinConfirmar();

        $cart = $this->crearCarritoPorApi(['articles' => [$this->lineaDelPayload($this->articulo, 100)]]);
        $this->assertSame(0.0, (float) $cart->envio_precio, '100 × 1000 ≥ 50000: gratis');
        $this->assertTrue($cart->envio_opcion['envio_gratis']);

        $this->withSession(['carritos_propios' => [$cart->id]])
            ->putJson('/api/carts/update-article-amount/'.$cart->id, ['id' => $this->articulo->id, 'amount' => 1])
            ->assertStatus(200);

        $cart->refresh();
        $this->assertNull($cart->envio_precio, 'la cotización gratis quedó invalidada');

        /* El SPA guarda antes de confirmar: se re-cotiza con 1 unidad y ya no es gratis. */
        $this->actualizarCarrito($cart, ['articles' => [$this->lineaDelPayload($this->articulo, 1)]])->assertStatus(200);

        $cart->refresh();
        $this->assertFalse($cart->envio_opcion['envio_gratis']);
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $cart->envio_precio);

        $comprador = $this->compradorDe($this->comercio);
        $order_id = $this->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $comprador->id])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id])
            ->assertStatus(201)
            ->json('order_id');

        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) Order::find($order_id)->envio_precio, 'el pedido paga el envío de 1 unidad');
    }

    public function test_una_cotizacion_de_mas_de_24_horas_se_vuelve_a_pedir()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi();
        Http::assertSentCount(1);

        /* Un día y una hora después, el mismo guardado de siempre: la cotización venció. */
        $this->travel(25)->hours();

        $this->actualizarCarrito($cart)->assertStatus(200);

        Http::assertSentCount(2);
        $this->assertNotSame(
            Cart::find($cart->id)->envio_cotizacion['quoted_at'],
            $cart->envio_cotizacion['quoted_at'],
            'el snapshot se renovó'
        );
    }

    public function test_el_pedido_no_se_crea_con_una_cotizacion_vencida()
    {
        $this->zipnovaCotiza();
        $this->asegurarEstadoSinConfirmar();

        $cart = $this->crearCarritoPorApi();
        $comprador = $this->compradorDe($this->comercio);

        $this->travel(25)->hours();

        $respuesta = $this->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $comprador->id])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id]);

        $respuesta->assertStatus(422);
        $this->assertSame('opcion_envio', $respuesta->json('codigo'));
        $this->assertStringContainsString('venció', $respuesta->json('message'));
    }

    public function test_las_promociones_de_vinoteca_cuentan_con_el_precio_de_la_base_y_entran_en_el_hash()
    {
        /* Umbral de 3000. Artículo 1 × 1000 + promo 1 × 500 (en la base) = 1500: no es gratis.
           El body trae la promo a 99999: si ese precio contara, sería gratis. */
        $this->conectorZipnova($this->comercio, ['envio_gratis_desde' => 3000]);
        $this->zipnovaCotiza();

        $promo = PromocionVinoteca::create([
            'name'        => 'Promo Envios Test',
            'user_id'     => $this->comercio->id,
            'final_price' => 500,
            'online'      => 1,
        ]);

        $linea_promo = function ($amount) use ($promo) {
            return [
                'id'                    => $promo->id,
                'name'                  => $promo->name,
                'final_price'           => 99999,
                'cost'                  => null,
                'is_promocion_vinoteca' => true,
                'pivot'                 => ['amount' => $amount, 'notes' => null],
            ];
        };

        $cart = $this->crearCarritoPorApi([
            'articles'             => [$this->lineaDelPayload($this->articulo, 1)],
            'promociones_vinoteca' => [$linea_promo(1)],
        ]);

        $this->assertFalse($cart->envio_opcion['envio_gratis'], '1500 < 3000 con el precio de la base');
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $cart->envio_precio);
        Http::assertSent(function ($request) {
            $this->assertSame(1500.0, (float) $request->data()['declared_value'], 'el subtotal declarado usa el precio de la base de la promo');

            return true;
        });

        /* Mismos artículos, la promo pasa a 2 unidades: cambia el hash y se re-cotiza. */
        $this->actualizarCarrito($cart, [
            'articles'             => [$this->lineaDelPayload($this->articulo, 1)],
            'promociones_vinoteca' => [$linea_promo(2)],
        ])->assertStatus(200);

        Http::assertSentCount(2);

        /* Y el hash del snapshot es el de las líneas reales del carrito (artículos + promos). */
        $cart->refresh();
        $this->assertSame(EnvioCartHelper::hash_del_carrito($cart), $cart->envio_cotizacion['items_hash']);
    }

    public function test_una_sucursal_que_no_es_de_la_opcion_es_422_destino()
    {
        $this->zipnovaCotiza();

        $respuesta = $this->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart'        => [
                'articles'             => [$this->lineaDelPayload($this->articulo, 2)],
                'promociones_vinoteca' => [],
                'deliver'              => 1,
                'envio'                => $this->envioDelPayload([
                    'opcion_key' => self::KEY_RETIRO_CORREO_ARG,
                    'point_id'   => 999,
                    'destino'    => $this->destinoCompleto(['calle' => null, 'numero' => null]),
                ]),
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame('destino', $respuesta->json('codigo'));
        $this->assertArrayHasKey('point_id', $respuesta->json('errors'));
    }

    public function test_el_codigo_postal_se_compara_limpio_en_el_snapshot_y_en_la_direccion()
    {
        $this->zipnovaCotiza();

        /* El comprador escribe el CPA con guión y minúsculas en los dos lados. */
        $cart = $this->crearCarritoPorApi([
            'envio' => $this->envioDelPayload([
                'zipcode' => 'x5000-abc',
                'destino' => $this->destinoCompleto(['codigo_postal' => 'x5000-abc']),
            ]),
        ]);

        $this->assertSame('X5000ABC', $cart->envio_cotizacion['zipcode'], 'el snapshot guarda el CP limpio del comprador, no el eco de Zipnova');
        $this->assertSame('X5000ABC', $cart->envio_destino['codigo_postal']);
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $cart->envio_precio);

        /* El mismo guardado, con el CP escrito distinto: es el mismo CP, no se re-cotiza. */
        $this->actualizarCarrito($cart, [
            'envio' => $this->envioDelPayload([
                'zipcode' => 'X5000 ABC',
                'destino' => $this->destinoCompleto(['codigo_postal' => 'x5000abc']),
            ]),
        ])->assertStatus(200);

        Http::assertSentCount(1);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 4. El pedido
    |---------------------------------------------------------------------------------------------
    */

    public function test_el_pedido_copia_el_envio_del_carrito_y_arma_la_direccion_en_texto()
    {
        $this->zipnovaCotiza();
        $this->asegurarEstadoSinConfirmar();

        $cart = $this->crearCarritoPorApi();
        $comprador = $this->compradorDe($this->comercio);

        $respuesta = $this->withSession([
                'carritos_propios'  => [$cart->id],
                'checkout_buyer_id' => $comprador->id,
            ])
            ->postJson('/api/orders', [
                'cart_id'     => $cart->id,
                'commerce_id' => $this->comercio->id,
                'buyer_id'    => $comprador->id,
                /* La dirección "de siempre" del checkout: con Zipnova la pisa el destino. */
                'address'     => 'San Martin 100',
            ]);

        $respuesta->assertStatus(201);

        $order = Order::find($respuesta->json('order_id'));

        $this->assertSame('zipnova', $order->envio_proveedor);
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $order->envio_precio);
        $this->assertSame(self::KEY_DOMICILIO_CORREO_ARG, $order->envio_opcion['key']);
        $this->assertSame('5000', $order->envio_cotizacion['zipcode']);
        $this->assertSame('30111222', $order->envio_destino['documento']);
        $this->assertSame(1, (int) $order->deliver);
        $this->assertNull($order->delivery_zone_id);
        $this->assertSame(2000.0, (float) $order->total);

        $this->assertSame(
            'Av. Colon 123 4 B, Cordoba, Cordoba (CP 5000) — Juan Perez, DNI 30111222, tel +5493511234567 (porton negro)',
            $order->address
        );
    }

    public function test_sin_direccion_no_se_crea_el_pedido_con_envio_por_correo()
    {
        $this->zipnovaCotiza();
        $this->asegurarEstadoSinConfirmar();

        $cart = $this->crearCarritoPorApi([
            'envio' => $this->envioDelPayload(['destino' => null]),
        ]);
        $this->assertNull($cart->envio_destino);

        $comprador = $this->compradorDe($this->comercio);
        $pedidos_antes = Order::where('user_id', $this->comercio->id)->count();

        $respuesta = $this->withSession([
                'carritos_propios'  => [$cart->id],
                'checkout_buyer_id' => $comprador->id,
            ])
            ->postJson('/api/orders', [
                'cart_id'     => $cart->id,
                'commerce_id' => $this->comercio->id,
                'address'     => 'San Martin 100',
            ]);

        $respuesta->assertStatus(422);
        $this->assertSame('destino', $respuesta->json('codigo'));
        $this->assertArrayHasKey('calle', $respuesta->json('errors'));
        $this->assertSame($pedidos_antes, Order::where('user_id', $this->comercio->id)->count(), 'no se creó ningún pedido');
    }

    public function test_un_pedido_sin_envio_por_correo_se_crea_como_siempre()
    {
        Http::fake();
        $this->asegurarEstadoSinConfirmar();

        $cart = $this->crearCarritoPorApi(['envio' => null, 'deliver' => 0]);
        $comprador = $this->compradorDe($this->comercio);

        $respuesta = $this->withSession([
                'carritos_propios'  => [$cart->id],
                'checkout_buyer_id' => $comprador->id,
            ])
            ->postJson('/api/orders', [
                'cart_id'     => $cart->id,
                'commerce_id' => $this->comercio->id,
                'address'     => 'San Martin 100',
            ]);

        $respuesta->assertStatus(201);

        $order = Order::find($respuesta->json('order_id'));
        $this->assertNull($order->envio_proveedor);
        $this->assertNull($order->envio_opcion);
        $this->assertNull($order->envio_precio);
        $this->assertSame('San Martin 100', $order->address);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 5. Mercado Pago cobra el envío del carrito
    |---------------------------------------------------------------------------------------------
    */

    public function test_la_preferencia_de_mercado_pago_cobra_el_envio_del_carrito_y_no_la_zona_del_body()
    {
        $this->zipnovaCotiza();

        $cart = $this->crearCarritoPorApi();
        $payment_method = $this->paymentMethodMp();

        $request = Request::create('/api/mercado-pago/preference', 'POST', [
            'payment_method' => ['id' => $payment_method->id, 'user_id' => $this->comercio->id],
            'cupon'          => null,
            /* Una zona con otro precio en el body: con Zipnova en el carrito se ignora. */
            'delivery_zone'  => ['id' => 1, 'name' => 'Zona vieja', 'price' => 999],
            'articles'       => [['name' => $this->articulo->name, 'final_price' => 1000, 'amount' => 2]],
            'cart_id'        => $cart->id,
        ]);

        $items = $this->articulosACobrar($request, $payment_method, $cart);

        $envios = array_values(array_filter($items, function ($item) {
            return $item['name'] === 'Envio';
        }));

        $this->assertCount(1, $envios, 'un solo ítem de envío');
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $envios[0]['final_price']);
        $this->assertSame(1, $envios[0]['amount']);
        $this->assertSame(1000.0, (float) $items[0]['final_price'], 'los artículos siguen igual');
    }

    public function test_con_envio_gratis_la_preferencia_no_agrega_el_item_de_envio()
    {
        $this->zipnovaCotiza();

        $gratis = $this->articuloConMedidas($this->comercio, ['free_shipping' => 1]);
        $cart = $this->crearCarritoPorApi(['articles' => [$this->lineaDelPayload($gratis, 1)]]);
        $this->assertSame(0.0, (float) $cart->envio_precio);

        $payment_method = $this->paymentMethodMp();
        $request = Request::create('/api/mercado-pago/preference', 'POST', [
            'payment_method' => ['id' => $payment_method->id, 'user_id' => $this->comercio->id],
            'cupon'          => null,
            'delivery_zone'  => ['id' => 1, 'name' => 'Zona vieja', 'price' => 999],
            'articles'       => [['name' => $gratis->name, 'final_price' => 1000, 'amount' => 1]],
            'cart_id'        => $cart->id,
        ]);

        $items = $this->articulosACobrar($request, $payment_method, $cart);

        $this->assertCount(1, $items, 'solo el artículo: ni la zona del body ni un envío de $0');
    }

    public function test_sin_carrito_por_zipnova_la_preferencia_sigue_cobrando_la_zona_del_body()
    {
        Http::fake();

        $cart = $this->crearCarritoPorApi(['envio' => null, 'deliver' => 1, 'delivery_zone_id' => 1]);
        $payment_method = $this->paymentMethodMp();

        $request = Request::create('/api/mercado-pago/preference', 'POST', [
            'payment_method' => ['id' => $payment_method->id, 'user_id' => $this->comercio->id],
            'cupon'          => null,
            'delivery_zone'  => ['id' => 1, 'name' => 'Zona', 'price' => 350],
            'articles'       => [['name' => $this->articulo->name, 'final_price' => 1000, 'amount' => 2]],
            'cart_id'        => $cart->id,
        ]);

        $items = $this->articulosACobrar($request, $payment_method, $cart);

        $this->assertCount(2, $items);
        $this->assertSame('Envio', $items[1]['name']);
        $this->assertSame(350.0, (float) $items[1]['final_price'], 'la zona, como siempre');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 6. El desglose del mail
    |---------------------------------------------------------------------------------------------
    */

    public function test_el_desglose_del_mail_suma_el_envio_por_correo_y_nombra_al_correo()
    {
        $this->zipnovaCotiza();
        $this->asegurarEstadoSinConfirmar();

        $cart = $this->crearCarritoPorApi();
        $comprador = $this->compradorDe($this->comercio);

        $order_id = $this->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $comprador->id])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id])
            ->assertStatus(201)
            ->json('order_id');

        $order = Order::where('id', $order_id)->withAll()->first();

        $totales = OrderTotalsHelper::breakdown($order);

        $this->assertSame(2000.0, (float) $totales['subtotal']);
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $totales['delivery_amount']);
        $this->assertSame('Envio por Correo Argentino (Envio a domicilio)', $totales['delivery_label']);
        $this->assertSame(2000.0 + self::PRECIO_DOMICILIO_CORREO_ARG, (float) $totales['total']);

        $entrega = OrderMailDataHelper::entrega($order);
        $this->assertSame('Envio a domicilio por Correo Argentino (Zipnova)', $entrega['tipo']);
        $this->assertStringContainsString('Av. Colon 123', $entrega['detalle']);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 7. "Mis pedidos" muestra el envío generado por el ERP
    |---------------------------------------------------------------------------------------------
    */

    public function test_mis_pedidos_trae_el_envio_generado_sin_el_payload_crudo()
    {
        $this->zipnovaCotiza();
        $this->asegurarEstadoSinConfirmar();

        $cart = $this->crearCarritoPorApi();
        $comprador = $this->compradorDe($this->comercio);

        $order_id = $this->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $comprador->id])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id])
            ->assertStatus(201)
            ->json('order_id');

        /* Lo que el ERP deja en `envios` al confirmar el pedido (fixture del 201 de Zipnova). */
        $shipment = $this->fixture('zipnova_shipment.json');

        $viejo = Envio::create([
            'user_id'            => $this->comercio->id,
            'order_id'           => $order_id,
            'proveedor'          => 'zipnova',
            'proveedor_envio_id' => '111',
            'status'             => 'cancelled',
            'status_name'        => 'Cancelado',
        ]);

        $envio = Envio::create([
            'user_id'               => $this->comercio->id,
            'order_id'              => $order_id,
            'proveedor'             => 'zipnova',
            'proveedor_envio_id'    => (string) $shipment['id'],
            'external_id'           => $shipment['external_id'],
            'account_id'            => (string) $shipment['account_id'],
            'delivery_id'           => $shipment['delivery_id'],
            'carrier_id'            => (string) $shipment['carrier']['id'],
            'carrier_name'          => $shipment['carrier']['name'],
            'service_type'          => $shipment['service_type'],
            'status'                => $shipment['status'],
            'status_name'           => $shipment['status_name'],
            'tracking_url'          => $shipment['tracking'],
            'tracking_external_url' => $shipment['tracking_external'],
            'carrier_tracking_id'   => $shipment['carrier_tracking_id'],
            'estimated_delivery'    => $shipment['delivery_time']['estimated_delivery'],
            'bultos'                => $shipment['packages'],
            'error_message'         => 'detalle tecnico que es del comercio',
            'respuesta'             => $shipment,
        ]);

        $this->assertFalse($envio->esta_cerrado());
        $this->assertTrue($viejo->esta_cerrado());

        $respuesta = $this->actingAs($comprador, 'buyer')->json('GET', '/api/orders')->assertStatus(200);

        $pedido = $respuesta->json('orders.data.0');
        $this->assertSame($order_id, $pedido['id']);
        $this->assertSame((string) $shipment['id'], $pedido['envio']['proveedor_envio_id'], 'el envío más nuevo, no el cancelado');
        $this->assertSame('Procesando', $pedido['envio']['status_name']);
        $this->assertSame('https://tracking.zipnova.com/987654', $pedido['envio']['tracking_url']);
        $this->assertArrayNotHasKey('respuesta', $pedido['envio'], 'el payload completo de Zipnova no viaja al comprador');

        /* Y el pedido de la página de gracias también lo trae. */
        $actual = $this->actingAs($comprador, 'buyer')->json('GET', '/api/orders/current/'.$this->comercio->id)->assertStatus(200);
        $this->assertSame('Procesando', $actual->json('order.envio.status_name'));
        $this->assertSame(self::KEY_DOMICILIO_CORREO_ARG, $actual->json('order.envio_opcion.key'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Helpers
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Medio de pago de Mercado Pago del comercio, sin recargos ni descuentos.
     *
     * @return \App\PaymentMethod
     */
    private function paymentMethodMp()
    {
        $tipo = PaymentMethodType::where('name', 'MercadoPago')->first();

        if (!$tipo) {
            $tipo = new PaymentMethodType;
            $tipo->name = 'MercadoPago';
            $tipo->save();
        }

        $payment_method = new PaymentMethod;
        $payment_method->name                   = 'Mercado Pago';
        $payment_method->user_id                = $this->comercio->id;
        $payment_method->payment_method_type_id = $tipo->id;
        $payment_method->public_key             = 'PUBLIC-KEY-DE-PRUEBA';
        $payment_method->access_token           = 'TOKEN-DE-PRUEBA';
        $payment_method->save();

        return $payment_method;
    }

    /**
     * Invoca `articulos_a_cobrar()` por reflexión: es la única parte de `preference()` que se
     * puede probar sin salir a la red (misma técnica que `datos_de_preferencia()`).
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\PaymentMethod $payment_method
     * @param \App\Cart $cart
     * @return array
     */
    private function articulosACobrar(Request $request, PaymentMethod $payment_method, Cart $cart)
    {
        $controller = new MercadoPagoController();
        $metodo = new ReflectionMethod($controller, 'articulos_a_cobrar');
        $metodo->setAccessible(true);

        return $metodo->invoke($controller, $request, $this->comercio->fresh(), $payment_method, $cart);
    }
}
