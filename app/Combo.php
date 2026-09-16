<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un combo del ERP publicado en la tienda (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * El molde de punta a punta es `PromocionVinoteca`: es el unico precedente de este repo de algo
 * que se compra y no es un `Article`, y tiene los tres pivotes (`cart_`, `order_`, `budget_`).
 *
 * ── Que es un combo, y en que NO se parece a un articulo ──────────────────────────────────────
 *
 * Es una RECETA, no una entidad de catalogo: `combos` + el pivote `article_combo` con el `amount`
 * de cada componente ("1x Taladro, 2x Mecha"). No tiene imagen propia, ni descripcion, ni slug, ni
 * stock propio, ni sucursal. La tarjeta de la tienda se arma con las imagenes de SUS ARTICULOS.
 *
 * `combos.price` es un precio fijo cargado a mano en el ABM y se trata como final, CON IVA — igual
 * que en el ERP. No pasa por listas de precio, ni por recargos, ni por `checkPriceTypes`.
 *
 * ⚠️ `combos` no tiene ninguna columna de este repo: el esquema entero lo crea `empresa-api`, y
 * `online` —el check "Mostrar en la tienda"— llega recien con la migracion de esta mision. Todo
 * acceso pasa antes por `ComboEsquemaHelper::disponible()`.
 */
class Combo extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    /**
     * `articles.images` y no solo `articles`: la tarjeta del combo no tiene imagen propia y se
     * dibuja con las de sus componentes. Sin esto serian N+1 consultas por combo en la home.
     */
    function scopeWithAll($query) {
        $query->with('articles.images');
    }

    /**
     * Los componentes de la receta, con cuantas unidades entran de cada uno.
     * Pivote `article_combo` (el nombre por convencion ya coincide).
     */
    function articles() {
        return $this->belongsToMany('App\Article')->withPivot('amount');
    }

    function user() {
        return $this->belongsTo('App\User');
    }
}
