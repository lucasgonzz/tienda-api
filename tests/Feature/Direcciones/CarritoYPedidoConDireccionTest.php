<?php

namespace Tests\Feature\Direcciones;

use App\BuyerAddress;
use App\Cart;
use App\DeliveryZone;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\DireccionesEsquemaHelper;
use App\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Envios\ArmaComercioConZipnova;
use Tests\TestCase;

/**
 * La dirección de entrega elegida en el checkout, del carrito al pedido (misión
 * formas-envio-direcciones-maps, 19/10/2026).
 *
 * Lo que fija:
 *   1. `direccion_envio: {buyer_address_id}` con sesión de cuenta guarda el id y la foto leída de la
 *      BASE (no del body); una dirección de otro comprador, o pedida sin sesión de cuenta, es 422
 *      `direccion` y no deja el carrito escrito.
 *   2. El invitado manda el objeto entero: se normaliza con las reglas del ABM y queda sin
 *      `buyer_address_id`; si le faltan datos, 422 `direccion` con `errors`.
 *   3. Sin la clave (SPA viejo) no se toca nada; con `deliver != 1` o `direccion_envio: null` se limpia.
 *   4. `POST /api/orders` copia el id y la foto al pedido, y sin Zipnova arma `orders.address` desde
 *      la foto (línea de Google + piso/depto + referencia), por encima del `address` del body.
 *
 * Con Zipnova la dirección del pedido sigue siendo la del destino del correo: eso va en
 * `Tests\Feature\Envios\FormasDeEnvioTest`.
 */
class CarritoYPedidoConDireccionTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConZipnova;
    use ArmaDirecciones;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article */
    private $articulo;

    /** @var \App\Buyer */
    private $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        DireccionesEsquemaHelper::olvidar();
        ArticleHelper::olvidar_visibilidad_del_anonimo();
        $this->assertTrue(DireccionesEsquemaHelper::disponible(), 'La base del slot tiene que tener el esquema de direcciones.');

        $this->comercio = $this->comercioConTienda();
        $this->articulo = $this->articuloConMedidas($this->comercio);
        // Una CUENTA: las direcciones guardadas son solo de las cuentas (`Buyer::es_cuenta()`).
        $this->comprador = $this->compradorConCuenta($this->comercio);

        // Ningún caso de esta clase sale a Zipnova: no hay conector ni opción de correo.
        Http::fake();
    }

    /**
     * El payload `cart` del POST /api/carts.
     *
     * @param array $extra
     * @return array
     */
    private function payloadDelCarrito(array $extra = [])
    {
        return array_merge([
            'articles'             => [$this->lineaDelPayload($this->articulo, 2)],
            'promociones_vinoteca' => [],
            'deliver'              => 1,
        ], $extra);
    }

    /**
     * POST /api/carts, con o sin sesión de cuenta.
     *
     * @param array $extra
     * @param bool $con_cuenta
     * @return \Illuminate\Testing\TestResponse
     */
    private function crearCarrito(array $extra = [], $con_cuenta = true)
    {
        $pedido = $con_cuenta ? $this->actingAs($this->comprador, 'buyer') : $this;

        return $pedido->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart'        => $this->payloadDelCarrito($extra),
        ]);
    }

    /**
     * PUT /api/carts con la sesión dueña del carrito.
     *
     * @param \App\Cart $cart
     * @param array $extra
     * @return \Illuminate\Testing\TestResponse
     */
    private function actualizarCarrito(Cart $cart, array $extra = [])
    {
        return $this->actingAs($this->comprador, 'buyer')
            ->withSession(['carritos_propios' => [$cart->id]])
            ->putJson('/api/carts', array_merge(['id' => $cart->id], $this->payloadDelCarrito($extra)));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 1. Una dirección guardada
    |---------------------------------------------------------------------------------------------
    */

    public function test_con_una_direccion_propia_el_carrito_guarda_el_id_y_la_foto_de_la_base()
    {
        $direccion = $this->direccionGuardada($this->comprador, ['is_default' => true]);

        $respuesta = $this->crearCarrito([
            /* El body trae otra calle: no pesa, la foto sale de la base. */
            'direccion_envio' => ['buyer_address_id' => $direccion->id, 'street' => 'Inventada'],
        ])->assertStatus(201);

        $cart = Cart::find($respuesta->json('cart.id'));

        $this->assertSame($direccion->id, (int) $cart->buyer_address_id);

        /* assertEquals y no assertSame para el array: MySQL guarda el json con las claves en su
           propio orden. Los tipos que importan se miran aparte. */
        $this->assertEquals([
            'buyer_address_id'  => $direccion->id,
            'formatted_address' => 'Av. Colón 123, X5000 Córdoba, Argentina',
            'street'            => 'Avenida Colón',
            'street_number'     => '123',
            'apartment'         => '4 B',
            'city'              => 'Córdoba',
            'province'          => 'Córdoba',
            'zip_code'          => 'X5000',
            'reference'         => 'portón negro',
            'lat'               => -31.4135,
            'lng'               => -64.1811,
            'place_id'          => 'ChIJ-prueba-123',
        ], $cart->envio_direccion);
        $this->assertSame(-31.4135, $cart->envio_direccion['lat']);
        $this->assertSame($direccion->id, $cart->envio_direccion['buyer_address_id']);

        /* Y vuelve al SPA con el cast aplicado. */
        $this->assertSame('Avenida Colón', $respuesta->json('cart.envio_direccion.street'));
        $this->assertSame($direccion->id, $respuesta->json('cart.buyer_address_id'));
    }

    public function test_una_direccion_de_otro_comprador_es_422_y_no_crea_el_carrito()
    {
        $otro = $this->compradorDe($this->comercio);
        $ajena = $this->direccionGuardada($otro);

        $carritos_antes = Cart::where('user_id', $this->comercio->id)->count();

        $respuesta = $this->crearCarrito(['direccion_envio' => ['buyer_address_id' => $ajena->id]])
            ->assertStatus(422);

        $this->assertSame('direccion', $respuesta->json('codigo'));
        $this->assertNotEmpty($respuesta->json('message'));
        $this->assertSame($carritos_antes, Cart::where('user_id', $this->comercio->id)->count(), 'el 422 va antes del primer INSERT');
    }

    public function test_una_direccion_ajena_en_el_put_no_toca_el_carrito()
    {
        $propia = $this->direccionGuardada($this->comprador);
        $cart = Cart::find($this->crearCarrito(['direccion_envio' => ['buyer_address_id' => $propia->id]])->json('cart.id'));

        $ajena = $this->direccionGuardada($this->compradorDe($this->comercio), ['street' => 'Ajena']);

        $this->actualizarCarrito($cart, ['direccion_envio' => ['buyer_address_id' => $ajena->id]])
            ->assertStatus(422)
            ->assertJson(['codigo' => 'direccion']);

        $cart->refresh();
        $this->assertSame($propia->id, (int) $cart->buyer_address_id);
        $this->assertSame('Avenida Colón', $cart->envio_direccion['street']);
    }

    public function test_sin_sesion_de_cuenta_no_se_puede_usar_una_direccion_guardada()
    {
        $direccion = $this->direccionGuardada($this->comprador);

        /* Aunque la sesión tenga la identidad de checkout de ese comprador: eso no abre su libreta. */
        $this->withSession(['checkout_buyer_id' => $this->comprador->id])
            ->postJson('/api/carts', [
                'commerce_id' => $this->comercio->id,
                'cart'        => $this->payloadDelCarrito(['direccion_envio' => ['buyer_address_id' => $direccion->id]]),
            ])
            ->assertStatus(422)
            ->assertJson(['codigo' => 'direccion']);
    }

    public function test_la_direccion_ya_elegida_que_se_borro_conserva_la_foto_y_no_traba_el_carrito()
    {
        $direccion = $this->direccionGuardada($this->comprador, ['is_default' => true]);
        $cart = Cart::find($this->crearCarrito(['direccion_envio' => ['buyer_address_id' => $direccion->id]])->json('cart.id'));
        $foto = $cart->envio_direccion;

        /* La borra desde "Mis direcciones" (en otra pestaña) con el carrito abierto. */
        $this->actingAs($this->comprador, 'buyer')->deleteJson('/api/buyer/addresses/'.$direccion->id)->assertStatus(200);
        $this->assertNull(BuyerAddress::find($direccion->id));

        /* El SPA sigue mandando el mismo id al agregar un artículo: el carrito se guarda con la foto. */
        $this->actualizarCarrito($cart, [
            'articles'        => [$this->lineaDelPayload($this->articulo, 3)],
            'direccion_envio' => ['buyer_address_id' => $direccion->id],
        ])->assertStatus(200);

        $cart->refresh();
        $this->assertSame($direccion->id, (int) $cart->buyer_address_id);
        $this->assertEquals($foto, $cart->envio_direccion);
        $this->assertSame(3000.0, (float) $cart->total);

        /* Pero un id DISTINTO que no es del comprador sigue siendo 422. */
        $ajena = $this->direccionGuardada($this->compradorConCuenta($this->comercio), ['street' => 'Ajena']);

        $this->actualizarCarrito($cart, ['direccion_envio' => ['buyer_address_id' => $ajena->id]])
            ->assertStatus(422)
            ->assertJson(['codigo' => 'direccion']);

        $this->assertEquals($foto, $cart->fresh()->envio_direccion);
    }

    public function test_una_ficha_de_invitado_logueada_no_puede_usar_una_direccion_guardada()
    {
        $ficha = $this->fichaDeInvitado($this->comercio);
        $suya = $this->direccionGuardada($ficha);

        $this->actingAs($ficha, 'buyer')
            ->postJson('/api/carts', [
                'commerce_id' => $this->comercio->id,
                'cart'        => $this->payloadDelCarrito(['direccion_envio' => ['buyer_address_id' => $suya->id]]),
            ])
            ->assertStatus(422)
            ->assertJson(['codigo' => 'direccion']);

        /* Con la dirección entera, como cualquier invitado, sí. */
        $this->actingAs($ficha, 'buyer')
            ->postJson('/api/carts', [
                'commerce_id' => $this->comercio->id,
                'cart'        => $this->payloadDelCarrito(['direccion_envio' => $this->direccionDeGoogle()]),
            ])
            ->assertStatus(201);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 2. La dirección del invitado
    |---------------------------------------------------------------------------------------------
    */

    public function test_el_invitado_manda_la_direccion_entera_y_se_normaliza()
    {
        $respuesta = $this->crearCarrito([
            'direccion_envio' => [
                'buyer_address_id' => null,
                'street'           => '  San Martín ',
                'street_number'    => '450',
                'apartment'        => '',
                'city'             => 'Rosario',
                'province'         => 'Santa Fe',
                'zip_code'         => 'S2000',
                'reference'        => 'casa de rejas',
                'lat'              => 'no-es-un-numero',
                'lng'              => -60.63,
            ],
        ], false)->assertStatus(201);

        $cart = Cart::find($respuesta->json('cart.id'));

        $this->assertNull($cart->buyer_address_id);
        $this->assertNull($cart->envio_direccion['buyer_address_id']);
        $this->assertSame('San Martín', $cart->envio_direccion['street']);
        $this->assertNull($cart->envio_direccion['apartment']);
        $this->assertSame('San Martín 450, Rosario, Santa Fe', $cart->envio_direccion['formatted_address'], 'carga a mano: la línea la arma el servidor');
        $this->assertNull($cart->envio_direccion['lat']);
        $this->assertNull($cart->envio_direccion['lng']);
        $this->assertNull($cart->envio_direccion['place_id']);
    }

    public function test_una_direccion_del_invitado_a_medias_es_422_con_los_campos()
    {
        $respuesta = $this->crearCarrito([
            'direccion_envio' => ['street' => 'San Martín', 'street_number' => '450'],
        ], false)->assertStatus(422);

        $this->assertSame('direccion', $respuesta->json('codigo'));
        $this->assertSame(['city', 'province'], array_keys($respuesta->json('errors')));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 3. Compatibilidad: sin la clave no se toca; sin envío se limpia
    |---------------------------------------------------------------------------------------------
    */

    public function test_sin_la_clave_no_se_toca_y_con_retiro_o_null_se_limpia()
    {
        $direccion = $this->direccionGuardada($this->comprador);
        $cart = Cart::find($this->crearCarrito(['direccion_envio' => ['buyer_address_id' => $direccion->id]])->json('cart.id'));

        /* Un SPA viejo (o un guardado que no toca la dirección): queda como estaba. */
        $this->actualizarCarrito($cart)->assertStatus(200);
        $cart->refresh();
        $this->assertSame($direccion->id, (int) $cart->buyer_address_id);
        $this->assertSame('Avenida Colón', $cart->envio_direccion['street']);

        /* Retiro por el local: sin envío no hay dirección de entrega, venga o no la clave. */
        $this->actualizarCarrito($cart, ['deliver' => 0])->assertStatus(200);
        $cart->refresh();
        $this->assertNull($cart->buyer_address_id);
        $this->assertNull($cart->envio_direccion);

        /* Vuelve al envío con la dirección, y después la saca a propósito. */
        $this->actualizarCarrito($cart, ['direccion_envio' => ['buyer_address_id' => $direccion->id]])->assertStatus(200);
        $this->assertNotNull($cart->fresh()->envio_direccion);

        $this->actualizarCarrito($cart, ['direccion_envio' => null])->assertStatus(200);
        $cart->refresh();
        $this->assertNull($cart->buyer_address_id);
        $this->assertNull($cart->envio_direccion);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 4. El pedido
    |---------------------------------------------------------------------------------------------
    */

    public function test_el_pedido_copia_la_direccion_y_arma_la_linea_sin_zipnova()
    {
        $this->asegurarEstadoSinConfirmar();

        $zona = new DeliveryZone();
        $zona->name = 'Acordar con el vendedor';
        $zona->price = 0;
        $zona->costo_a_convenir = 1;
        $zona->user_id = $this->comercio->id;
        $zona->save();

        $direccion = $this->direccionGuardada($this->comprador);
        $cart = Cart::find($this->crearCarrito([
            'delivery_zone_id' => $zona->id,
            'direccion_envio'  => ['buyer_address_id' => $direccion->id],
        ])->json('cart.id'));

        $respuesta = $this->actingAs($this->comprador, 'buyer')
            ->withSession(['carritos_propios' => [$cart->id]])
            ->postJson('/api/orders', [
                'cart_id'     => $cart->id,
                'commerce_id' => $this->comercio->id,
                /* Lo que manda el SPA en texto: con la foto en el carrito, manda la foto. */
                'address'     => 'San Martin 100',
            ])
            ->assertStatus(201);

        $order = Order::find($respuesta->json('order_id'));

        $this->assertSame($direccion->id, (int) $order->buyer_address_id);
        $this->assertEquals($cart->envio_direccion, $order->envio_direccion);
        $this->assertSame((int) $zona->id, (int) $order->delivery_zone_id);
        $this->assertNull($order->envio_opcion, 'una forma propia nunca escribe envio_opcion');
        $this->assertSame('Av. Colón 123, X5000 Córdoba, Argentina, 4 B (portón negro)', $order->address);
    }

    public function test_un_pedido_sin_direccion_elegida_se_crea_como_siempre()
    {
        $this->asegurarEstadoSinConfirmar();

        $cart = Cart::find($this->crearCarrito()->json('cart.id'));
        $this->assertNull($cart->envio_direccion);

        $order_id = $this->actingAs($this->comprador, 'buyer')
            ->withSession(['carritos_propios' => [$cart->id]])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id, 'address' => 'San Martin 100'])
            ->assertStatus(201)
            ->json('order_id');

        $order = Order::find($order_id);
        $this->assertNull($order->envio_direccion);
        $this->assertNull($order->buyer_address_id);
        $this->assertSame('San Martin 100', $order->address);
    }
}
