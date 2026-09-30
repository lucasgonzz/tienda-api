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
 * de cada componente ("1x Taladro, 2x Mecha"). No tiene descripcion, ni slug, ni sucursal, y su
 * stock NO se guarda: se calcula al leer desde el de sus componentes (`ComboStockHelper`).
 *
 * ── Que cambio con la mision combos-calculados (30/9/2026) ────────────────────────────────────
 *
 *   - Ahora puede tener FOTO PROPIA (`images()`, tabla `images` con `imageable_type = 'combo'`).
 *     La tarjeta de la tienda usa esa foto si existe y, si no, hace el collage con las imagenes de
 *     sus articulos (que por eso siguen viajando).
 *   - `combos.price` sigue siendo el precio que la tienda cobra por defecto, pero ya no es el
 *     unico: un combo "calculado desde sus articulos" tiene ademas un precio por lista de precios
 *     en `combo_price_type`, y la tienda elige la lista del comprador con las mismas reglas que un
 *     articulo (`ComboPrecioHelper`). `combos.price` es siempre el de la lista por defecto, y por
 *     eso una tienda vieja que solo lee esa columna sigue cobrando bien.
 *   - Sigue sin pasar por recargos ni por `checkPriceTypes`: el combo no es un `Article`.
 *
 * ⚠️ `combos` no tiene ninguna columna de este repo: el esquema entero lo crea `empresa-api`, y
 * `online` —el check "Mostrar en la tienda"— llega con su migracion. Todo acceso pasa antes por
 * `ComboEsquemaHelper::disponible()`. La tabla `combo_price_type` tiene su guarda propia
 * (`precios_por_lista_disponible()`), y es el unico esquema nuevo de esta mision: las imagenes
 * viven en una tabla que existe en todas las bases.
 */
class Combo extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    /**
     * 🔴 `cost` es lo que al comerciante le CUESTA el combo, y la tienda es publica: cualquier
     * visitante que abra las devtools en la home veria el margen de cada combo. La tienda ya no
     * publica el costo de un articulo (`Cart::articles()` no trae `cost` en el pivot), asi que
     * esto solo mantiene el mismo criterio en una coleccion nueva.
     *
     * No afecta a la API: `$hidden` toca la serializacion, no la lectura. `CartHelper::attach_combos`
     * y `OrderHelper::attachCombos` siguen leyendo `$combo->cost` normalmente.
     *
     * ⚠️ `PromocionVinoteca` SI lo publica hoy (su modelo no tiene `$hidden` y `get_promociones_vinoteca`
     * devuelve el modelo entero). Es un defecto preexistente, no se arregla en esta mision para no
     * cambiar una respuesta que el SPA ya consume — queda denunciado.
     *
     * Las tres columnas de la mision combos-calculados tambien se esconden: `calcular_desde_articulos`,
     * `descuento_tipo` y `descuento_valor` son instrucciones para el calculo que hace `empresa-api`
     * y la tienda no las usa — el precio que cobra ya viene con el descuento aplicado (`combos.price`
     * y `combo_price_type.price`). Publicarlas solo le diria al visitante como se arma el precio.
     * Si la base todavia no las tiene, el `$hidden` de una columna que no existe no hace nada.
     *
     * @var array<int, string>
     */
    protected $hidden = ['cost', 'calcular_desde_articulos', 'descuento_tipo', 'descuento_valor'];

    /**
     * Las columnas de los componentes que VIAJAN al navegador.
     *
     * 🔴 Antes el combo serializaba a cada componente ENTERO, o sea con `cost`, `percentage_gain`,
     * `provider_code` y todo lo demas de `articles` (`Article` no tiene `$hidden`). La tienda es
     * publica: cualquiera con las devtools abiertas en la home veia el costo de cada articulo que
     * componia un combo, aunque el costo del combo mismo ya estuviera escondido (`$hidden`).
     *
     * Lo que el SPA usa de un componente es `name`, `images` (mosaico de la tarjeta) y
     * `pivot.amount` (la receta "2x Mecha"); `slug` queda por si una tarjeta quiere linkear y
     * `stock` / `deleted_at` son los que ya pedia el contrato de la mision. El `id` es obligatorio:
     * sin el, Eloquent no puede colgarle las `images` a cada componente ni armar el pivote.
     */
    const COLUMNAS_DE_COMPONENTES = [
        'articles.id',
        'articles.name',
        'articles.slug',
        'articles.stock',
        'articles.deleted_at',
    ];

    /**
     * Las relaciones que hay que cargar cuando el combo viaja al navegador, colgado de donde este
     * (`''` si se carga desde `Combo`, `'combos.'` si desde el carrito o el pedido).
     *
     * Es UN solo lugar para que los cuatro `with(...)` de la tienda (home, carrito, pedido y
     * `OrderController@current`) no puedan diverger: el select acotado de los componentes es justo
     * la clase de cosa que se arregla en tres y se olvida en el cuarto.
     *
     *   - `articles` con el select acotado de arriba.
     *   - `articles.images`: el collage cuando el combo no tiene foto propia.
     *   - `images`: la foto propia del combo.
     *
     * @param  string  $prefijo  Ruta de la relacion `combos` desde el modelo que se consulta.
     * @return array  Argumento listo para `->with(...)`.
     */
    static function relaciones_para_la_tienda($prefijo = '') {
        return [
            $prefijo.'articles' => function ($query) {
                $query->select(self::COLUMNAS_DE_COMPONENTES);
            },
            $prefijo.'articles.images',
            $prefijo.'images',
        ];
    }

    /**
     * Ver `relaciones_para_la_tienda()`: `images` (foto propia) y `articles.images` (collage).
     * Sin esto serian N+1 consultas por combo en la home.
     */
    function scopeWithAll($query) {
        $query->with(self::relaciones_para_la_tienda());
    }

    /**
     * Los componentes de la receta, con cuantas unidades entran de cada uno.
     * Pivote `article_combo` (el nombre por convencion ya coincide).
     *
     * 🔴 Sin `withTrashed()` a proposito: el detalle del combo no tiene que listar un articulo que
     * el comerciante ya borro. Al reves, el STOCK si tiene que mirar los borrados (un combo con un
     * componente borrado no se puede armar), y eso lo hace `ComboStockHelper` por SQL propio.
     */
    function articles() {
        return $this->belongsToMany('App\Article')->withPivot('amount');
    }

    /**
     * La foto propia del combo (misma tabla `images` que los articulos).
     *
     * El tipo polimorfico es el alias `combo`: `AppServiceProvider` lo registra en el morph map
     * apuntando a esta clase, igual que hace `empresa-api` con la suya. Sin el alias Eloquent
     * buscaria `imageable_type = 'App\Combo'` y no encontraria nunca las fotos que carga el ERP.
     */
    function images() {
        return $this->morphMany('App\Image', 'imageable');
    }

    function user() {
        return $this->belongsTo('App\User');
    }
}
