<?php

namespace Tests\Feature\Envios;

use App\Cart;
use App\DeliveryZone;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\DireccionesEsquemaHelper;
use App\Http\Controllers\Helpers\OnlinePaymentHelper;
use App\Http\Controllers\Helpers\OrderTotalsHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use App\Http\Controllers\MercadoPagoController;
use App\Mail\NuevoPedidoComercio;
use App\Mail\PedidoRecibidoComprador;
use App\OnlineConfiguration;
use App\Order;
use App\PaymentMethod;
use App\PaymentMethodType;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Las formas de envío del comercio en el checkout (misión formas-envio-direcciones-maps,
 * 19/10/2026).
 *
 * ── Qué fija esta clase ───────────────────────────────────────────────────────────────────────
 *
 * 1. Una forma propia "a convenir" (`delivery_zones.costo_a_convenir = 1`) no cobra NADA online:
 *    no suma en el desglose del mail (la fila dice "A coordinar con el vendedor", nunca "$ 0,00"), no agrega ítem de envío a la
 *    preferencia de Mercado Pago (ni con carrito ni con la zona del body) y no infla el recargo por
 *    cuotas.
 * 2. El carrito solo acepta una forma propia DEL COMERCIO: la de otro comercio o una inexistente
 *    queda en null.
 * 3. "Ofrecer envío por correo con Zipnova" destildado (`ofrecer_envio_zipnova = 0`) apaga Zipnova
 *    aunque el conector esté conectado: `commerce.envios_zipnova: false`, `/envios/cotizar` 422
 *    `sin_zipnova` y el carrito rechaza la opción de correo, todo sin llamar a Zipnova. `null` = se
 *    ofrece, como siempre.
 * 4. Con Zipnova, el pedido copia también la dirección elegida (`envio_direccion`), pero su
 *    `address` sigue siendo el destino del correo en texto.
 *
 * ⚠️ Nada sale a la red (`Http::fake()`); base real del slot con DatabaseTransactions.
 */
