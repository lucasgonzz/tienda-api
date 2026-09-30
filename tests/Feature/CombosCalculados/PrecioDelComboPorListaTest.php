<?php

namespace Tests\Feature\CombosCalculados;

use App\OnlineConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El precio del combo segun la LISTA DE PRECIOS del comprador (mision combos-calculados,
 * 30/9/2026).
 *
 * Decision de Lucas: "el combo respeta la lista del comprador, con las mismas reglas que un
 * articulo". Estos casos fijan esas reglas una por una y las miden en los cuatro lugares donde la
 * tienda entrega un precio de combo: la home, el armado del carrito, la resincronizacion de
 * "Actualizar" y el carrito que vuelve al navegador.
 *
 * ── Los numeros ───────────────────────────────────────────────────────────────────────────────
 *
 * Tres listas y un `combos.price` que NO coincide con ninguna, a proposito:
 *
 *   Minorista  position 1                  -> $7.000
 *   Mayorista  position 2                  -> $8.000   (la de mayor position visible al publico)
 *   Reservada  position 3, oculta al publico -> $9.500 (la de mayor position, pero no se ve)
 *   combos.price                           -> $8.500   (el fallback: sin fila, sin lista, sin tabla)
 *
 * Como los cuatro numeros son distintos, cada assert dice exactamente QUE camino eligio el
 * servidor: un fallback y una lista equivocada no pueden dar el mismo valor por casualidad.
 *
 * El precio se lee de la BASE (`cart_combo.price`), no de la respuesta: es la fila que despues
 * arrastra el pedido y el cobro online.
 */
class PrecioDelComboPorListaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCombosCalculados;

    const PRECIO_MINORISTA = 7000.00;
    const PRECIO_MAYORISTA = 8000.00;
    const PRECIO_RESERVADA = 9500.00;
    const PRECIO_BASE_DEL_COMBO = 8500.00;

    /** @var \App\User */
    private $comercio;

    /** @var \App\PriceType */
    private $minorista;

    /** @var \App\PriceType */
    private $mayorista;

    /** @var \App\PriceType */
    private $reservada;

    /** @var \App\Combo */
    private $combo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarTodo();
        $this->exigirElEsquemaDePrecios();

        $this->comercio = $this->comercioConTienda();

        $this->minorista = $this->lista($this->comercio, 'Minorista', 1);
        $this->mayorista = $this->lista($this->comercio, 'Mayorista', 2);
        $this->reservada = $this->lista($this->comercio, 'Reservada', 3, 1);

        $this->combo = $this->combo($this->comercio, ['price' => self::PRECIO_BASE_DEL_COMBO]);
        $this->componente($this->combo, $this->articuloConStock($this->comercio, 100), 2);

        $this->precioPorLista($this->combo, $this->minorista, self::PRECIO_MINORISTA);
        $this->precioPorLista($this->combo, $this->mayorista, self::PRECIO_MAYORISTA);
        $this->precioPorLista($this->combo, $this->reservada, self::PRECIO_RESERVADA);
    }

    protected function tearDown(): void
    {
        $this->olvidarTodo();

        parent::tearDown();
    }

    /** El precio de un combo en la home para la sesion actual. */
    private function precioEnLaHome($combo = null)
    {
        $this->olvidarTodo();

        return (float) $this->comboEnLaHome($this->comercio, $combo ? $combo : $this->combo)['final_price'];
    }

    /** Agrega el combo a un carrito nuevo, como la sesion actual, y devuelve el id del carrito. */
    private function carritoConElCombo($amount = 1)
    {
        $this->olvidarTodo();

        $creado = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($this->combo, $amount)],
        ]);
        $creado->assertStatus(201);

        return (int) $creado->json('cart.id');
    }

    private function precioGuardadoDelCombo($cart_id)
    {
        return (float) $this->lineaDeComboGuardada($cart_id, $this->combo)->price;
    }

    /*
    |---------------------------------------------------------------------------------------------
    | La eleccion de la lista, caso por caso
    |---------------------------------------------------------------------------------------------
    */

    /**
     * El anonimo ve la lista de mayor `position` que NO este oculta al publico. La Reservada tiene
     * la position mas alta y no cuenta: sale la Mayorista.
     */
    public function test_el_anonimo_ve_la_lista_publica_mas_alta()
    {
        $this->assertSame(self::PRECIO_MAYORISTA, $this->precioEnLaHome());
    }

    /** El logueado con un cliente que tiene lista propia ve ESA lista, no la publica. */
    public function test_el_logueado_con_lista_propia_ve_su_lista()
    {
        $this->actingAs($this->compradorConLista($this->comercio, $this->minorista), 'buyer');

        $this->assertSame(self::PRECIO_MINORISTA, $this->precioEnLaHome());
    }

    /** Aunque la lista propia este oculta al publico: es SU lista, no la elige el publico. */
    public function test_la_lista_propia_puede_ser_una_oculta_al_publico()
    {
        $this->actingAs($this->compradorConLista($this->comercio, $this->reservada), 'buyer');

        $this->assertSame(self::PRECIO_RESERVADA, $this->precioEnLaHome());
    }

    /**
     * El logueado SIN lista propia cae en la de `position` mas alta, ocultas incluidas: es lo que
     * hace el articulo (`ocultar_al_publico` solo aparta las listas del ANONIMO).
     */
    public function test_el_logueado_sin_lista_propia_ve_la_position_mas_alta_aunque_este_oculta()
    {
        $this->actingAs($this->compradorSinCliente($this->comercio), 'buyer');
        $this->assertSame(self::PRECIO_RESERVADA, $this->precioEnLaHome(), 'sin cliente vinculado');

        $this->actingAs($this->compradorConLista($this->comercio, null), 'buyer');
        $this->assertSame(self::PRECIO_RESERVADA, $this->precioEnLaHome(), 'con cliente pero sin lista asignada');
    }

    /**
     * Sin fila para la lista elegida se usa `combos.price`: un combo manual, o una lista nueva que
     * el ERP todavia no recalculo.
     */
    public function test_un_combo_sin_fila_para_la_lista_cobra_combos_price()
    {
        $manual = $this->combo($this->comercio, ['price' => 6100, 'name' => 'Manual']);
        $this->componente($manual, $this->articuloConStock($this->comercio, 5), 1);

        $this->assertSame(6100.0, $this->precioEnLaHome($manual), 'anonimo');

        $this->actingAs($this->compradorConLista($this->comercio, $this->minorista), 'buyer');
        $this->assertSame(6100.0, $this->precioEnLaHome($manual), 'logueado con lista propia');
    }

    /** Una fila de OTRA lista no se cuela: el combo con fila solo en Minorista, visto por el anonimo. */
    public function test_la_fila_de_otra_lista_no_se_usa()
    {
        $solo_minorista = $this->combo($this->comercio, ['price' => 6100, 'name' => 'Solo minorista']);
        $this->componente($solo_minorista, $this->articuloConStock($this->comercio, 5), 1);
        $this->precioPorLista($solo_minorista, $this->minorista, 3333);

        $this->assertSame(6100.0, $this->precioEnLaHome($solo_minorista),
            'el anonimo ve Mayorista, que este combo no tiene: cae a combos.price y no toma la de Minorista');
    }

    /** Si TODAS las listas estan ocultas, el anonimo cobra `combos.price` (lo de siempre). */
    public function test_con_todas_las_listas_ocultas_el_anonimo_cobra_combos_price()
    {
        DB::table('price_types')->where('user_id', $this->comercio->id)->update(['ocultar_al_publico' => 1]);

        $this->assertSame(self::PRECIO_BASE_DEL_COMBO, $this->precioEnLaHome());
    }

    /** Un comercio sin listas con position cobra `combos.price`. */
    public function test_sin_listas_el_combo_cobra_combos_price()
    {
        DB::table('price_types')->where('user_id', $this->comercio->id)->update(['position' => null]);

        $this->assertSame(self::PRECIO_BASE_DEL_COMBO, $this->precioEnLaHome());
    }

    /**
     * Dos listas con la misma position: gana la de id mas alto, que es el criterio con el que el
     * ERP elige la lista por defecto para escribir `combos.price`.
     */
    public function test_el_empate_de_position_lo_gana_el_id_mas_alto()
    {
        DB::table('price_types')->where('id', $this->minorista->id)->update(['position' => 2]);

        $this->assertSame(self::PRECIO_MAYORISTA, $this->precioEnLaHome(),
            'Minorista y Mayorista comparten position 2: gana la creada despues (Mayorista)');
    }

    /**
     * Con la extension de rangos por cantidad vendida el articulo NO cambia de lista, y el combo
     * tampoco: cobra `combos.price`, sea anonimo o tenga lista propia.
     */
    public function test_con_la_extension_de_rangos_el_combo_cobra_combos_price()
    {
        $this->activarExtensionDeRangos($this->comercio);

        $this->assertSame(self::PRECIO_BASE_DEL_COMBO, $this->precioEnLaHome(), 'anonimo');

        $this->actingAs($this->compradorConLista($this->comercio, $this->minorista), 'buyer');
        $this->assertSame(self::PRECIO_BASE_DEL_COMBO, $this->precioEnLaHome(), 'logueado con lista propia');
    }

    /**
     * Si la tienda esta configurada para que el visitante sin login NO vea precios, el combo
     * cobra `combos.price` (lo que hacia hasta hoy) y la lista no entra en juego para el anonimo;
     * el logueado con lista propia sigue viendo la suya.
     */
    public function test_la_tienda_restringida_no_le_da_lista_al_anonimo()
    {
        $solo_registrados = DB::table('online_price_types')->where('slug', 'only_registered')->value('id');
        $this->assertNotNull($solo_registrados, 'la base de testing trae la fila only_registered');

        OnlineConfiguration::where('user_id', $this->comercio->id)->update([
            'register_to_buy'      => 1,
            'online_price_type_id' => $solo_registrados,
        ]);

        $this->assertSame(self::PRECIO_BASE_DEL_COMBO, $this->precioEnLaHome(), 'anonimo, tienda restringida');

        $this->actingAs($this->compradorConLista($this->comercio, $this->minorista), 'buyer');
        $this->assertSame(self::PRECIO_MINORISTA, $this->precioEnLaHome(), 'el registrado si ve su lista');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Los ajustes de cliente van ENCIMA de la lista
    |---------------------------------------------------------------------------------------------
    */

    /** Descuento de cliente del 10% sobre la lista Minorista: 7.000 -> 6.300, con la base a la vista. */
    public function test_los_ajustes_de_cliente_se_aplican_encima_de_la_lista()
    {
        $comprador = $this->compradorConLista($this->comercio, $this->minorista);
        $this->descuentoDeCliente($this->comercio, $comprador, 10);
        $this->actingAs($comprador, 'buyer');

        $this->olvidarTodo();
        $combo = $this->comboEnLaHome($this->comercio, $this->combo);

        $this->assertEquals(6300.0, $combo['final_price']);
        $this->assertEquals(self::PRECIO_MINORISTA, $combo['precio_sin_ajustes_de_cliente'],
            'la base que viaja para que el carrito no aplique el factor dos veces es la de LA LISTA');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El carrito
    |---------------------------------------------------------------------------------------------
    */

    /** El carrito del anonimo guarda el precio de la lista publica. */
    public function test_el_carrito_del_anonimo_guarda_la_lista_publica()
    {
        $cart_id = $this->carritoConElCombo(2);

        $this->assertSame(self::PRECIO_MAYORISTA, $this->precioGuardadoDelCombo($cart_id));
        $this->assertSame(self::PRECIO_MAYORISTA * 2, $this->totalGuardado($cart_id));
    }

    /** El carrito del logueado guarda el precio de SU lista. */
    public function test_el_carrito_del_logueado_guarda_su_lista()
    {
        $this->actingAs($this->compradorConLista($this->comercio, $this->minorista), 'buyer');

        $cart_id = $this->carritoConElCombo(3);

        $this->assertSame(self::PRECIO_MINORISTA, $this->precioGuardadoDelCombo($cart_id));
        $this->assertSame(self::PRECIO_MINORISTA * 3, $this->totalGuardado($cart_id));
    }

    /**
     * 🔴 EL SERVIDOR FIJA EL PRECIO DESDE LA BASE, NUNCA DEL PAYLOAD. Un navegador adulterado que
     * manda el combo a $1 (o a otro precio de lista) cobra el de la base.
     */
    public function test_el_precio_del_payload_no_se_lee()
    {
        $this->olvidarTodo();

        $creado = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($this->combo, 1, [
                'final_price' => 1,
                'price'       => 1,
                'pivot'       => ['amount' => 1, 'notes' => null, 'price' => 1],
            ])],
        ]);
        $creado->assertStatus(201);

        $this->assertSame(self::PRECIO_MAYORISTA, $this->precioGuardadoDelCombo((int) $creado->json('cart.id')));
    }

    /**
     * 🔴 La resincronizacion de "Actualizar" usa la MISMA base que el armado. Con un descuento de
     * cliente del 10% la linea vale 7.000 x 0,9 = 6.300; si la resincronizacion leyera
     * `combos.price` pelado volveria a 8.500 x 0,9 = 7.650 en cuanto el comprador tocara la
     * cantidad, sin un solo error.
     */
    public function test_actualizar_la_cantidad_no_pierde_la_lista_del_comprador()
    {
        $comprador = $this->compradorConLista($this->comercio, $this->minorista);
        $this->descuentoDeCliente($this->comercio, $comprador, 10);
        $this->actingAs($comprador, 'buyer');

        $cart_id = $this->carritoConElCombo(1);

        $this->assertSame(6300.0, $this->precioGuardadoDelCombo($cart_id), 'armado: lista Minorista con 10% encima');

        $this->olvidarTodo();
        $this->putJson('/api/carts/update-article-amount/'.$cart_id, [
            'id'       => $this->combo->id,
            'amount'   => 4,
            'is_combo' => true,
        ])->assertStatus(200);

        $linea = $this->lineaDeComboGuardada($cart_id, $this->combo);

        $this->assertEquals(4, $linea->amount);
        $this->assertSame(6300.0, (float) $linea->price, 'actualizar: sigue en la lista del comprador');
        $this->assertSame(6300.0 * 4, $this->totalGuardado($cart_id));
    }

    /**
     * Si el ERP cambia el precio de la lista con el carrito armado y el comprador tiene un
     * descuento, "Actualizar" toma el precio NUEVO de su lista: la resincronizacion resuelve la
     * base contra la base de datos y no arrastra la vieja.
     */
    public function test_actualizar_toma_el_precio_nuevo_de_la_lista()
    {
        $comprador = $this->compradorConLista($this->comercio, $this->minorista);
        $this->descuentoDeCliente($this->comercio, $comprador, 10);
        $this->actingAs($comprador, 'buyer');

        $cart_id = $this->carritoConElCombo(1);

        DB::table('combo_price_type')
            ->where('combo_id', $this->combo->id)
            ->where('price_type_id', $this->minorista->id)
            ->update(['price' => 10000]);

        $this->olvidarTodo();
        $this->putJson('/api/carts/update-article-amount/'.$cart_id, [
            'id'       => $this->combo->id,
            'amount'   => 1,
            'is_combo' => true,
        ])->assertStatus(200);

        $this->assertSame(9000.0, $this->precioGuardadoDelCombo($cart_id), '10.000 x 0,9');
    }

    /** El carrito que vuelve al navegador trae el precio de la lista (y el ajuste encima). */
    public function test_el_carrito_devuelto_trae_el_precio_de_la_lista()
    {
        $comprador = $this->compradorConLista($this->comercio, $this->minorista);
        $this->descuentoDeCliente($this->comercio, $comprador, 10);
        $this->actingAs($comprador, 'buyer');

        $this->olvidarTodo();
        $creado = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($this->combo, 1)],
        ]);
        $creado->assertStatus(201);

        $en_el_carrito = $creado->json('cart.combos.0');

        $this->assertEquals(6300.0, $en_el_carrito['final_price']);
        $this->assertEquals(self::PRECIO_MINORISTA, $en_el_carrito['precio_sin_ajustes_de_cliente']);
    }
}
