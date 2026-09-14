<?php

namespace Tests\Feature\Envios;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use App\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El contrato con `empresa-api`: la tienda desplegada contra una base que todavía NO tiene las
 * columnas `envio_*` ni la tabla `envios` (misión zipnova-envios, 14/9/2026, §0.7 y §8.3 del plan).
 *
 * 🔴 Este es el escenario NORMAL, no el borde. El esquema lo crean las migraciones de
 * `empresa-api` y llega a la base de un cliente con el release del ERP; la tienda la despliega
 * Lucas por cliente cuando quiere. O sea que esta tienda va a estar días o semanas contra bases
 * sin nada de esto — hoy, contra todas. Si eso no está resuelto, guardar un carrito con `envio`
 * en el payload es un UPDATE sobre una columna que no existe: 500 en el medio del checkout.
 *
 * Lo que se fija, con el esquema escondido y un conector de Zipnova PRESENTE (para que quede
 * claro que la guarda es el esquema y no el conector):
 *   - `GET /api/commerce/{id}` responde 200 con `envios_zipnova: false`;
 *   - `POST /api/envios/cotizar` responde 422 `sin_zipnova` y no llama a Zipnova;
 *   - `POST` y `PUT /api/carts` con `envio` en el payload responden como siempre (201/200), sin
 *     tocar nada de envío ni llamar a Zipnova;
 *   - `POST /api/orders` crea el pedido con la dirección de siempre;
 *   - `GET /api/orders` y `GET /api/orders/current/{id}` responden 200 sin la clave `envio`.
 *
 * ⚠️ Por qué esta clase NO usa DatabaseTransactions: el único modo honesto de probar "el esquema
 * no está" es que no esté, y eso se hace con `ALTER TABLE ... RENAME COLUMN` y `RENAME TABLE`.
 * Son DDL, y MySQL les hace commit implícito a la transacción abierta, así que el rollback del
 * trait no revertiría nada. Acá el DDL va afuera, y lo que se inserta (comercio, artículo,
 * conector, carrito, pedido) va adentro de una transacción manual que se revierte en el
 * `finally`. Mismo criterio que `SinEsquemaDeOfertasTest` y `ContratoConEmpresaApiTest`.
 *
 * 🔴 El restore del esquema va en el `finally` Y en el `tearDown` Y en el `setUp` (por si una
 * corrida anterior murió a mitad): si las columnas quedaran renombradas, se llevarían puesta la
 * suite entera y todo lo que corra después en este slot.
 */
class SinEsquemaDeEnviosTest extends TestCase
{
    use ArmaComercioConZipnova;

    /** Sufijo con el que se esconden las columnas y la tabla. */
    const SUFIJO = '_escondida_test';

