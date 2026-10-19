<?php

namespace Tests\Feature\Direcciones;

use App\Cart;
use App\DeliveryZone;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\DireccionesEsquemaHelper;
use App\Http\Controllers\Helpers\OrderTotalsHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use App\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Envios\ArmaComercioConZipnova;
use Tests\TestCase;

/**
 * El contrato con `empresa-api`: la tienda nueva desplegada contra una base que todavía NO tiene el
 * esquema de la misión formas-envio-direcciones-maps (19/10/2026): ni `buyer_addresses`, ni
 * `carts/orders.buyer_address_id` + `envio_direccion`, ni `delivery_zones.costo_a_convenir`, ni
 * `online_configurations.ofrecer_envio_zipnova`.
 *
 * 🔴 Es el escenario normal durante días o semanas: el esquema llega con el release del ERP y la
 * tienda la despliega Lucas por cliente cuando quiere. Lo que se fija, todo sin un 500:
 *   - `GET /api/commerce/{id}` dice `direcciones_de_envio: false`, y Zipnova se sigue ofreciendo con
 *     el conector conectado (sin la columna, "ofrecer" es el comportamiento de siempre);
 *   - `/api/buyer/addresses` responde 422 `sin_direcciones`;
 *   - el carrito con `direccion_envio` y una forma propia se guarda como siempre, sin escribir nada
 *     nuevo;
 *   - el pedido se crea con la dirección del body, y la forma propia se cobra a su precio.
 *
 * ⚠️ Por qué NO usa DatabaseTransactions: esconder el esquema es DDL (`RENAME`), y MySQL le hace
 * commit implícito a la transacción abierta. El DDL va afuera y lo que se inserta va adentro de una
 * transacción manual que se revierte en el `finally`. Mismo criterio que `SinEsquemaDeEnviosTest`.
 *
 * 🔴 El restore va en el `finally`, en el `tearDown` y en el `setUp` (por si una corrida anterior
 * murió a mitad): si el esquema quedara escondido, se llevaría puesta la suite entera del slot.
 */
class SinEsquemaDeDireccionesTest extends TestCase
{
    use ArmaComercioConZipnova;
    use ArmaDirecciones;

    /** Sufijo con el que se esconden las columnas y la tabla. */
    const SUFIJO = '_escondida_test';

    /** Las columnas sueltas, las que crean 2026_10_19_100100 y 2026_10_19_100200 de `empresa-api`. */
    const COLUMNAS = [
        'carts'                 => ['buyer_address_id', 'envio_direccion'],
        'orders'                => ['buyer_address_id', 'envio_direccion'],
        'delivery_zones'        => ['costo_a_convenir'],
        'online_configurations' => ['ofrecer_envio_zipnova'],
    ];

    /** La tabla de 2026_10_19_100000. */
    const TABLA = 'buyer_addresses';

    protected function setUp(): void
    {
        parent::setUp();

        DireccionesEsquemaHelper::olvidar();
        ZipnovaEsquemaHelper::olvidar();
        ArticleHelper::olvidar_visibilidad_del_anonimo();

        /* Por si una corrida anterior murió con el esquema escondido. */
        $this->restaurarElEsquema();

        $this->assertTrue(DireccionesEsquemaHelper::disponible(), 'La base del slot tiene que arrancar con el esquema de direcciones.');
        $this->assertTrue(DireccionesEsquemaHelper::costo_a_convenir_disponible());
        $this->assertTrue(DireccionesEsquemaHelper::ofrecer_envio_zipnova_disponible());
    }

    protected function tearDown(): void
    {
        $this->restaurarElEsquema();
        DireccionesEsquemaHelper::olvidar();

        parent::tearDown();
    }

