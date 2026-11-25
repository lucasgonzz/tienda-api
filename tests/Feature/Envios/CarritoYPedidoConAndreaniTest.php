<?php

namespace Tests\Feature\Envios;

use App\Cart;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use App\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El envío por Andreani de punta a punta dentro del checkout: carrito -> pedido (misión envios-andreani, 25/11/2026).
 *
 * ── Qué fija esta clase (CA9 y CA10 del plan) ─────────────────────────────────────────────────────
 *
 * 1. CA9: elegir `andreani|domicilio` en el carrito guarda el precio que cotizó EL SERVIDOR (779.51) y la opción con
 *    `proveedor:'andreani'`; el pedido nace con `envio_proveedor='andreani'`, el mismo precio y los bultos en
 *    `envio_opcion.andreani.bultos` (es lo que el comercio carga a mano en el portal de Andreani).
 * 2. CA9: una key inventada (`andreani|express`) es 422 `opcion_envio` con las opciones frescas; una sucursal que no
 *    es de la lista es 422 `destino` (y una de la lista se acepta, con el precio del contrato de sucursal).
 * 3. CA10: si el comercio apagó Andreani (desconectó o destildó «Ofrecer en la tienda») entre que el comprador eligió
 *    y confirmó, el pedido NO nace: 422 `opcion_envio`. Y con Zipnova desconectado y Andreani conectado, el pedido
 *    Andreani SÍ se crea (hoy la guarda de `OrderController@store` lo rechazaría porque mira sólo Zipnova).
 *
 * Decisión de Lucas (25/11): esta misión no genera órdenes ni etiquetas en Andreani; el pedido queda a despachar a mano.
 *
 * 🔴 Hoy estos tests están en ROJO a propósito: la tienda no cotiza Andreani, así que elegir `andreani|domicilio`
 * es 422 `opcion_envio` ya al guardar el carrito.
 *
 * ⚠️ Nada sale a la red: un único fake atiende a Zipnova y a Andreani (`fakearEnvios`).
 */
class CarritoYPedidoConAndreaniTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConZipnova;
    use ArmaComercioConAndreani;

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
        $this->assertTrue(ZipnovaEsquemaHelper::tabla_envios(), 'La base de testing tiene que tener la tabla envios.');

        $this->comercio = $this->comercioConTienda();
        $this->articulo = $this->articuloConMedidas($this->comercio, ['peso' => 1, 'alto' => 20, 'ancho' => 20, 'profundidad' => 20]);
    }

    /**
     * El bloque `envio` que manda el SPA al elegir una opción de Andreani.
     *
     * @param array $atributos
     * @return array
     */
    private function envioAndreani(array $atributos = [])
    {
        return array_merge([
            'zipcode'    => '5000',
            'city'       => null,
            'state'      => null,
            'opcion_key' => self::KEY_ANDREANI_DOMICILIO,
            'point_id'   => null,
            'destino'    => $this->destinoCompleto(),
        ], $atributos);
    }

    /**
     * Payload de `POST /api/carts` con envío por correo.
     *
     * @param array $envio Overrides del bloque `envio`.
     * @return array
     */
    private function payloadDeCarrito(array $envio = [])
    {
        return [
            'commerce_id' => $this->comercio->id,
            'cart'        => [
                'articles'             => [$this->lineaDelPayload($this->articulo, 1)],
                'promociones_vinoteca' => [],
                'deliver'              => 1,
                'envio'                => $this->envioAndreani($envio),
            ],
        ];
    }

    /**
     * Crea el carrito por el endpoint (201) y devuelve el modelo.
     *
     * @param array $envio Overrides del bloque `envio`.
     * @return \App\Cart
     */
    private function crearCarritoAndreani(array $envio = [])
    {
        $respuesta = $this->postJson('/api/carts', $this->payloadDeCarrito($envio));

        $respuesta->assertStatus(201);

        return Cart::find($respuesta->json('cart.id'));
    }

    /**
     * `POST /api/orders` del carrito, con la sesión dueña y un comprador.
     *
     * @param \App\Cart $cart
     * @return \Illuminate\Testing\TestResponse
     */
    private function confirmarPedido(Cart $cart)
    {
        $this->asegurarEstadoSinConfirmar();

        $comprador = $this->compradorDe($this->comercio);

        return $this->withSession([
                'carritos_propios'  => [$cart->id],
                'checkout_buyer_id' => $comprador->id,
            ])
            ->postJson('/api/orders', [
                'cart_id'     => $cart->id,
                'commerce_id' => $this->comercio->id,
                'buyer_id'    => $comprador->id,
                'address'     => 'San Martin 100',
            ]);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA9
    |---------------------------------------------------------------------------------------------
    */

    public function test_elegir_andreani_guarda_precio_del_servidor_y_el_pedido_queda_con_proveedor_andreani()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios();

        // El body intenta imponer otro precio: lo fija el servidor.
        $respuesta = $this->postJson('/api/carts', array_replace_recursive($this->payloadDeCarrito(), [
            'cart' => ['envio_precio' => 1, 'envio' => ['precio' => 1]],
        ]));

        $respuesta->assertStatus(201);

        $cart = Cart::find($respuesta->json('cart.id'));

        $this->assertSame(self::PRECIO_ANDREANI_DOMICILIO, (float) $cart->envio_precio);
        $this->assertSame(self::KEY_ANDREANI_DOMICILIO, $cart->envio_opcion['key']);
        $this->assertSame('andreani', $cart->envio_opcion['proveedor']);
        $this->assertSame('5000', $cart->envio_cotizacion['zipcode']);
        $this->assertSame('30111222', $cart->envio_destino['documento']);
        $this->assertNull($cart->delivery_zone_id);

        $pedido = $this->confirmarPedido($cart);

        $pedido->assertStatus(201);

        $order = Order::find($pedido->json('order_id'));

        $this->assertSame('andreani', $order->envio_proveedor);
        $this->assertSame(self::PRECIO_ANDREANI_DOMICILIO, (float) $order->envio_precio);
        $this->assertSame(self::KEY_ANDREANI_DOMICILIO, $order->envio_opcion['key']);
        $this->assertSame('domicilio', $order->envio_opcion['andreani']['tipo']);
        $this->assertCount(1, $order->envio_opcion['andreani']['bultos'], 'Los bultos viajan en el pedido: el comercio los carga en el portal.');
        $this->assertEquals(1, $order->envio_opcion['andreani']['bultos'][0]['kilos']);
        $this->assertEquals(8000, $order->envio_opcion['andreani']['bultos'][0]['volumen_cm3']);
        $this->assertSame('30111222', $order->envio_destino['documento']);
        $this->assertSame(1, (int) $order->deliver);
        $this->assertNull($order->delivery_zone_id);
        $this->assertSame(1000.0, (float) $order->total, 'El total de artículos no incluye el envío.');

        // El pedido no lleva el número de cliente ni el contrato (son del comercio y viven en el ERP).
        $this->assertStringNotContainsString(self::ANDREANI_CLIENTE, json_encode($order->envio_opcion));
        $this->assertStringNotContainsString(self::ANDREANI_CONTRATO_DOMICILIO, json_encode($order->envio_opcion));
    }

    public function test_key_inventada_da_opcion_envio()
    {
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios();

        $carritos_antes = Cart::where('user_id', $this->comercio->id)->count();

        $respuesta = $this->postJson('/api/carts', $this->payloadDeCarrito(['opcion_key' => 'andreani|express']));

        $respuesta->assertStatus(422);
        $this->assertSame('opcion_envio', $respuesta->json('codigo'));
        $this->assertContains(self::KEY_ANDREANI_DOMICILIO, array_column($respuesta->json('opciones'), 'key'), 'Trae la cotización fresca, con Andreani adentro, para que el SPA la muestre.');
        $this->assertSame($carritos_antes, Cart::where('user_id', $this->comercio->id)->count(), 'Ningún carrito quedó en la base.');
    }

    public function test_sucursal_ajena_da_destino()
    {
        $this->conectorAndreani($this->comercio, ['contrato_sucursal' => self::ANDREANI_CONTRATO_SUCURSAL]);
        $this->fakearEnvios();

        $respuesta = $this->postJson('/api/carts', $this->payloadDeCarrito([
            'opcion_key' => self::KEY_ANDREANI_SUCURSAL,
            'point_id'   => 999,
            'destino'    => $this->destinoCompleto(['calle' => null, 'numero' => null]),
        ]));

        $respuesta->assertStatus(422);
        $this->assertSame('destino', $respuesta->json('codigo'));
        $this->assertArrayHasKey('point_id', $respuesta->json('errors'));
    }

    public function test_una_sucursal_de_la_lista_se_acepta_con_el_precio_del_contrato_de_sucursal()
    {
        $this->conectorAndreani($this->comercio, ['contrato_sucursal' => self::ANDREANI_CONTRATO_SUCURSAL]);
        $this->fakearEnvios();

        $cart = $this->crearCarritoAndreani([
            'opcion_key' => self::KEY_ANDREANI_SUCURSAL,
            'point_id'   => self::ANDREANI_SUCURSAL_ID,
            'destino'    => $this->destinoCompleto(['calle' => null, 'numero' => null]),
        ]);

        $this->assertSame(self::PRECIO_ANDREANI_SUCURSAL, (float) $cart->envio_precio);
        $this->assertSame(self::KEY_ANDREANI_SUCURSAL, $cart->envio_opcion['key']);
        $this->assertSame(self::ANDREANI_SUCURSAL_ID, (int) $cart->envio_destino['point_id']);

        $order = Order::find($this->confirmarPedido($cart)->assertStatus(201)->json('order_id'));

        $this->assertSame('andreani', $order->envio_proveedor);
        $this->assertSame('sucursal', $order->envio_opcion['andreani']['tipo']);
        $this->assertSame(self::ANDREANI_SUCURSAL_ID, (int) $order->envio_destino['point_id'], 'El comercio necesita saber a qué sucursal despachar.');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA10
    |---------------------------------------------------------------------------------------------
    */

    public function test_andreani_desconectado_no_deja_crear_el_pedido()
    {
        $this->conectorZipnova($this->comercio);
        $conector = $this->conectorAndreani($this->comercio);
        $this->fakearEnvios();

        $cart = $this->crearCarritoAndreani();

        // El comercio desconecta Andreani entre que el comprador eligió y confirmó.
        $this->desconectarAndreani($conector);

        $pedidos_antes = Order::where('user_id', $this->comercio->id)->count();

        $respuesta = $this->confirmarPedido($cart);

        $respuesta->assertStatus(422);
        $this->assertSame('opcion_envio', $respuesta->json('codigo'));
        $this->assertSame($pedidos_antes, Order::where('user_id', $this->comercio->id)->count(), 'No tiene que nacer ningún pedido.');
    }

    public function test_andreani_apagado_en_la_tienda_no_deja_crear_el_pedido()
    {
        $this->conectorZipnova($this->comercio);
        $conector = $this->conectorAndreani($this->comercio);
        $this->fakearEnvios();

        $cart = $this->crearCarritoAndreani();

        // «Ofrecer Andreani en la tienda» destildado, con el conector todavía conectado.
        $config = $conector->extra_config;
        $config['ofrecer_en_tienda'] = false;
        $conector->extra_config = $config;
        $conector->save();

        $respuesta = $this->confirmarPedido($cart);

        $respuesta->assertStatus(422);
        $this->assertSame('opcion_envio', $respuesta->json('codigo'));
        $this->assertSame(0, Order::where('user_id', $this->comercio->id)->count());
    }

    public function test_sin_zipnova_el_pedido_andreani_se_crea()
    {
        // Sólo Andreani: la guarda de OrderController@store no puede mirar únicamente a Zipnova.
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios();

        $cart = $this->crearCarritoAndreani();

        $respuesta = $this->confirmarPedido($cart);

        $respuesta->assertStatus(201);

        $order = Order::find($respuesta->json('order_id'));

        $this->assertSame('andreani', $order->envio_proveedor);
        $this->assertSame(self::PRECIO_ANDREANI_DOMICILIO, (float) $order->envio_precio);
    }

    public function test_un_pedido_de_zipnova_sigue_saliendo_con_proveedor_zipnova()
    {
        // Con Andreani conectado, elegir un correo de Zipnova no cambia su proveedor.
        $this->conectorZipnova($this->comercio);
        $this->conectorAndreani($this->comercio);
        $this->fakearEnvios();

        $cart = $this->crearCarritoAndreani(['opcion_key' => self::KEY_DOMICILIO_CORREO_ARG]);

        $order = Order::find($this->confirmarPedido($cart)->assertStatus(201)->json('order_id'));

        $this->assertSame('zipnova', $order->envio_proveedor);
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $order->envio_precio);
    }
}
