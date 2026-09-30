<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * El stock de un combo: cuantos se pueden ARMAR con lo que hay de cada componente (mision
 * combos-calculados, 30/9/2026).
 *
 * ── Es una regla DUPLICADA con `empresa-api`, a proposito ─────────────────────────────────────
 *
 * El ERP calcula lo mismo con su `ComboStockHelper` y lo muestra en Vender. Las dos puntas tienen
 * que dar el mismo numero para el mismo combo: si la tienda dijera "quedan 3" y el ERP "quedan 2",
 * el comerciante pierde la confianza en uno de los dos. No se comparte codigo porque son dos
 * repos; se comparte la REGLA, que esta escrita literal en `calcular()` y clavada con los mismos
 * ejemplos en los tests de las dos puntas. Si se cambia una, se cambia la otra.
 *
 * ── Por que NO se persiste ────────────────────────────────────────────────────────────────────
 *
 * `articles.stock` se escribe por muchisimos caminos (ventas, compras, ajustes, importaciones,
 * SQL crudo). Guardar el stock del combo obligaria a enganchar todos, y el primero que se olvide
 * deja un numero viejo en la vidriera. Se calcula al leer.
 *
 * ── Una consulta, no una por combo ───────────────────────────────────────────────────────────
 *
 * La home lista todos los combos publicados y el carrito los suyos. `para()` trae los componentes
 * de TODOS con un solo JOIN; un accessor por combo seria N+1 en la pagina mas visitada.
 *
 * ── Por que SQL propio y no `$combo->articles` ───────────────────────────────────────────────
 *
 * `Combo::articles()` no incluye los articulos borrados (para que el detalle no los liste), pero
 * el stock TIENE que verlos: un componente borrado hace que el combo no se pueda armar. Si se
 * calculara desde la relacion, el articulo borrado desapareceria de la cuenta y el combo pareceria
 * tener stock de sobra. El JOIN va sin filtrar `deleted_at` justamente para leerlo.
 */
class ComboStockHelper
{
    /**
     * `stock_disponible` de cada combo, en una sola consulta.
     *
     * @param  array  $combo_ids  Ids de `combos`.
     * @return array<int, int|null>  [combo_id => stock_disponible]. Todos los ids pedidos vuelven
     *                               con clave; un combo sin componentes da null.
     */
    static function para(array $combo_ids) {

        $combo_ids = array_values(array_unique(array_filter(array_map('intval', $combo_ids))));

        if (count($combo_ids) == 0) {
            return [];
        }

        /* LEFT JOIN y no INNER: si el articulo fue borrado de verdad (hard delete) la fila del
           pivote queda huerfana, `a.id` viene null y `calcular()` lo trata como componente borrado.
           Con INNER JOIN esa linea desapareceria y el combo parecería armable. */
        $filas = DB::table('article_combo as ac')
                    ->leftJoin('articles as a', 'a.id', '=', 'ac.article_id')
                    ->whereIn('ac.combo_id', $combo_ids)
                    ->select('ac.combo_id', 'ac.article_id', 'ac.amount', 'a.id as articulo_existente', 'a.stock', 'a.deleted_at')
                    ->get();

        $por_combo = [];

        foreach ($combo_ids as $combo_id) {
            $por_combo[$combo_id] = [];
        }

        foreach ($filas as $fila) {
            $por_combo[(int) $fila->combo_id][] = [
                /* El articulo del RENGLON (`ac.article_id`), que es la clave con la que `calcular()`
                   agrupa los renglones repetidos. Se toma del pivote y no del JOIN para no depender
                   de que el articulo exista; un renglon huerfano es "borrado" igual y deja el combo
                   en 0, agrupado o no. */
                'article_id' => $fila->article_id,
                'amount'     => $fila->amount,
                'stock'      => $fila->stock,
                'borrado'    => is_null($fila->articulo_existente) || !is_null($fila->deleted_at),
            ];
        }

        $resultado = [];

        foreach ($por_combo as $combo_id => $componentes) {
            $resultado[$combo_id] = self::calcular($componentes);
        }

        return $resultado;
    }