    public function test_sin_el_esquema_la_tienda_sigue_con_el_checkout_de_siempre()
    {
        $this->esconderElEsquema();

        try {
            $this->assertFalse(DireccionesEsquemaHelper::disponible(), 'con la tabla y las columnas escondidas la guarda da false');
            $this->assertFalse(DireccionesEsquemaHelper::costo_a_convenir_disponible());
            $this->assertFalse(DireccionesEsquemaHelper::ofrecer_envio_zipnova_disponible());

            DB::beginTransaction();

            try {
                $comercio = $this->comercioConTienda();
                $articulo = $this->articuloConMedidas($comercio);
                $comprador = $this->compradorDe($comercio);
                $this->conectorZipnova($comercio);

                $zona = new DeliveryZone();
                $zona->name = 'Moto';
                $zona->price = 500;
                $zona->user_id = $comercio->id;
                $zona->save();

                Http::fake();

                /* 1. El comercio público: sin direcciones, y Zipnova igual que siempre. */
                $publico = $this->json('GET', '/api/commerce/'.$comercio->id)->assertStatus(200);
                $this->assertFalse($publico->json('commerce.direcciones_de_envio'));
                $this->assertTrue($publico->json('commerce.envios_zipnova'), 'sin la columna, Zipnova se ofrece como siempre');

                /* 2. "Mis direcciones": 422 con código, nunca un 500. */
                $this->actingAs($comprador, 'buyer')->getJson('/api/buyer/addresses')
                    ->assertStatus(422)->assertJson(['codigo' => 'sin_direcciones']);
                $this->actingAs($comprador, 'buyer')->postJson('/api/buyer/addresses', $this->direccionDeGoogle())
                    ->assertStatus(422)->assertJson(['codigo' => 'sin_direcciones']);
                $this->actingAs($comprador, 'buyer')->putJson('/api/buyer/addresses/1', ['street' => 'X'])
                    ->assertStatus(422)->assertJson(['codigo' => 'sin_direcciones']);
                $this->actingAs($comprador, 'buyer')->deleteJson('/api/buyer/addresses/1')
                    ->assertStatus(422)->assertJson(['codigo' => 'sin_direcciones']);

                /* 3. El carrito de un SPA nuevo, con `direccion_envio`: se guarda como siempre. */
                $creado = $this->actingAs($comprador, 'buyer')->postJson('/api/carts', [
                    'commerce_id' => $comercio->id,
                    'cart'        => [
                        'articles'             => [$this->lineaDelPayload($articulo, 2)],
                        'promociones_vinoteca' => [],
                        'deliver'              => 1,
                        'delivery_zone_id'     => $zona->id,
                        'direccion_envio'      => $this->direccionDeGoogle(),
                    ],
                ])->assertStatus(201);

                $cart_id = (int) $creado->json('cart.id');
                $this->assertArrayNotHasKey('envio_direccion', $creado->json('cart'), 'sin la columna no hay atributo');
                $this->assertSame((int) $zona->id, (int) Cart::find($cart_id)->delivery_zone_id);

                /* 4. El pedido: con la dirección del body y la zona a su precio. */
                $this->asegurarEstadoSinConfirmar();

                $pedido = $this->actingAs($comprador, 'buyer')
                    ->withSession(['carritos_propios' => [$cart_id]])
                    ->postJson('/api/orders', [
                        'cart_id'     => $cart_id,
                        'commerce_id' => $comercio->id,
                        'address'     => 'San Martin 100',
                    ])->assertStatus(201);

                $order = Order::where('id', $pedido->json('order_id'))->withAll()->first();
                $this->assertSame('San Martin 100', $order->address);

                $totales = OrderTotalsHelper::breakdown($order);
                $this->assertSame(500.0, (float) $totales['delivery_amount']);
                $this->assertSame(2500.0, (float) $totales['total']);

                Http::assertNothingSent();
            } finally {
                DB::rollBack();
            }
        } finally {
            $this->restaurarElEsquema();
        }

        DireccionesEsquemaHelper::olvidar();
        $this->assertTrue(DireccionesEsquemaHelper::disponible(), 'la base volvió a tener el esquema');
    }

    /**
     * Renombra la tabla y las seis columnas. DDL: commit implícito, por eso va afuera de la
     * transacción del caso.
     *
     * @return void
     */
    private function esconderElEsquema()
    {
        foreach (self::COLUMNAS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    DB::statement('ALTER TABLE `'.$tabla.'` RENAME COLUMN `'.$columna.'` TO `'.$columna.self::SUFIJO.'`');
                }
            }
        }

        if (Schema::hasTable(self::TABLA)) {
            DB::statement('RENAME TABLE `'.self::TABLA.'` TO `'.self::TABLA.self::SUFIJO.'`');
        }
    }

    /**
     * Devuelve la tabla y las columnas a su nombre. Idempotente.
     *
     * @return void
     */
    private function restaurarElEsquema()
    {
        foreach (self::COLUMNAS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                if (Schema::hasColumn($tabla, $columna.self::SUFIJO)) {
                    DB::statement('ALTER TABLE `'.$tabla.'` RENAME COLUMN `'.$columna.self::SUFIJO.'` TO `'.$columna.'`');
                }
            }
        }

        if (Schema::hasTable(self::TABLA.self::SUFIJO)) {
            DB::statement('RENAME TABLE `'.self::TABLA.self::SUFIJO.'` TO `'.self::TABLA.'`');
        }
    }
}