class FormasDeEnvioTest extends TestCase
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
        DireccionesEsquemaHelper::olvidar();
        ArticleHelper::olvidar_visibilidad_del_anonimo();

        $this->assertTrue(ZipnovaEsquemaHelper::disponible(), 'La base del slot tiene que tener las columnas de envío.');
        $this->assertTrue(DireccionesEsquemaHelper::costo_a_convenir_disponible(), 'La base del slot tiene que tener delivery_zones.costo_a_convenir.');
        $this->assertTrue(DireccionesEsquemaHelper::ofrecer_envio_zipnova_disponible(), 'La base del slot tiene que tener online_configurations.ofrecer_envio_zipnova.');

        $this->comercio = $this->comercioConTienda();
        $this->articulo = $this->articuloConMedidas($this->comercio);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Forma de envío propia (`DeliveryZone` no declara $guarded: propiedad por propiedad).
     *
     * @param \App\User $comercio
     * @param float $precio
     * @param bool $a_convenir
     * @return \App\DeliveryZone
     */
    private function formaPropia(User $comercio, $precio, $a_convenir)
    {
        $zona = new DeliveryZone();
        $zona->name = $a_convenir ? 'Acordar con el vendedor' : 'Moto en el centro';
        $zona->price = $precio;
        $zona->costo_a_convenir = $a_convenir ? 1 : 0;
        $zona->user_id = $comercio->id;
        $zona->save();

        return $zona;
    }

    /**
     * POST /api/carts como invitado, y el modelo.
     *
     * @param array $extra
     * @return \Illuminate\Testing\TestResponse
     */
    private function crearCarrito(array $extra = [])
    {
        return $this->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart'        => array_merge([
                'articles'             => [$this->lineaDelPayload($this->articulo, 2)],
                'promociones_vinoteca' => [],
                'deliver'              => 1,
            ], $extra),
        ]);
    }

    /**
     * El pedido del carrito por el endpoint, cargado con `withAll()` como lo lee el mail.
     *
     * @param \App\Cart $cart
     * @return \App\Order
     */
    private function pedidoDe(Cart $cart)
    {
        $this->asegurarEstadoSinConfirmar();
        $comprador = $this->compradorDe($this->comercio);

        $order_id = $this->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $comprador->id])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id, 'address' => 'San Martin 100'])
            ->assertStatus(201)
            ->json('order_id');

        return Order::where('id', $order_id)->withAll()->first();
    }

    /**
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
     * `MercadoPagoController::articulos_a_cobrar()` por reflexión (la parte de la preferencia que
     * se puede probar sin red, misma técnica que `CarritoConEnvioTest`).
     *
     * @param array $body
     * @param \App\Cart|null $cart
     * @return array
     */
    private function articulosACobrar(array $body, $cart)
    {
        $payment_method = $this->paymentMethodMp();

        $request = Request::create('/api/mercado-pago/preference', 'POST', array_merge([
            'payment_method' => ['id' => $payment_method->id, 'user_id' => $this->comercio->id],
            'cupon'          => null,
            'articles'       => [['name' => $this->articulo->name, 'final_price' => 1000, 'amount' => 2]],
        ], $body));

        $controller = new MercadoPagoController();
        $metodo = new ReflectionMethod($controller, 'articulos_a_cobrar');
        $metodo->setAccessible(true);

        return $metodo->invoke($controller, $request, $this->comercio->fresh(), $payment_method, $cart);
    }

    /**
     * @param array $items
     * @return array Los ítems "Envio".
     */
    private function itemsDeEnvio(array $items)
    {
        return array_values(array_filter($items, function ($item) {
            return $item['name'] === 'Envio';
        }));
    }

    /**
     * @param int|null $valor
     * @return void
     */
    private function ofrecerZipnova($valor)
    {
        OnlineConfiguration::where('user_id', $this->comercio->id)->update(['ofrecer_envio_zipnova' => $valor]);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 1. Forma propia "a convenir"
    |---------------------------------------------------------------------------------------------
    */

    public function test_una_forma_a_convenir_no_suma_en_el_desglose_del_mail()
    {
        Http::fake();

        /* Un precio guardado distinto de 0 (un ERP viejo, o la base tocada a mano): igual no se cobra. */
        $zona = $this->formaPropia($this->comercio, 800, true);

        $cart = Cart::find($this->crearCarrito(['delivery_zone_id' => $zona->id])->assertStatus(201)->json('cart.id'));
        $this->assertSame((int) $zona->id, (int) $cart->delivery_zone_id);

        $order = $this->pedidoDe($cart);
        $totales = OrderTotalsHelper::breakdown($order);

        $this->assertSame(2000.0, (float) $totales['subtotal']);
        $this->assertNull($totales['delivery_amount'], 'sin monto: no suma nada');
        $this->assertSame('Envio a Acordar con el vendedor', $totales['delivery_label']);
        $this->assertSame('A coordinar con el vendedor', $totales['delivery_text']);
        $this->assertSame(2000.0, (float) $totales['total']);

        /* Los dos mails renderizados: la fila del envío nombra la forma y dice "A coordinar con el
           vendedor", nunca "$ 0,00" ni el precio guardado. */
        foreach ([new PedidoRecibidoComprador($order), new NuevoPedidoComercio($order)] as $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('Envio a Acordar con el vendedor', $html, get_class($mail));
            $this->assertStringContainsString('A coordinar con el vendedor', $html, get_class($mail));
            $this->assertStringNotContainsString('$ 0,00', $html, get_class($mail));
            $this->assertStringNotContainsString('$ 800', $html, get_class($mail));
            $this->assertStringContainsString('$ 2.000,00', $html, 'el total es el de los artículos');
        }
    }

    public function test_el_mail_de_una_forma_con_precio_muestra_el_importe_como_siempre()
    {
        Http::fake();

        $zona = $this->formaPropia($this->comercio, 500, false);
        $cart = Cart::find($this->crearCarrito(['delivery_zone_id' => $zona->id])->assertStatus(201)->json('cart.id'));

        $html = (new PedidoRecibidoComprador($this->pedidoDe($cart)))->render();

        $this->assertStringContainsString('Envio a Moto en el centro', $html);
        $this->assertStringContainsString('$ 500,00', $html);
        $this->assertStringNotContainsString('A coordinar con el vendedor', $html);
    }

    public function test_una_forma_con_precio_sigue_sumando_como_siempre()
    {
        Http::fake();

        $zona = $this->formaPropia($this->comercio, 500, false);
        $cart = Cart::find($this->crearCarrito(['delivery_zone_id' => $zona->id])->assertStatus(201)->json('cart.id'));

        $totales = OrderTotalsHelper::breakdown($this->pedidoDe($cart));

        $this->assertSame(500.0, (float) $totales['delivery_amount']);
        $this->assertSame('Envio a Moto en el centro', $totales['delivery_label']);
        $this->assertSame(2500.0, (float) $totales['total']);
    }

    public function test_la_preferencia_no_cobra_una_forma_a_convenir_ni_con_carrito_ni_con_la_zona_del_body()
    {
        Http::fake();

        $zona = $this->formaPropia($this->comercio, 800, true);
        $cart = Cart::find($this->crearCarrito(['delivery_zone_id' => $zona->id])->json('cart.id'));

        /* Con carrito: la zona sale del carrito y es a convenir. */
        $items = $this->articulosACobrar(['cart_id' => $cart->id], $cart);
        $this->assertCount(0, $this->itemsDeEnvio($items), 'ni un ítem de envío, tampoco uno de $0');
        $this->assertCount(1, $items);

        /* Sin carrito (SPA viejo): la zona del body con su marca tampoco se cobra... */
        $items = $this->articulosACobrar(['delivery_zone' => ['id' => $zona->id, 'price' => 800, 'costo_a_convenir' => 1]], null);
        $this->assertCount(0, $this->itemsDeEnvio($items));

        /* ...y una zona con precio del body se sigue cobrando como siempre. */
        $items = $this->articulosACobrar(['delivery_zone' => ['id' => 1, 'price' => 300]], null);
        $this->assertCount(1, $this->itemsDeEnvio($items));
        $this->assertSame(300, $this->itemsDeEnvio($items)[0]['final_price']);
    }

    public function test_set_prices_saltea_la_zona_a_convenir_que_llega_en_el_body()
    {
        $helper = new OnlinePaymentHelper($this->comercio->fresh(), $this->paymentMethodMp());
        $articulos = [['name' => 'A', 'final_price' => 100, 'amount' => 1]];

        $this->assertCount(1, $helper->setPrices(null, ['price' => 0, 'costo_a_convenir' => true], $articulos));
        $this->assertCount(1, $helper->setPrices(null, ['price' => 50, 'costo_a_convenir' => '1'], $articulos));
        $this->assertCount(2, $helper->setPrices(null, ['price' => 50, 'costo_a_convenir' => 0], $articulos));
        $this->assertCount(2, $helper->setPrices(null, ['price' => 50], $articulos), 'sin la marca es la zona de siempre');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 2. Solo formas del comercio
    |---------------------------------------------------------------------------------------------
    */

    public function test_una_forma_de_otro_comercio_o_inexistente_se_descarta()
    {
        Http::fake();

        $otro_comercio = $this->comercioConTienda();
        $ajena = $this->formaPropia($otro_comercio, 100, false);

        $cart = Cart::find($this->crearCarrito(['delivery_zone_id' => $ajena->id])->assertStatus(201)->json('cart.id'));
        $this->assertNull($cart->delivery_zone_id, 'la zona de otro comercio no se ata al carrito');

        $cart = Cart::find($this->crearCarrito(['delivery_zone_id' => 999999999])->assertStatus(201)->json('cart.id'));
        $this->assertNull($cart->delivery_zone_id, 'un id que no existe tampoco');

        $propia = $this->formaPropia($this->comercio, 100, false);
        $cart = Cart::find($this->crearCarrito(['delivery_zone_id' => $propia->id])->assertStatus(201)->json('cart.id'));
        $this->assertSame((int) $propia->id, (int) $cart->delivery_zone_id);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 3. Zipnova apagado por el comercio
    |---------------------------------------------------------------------------------------------
    */

    public function test_con_zipnova_destildado_la_tienda_no_lo_ofrece_aunque_este_conectado()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        /* Prendido (null = de fábrica): se ofrece. */
        $this->ofrecerZipnova(null);
        $this->assertTrue($this->json('GET', '/api/commerce/'.$this->comercio->id)->assertStatus(200)->json('commerce.envios_zipnova'));

        $this->ofrecerZipnova(1);
        $this->assertTrue($this->json('GET', '/api/commerce/'.$this->comercio->id)->json('commerce.envios_zipnova'));

        /* Destildado. */
        $this->ofrecerZipnova(0);

        $publico = $this->json('GET', '/api/commerce/'.$this->comercio->id)->assertStatus(200);
        $this->assertFalse($publico->json('commerce.envios_zipnova'));
        $this->assertNull($publico->json('commerce.envios_zipnova_config.envio_gratis_desde'));

        $this->postJson('/api/envios/cotizar', [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $this->articulo->id, 'amount' => 1]],
        ])->assertStatus(422)->assertJson(['codigo' => 'sin_zipnova']);

        $carritos_antes = Cart::where('user_id', $this->comercio->id)->count();

        $this->crearCarrito([
            'envio' => [
                'zipcode'    => '5000',
                'opcion_key' => self::KEY_DOMICILIO_CORREO_ARG,
                'point_id'   => null,
                'destino'    => $this->destinoCompleto(),
            ],
        ])->assertStatus(422)->assertJson(['codigo' => 'sin_zipnova']);

        $this->assertSame($carritos_antes, Cart::where('user_id', $this->comercio->id)->count());

        Http::assertNothingSent();
    }

    /**
     * El hueco que el carrito no ve: el comprador eligió Zipnova (cotización vigente) y DESPUÉS el
     * comercio lo apagó. El pedido no puede nacer con Zipnova, porque al confirmarlo en el ERP se
     * generaría y cobraría el envío.
     */
    public function test_un_pedido_no_nace_con_zipnova_si_el_comercio_lo_apago_despues_de_elegirlo()
    {
        $conector = $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();
        $this->asegurarEstadoSinConfirmar();

        $cart = Cart::find($this->crearCarrito([
            'envio' => [
                'zipcode'    => '5000',
                'opcion_key' => self::KEY_DOMICILIO_CORREO_ARG,
                'point_id'   => null,
                'destino'    => $this->destinoCompleto(),
            ],
        ])->assertStatus(201)->json('cart.id'));
        $this->assertSame(self::KEY_DOMICILIO_CORREO_ARG, $cart->envio_opcion['key']);

        $comprador = $this->compradorDe($this->comercio);
        $pedidos_antes = Order::where('user_id', $this->comercio->id)->count();

        $pedir = function () use ($cart, $comprador) {
            return $this->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $comprador->id])
                ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id, 'address' => 'San Martin 100']);
        };

        /* 1. El comercio destildó "Ofrecer envío por correo con Zipnova". */
        $this->ofrecerZipnova(0);

        $respuesta = $pedir()->assertStatus(422);
        $this->assertSame('opcion_envio', $respuesta->json('codigo'));
        $this->assertSame('Esa forma de envío ya no está disponible. Elegí otra.', $respuesta->json('message'));
        $this->assertSame($pedidos_antes, Order::where('user_id', $this->comercio->id)->count(), 'no se creó ningún pedido');
        $this->assertNotNull(Cart::find($cart->id), 'el carrito queda para volver a elegir');

        /* 2. Lo vuelve a ofrecer pero ya no tiene el conector (lo desconectó en el ERP): igual. La
              tienda decide "conectado" por la credencial (`PlatformConnector::is_connected()`),
              no por `status`, así que se simula sacando el conector. */
        $this->ofrecerZipnova(1);
        $conector->delete();

        $pedir()->assertStatus(422)->assertJson(['codigo' => 'opcion_envio']);
        $this->assertSame($pedidos_antes, Order::where('user_id', $this->comercio->id)->count());

        /* 3. Con los dos en orden, el pedido nace con Zipnova como siempre. */
        $this->conectorZipnova($this->comercio);

        $order = Order::find($pedir()->assertStatus(201)->json('order_id'));
        $this->assertSame('zipnova', $order->envio_proveedor);

        /* La cotización vigente no volvió a salir a Zipnova: la única llamada fue la del carrito. */
        Http::assertSentCount(1);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 4. Zipnova con la dirección elegida
    |---------------------------------------------------------------------------------------------
    */

    public function test_con_zipnova_el_pedido_copia_la_direccion_pero_la_linea_es_la_del_correo()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $cart = Cart::find($this->crearCarrito([
            'envio'           => [
                'zipcode'    => '5000',
                'opcion_key' => self::KEY_DOMICILIO_CORREO_ARG,
                'point_id'   => null,
                'destino'    => $this->destinoCompleto(),
            ],
            'direccion_envio' => [
                'street'            => 'Av. Colon',
                'street_number'     => '123',
                'apartment'         => '4 B',
                'city'              => 'Cordoba',
                'province'          => 'Cordoba',
                'zip_code'          => '5000',
                'formatted_address' => 'Av. Colón 123, X5000 Córdoba, Argentina',
                'lat'               => -31.41,
                'lng'               => -64.18,
            ],
        ])->assertStatus(201)->json('cart.id'));

        $order = $this->pedidoDe($cart);

        $this->assertSame('zipnova', $order->envio_proveedor);
        $this->assertSame('Av. Colón 123, X5000 Córdoba, Argentina', $order->envio_direccion['formatted_address']);
        $this->assertSame(-31.41, $order->envio_direccion['lat']);
        $this->assertNull($order->buyer_address_id, 'invitado: sin dirección guardada');
        $this->assertStringStartsWith('Av. Colon 123 4 B, Cordoba, Cordoba (CP 5000)', $order->address, 'con Zipnova la línea es la del destino del correo');
    }

    public function test_el_comercio_publica_si_hay_direcciones_de_envio()
    {
        Http::fake();

        $this->assertTrue($this->json('GET', '/api/commerce/'.$this->comercio->id)->assertStatus(200)->json('commerce.direcciones_de_envio'));
    }
}
