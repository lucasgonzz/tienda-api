<?php

namespace Tests\Feature\CombosCalculados;

use App\Combo;
use App\Http\Controllers\Helpers\OrderTotalsHelper;
use App\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La foto propia del combo y lo que viaja de sus componentes (mision combos-calculados,
 * 30/9/2026).
 *
 * Tres cosas que se fijan:
 *
 *   1. `images`: la foto que carga el ERP (tabla `images`, `imageable_type = 'combo'`) llega en la
 *      home, en el carrito y en el pedido. Sin foto propia viaja VACIA (no ausente) y la tarjeta
 *      hace el collage con `articles[].images`, que tienen que seguir viajando.
 *   2. El alias del morph map. Si `Combo::images()` buscara `App\Combo` no encontraria nunca las
 *      fotos que guarda `empresa-api`.
 *   3. 🔴 Los componentes viajan con un select ACOTADO: la tienda es publica y antes cada
 *      componente salia entero, con su `cost`.
 */
class ImagenesDelComboTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCombosCalculados;

    /** Lo unico que un componente tiene permitido publicar (mas `images` y `pivot`). */
    const ATRIBUTOS_PERMITIDOS = ['id', 'name', 'slug', 'stock', 'deleted_at', 'images', 'pivot'];

    /** @var \App\User */
    private $comercio;

    /** @var \App\Combo */
    private $combo;

    /** @var \App\Article */
    private $taladro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarTodo();

        $this->comercio = $this->comercioConTienda();

        /* El costo del componente es el dato sensible: distinto de cero y facil de buscar. */
        $this->taladro = $this->articuloConStock($this->comercio, 10, [
            'name' => 'Taladro',
            'cost' => 12345.67,
        ]);
        $mecha = $this->articuloConStock($this->comercio, 10, ['name' => 'Mecha', 'cost' => 99.99]);

        $this->combo = $this->combo($this->comercio);
        $this->componente($this->combo, $this->taladro, 1);
        $this->componente($this->combo, $mecha, 2);

        $this->fotoDelArticulo($this->taladro, 'https://cdn.test/taladro.jpg');
    }

    protected function tearDown(): void
    {
        $this->olvidarTodo();

        parent::tearDown();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | La foto propia
    |---------------------------------------------------------------------------------------------
    */

    /** El alias `combo` esta en el morph map: es el contrato con `empresa-api`. */
    public function test_el_alias_combo_esta_en_el_morph_map()
    {
        $this->assertSame('combo', (new Combo)->getMorphClass());
        $this->assertSame(Combo::class, \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel('combo'));
    }

    /** La foto propia llega en la home. */
    public function test_la_home_trae_la_foto_propia_del_combo()
    {
        $this->fotoDelCombo($this->combo, 'https://cdn.test/combo-propio.jpg');

        $combo = $this->comboEnLaHome($this->comercio, $this->combo);

        $this->assertCount(1, $combo['images']);
        $this->assertSame('https://cdn.test/combo-propio.jpg', $combo['images'][0]['hosting_url']);
    }

    /**
     * Sin foto propia `images` viaja como lista VACIA —no falta la clave— y los componentes
     * siguen trayendo las suyas para el collage.
     */
    public function test_sin_foto_propia_images_viaja_vacio_y_los_componentes_traen_las_suyas()
    {
        $combo = $this->comboEnLaHome($this->comercio, $this->combo);

        $this->assertArrayHasKey('images', $combo);
        $this->assertSame([], $combo['images']);

        $por_nombre = [];
        foreach ($combo['articles'] as $componente) {
            $por_nombre[$componente['name']] = $componente;
        }

        $this->assertSame('https://cdn.test/taladro.jpg', $por_nombre['Taladro']['images'][0]['hosting_url'],
            'el collage se arma con las imagenes de los componentes');
        $this->assertSame([], $por_nombre['Mecha']['images']);
    }

    /**
     * 🔴 Una imagen guardada con el nombre de la CLASE (`App\Combo`) no es la foto del combo: el
     * contrato con el ERP es el alias. Fija que la relacion consulta por el alias y no por el
     * nombre de la clase.
     */
    public function test_solo_cuenta_la_imagen_con_el_alias_combo()
    {
        $this->fotoDelCombo($this->combo, 'https://cdn.test/con-nombre-de-clase.jpg', 'App\Combo');

        $this->assertSame([], $this->comboEnLaHome($this->comercio, $this->combo)['images']);
    }

    /** Una imagen de un ARTICULO con el mismo id que el combo no se confunde con la del combo. */
    public function test_la_foto_de_un_articulo_con_el_mismo_id_no_es_la_del_combo()
    {
        $imagen = $this->fotoDelArticulo($this->taladro, 'https://cdn.test/intruso.jpg');
        DB::table('images')->where('id', $imagen->id)->update(['imageable_id' => $this->combo->id]);

        $this->assertSame([], $this->comboEnLaHome($this->comercio, $this->combo)['images']);
    }

    /** El carrito devuelve la foto propia. */
    public function test_el_carrito_trae_la_foto_propia_del_combo()
    {
        $this->fotoDelCombo($this->combo, 'https://cdn.test/combo-propio.jpg');

        $creado = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($this->combo, 1)],
        ]);
        $creado->assertStatus(201);

        $this->assertSame('https://cdn.test/combo-propio.jpg', $creado->json('cart.combos.0.images.0.hosting_url'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Lo que viaja de los componentes
    |---------------------------------------------------------------------------------------------
    */

    /** Lo comun a la home y al carrito: ningun componente publica nada fuera de la lista. */
    private function afirmarComponentesAcotados(array $combo, $donde)
    {
        $this->assertCount(2, $combo['articles'], $donde.': los dos componentes viajan');

        foreach ($combo['articles'] as $componente) {
            $sobran = array_diff(array_keys($componente), self::ATRIBUTOS_PERMITIDOS);

            $this->assertSame([], array_values($sobran),
                $donde.': el componente "'.$componente['name'].'" publica atributos de mas: '.implode(', ', $sobran));

            $this->assertArrayNotHasKey('cost', $componente, $donde.': el costo no viaja');
            $this->assertArrayNotHasKey('final_price', $componente, $donde);
            $this->assertArrayHasKey('amount', $componente['pivot'], $donde.': la receta ("2x Mecha") necesita la cantidad');
        }
    }

    /** 🔴 La home no publica el costo de los componentes. */
    public function test_la_home_no_publica_el_costo_de_los_componentes()
    {
        $combo = $this->comboEnLaHome($this->comercio, $this->combo);

        $this->afirmarComponentesAcotados($combo, 'home');
        $this->assertArrayNotHasKey('cost', $combo, 'y el costo del combo mismo sigue escondido');
        $this->assertStringNotContainsString('12345', json_encode($combo));
    }

    /** 🔴 Ni el carrito. */
    public function test_el_carrito_no_publica_el_costo_de_los_componentes()
    {
        $creado = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($this->combo, 1)],
        ]);
        $creado->assertStatus(201);

        $this->afirmarComponentesAcotados($creado->json('cart.combos.0'), 'carrito');
        $this->assertStringNotContainsString('12345', json_encode($creado->json('cart.combos')));
    }

    /**
     * 🔴 La pagina de "gracias" (`GET /api/orders/current/{commerce_id}`) arma sus propios `with`
     * a mano —el `withAll()` no participa ahi— y es el cuarto lugar donde viajan los combos. Es
     * justo el que se olvida cuando se acota el select en los otros tres.
     */
    public function test_el_pedido_de_la_pagina_de_gracias_trae_la_foto_y_no_el_costo()
    {
        $comprador = $this->compradorSinCliente($this->comercio);
        $this->actingAs($comprador, 'buyer');

        $this->fotoDelCombo($this->combo, 'https://cdn.test/combo-propio.jpg');

        $pedido_id = DB::table('orders')->insertGetId([
            'user_id'         => $this->comercio->id,
            'buyer_id'        => $comprador->id,
            'deliver'         => 0,
            'order_status_id' => 1,
            'status'          => 'unconfirmed',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        DB::table('order_combo')->insert([
            'order_id'   => $pedido_id,
            'combo_id'   => $this->combo->id,
            'amount'     => 1,
            'price'      => 9000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $respuesta = $this->getJson('/api/orders/current/'.$this->comercio->id);
        $respuesta->assertStatus(200);

        $combo = $respuesta->json('order.combos.0');

        $this->assertSame('https://cdn.test/combo-propio.jpg', $combo['images'][0]['hosting_url']);
        $this->afirmarComponentesAcotados($combo, 'pedido actual');
    }

    /**
     * 🔴 El costo del combo que `attach_combos()` y `OrderHelper::attachCombos()` congelan en
     * `cart_combo.cost` / `order_combo.cost` NO viaja al navegador: ni en el carrito, ni en
     * `GET /api/orders/current`, ni en el listado de "Mis pedidos". `Combo::$hidden` solo cubre el
     * `cost` del modelo; el del PIVOT lo cubre que `Cart::combos()` y `Order::combos()` no lo
     * declaren en `withPivot` (medido igual en `origin/master`: no es un cambio de esta mision).
     * Si alguien lo agrega al `withPivot`, este caso se pone rojo.
     */
    public function test_el_costo_congelado_del_pivote_no_viaja_al_navegador()
    {
        $comprador = $this->compradorSinCliente($this->comercio);
        $this->actingAs($comprador, 'buyer');

        /* Un costo que no aparece en ningun otro lado de las fixtures: si sale, se ve. */
        $creado = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($this->combo, 1)],
        ]);
        $creado->assertStatus(201);

        $this->assertEquals(self::COSTO_COMBO, DB::table('cart_combo')->value('cost'),
            'el escenario guarda el costo en el pivote del carrito: si no, el caso seria vacuo');
        $this->assertArrayNotHasKey('cost', $creado->json('cart.combos.0.pivot'));

        $pedido_id = DB::table('orders')->insertGetId([
            'user_id'         => $this->comercio->id,
            'buyer_id'        => $comprador->id,
            'deliver'         => 0,
            'order_status_id' => 1,
            'status'          => 'unconfirmed',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        DB::table('order_combo')->insert([
            'order_id'   => $pedido_id,
            'combo_id'   => $this->combo->id,
            'amount'     => 1,
            'price'      => 9000,
            'cost'       => 777.77,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actual = $this->getJson('/api/orders/current/'.$this->comercio->id);
        $actual->assertStatus(200);
        $this->assertArrayNotHasKey('cost', $actual->json('order.combos.0.pivot'));
        $this->assertStringNotContainsString('777.77', $actual->getContent());

        $listado = $this->getJson('/api/orders');
        $listado->assertStatus(200);
        $this->assertNotEmpty($listado->json('orders.data.0.combos'), 'el listado trae los combos del pedido');
        $this->assertArrayNotHasKey('cost', $listado->json('orders.data.0.combos.0.pivot'));
        $this->assertStringNotContainsString('777.77', $listado->getContent());
    }

    /**
     * El select acotado no rompe la receta del pedido: `OrderTotalsHelper::comboDescription()` lee
     * `name` y `pivot.amount` de cada componente, que son justamente lo que se conserva.
     */
    public function test_la_receta_del_pedido_sigue_armandose_con_el_select_acotado()
    {
        /* El pedido a mano: lo que se mide es la carga de la relacion y la receta, no el checkout. */
        $pedido_id = DB::table('orders')->insertGetId([
            'user_id'    => $this->comercio->id,
            'deliver'    => 0,
            'buyer_id'   => 0,
            'order_status_id' => 1,
            'status'     => 'unconfirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_combo')->insert([
            'order_id'   => $pedido_id,
            'combo_id'   => $this->combo->id,
            'amount'     => 1,
            'price'      => 9000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pedido = Order::where('id', $pedido_id)->withAll()->first();

        $this->assertSame('1x Taladro, 2x Mecha', OrderTotalsHelper::comboDescription($pedido->combos[0]));

        $componente = $pedido->combos[0]->articles[0]->toArray();
        $this->assertArrayNotHasKey('cost', $componente, 'el pedido tambien carga los componentes acotados');
        $this->assertArrayHasKey('images', $pedido->combos[0]->toArray(), 'y la foto propia del combo');
    }
}
