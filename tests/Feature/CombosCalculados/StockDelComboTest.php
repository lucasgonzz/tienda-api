<?php

namespace Tests\Feature\CombosCalculados;

use App\Http\Controllers\Helpers\ComboStockHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El stock de un combo en la tienda (mision combos-calculados, 30/9/2026): cuantos combos se
 * pueden ARMAR con lo que hay de cada componente.
 *
 * La regla es la misma que calcula `empresa-api` para Vender, y los ejemplos numericos de esta
 * clase son los mismos que clavan los tests de esa punta: si una cambia y la otra no, el
 * comerciante ve "quedan 3" en la tienda y "quedan 2" en el sistema.
 *
 * Dos capas:
 *   - `calcular()` puro (sin base): la regla, con el ejemplo de Lucas.
 *   - `para()` y los endpoints: que la regla se aplique sobre la base real, INCLUYENDO los
 *     articulos borrados (que la relacion `Combo::articles()` no ve a proposito) y que cueste UNA
 *     consulta por pagina y no una por combo.
 */
class StockDelComboTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCombosCalculados;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarTodo();
    }

    protected function tearDown(): void
    {
        $this->olvidarTodo();

        parent::tearDown();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | La regla, pura
    |---------------------------------------------------------------------------------------------
    */

    /** Un componente para `calcular()`. */
    private function comp($amount, $stock, $borrado = false, $article_id = null)
    {
        return ['article_id' => $article_id, 'amount' => $amount, 'stock' => $stock, 'borrado' => $borrado];
    }

    /**
     * 🔴 EL EJEMPLO DE LUCAS: A x2 (stock 2), B x3 (stock 3), C x4 (stock 4). 2/2 = 1, 3/3 = 1,
     * 4/4 = 1: se arma UN combo. El limitante manda.
     */
    public function test_el_ejemplo_de_lucas_da_un_combo()
    {
        $this->assertSame(1, ComboStockHelper::calcular([
            $this->comp(2, 2), $this->comp(3, 3), $this->comp(4, 4),
        ]));
    }

    /**
     * 🔴 SOBREVENTA: el mismo articulo en dos renglones suma sus cantidades antes de dividir. A con
     * stock 2 en dos renglones de 1 necesita 2 unidades por combo: se arma UNO, no dos. Dividir
     * renglon por renglon (2/1 = 2 en cada uno) daba 2.
     */
    public function test_el_mismo_articulo_repetido_suma_las_cantidades_antes_de_dividir()
    {
        $this->assertSame(1, ComboStockHelper::calcular([
            $this->comp(1, 2, false, 7), $this->comp(1, 2, false, 7),
        ]));

        $this->assertSame(1, ComboStockHelper::calcular([
            $this->comp(1, 5, false, 7), $this->comp(2, 5, false, 7), $this->comp(1, 100, false, 8),
        ]), 'A x1 + A x2 = x3 sobre 5 -> floor(5/3) = 1; B x1 sobre 100 -> 100; manda A');
    }

    /** El articulo repetido y el limitante de otro componente: manda el menor de los grupos. */
    public function test_el_repetido_compite_con_los_demas_componentes()
    {
        $this->assertSame(2, ComboStockHelper::calcular([
            $this->comp(1, 6, false, 7), $this->comp(2, 6, false, 7), $this->comp(1, 100, false, 8),
        ]), 'A: 6 / (1 + 2) = 2; B: 100 / 1 = 100 -> 2 (por renglon daria 6, 3 y 100 -> 3)');
    }

    /** Repetido con uno "sin control" (stock NULL): el grupo sin stock no limita, el otro si. */
    public function test_repetido_con_un_articulo_sin_control_de_stock()
    {
        $this->assertSame(3, ComboStockHelper::calcular([
            $this->comp(1, null, false, 7), $this->comp(1, null, false, 7), $this->comp(2, 6, false, 8),
        ]), 'A sin control (repetido) no limita; B: 6 / 2 = 3');

        $this->assertNull(ComboStockHelper::calcular([
            $this->comp(1, null, false, 7), $this->comp(1, null, false, 7),
        ]), 'y si solo hay articulos sin control el combo esta "sin control"');
    }

    /** Repetido y borrado en uno de los renglones: el combo no se arma. */
    public function test_repetido_con_un_renglon_borrado_deja_el_combo_en_cero()
    {
        $this->assertSame(0, ComboStockHelper::calcular([
            $this->comp(1, 50, false, 7), $this->comp(1, 50, true, 7),
        ]));
    }

    /**
     * El caso literal de Lucas con cantidad 1 de cada uno: A, B, C con stock 2 / 3 / 4 -> 2 combos
     * (manda A). Con cantidades 2 / 3 / 4 sobre los mismos stocks da 1 (ya cubierto arriba).
     */
    public function test_a_b_c_con_cantidad_uno_y_stock_dos_tres_cuatro_da_dos()
    {
        $this->assertSame(2, ComboStockHelper::calcular([
            $this->comp(1, 2, false, 1), $this->comp(1, 3, false, 2), $this->comp(1, 4, false, 3),
        ]));

        $this->assertSame(1, ComboStockHelper::calcular([
            $this->comp(2, 2, false, 1), $this->comp(3, 3, false, 2), $this->comp(4, 4, false, 3),
        ]));
    }

    /** El minimo se lo lleva el componente mas escaso, no el primero ni el ultimo. */
    public function test_manda_el_componente_limitante()
    {
        $this->assertSame(2, ComboStockHelper::calcular([
            $this->comp(2, 100), $this->comp(3, 6), $this->comp(4, 40),
        ]), 'el de en medio limita: 6/3 = 2');

        $this->assertSame(3, ComboStockHelper::calcular([
            $this->comp(2, 6), $this->comp(3, 100), $this->comp(4, 100),
        ]), 'el primero limita: 6/2 = 3');

        $this->assertSame(0, ComboStockHelper::calcular([
            $this->comp(1, 100), $this->comp(1, 100), $this->comp(2, 1),
        ]), 'el ultimo tiene 1 y pide 2: no se puede armar ninguno');
    }

    /** floor: con 7 unidades y receta de 2 caben 3 combos, no 3,5. */
    public function test_el_resultado_es_entero_hacia_abajo()
    {
        $this->assertSame(3, ComboStockHelper::calcular([$this->comp(2, 7)]));
        $this->assertSame(3, ComboStockHelper::calcular([$this->comp(2, '7.99')]),
            'el stock decimal de la base (decimal 12,2) llega como texto y tambien se redondea para abajo');
    }

    /** Un componente sin stock (NULL, no lleva control) NO limita. */
    public function test_un_componente_con_stock_null_no_limita()
    {
        $this->assertSame(2, ComboStockHelper::calcular([
            $this->comp(1, null), $this->comp(3, 6), $this->comp(2, null),
        ]));
    }

    /** Si NINGUN componente lleva stock el combo esta "sin control": null, no 0. */
    public function test_si_ningun_componente_lleva_stock_es_null_no_cero()
    {
        $this->assertNull(ComboStockHelper::calcular([$this->comp(1, null), $this->comp(2, null)]));
        $this->assertNull(ComboStockHelper::calcular([]), 'un combo sin componentes tampoco tiene stock que controlar');
    }

    /** Un componente borrado hace que el combo no se pueda armar, gane sobre lo demas. */
    public function test_un_componente_borrado_deja_el_combo_en_cero()
    {
        $this->assertSame(0, ComboStockHelper::calcular([
            $this->comp(1, 50), $this->comp(1, 50, true), $this->comp(1, 50),
        ]));

        $this->assertSame(0, ComboStockHelper::calcular([
            $this->comp(1, null, true),
        ]), 'aun sin stock cargado: un articulo que ya no existe no se puede poner en la caja');
    }

    /** El stock negativo (se vendio de mas) cuenta como cero. */
    public function test_el_stock_negativo_cuenta_como_cero()
    {
        $this->assertSame(0, ComboStockHelper::calcular([$this->comp(1, -5), $this->comp(1, 10)]));
    }

    /** Una cantidad rota (0 o negativa) no divide por cero: ese componente no limita. */
    public function test_una_cantidad_cero_no_divide_por_cero()
    {
        $this->assertSame(4, ComboStockHelper::calcular([$this->comp(0, 5), $this->comp(1, 4)]));
        $this->assertNull(ComboStockHelper::calcular([$this->comp(0, 5)]));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Contra la base
    |---------------------------------------------------------------------------------------------
    */

    /** El ejemplo de Lucas, de punta a punta: articulos reales, receta real, `para()`. */
    public function test_para_calcula_el_ejemplo_de_lucas_con_articulos_reales()
    {
        $comercio = $this->comercioConTienda();
        $combo = $this->combo($comercio);

        $this->componente($combo, $this->articuloConStock($comercio, 2), 2);
        $this->componente($combo, $this->articuloConStock($comercio, 3), 3);
        $this->componente($combo, $this->articuloConStock($comercio, 4), 4);

        $this->assertSame([$combo->id => 1], ComboStockHelper::para([$combo->id]));
    }

    /**
     * 🔴 El articulo BORRADO (soft delete) cuenta: el combo queda en cero.
     *
     * `Combo::articles()` no lo trae (a proposito, para que el detalle no lo liste), asi que si el
     * stock se calculara desde la relacion el componente borrado desapareceria de la cuenta y el
     * combo parecería armable. La contraprueba de abajo fija que la relacion SI lo esconde.
     */
    public function test_un_articulo_borrado_deja_el_combo_en_cero()
    {
        $comercio = $this->comercioConTienda();
        $combo = $this->combo($comercio);

        $vivo = $this->articuloConStock($comercio, 10);
        $borrado = $this->articuloConStock($comercio, 10);

        $this->componente($combo, $vivo, 1);
        $this->componente($combo, $borrado, 1);

        $this->assertSame([$combo->id => 10], ComboStockHelper::para([$combo->id]));

        /* Soft delete por SQL: `Article::delete()` dispara el evento de Likeable, que necesita una
           tabla (`likeable_likes`) que esta base de testing no tiene y que nada tiene que ver con lo
           que se prueba. Lo que importa es la columna `deleted_at`. */
        DB::table('articles')->where('id', $borrado->id)->update(['deleted_at' => now()]);

        $this->assertSame([$combo->id => 0], ComboStockHelper::para([$combo->id]),
            'con un componente borrado el combo no se puede armar');

        $this->assertCount(1, $combo->fresh()->articles,
            'contraprueba: la relacion del detalle NO lista el borrado, por eso el stock no puede calcularse desde ella');
    }

    /** Una fila de `article_combo` huerfana (el articulo se borro de verdad) tambien es cero. */
    public function test_una_fila_huerfana_del_pivote_cuenta_como_componente_borrado()
    {
        $comercio = $this->comercioConTienda();
        $combo = $this->combo($comercio);

        $this->componente($combo, $this->articuloConStock($comercio, 10), 1);

        DB::table('article_combo')->insert([
            'article_id' => 999999999,
            'combo_id'   => $combo->id,
            'amount'     => 1,
        ]);

        $this->assertSame([$combo->id => 0], ComboStockHelper::para([$combo->id]));
    }

    /**
     * 🔴 El mismo articulo en dos filas de `article_combo` (lo que permite el esquema, que no tiene
     * unique): `para()` tiene que agrupar por el `article_id` del pivote, tambien para dos filas
     * huerfanas distintas, que no se agrupan entre si.
     */
    public function test_para_agrupa_el_articulo_repetido_en_el_pivote()
    {
        $comercio = $this->comercioConTienda();
        $combo = $this->combo($comercio);

        $a = $this->articuloConStock($comercio, 2);
        $this->componente($combo, $a, 1);
        $this->componente($combo, $a, 1);

        $this->assertSame([$combo->id => 1], ComboStockHelper::para([$combo->id]),
            'A stock 2 en dos renglones de 1 -> se arma un combo, no dos');
    }

    /** Un componente con stock NULL de verdad en la base no limita. */
    public function test_un_articulo_sin_control_de_stock_no_limita()
    {
        $comercio = $this->comercioConTienda();
        $combo = $this->combo($comercio);

        $this->componente($combo, $this->articuloConStock($comercio, null), 1);
        $this->componente($combo, $this->articuloConStock($comercio, 8), 2);

        $this->assertSame([$combo->id => 4], ComboStockHelper::para([$combo->id]));

        $solo_null = $this->combo($comercio);
        $this->componente($solo_null, $this->articuloConStock($comercio, null), 1);

        $this->assertSame([$solo_null->id => null], ComboStockHelper::para([$solo_null->id]));
    }

    /**
     * 🔴 UNA consulta para todos los combos, no una por combo: la home lista todos los publicados.
     */
    public function test_el_stock_de_varios_combos_cuesta_una_sola_consulta()
    {
        $comercio = $this->comercioConTienda();

        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $combo = $this->combo($comercio);
            $this->componente($combo, $this->articuloConStock($comercio, $i * 2), 2);
            $this->componente($combo, $this->articuloConStock($comercio, 100), 1);
            $ids[] = $combo->id;
        }

        $consultas = [];
        DB::listen(function ($consulta) use (&$consultas) {
            $consultas[] = $consulta->sql;
        });

        $stock = ComboStockHelper::para($ids);

        $this->assertCount(1, $consultas, 'una consulta agregada, no una por combo');
        $this->assertSame([$ids[0] => 1, $ids[1] => 2, $ids[2] => 3, $ids[3] => 4, $ids[4] => 5], $stock);
    }

    /** Sin ids no hay consulta. */
    public function test_sin_combos_no_consulta_nada()
    {
        $consultas = [];
        DB::listen(function ($consulta) use (&$consultas) {
            $consultas[] = $consulta->sql;
        });

        $this->assertSame([], ComboStockHelper::para([]));
        $this->assertCount(0, $consultas);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Lo que ve el navegador
    |---------------------------------------------------------------------------------------------
    */

    /** La home trae `stock_disponible` en cada combo. */
    public function test_la_home_trae_el_stock_disponible_de_cada_combo()
    {
        $comercio = $this->comercioConTienda();

        $con_stock = $this->combo($comercio, ['name' => 'Con stock']);
        $this->componente($con_stock, $this->articuloConStock($comercio, 9), 3);

        $sin_control = $this->combo($comercio, ['name' => 'Sin control']);
        $this->componente($sin_control, $this->articuloConStock($comercio, null), 1);

        $agotado = $this->combo($comercio, ['name' => 'Agotado']);
        $this->componente($agotado, $this->articuloConStock($comercio, 1), 2);

        $this->assertSame(3, $this->comboEnLaHome($comercio, $con_stock)['stock_disponible']);
        $this->assertNull($this->comboEnLaHome($comercio, $sin_control)['stock_disponible']);
        $this->assertArrayHasKey('stock_disponible', $this->comboEnLaHome($comercio, $sin_control),
            'el null viaja como null y no como clave ausente: el SPA distingue "sin control" de "no me llego"');
        $this->assertSame(0, $this->comboEnLaHome($comercio, $agotado)['stock_disponible']);
    }

    /** El carrito devuelve el stock de sus combos: es lo que le permite a la interfaz topar la cantidad. */
    public function test_el_carrito_trae_el_stock_disponible_de_sus_combos()
    {
        $comercio = $this->comercioConTienda();
        $combo = $this->combo($comercio);
        $this->componente($combo, $this->articuloConStock($comercio, 7), 2);

        $creado = $this->crearCarrito($comercio, [
            'combos' => [$this->lineaDeComboDelPayload($combo, 1)],
        ]);
        $creado->assertStatus(201);

        $en_el_carrito = $creado->json('cart.combos.0');

        $this->assertSame($combo->id, $en_el_carrito['id']);
        $this->assertSame(3, $en_el_carrito['stock_disponible'], '7 unidades / 2 por combo = 3');
    }
}
