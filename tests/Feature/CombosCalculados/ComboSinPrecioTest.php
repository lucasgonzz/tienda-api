<?php

namespace Tests\Feature\CombosCalculados;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un combo publicado SIN precio vendible no se ofrece ni se compra (mision combos-calculados,
 * correccion del verificador del contrato, 30/9/2026).
 *
 * 🔴 El defecto: un combo calculado sin articulos, o cuyos componentes no tienen precio, queda en
 * `combos.price = 0.00` (empresa) y `stock_disponible = null` ("hay siempre"). La tienda lo
 * listaba y el carrito lo aceptaba: se compraba a $0. Ahora, con el precio RESUELTO (el que sale de
 * `ComboPrecioHelper`, no la columna cruda) NULL o <= 0, la home no lo lista y el carrito lo
 * descarta del payload igual que a uno sin publicar o de otro comercio.
 *
 * Se mide con tres combos por caso —precio 0, NULL y mayor a cero— porque un test con uno solo no
 * distingue "filtra los sin precio" de "filtra todo".
 */
class ComboSinPrecioTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCombosCalculados;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Combo */
    private $en_cero;

    /** @var \App\Combo */
    private $en_null;

    /** @var \App\Combo */
    private $con_precio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarTodo();

        $this->comercio = $this->comercioConTienda();

        /* Los tres sin control de stock (null): el caso real, "hay siempre". */
        $this->en_cero = $this->combo($this->comercio, ['name' => 'En cero', 'price' => 0]);
        $this->en_null = $this->combo($this->comercio, ['name' => 'En null', 'price' => null]);
        $this->con_precio = $this->combo($this->comercio, ['name' => 'Con precio', 'price' => 4321.50]);

        foreach ([$this->en_cero, $this->en_null, $this->con_precio] as $combo) {
            $this->componente($combo, $this->articuloConStock($this->comercio, null), 1);
        }
    }

    protected function tearDown(): void
    {
        $this->olvidarTodo();

        parent::tearDown();
    }

    private function idsEnLaHome()
    {
        $ids = array_column($this->combosDeLaHome($this->comercio), 'id');
        sort($ids);

        return $ids;
    }

    private function lineasDelCarrito($cart_id)
    {
        return $this->combosGuardados($cart_id)->pluck('combo_id')->map(function ($id) {
            return (int) $id;
        })->sort()->values()->all();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | La home
    |---------------------------------------------------------------------------------------------
    */

    /** Solo el combo con precio mayor a cero se ofrece. */
    public function test_la_home_no_ofrece_combos_con_precio_cero_ni_null()
    {
        $this->assertSame([$this->con_precio->id], $this->idsEnLaHome());
    }

    /** El filtro no toca al que tiene precio: sigue con su precio y su stock "sin control". */
    public function test_la_home_conserva_el_combo_con_precio()
    {
        $combo = $this->comboEnLaHome($this->comercio, $this->con_precio);

        $this->assertEquals(4321.50, $combo['final_price']);
        $this->assertNull($combo['stock_disponible']);
    }

    /**
     * Se mira el precio RESUELTO y no la columna: si la lista del comprador tiene precio aunque
     * `combos.price` sea 0, se ofrece; y si `combos.price` tiene precio pero la lista del comprador
     * esta en 0, no se ofrece (se cobraria $0).
     */
    public function test_se_mira_el_precio_resuelto_por_lista_y_no_la_columna()
    {
        $this->exigirElEsquemaDePrecios();

        $publica = $this->lista($this->comercio, 'Publica', 2);

        $this->precioPorLista($this->en_cero, $publica, 1500);
        $this->precioPorLista($this->con_precio, $publica, 0);

        $this->assertSame([$this->en_cero->id], $this->idsEnLaHome(),
            'el de columna en 0 pero con lista en 1500 se ofrece; el de columna con precio y lista en 0, no');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El carrito
    |---------------------------------------------------------------------------------------------
    */

    /** Los tres combos en el payload: el carrito se queda solo con el que tiene precio. */
    public function test_el_carrito_descarta_los_combos_sin_precio_del_payload()
    {
        $creado = $this->crearCarrito($this->comercio, [
            'combos' => [
                $this->lineaDeComboDelPayload($this->en_cero, 1),
                $this->lineaDeComboDelPayload($this->en_null, 1),
                $this->lineaDeComboDelPayload($this->con_precio, 2),
            ],
        ]);
        $creado->assertStatus(201);

        $cart_id = (int) $creado->json('cart.id');

        $this->assertSame([$this->con_precio->id], $this->lineasDelCarrito($cart_id));
        $this->assertEquals(4321.50 * 2, $this->totalGuardado($cart_id));
    }

    /**
     * 🔴 El payload no manda: un navegador que dice que el combo en 0 cuesta $5.000 no lo mete en
     * el carrito, y uno que lo manda en $0 tampoco.
     */
    public function test_el_precio_del_payload_no_rescata_un_combo_sin_precio()
    {
        $creado = $this->crearCarrito($this->comercio, [
            'combos' => [
                $this->lineaDeComboDelPayload($this->en_cero, 1, ['final_price' => 5000, 'price' => 5000]),
            ],
        ]);
        $creado->assertStatus(201);

        $this->assertSame([], $this->lineasDelCarrito((int) $creado->json('cart.id')));
        $this->assertEquals(0, $this->totalGuardado((int) $creado->json('cart.id')));
    }

    /** Un carrito de SOLO combos sin precio queda vacio y con total cero, no con lineas a $0. */
    public function test_un_carrito_de_solo_combos_sin_precio_queda_vacio()
    {
        $creado = $this->crearCarrito($this->comercio, [
            'combos' => [
                $this->lineaDeComboDelPayload($this->en_cero, 3),
                $this->lineaDeComboDelPayload($this->en_null, 3),
            ],
        ]);
        $creado->assertStatus(201);

        $this->assertSame([], $this->lineasDelCarrito((int) $creado->json('cart.id')));
    }

    /**
     * Guardar de nuevo el carrito (`PUT /api/carts`) pasa por el mismo attach: tampoco cuela el
     * combo sin precio.
     */
    public function test_actualizar_el_carrito_tampoco_cuela_un_combo_sin_precio()
    {
        $creado = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($this->con_precio, 1)],
        ]);
        $creado->assertStatus(201);
        $cart_id = (int) $creado->json('cart.id');

        $this->withSession(['carritos_propios' => [$cart_id]])
            ->putJson('/api/carts', [
                'id'                   => $cart_id,
                'articles'             => [],
                'promociones_vinoteca' => [],
                'combos'               => [
                    $this->lineaDeComboDelPayload($this->con_precio, 1),
                    $this->lineaDeComboDelPayload($this->en_cero, 1),
                ],
            ])
            ->assertStatus(200);

        $this->assertSame([$this->con_precio->id], $this->lineasDelCarrito($cart_id));
    }

    /**
     * La resincronizacion de "Actualizar" no reescribe una linea a $0 si el ERP dejo despues el
     * combo sin precio: conserva el precio con el que entro al carrito.
     */
    public function test_actualizar_no_reescribe_una_linea_a_cero()
    {
        $comprador = $this->compradorConLista($this->comercio, null);
        $this->descuentoDeCliente($this->comercio, $comprador, 10);
        $this->actingAs($comprador, 'buyer');

        $creado = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($this->con_precio, 1)],
        ]);
        $creado->assertStatus(201);
        $cart_id = (int) $creado->json('cart.id');

        $entro_a = (float) $this->lineaDeComboGuardada($cart_id, $this->con_precio)->price;
        $this->assertEqualsWithDelta(4321.50 * 0.9, $entro_a, 0.01);

        DB::table('combos')->where('id', $this->con_precio->id)->update(['price' => 0]);

        $this->olvidarTodo();
        $this->putJson('/api/carts/update-article-amount/'.$cart_id, [
            'id'       => $this->con_precio->id,
            'amount'   => 2,
            'is_combo' => true,
        ])->assertStatus(200);

        $linea = $this->lineaDeComboGuardada($cart_id, $this->con_precio);

        $this->assertEquals(2, $linea->amount);
        $this->assertEqualsWithDelta($entro_a, (float) $linea->price, 0.001, 'la linea no se reescribe a $0');
    }
}
