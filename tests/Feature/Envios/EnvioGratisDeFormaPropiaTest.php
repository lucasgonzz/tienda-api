<?php

namespace Tests\Feature\Envios;

use App\Cart;
use App\DeliveryZone;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use App\Http\Controllers\Helpers\DireccionesEsquemaHelper;
use App\Http\Controllers\Helpers\EnvioZonaPropiaHelper;
use App\Http\Controllers\Helpers\OnlinePaymentHelper;
use App\Http\Controllers\Helpers\OrderTotalsHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use App\Order;
use App\PaymentMethod;
use App\PaymentMethodType;
use App\PromocionVinoteca;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Envío gratis en una forma de envío propia (misión truvari-carrito-entrega-pago, 26/11/2026).
 * Criterios de aceptación CA4 a CA11 del PLAN.
 *
 * ── Qué fija esta clase ───────────────────────────────────────────────────────────────────────
 *
 * Una forma propia (`delivery_zones`) puede ser gratis desde un monto (`envio_gratis_desde`) o si
 * el pedido tiene un combo o promoción (`envio_gratis_con_combo`). El servidor la resuelve con el
 * carrito vivo (subtotal = `carts.total`: sin envío, sin recargo web, sin recargo ni descuento del
 * medio de pago) y fotografía lo que se cobró en el pedido (`orders.envio_zona_precio` y
 * `orders.envio_zona_gratis_motivo`). Todos los lectores (mail, preferencia de Mercado Pago, página
 * de gracias) usan lo mismo.
 *
 * Datos de referencia: zona "Envío Truvari" $7.000, gratis desde $80.000 y con combos.
 *
 * ⚠️ Las cuatro columnas nuevas las crea `empresa-api` (la tienda no tiene migraciones): la base
 * del slot ya las tiene. Nada sale a la red (`Http::fake()`).
 *
 * ⚠️ CA10 esconde las columnas con DDL (`RENAME COLUMN`), que le hace commit implícito a la
 * transacción: por eso ese caso arma su propia transacción adentro y restaura en el `finally` y en
 * el `tearDown`, igual que `SinEsquemaDeDireccionesTest`.
 */
class EnvioGratisDeFormaPropiaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConZipnova;

    /** Sufijo con el que se esconden las columnas en CA10. */
    const SUFIJO = '_escondida_test';

    /** Las cuatro columnas que crea la migración de empresa-api, por tabla. */
    const COLUMNAS = [
        'delivery_zones' => ['envio_gratis_desde', 'envio_gratis_con_combo'],
        'orders'         => ['envio_zona_precio', 'envio_zona_gratis_motivo'],
    ];

    /** @var \App\User */
    private $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        /* Por si una corrida anterior murió con las columnas escondidas. */
        $this->restaurarLasColumnas();

        ZipnovaEsquemaHelper::olvidar();
        DireccionesEsquemaHelper::olvidar();
        ComboEsquemaHelper::olvidar();
        ArticleHelper::olvidar_visibilidad_del_anonimo();

        /* Se mide con Schema y no con el helper nuevo, así cada caso falla por su propio motivo. */
        foreach (self::COLUMNAS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                $this->assertTrue(Schema::hasColumn($tabla, $columna), 'La base del slot tiene que tener '.$tabla.'.'.$columna.'.');
            }
        }

        $this->comercio = $this->comercioConTienda();
    }

    protected function tearDown(): void
    {
        $this->restaurarLasColumnas();

        if (class_exists(EnvioZonaPropiaHelper::class)) {
            EnvioZonaPropiaHelper::olvidar();
        }

        parent::tearDown();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures
    |---------------------------------------------------------------------------------------------
    */

    /**
     * La forma propia de Truvari: $7.000, gratis desde $80.000 y con combos. Con `$con_regla`
     * false (CA10, columnas escondidas) no se asignan las dos columnas de la regla.
     *
     * @param array $cambios Atributos que pisan los de referencia.
     * @param bool $con_regla
     * @return \App\DeliveryZone
     */
    private function zonaTruvari(array $cambios = [], $con_regla = true)
    {
        $zona = new DeliveryZone();
        $zona->name = 'Envío Truvari';
        $zona->price = 7000;
        $zona->costo_a_convenir = 0;
        $zona->user_id = $this->comercio->id;

        if ($con_regla) {
            $zona->envio_gratis_desde = 80000;
            $zona->envio_gratis_con_combo = 1;
        }

        foreach ($cambios as $atributo => $valor) {
            $zona->{$atributo} = $valor;
        }

        $zona->save();

        return $zona;
    }

    /**
     * POST /api/carts como invitado, con envío a domicilio por la forma propia.
     *
     * @param \App\DeliveryZone $zona
     * @param array $lineas Artículos del payload.
     * @param array $extra Claves extra del carrito (promociones, medio de pago...).
     * @param \App\Buyer|null $comprador Si viene, el carrito se crea con su sesión.
     * @return \App\Cart
     */
    private function carritoCon(DeliveryZone $zona, array $lineas, array $extra = [], $comprador = null)
    {
        $peticion = $comprador ? $this->actingAs($comprador, 'buyer') : $this;

        $respuesta = $peticion->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart'        => array_merge([
                'articles'             => $lineas,
                'promociones_vinoteca' => [],
                'deliver'              => 1,
                'delivery_zone_id'     => $zona->id,
            ], $extra),
        ])->assertStatus(201);

        return Cart::findOrFail($respuesta->json('cart.id'));
    }

    /**
     * Línea de artículo con el precio y la cantidad pedidos.
     *
     * @param float $precio
     * @param int $cantidad
     * @return array
     */
    private function lineaDeArticulo($precio, $cantidad)
    {
        $articulo = $this->articuloConMedidas($this->comercio, ['final_price' => $precio]);

        return $this->lineaDelPayload($articulo, $cantidad);
    }

    /**
     * Línea de promoción de vinoteca (lo que la tienda vende como "combo").
     *
     * @param float $precio
     * @return array
     */
    private function lineaDePromocion($precio)
    {
        $promo = PromocionVinoteca::create([
            'name'        => 'Caja de seis vinos',
            'user_id'     => $this->comercio->id,
            'online'      => 1,
            'final_price' => $precio,
        ]);

        return [
            'id'          => $promo->id,
            'name'        => $promo->name,
            'final_price' => $promo->final_price,
            'cost'        => null,
            'pivot'       => ['amount' => 1, 'notes' => null],
        ];
    }

    /**
     * El pedido del carrito por el endpoint real (201), cargado con `withAll()` como lo lee el mail.
     *
     * @param \App\Cart $cart
     * @param \App\Buyer|null $comprador
     * @return \App\Order
     */
    private function pedidoDe(Cart $cart, $comprador = null)
    {
        $this->asegurarEstadoSinConfirmar();
        $comprador = $comprador ?: $this->compradorDe($this->comercio);

        $order_id = $this->actingAs($comprador, 'buyer')
            ->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $comprador->id])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id, 'address' => 'San Martin 100'])
            ->assertStatus(201)
            ->json('order_id');

        return Order::where('id', $order_id)->withAll()->first();
    }

    /**
     * Medio de pago de Mercado Pago del comercio, con descuento y/o recargo.
     *
     * @param float|null $descuento Porcentaje de descuento.
     * @param float|null $recargo Porcentaje de recargo.
     * @return \App\PaymentMethod
     */
    private function medioDePago($descuento = null, $recargo = null)
    {
        $tipo = PaymentMethodType::where('name', 'MercadoPago')->first();

        if (!$tipo) {
            $tipo = new PaymentMethodType;
            $tipo->name = 'MercadoPago';
            $tipo->save();
        }

        $medio = new PaymentMethod;
        $medio->name                   = 'Mercado Pago';
        $medio->user_id                = $this->comercio->id;
        $medio->payment_method_type_id = $tipo->id;
        $medio->discount               = $descuento;
        $medio->surchage               = $recargo;
        $medio->save();

        return $medio;
    }

    /**
     * Los ítems que se le cobran al comprador del carrito (misma lista de Checkout Pro y del cobro
     * con tarjeta).
     *
     * @param \App\Cart $cart
     * @param \App\PaymentMethod $medio
     * @return array
     */
    private function itemsDelCarrito(Cart $cart, PaymentMethod $medio)
    {
        return OnlinePaymentHelper::items_del_carrito($cart->fresh(), $this->comercio->fresh(), $medio);
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
     * @param array $items
     * @return float Lo que Mercado Pago le va a cobrar: suma de `final_price * amount`.
     */
    private function sumaDeLosItems(array $items)
    {
        return (float) array_sum(array_map(function ($item) {
            return $item['final_price'] * $item['amount'];
        }, $items));
    }

    /**
     * Lo guardado en la fila del pedido, tal cual sale de la base (sin casts).
     *
     * @param \App\Order $order
     * @return object
     */
    private function filaDelPedido(Order $order)
    {
        return DB::table('orders')->where('id', $order->id)->first();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA4 a CA6: el pedido guarda lo que se cobró de envío
    |---------------------------------------------------------------------------------------------
    */

    /** CA4 */
    public function test_pedido_que_supera_el_minimo_sale_gratis()
    {
        Http::fake();

        $zona = $this->zonaTruvari();
        $cart = $this->carritoCon($zona, [$this->lineaDeArticulo(45000, 2)]);
        $this->assertEquals(90000, $cart->total);

        $order = $this->pedidoDe($cart);
        $fila = $this->filaDelPedido($order);

        $this->assertNotNull($fila->envio_zona_precio, 'el pedido fotografía lo que se cobró, aunque sea $0');
        $this->assertSame('0.00', (string) $fila->envio_zona_precio);
        $this->assertSame('monto', $fila->envio_zona_gratis_motivo);
        $this->assertEquals(90000, $fila->total, 'orders.total no incluye el envío');

        $totales = OrderTotalsHelper::breakdown($order);
        $this->assertSame(0.0, (float) $totales['delivery_amount']);
        $this->assertStringEndsWith('- gratis', $totales['delivery_label']);
        $this->assertSame(90000.0, (float) $totales['total']);

        Http::assertNothingSent();
    }

    /** CA5 */
    public function test_pedido_que_no_llega_cobra_el_precio()
    {
        Http::fake();

        $zona = $this->zonaTruvari();
        $cart = $this->carritoCon($zona, [$this->lineaDeArticulo(50000, 1)]);

        $order = $this->pedidoDe($cart);
        $fila = $this->filaDelPedido($order);

        $this->assertNotNull($fila->envio_zona_precio);
        $this->assertSame('7000.00', (string) $fila->envio_zona_precio);
        $this->assertNull($fila->envio_zona_gratis_motivo);

        $totales = OrderTotalsHelper::breakdown($order);
        $this->assertSame(7000.0, (float) $totales['delivery_amount']);
        $this->assertStringEndsNotWith('- gratis', $totales['delivery_label']);
        $this->assertSame(57000.0, (float) $totales['total']);
    }

    /** CA6 */
    public function test_un_combo_da_envio_gratis_si_la_forma_lo_permite()
    {
        Http::fake();

        /* $20.000 + promoción de $15.000 = $35.000: no llega al mínimo, pero hay combo. */
        $zona = $this->zonaTruvari();
        $cart = $this->carritoCon($zona, [$this->lineaDeArticulo(20000, 1)], ['promociones_vinoteca' => [$this->lineaDePromocion(15000)]]);
        $this->assertEquals(35000, $cart->total);

        $fila = $this->filaDelPedido($this->pedidoDe($cart));

        $this->assertNotNull($fila->envio_zona_precio);
        $this->assertSame('0.00', (string) $fila->envio_zona_precio);
        $this->assertSame('combo', $fila->envio_zona_gratis_motivo);

        /* Con la casilla apagada, el mismo carrito paga el envío. */
        $sin_casilla = $this->zonaTruvari(['envio_gratis_con_combo' => 0]);
        $otro = $this->carritoCon($sin_casilla, [$this->lineaDeArticulo(20000, 1)], ['promociones_vinoteca' => [$this->lineaDePromocion(15000)]]);

        $fila = $this->filaDelPedido($this->pedidoDe($otro));

        $this->assertNotNull($fila->envio_zona_precio);
        $this->assertSame('7000.00', (string) $fila->envio_zona_precio);
        $this->assertNull($fila->envio_zona_gratis_motivo);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA7 y CA8: lo que se cobra en Mercado Pago
    |---------------------------------------------------------------------------------------------
    */

    /** CA7 */
    public function test_la_preferencia_no_cobra_el_envio_gratis()
    {
        Http::fake();

        $zona = $this->zonaTruvari();
        $medio = $this->medioDePago();

        /* Carrito de CA4 ($90.000): ni un ítem de envío, ni siquiera uno de $0. */
        $gratis = $this->carritoCon($zona, [$this->lineaDeArticulo(45000, 2)]);
        $items = $this->itemsDelCarrito($gratis, $medio);

        $this->assertCount(0, $this->itemsDeEnvio($items), 'envío gratis: no viaja ítem "Envio"');
        $this->assertEquals(90000, $this->sumaDeLosItems($items));

        /* Carrito de CA5 ($50.000): el ítem "Envio" de $7.000. */
        $cobra = $this->carritoCon($zona, [$this->lineaDeArticulo(50000, 1)]);
        $items = $this->itemsDelCarrito($cobra, $medio);

        $this->assertCount(1, $this->itemsDeEnvio($items));
        $this->assertEquals(7000, $this->itemsDeEnvio($items)[0]['final_price']);
        $this->assertEquals(57000, $this->sumaDeLosItems($items));
    }

    /** CA8 */
    public function test_el_medio_de_pago_no_cambia_el_minimo()
    {
        Http::fake();

        $zona = $this->zonaTruvari();

        /* $85.000 con 10% de descuento del medio de pago: lo cobrado baja de $80.000, el envío sigue gratis. */
        $con_descuento = $this->medioDePago(10, null);
        $cart = $this->carritoCon($zona, [$this->lineaDeArticulo(85000, 1)], ['payment_method_id' => $con_descuento->id]);

        $items = $this->itemsDelCarrito($cart, $con_descuento);
        $this->assertCount(0, $this->itemsDeEnvio($items), 'el descuento del medio de pago no le saca el envío gratis');
        $this->assertEquals(76500, $this->sumaDeLosItems($items));

        $this->assertSame('0.00', (string) $this->filaDelPedido($this->pedidoDe($cart))->envio_zona_precio);

        /* $75.000 con 10% de recargo: lo cobrado supera $80.000, pero el subtotal no llega y el envío se cobra. */
        $con_recargo = $this->medioDePago(null, 10);
        $cart = $this->carritoCon($zona, [$this->lineaDeArticulo(75000, 1)], ['payment_method_id' => $con_recargo->id]);

        $items = $this->itemsDelCarrito($cart, $con_recargo);
        $this->assertCount(1, $this->itemsDeEnvio($items), 'el recargo del medio de pago no le da el envío gratis');
        $this->assertEquals(7000, $this->itemsDeEnvio($items)[0]['final_price'], 'el envío no lleva recargo');

        $fila = $this->filaDelPedido($this->pedidoDe($cart));
        $this->assertNotNull($fila->envio_zona_precio);
        $this->assertSame('7000.00', (string) $fila->envio_zona_precio);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA9: "a convenir" gana sobre todo
    |---------------------------------------------------------------------------------------------
    */

    /** CA9 */
    public function test_a_convenir_tiene_prioridad()
    {
        Http::fake();

        $zona = $this->zonaTruvari(['costo_a_convenir' => 1]);
        $cart = $this->carritoCon($zona, [$this->lineaDeArticulo(45000, 2)]);

        /* La regla pura: nunca "Gratis" en una forma a convenir. */
        $resuelto = EnvioZonaPropiaHelper::resolver($zona, 90000, false);
        $this->assertTrue($resuelto['a_convenir']);
        $this->assertFalse($resuelto['gratis']);
        $this->assertNull($resuelto['motivo']);
        $this->assertSame([], EnvioZonaPropiaHelper::atributos_para_pedido($cart->fresh()), 'a convenir no fotografía nada');

        /* La preferencia: sin ítem de envío. */
        $items = $this->itemsDelCarrito($cart, $this->medioDePago());
        $this->assertCount(0, $this->itemsDeEnvio($items));

        /* El pedido: sin foto, y el mail dice que se coordina con el vendedor. */
        $order = $this->pedidoDe($cart);
        $this->assertNull($this->filaDelPedido($order)->envio_zona_precio);
        $this->assertNull($this->filaDelPedido($order)->envio_zona_gratis_motivo);

        $totales = OrderTotalsHelper::breakdown($order);
        $this->assertSame('A coordinar con el vendedor', $totales['delivery_text']);
        $this->assertNull($totales['delivery_amount']);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA10: base sin las columnas
    |---------------------------------------------------------------------------------------------
    */

    /** CA10 */
    public function test_sin_las_columnas_la_tienda_cobra_como_siempre()
    {
        $this->esconderLasColumnas();

        try {
            EnvioZonaPropiaHelper::olvidar();
            $this->assertFalse(EnvioZonaPropiaHelper::regla_disponible(), 'sin las columnas de delivery_zones la regla no existe');
            $this->assertFalse(EnvioZonaPropiaHelper::snapshot_disponible(), 'sin las columnas de orders no hay foto');

            DB::beginTransaction();

            try {
                $this->comercio = $this->comercioConTienda();
                $zona = $this->zonaTruvari([], false);
                $medio = $this->medioDePago();

                /* Con un carrito de $90.000 la zona cobra su precio, como siempre. */
                $cart = $this->carritoCon($zona, [$this->lineaDeArticulo(45000, 2)]);
                $this->assertSame([], EnvioZonaPropiaHelper::atributos_para_pedido($cart->fresh()));

                $items = $this->itemsDelCarrito($cart, $medio);
                $this->assertCount(1, $this->itemsDeEnvio($items));
                $this->assertEquals(7000, $this->itemsDeEnvio($items)[0]['final_price']);

                $order = $this->pedidoDe($cart);
                $this->assertArrayNotHasKey('envio_zona_precio', (array) $this->filaDelPedido($order));

                $totales = OrderTotalsHelper::breakdown($order);
                $this->assertSame(7000.0, (float) $totales['delivery_amount']);
                $this->assertSame(97000.0, (float) $totales['total']);

                /* Con correo (Zipnova) el pedido nace como siempre y no recibe las claves de la zona. */
                $this->conectorZipnova($this->comercio);
                $this->zipnovaCotiza();

                $por_correo = $this->carritoCon($zona, [$this->lineaDeArticulo(45000, 2)], [
                    'delivery_zone_id' => null,
                    'envio'            => [
                        'zipcode'    => '5000',
                        'opcion_key' => self::KEY_DOMICILIO_CORREO_ARG,
                        'point_id'   => null,
                        'destino'    => $this->destinoCompleto(),
                    ],
                ]);
                $atributos = EnvioZonaPropiaHelper::atributos_para_pedido($por_correo->fresh());
                $this->assertArrayNotHasKey('envio_zona_precio', $atributos);
                $this->assertArrayNotHasKey('envio_zona_gratis_motivo', $atributos);

                $pedido_por_correo = $this->pedidoDe($por_correo);
                $this->assertSame('zipnova', $pedido_por_correo->envio_proveedor);
            } finally {
                DB::rollBack();
            }
        } finally {
            $this->restaurarLasColumnas();
        }

        EnvioZonaPropiaHelper::olvidar();
        $this->assertTrue(EnvioZonaPropiaHelper::regla_disponible(), 'la base volvió a tener las columnas de delivery_zones');
        $this->assertTrue(EnvioZonaPropiaHelper::snapshot_disponible(), 'la base volvió a tener las columnas de orders');
    }

    /**
     * Con las columnas presentes, un pedido por correo tampoco lleva la foto de la zona: la plata
     * del envío por correo es `envio_precio`, no `envio_zona_precio`.
     *
     * @return void
     */
    public function test_con_correo_el_pedido_no_lleva_la_foto_de_la_zona()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $zona = $this->zonaTruvari();
        $cart = $this->carritoCon($zona, [$this->lineaDeArticulo(45000, 2)], [
            'delivery_zone_id' => null,
            'envio'            => [
                'zipcode'    => '5000',
                'opcion_key' => self::KEY_DOMICILIO_CORREO_ARG,
                'point_id'   => null,
                'destino'    => $this->destinoCompleto(),
            ],
        ]);

        $this->assertSame([], EnvioZonaPropiaHelper::atributos_para_pedido($cart->fresh()));

        $order = $this->pedidoDe($cart);
        $fila = $this->filaDelPedido($order);

        $this->assertSame('zipnova', $order->envio_proveedor);
        $this->assertNull($fila->envio_zona_precio);
        $this->assertNull($fila->envio_zona_gratis_motivo);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA11: la página de gracias
    |---------------------------------------------------------------------------------------------
    */

    /** CA11 */
    public function test_la_pagina_de_gracias_recibe_la_forma_y_la_foto()
    {
        Http::fake();

        $comprador = $this->compradorDe($this->comercio);
        $zona = $this->zonaTruvari();
        $cart = $this->carritoCon($zona, [$this->lineaDeArticulo(45000, 2)], [], $comprador);
        $this->pedidoDe($cart, $comprador);

        $actual = $this->actingAs($comprador, 'buyer')
            ->json('GET', '/api/orders/current/'.$this->comercio->id)
            ->assertStatus(200);

        $this->assertSame('Envío Truvari', $actual->json('order.delivery_zone.name'), 'la relación delivery_zone viaja con el pedido');
        $this->assertNotNull($actual->json('order.envio_zona_precio'));
        $this->assertEquals(0, $actual->json('order.envio_zona_precio'));
        $this->assertSame('monto', $actual->json('order.envio_zona_gratis_motivo'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Esconder y restaurar las columnas (CA10)
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Renombra las cuatro columnas. DDL: commit implícito, por eso va afuera de la transacción del
     * caso (y por eso CA10 abre la suya adentro).
     *
     * @return void
     */
    private function esconderLasColumnas()
    {
        /* DDL cierra la transacción de DatabaseTransactions: se corta antes de tocar el esquema. */
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        foreach (self::COLUMNAS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    DB::statement('ALTER TABLE `'.$tabla.'` RENAME COLUMN `'.$columna.'` TO `'.$columna.self::SUFIJO.'`');
                }
            }
        }
    }

    /**
     * Devuelve las columnas a su nombre. Idempotente.
     *
     * @return void
     */
    private function restaurarLasColumnas()
    {
        foreach (self::COLUMNAS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                if (Schema::hasColumn($tabla, $columna.self::SUFIJO)) {
                    DB::statement('ALTER TABLE `'.$tabla.'` RENAME COLUMN `'.$columna.self::SUFIJO.'` TO `'.$columna.'`');
                }
            }
        }
    }
}