    /** Columnas de envío por tabla, las que crea `2026_09_14_100200_add_envio_to_carts_and_orders_tables`. */
    const COLUMNAS = [
        'carts'  => ['envio_cotizacion', 'envio_opcion', 'envio_destino', 'envio_precio'],
        'orders' => ['envio_cotizacion', 'envio_opcion', 'envio_destino', 'envio_precio', 'envio_proveedor'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        ZipnovaEsquemaHelper::olvidar();
        ArticleHelper::olvidar_visibilidad_del_anonimo();

        /* Por si una corrida anterior murió con el esquema escondido. */
        $this->restaurarElEsquema();

        $this->assertTrue(ZipnovaEsquemaHelper::disponible(), 'La base del slot tiene que arrancar con las columnas de envío.');
        $this->assertTrue(ZipnovaEsquemaHelper::tabla_envios(), 'La base del slot tiene que arrancar con la tabla envios.');
    }

    /**
     * Deja la base como estaba, pase lo que pase en el caso.
     */
    protected function tearDown(): void
    {
        $this->restaurarElEsquema();
        ZipnovaEsquemaHelper::olvidar();

        parent::tearDown();
    }

    public function test_sin_el_esquema_de_envios_la_tienda_sigue_andando_y_la_integracion_se_reporta_como_no_disponible()
    {
        $this->esconderElEsquema();

        try {
            $this->assertFalse(ZipnovaEsquemaHelper::disponible(), 'con las columnas escondidas la guarda tiene que dar false');
            $this->assertFalse(ZipnovaEsquemaHelper::tabla_envios());

            DB::beginTransaction();

            try {
                $comercio = $this->comercioConTienda();
                $articulo = $this->articuloConMedidas($comercio);

                /* El conector EXISTE: lo que frena es el esquema, no la falta de credenciales. */
                $this->conectorZipnova($comercio);

                Http::fake();

                /* 1. El comercio público: no disponible, y nunca un 500. */
                $publico = $this->json('GET', '/api/commerce/'.$comercio->id)->assertStatus(200);
                $this->assertFalse($publico->json('commerce.envios_zipnova'));
                $this->assertNull($publico->json('commerce.envios_zipnova_config.envio_gratis_desde'));

                /* 2. Cotizar: sin_zipnova, sin tocar Zipnova. */
                $this->postJson('/api/envios/cotizar', [
                    'commerce_id' => $comercio->id,
                    'zipcode'     => '5000',
                    'articles'    => [['id' => $articulo->id, 'amount' => 1]],
                ])->assertStatus(422)->assertJson(['codigo' => 'sin_zipnova']);
                Http::assertNothingSent();

                /* 3. El carrito con `envio` en el payload (un SPA nuevo): se guarda como siempre. */
                $envio = [
                    'zipcode'    => '5000',
                    'opcion_key' => self::KEY_DOMICILIO_CORREO_ARG,
                    'point_id'   => null,
                    'destino'    => $this->destinoCompleto(),
                ];

                $creado = $this->postJson('/api/carts', [
                    'commerce_id' => $comercio->id,
                    'cart'        => [
                        'articles'             => [$this->lineaDelPayload($articulo, 2)],
                        'promociones_vinoteca' => [],
                        'deliver'              => 1,
                        'envio'                => $envio,
                    ],
                ]);
                $creado->assertStatus(201);
                $cart_id = (int) $creado->json('cart.id');
                $this->assertSame(2000.0, (float) $creado->json('cart.total'));
                $this->assertArrayNotHasKey('envio_opcion', $creado->json('cart'), 'sin la columna no hay atributo');

                $this->withSession(['carritos_propios' => [$cart_id]])
                    ->putJson('/api/carts', [
                        'id'                   => $cart_id,
                        'articles'             => [$this->lineaDelPayload($articulo, 3)],
                        'promociones_vinoteca' => [],
                        'deliver'              => 1,
                        'envio'                => $envio,
                    ])
                    ->assertStatus(200);

                Http::assertNothingSent();

                /* 4. El pedido: con la dirección de siempre. */
                $this->asegurarEstadoSinConfirmar();
                $comprador = $this->compradorDe($comercio);

                $pedido = $this->withSession(['carritos_propios' => [$cart_id], 'checkout_buyer_id' => $comprador->id])
                    ->postJson('/api/orders', [
                        'cart_id'     => $cart_id,
                        'commerce_id' => $comercio->id,
                        'address'     => 'San Martin 100',
                    ]);
                $pedido->assertStatus(201);

                $order = Order::find($pedido->json('order_id'));
                $this->assertNotNull($order);
                $this->assertSame('San Martin 100', $order->address);
                $this->assertSame(3000.0, (float) $order->total);

                /* 5. Mis pedidos y la página de gracias: 200, sin `envio`. */
                $listado = $this->actingAs($comprador, 'buyer')->json('GET', '/api/orders')->assertStatus(200);
                $this->assertSame((int) $order->id, (int) $listado->json('orders.data.0.id'));
                $this->assertArrayNotHasKey('envio', $listado->json('orders.data.0'));

                $actual = $this->actingAs($comprador, 'buyer')->json('GET', '/api/orders/current/'.$comercio->id)->assertStatus(200);
                $this->assertSame((int) $order->id, (int) $actual->json('order.id'));
                $this->assertArrayNotHasKey('envio', $actual->json('order'));
            } finally {
                DB::rollBack();
            }
        } finally {
            $this->restaurarElEsquema();
        }

        ZipnovaEsquemaHelper::olvidar();
        $this->assertTrue(ZipnovaEsquemaHelper::disponible(), 'la base volvió a tener las columnas');
        $this->assertTrue(ZipnovaEsquemaHelper::tabla_envios(), 'la base volvió a tener la tabla');
    }

    /**
     * Renombra las nueve columnas y la tabla. DDL: commit implícito, por eso va afuera de la
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

        if (Schema::hasTable('envios')) {
            DB::statement('RENAME TABLE `envios` TO `envios'.self::SUFIJO.'`');
        }
    }

    /**
     * Devuelve las columnas y la tabla a su nombre. Idempotente: se llama en el `finally`, en el
     * `tearDown` y en el `setUp`.
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

        if (Schema::hasTable('envios'.self::SUFIJO)) {
            DB::statement('RENAME TABLE `envios'.self::SUFIJO.'` TO `envios`');
        }
    }
}