    /**
     * La regla, pura (sin base): el minimo entre componentes de `floor(stock / cantidad)`.
     *
     *   - componente con `stock` NULL (no lleva control de stock) -> NO limita;
     *   - componente borrado -> 0 (el combo no se puede armar);
     *   - stock negativo -> se toma como 0;
     *   - si NINGUN componente lleva stock (todos NULL, o el combo no tiene componentes) -> null,
     *     que quiere decir "sin control": hay siempre. NO es 0.
     *   - cantidad del componente <= 0 (dato roto) -> ese componente no limita: dividir por cero
     *     no puede tumbar la home.
     *   - 🔴 el MISMO articulo en mas de un renglon se AGRUPA por `article_id` y se SUMAN sus
     *     cantidades antes de dividir. Un combo con A (stock 2) en dos renglones de 1 necesita 2
     *     unidades de A por combo: dividir renglon por renglon daba 2 combos cuando solo se arma
     *     1 (sobreventa). La misma regla, con la misma clave de agrupacion, esta en el
     *     `ComboStockHelper` de `empresa-api`. Un renglon sin `article_id` no se agrupa con nadie.
     *
     * Es el stock GLOBAL `articles.stock`, no el de un deposito.
     *
     * Ejemplo de Lucas: A x2 (stock 2), B x3 (stock 3), C x4 (stock 4): 2/2 = 1, 3/3 = 1,
     * 4/4 = 1 -> se puede armar 1 combo. El limitante manda.
     *
     * @param  array  $componentes  Cada uno ['article_id' => int|null (clave de agrupacion),
     *                              'amount' => int|float|string, 'stock' => numerico|null,
     *                              'borrado' => bool].
     * @return int|null
     */
    static function calcular(array $componentes) {

        $minimo = null;

        foreach (self::agrupar_por_articulo($componentes) as $componente) {

            /* El borrado gana sobre todo lo demas, incluso sobre un stock NULL: un componente que
               ya no existe no se puede poner en la caja. */
            if (!empty($componente['borrado'])) {
                return 0;
            }

            $stock = isset($componente['stock']) ? $componente['stock'] : null;

            if (is_null($stock) || !is_numeric($stock)) {
                continue;
            }

            $cantidad = isset($componente['amount']) && is_numeric($componente['amount'])
                            ? (float) $componente['amount']
                            : 0;

            if ($cantidad <= 0) {
                continue;
            }

            /* Un stock negativo (se vendio de mas) no resta armables: cuenta como cero. */
            $armables = (int) floor(max(0, (float) $stock) / $cantidad);

            if (is_null($minimo) || $armables < $minimo) {
                $minimo = $armables;
            }
        }

        return $minimo;
    }

    /**
     * Junta los renglones del mismo articulo en uno solo, sumando sus cantidades.
     *
     * El borrado de cualquiera de los renglones borra el grupo; el stock es el del articulo (el
     * mismo en todos sus renglones, se toma el primero). Sin `article_id` el renglon queda solo.
     *
     * @param  array  $componentes
     * @return array  Mismo formato que `calcular()`, un elemento por articulo distinto.
     */
    private static function agrupar_por_articulo(array $componentes) {

        $grupos = [];

        foreach ($componentes as $indice => $componente) {

            $clave = isset($componente['article_id']) ? 'a'.$componente['article_id'] : 'r'.$indice;

            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'amount'  => 0,
                    'stock'   => isset($componente['stock']) ? $componente['stock'] : null,
                    'borrado' => false,
                ];
            }

            if (!empty($componente['borrado'])) {
                $grupos[$clave]['borrado'] = true;
            }

            if (isset($componente['amount']) && is_numeric($componente['amount'])) {
                $grupos[$clave]['amount'] += (float) $componente['amount'];
            }
        }

        return $grupos;
    }
}
