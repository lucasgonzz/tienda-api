<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use App\Combo;
use App\Http\Controllers\Helpers\AjustesDeClienteHelper;
use App\Icon;
use App\PromocionVinoteca;
use App\StockMovement;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


class HomeHelper
{
    static function addIndexCategory($categories, $commerce_id) {
        // $icon_home = Icon::where('slug', 'home')->first();
        $index_category = new \stdClass();
        $index_category->id = 0;
        $index_category->name = 'Inicio';
        // $index_category->icon = $icon_home;
        $index_category->is_index = true;
        $categories->prepend($index_category);
        return $categories;
    }

    static function removeCategoriesWithoutArticles($categories) {
        $new = [];
        foreach ($categories as $category) {
            if (count($category->sub_categories) > 0) {
                foreach ($category->sub_categories as $sub_category) {
                    if (count($sub_category->articles) > 0) {
                        $new[] = $category;
                        break;
                    }                    
                }
            }
        }
        return $new;
    }


    static function setResultadosSubCategory($articles) {
        $resultados_sub_category = new \stdClass();
        $resultados_sub_category->id = -2;
        $resultados_sub_category->name = 'Resultados';
        $resultados_sub_category->results = true;
        $resultados_sub_category->articles = $articles;
        $sub_categories = [];
        $sub_categories[] = $resultados_sub_category;
        return $sub_categories;
    }

    static function getFeatured($commerce_id) {
        $featured = Article::where('user_id', $commerce_id)
                            ->whereNotNull('featured')
                            ->where('featured', '!=', 0)
                            ->checkStock()
                            ->checkOnline()
                            ->withAll()
                            ->get();
        return $featured;
    }

    static function getInOffer($commerce_id) {
        $in_offer = Article::where('user_id', $commerce_id)
                            ->where('in_offer', 1)
                            ->checkStock()
                            ->checkOnline()
                            ->withAll()
                            ->get();
        return $in_offer;
    }

    static function get_promociones_vinoteca($commerce_id) {
        $promociones_vinoteca = PromocionVinoteca::where('user_id', $commerce_id)
                            ->withAll()
                            ->where('online', 1)
                            ->orderBy('id', 'DESC')
                            ->get();

        foreach ($promociones_vinoteca as $promocion_vinoteca) {
            
            $promocion_vinoteca->is_promocion_vinoteca = true;
        }
        /* Decision 2 de la mision descuentos-recargos-por-cliente: los ajustes del cliente del
           comprador van sobre todo lo comprable, asi que la tarjeta de la promo muestra el precio
           que despues cobra el carrito. Sin comprador vinculado no toca nada. */
        AjustesDeClienteHelper::aplicar_a_precios_fijos($promociones_vinoteca, $commerce_id);
        return $promociones_vinoteca;
    }

    /**
     * Los combos publicados del comercio, para la seccion propia de la home.
     *
     * 🔴 `ComboEsquemaHelper::disponible()` va PRIMERO y devuelve coleccion vacia: sin la columna
     * `online` este metodo seria "Unknown column 'online'" y la home entera caeria en 500. Es el
     * caso real de un cliente que actualiza la tienda antes que el ERP.
     *
     * `online = 1` es el check "Mostrar en la tienda" del ABM de empresa, y su default es 0: ningun
     * cliente ve combos aparecer en su ecommerce sin haberlos prendido.
     *
     * Las claves que se agregan a cada combo son el contrato con `tienda-spa`:
     *   - `is_combo`, para que el carrito sepa a que coleccion pertenece la linea (igual que
     *     `is_promocion_vinoteca`).
     *   - `final_price`, que es el precio con otro nombre: el SPA lee el precio de todo lo comprable
     *     por `final_price`, asi que la tarjeta del combo no necesita un caso aparte. Desde la
     *     mision combos-calculados NO es siempre `combos.price`: es el precio de la LISTA del
     *     comprador (ver `ComboPrecioHelper`), con los ajustes de cliente encima.
     *   - `stock_disponible` (int|null): cuantos combos se pueden armar con lo que hay de cada
     *     componente. `null` = sin control de stock. Ver `ComboStockHelper`.
     *   - `images`: la foto propia del combo (vacio si no tiene; entonces la tarjeta hace el
     *     collage con `articles[].images`).
     *
     * ── Los agotados ─────────────────────────────────────────────────────────────────────────
     *
     * Un combo con `stock_disponible === 0` no se lista, con el mismo criterio que
     * `Article::scopeCheckStock()` para un articulo: se oculta salvo que la tienda haya prendido
     * `ignorar_stock` o `show_articles_without_stock`. Un `null` (sin control) NUNCA se oculta, aun
     * con `stock_null_equal_0`: un combo cuyos componentes no llevan stock no esta agotado, no
     * tiene la nocion de stock.
     *
     * @param  int  $commerce_id
     * @return \Illuminate\Support\Collection
     */
    static function get_combos($commerce_id) {
        if (!ComboEsquemaHelper::disponible()) {
            return collect();
        }

        $combos = Combo::where('user_id', $commerce_id)
                            ->where('online', 1)
                            ->withAll()
                            ->orderBy('id', 'DESC')
                            ->get();

        /* Una consulta para el stock de todos y una para los precios por lista de todos: nada de
           accessors por combo en la pagina mas visitada de la tienda. */
        $stock = ComboStockHelper::para($combos->pluck('id')->all());
        $precios = ComboPrecioHelper::precios_base($combos, $commerce_id);

        /* 🔴 Un combo sin precio vendible (NULL o <= 0 despues de elegir la lista) no se ofrece: se
           podia comprar a $0. Se mira el precio RESUELTO, el que sale de `ComboPrecioHelper`, y no
           `combos.price`: una lista puede tener precio aunque el de la columna sea 0. */
        $combos = $combos->filter(function ($combo) use ($precios) {
            return ComboPrecioHelper::es_vendible($precios[$combo->id]);
        })->values();

        foreach ($combos as $combo) {
            $combo->is_combo = true;
            $combo->final_price = $precios[$combo->id];
            $combo->stock_disponible = $stock[$combo->id];
        }

        if (self::ocultar_combos_agotados($commerce_id)) {
            $combos = $combos->filter(function ($combo) {
                return $combo->stock_disponible !== 0;
            })->values();
        }

        /* Idem promos: el combo se muestra con los ajustes del cliente aplicados. */
        AjustesDeClienteHelper::aplicar_a_precios_fijos($combos, $commerce_id);

        return $combos;
    }

    /**
     * ¿Esta tienda oculta lo agotado? Espejo de `Article::scopeCheckStock()`, que es quien decide
     * lo mismo para los articulos:
     *
     *   - `ignorar_stock` prendido -> nada se oculta, nunca (la tienda entera se comporta como si
     *     ningun articulo llevara stock).
     *   - si no, se oculta cuando `show_articles_without_stock` esta apagado.
     *
     * ⚠️ `ignorar_stock` no existe en todas las bases (lo crea una migracion de `empresa-api` que
     * puede no haber llegado). Leer un atributo ausente de un modelo da null, no una excepcion, asi
     * que sin la columna cuenta como apagado: el mismo resultado que tiene el articulo.
     *
     * Un comercio sin fila de configuracion online no oculta nada: la tabla arranca con
     * `show_articles_without_stock = 1`.
     *
     * @param  int|string  $commerce_id
     * @return bool
     */
    static function ocultar_combos_agotados($commerce_id) {
        $commerce = User::find($commerce_id);

        $configuration = is_null($commerce) ? null : $commerce->online_configuration;

        if (is_null($configuration)) {
            return false;
        }

        if ($configuration->ignorar_stock) {
            return false;
        }

        return !$configuration->show_articles_without_stock;
    }

    /**
     * Los articulos que tienen al menos un tramo de precio por cantidad, para la seccion
     * "Comprando mas, pagas menos" de la home (debajo de Novedades).
     *
     * Mismo shape que `novedades`: los mismos `checkStock` / `checkOnline` / `withAll`, y el
     * `checkPriceTypes` se lo aplica el controller, como a todas las demas colecciones.
     *
     * @param  int  $commerce_id
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection
     */
    static function get_articulos_con_rangos($commerce_id) {
        if (!ArticlePriceRangeHelper::hay_tabla()) {
            return collect();
        }

        return Article::where('user_id', $commerce_id)
                        ->whereHas('article_price_ranges')
                        ->checkStock()
                        ->checkOnline()
                        ->withAll()
                        ->orderBy('created_at', 'DESC')
                        ->get();
    }

    /**
     * Conceptos de stock que cuentan como INGRESO DE MERCADERIA. Van por nombre y no por id:
     * los ids de `concepto_stock_movements` varian entre bases (ver los seeders de empresa-api).
     *
     * Quedan afuera a proposito los movimientos que suman stock sin que entre mercaderia nueva:
     * devoluciones (nota de credito, "se elimino de la venta"), movimientos entre depositos,
     * reseteos y ajustes.
     */
    const CONCEPTOS_DE_INGRESO = [
        'Ingreso manual',
        'Compra a proveedor',
        'Act Compra a proveedor',
        'Importacion de excel',
        'Produccion',
    ];

    /** Cuantos articulos muestra la seccion Novedades. */
    const CANTIDAD_DE_NOVEDADES = 20;

    /**
     * Novedades de la home: los ULTIMOS 20 articulos que recibieron un INGRESO de mercaderia
     * (compra a proveedor, ingreso manual, importacion de excel o produccion), el ingreso mas
     * nuevo primero. Una venta, una devolucion o un movimiento entre depositos NO es novedad.
     *
     * Se diferencia de "Ultimos ingresos", que ordena por la fecha en que el articulo se cargo
     * al sistema (`articles.created_at`).
     *
     * Solo entran articulos visibles hoy en la tienda (online, con stock, no borrados: el
     * SoftDeletes de Article ya excluye los borrados). Un articulo con varios ingresos es UNA
     * novedad, con la fecha de su ingreso mas nuevo.
     *
     * Tolerante a una base sin `concepto_stock_movements` (cliente con la tienda mas nueva que
     * el ERP): devuelve vacio y el SPA esconde la seccion.
     *
     * @param  int  $commerce_id
     * @return \Illuminate\Support\Collection
     */
    static function getNovedades($commerce_id) {
        if (!Schema::hasTable('concepto_stock_movements')) {
            return collect();
        }

        $ids_conceptos = DB::table('concepto_stock_movements')
                            ->whereIn('name', Self::CONCEPTOS_DE_INGRESO)
                            ->pluck('id');

        if ($ids_conceptos->isEmpty()) {
            return collect();
        }

        /* Un articulo con ingresos repetidos puede ocupar muchos movimientos: se traen de mas
           y se dedupica por articulo, para que salgan 20 ARTICULOS y no 20 movimientos. */
        $ids_articulos = StockMovement::where('user_id', $commerce_id)
                                    ->whereIn('concepto_stock_movement_id', $ids_conceptos)
                                    ->where('amount', '>', 0)
                                    ->whereNotNull('article_id')
                                    ->orderBy('created_at', 'DESC')
                                    ->orderBy('id', 'DESC')
                                    ->limit(Self::CANTIDAD_DE_NOVEDADES * 10)
                                    ->pluck('article_id')
                                    ->unique()
                                    ->values();

        if ($ids_articulos->isEmpty()) {
            return collect();
        }

        $articulos = Article::whereIn('id', $ids_articulos)
                            ->checkStock()
                            ->checkOnline()
                            ->withAll()
                            ->get()
                            ->keyBy('id');

        /* El orden es el del ingreso mas nuevo, no el que devuelva la base. */
        $articulos_novedades = collect();
        foreach ($ids_articulos as $article_id) {
            if (isset($articulos[$article_id])) {
                $articulos_novedades->push($articulos[$article_id]);
            }
            if ($articulos_novedades->count() >= Self::CANTIDAD_DE_NOVEDADES) {
                break;
            }
        }
        return $articulos_novedades;
    }

    static function addLastUploadsToList($commerce_id) {
        $category_last_uploads = new \stdClass();
        $category_last_uploads->id = -1;
        $category_last_uploads->name = 'Ultimos ingresados';
        $category_last_uploads->last_uploads = true;
        return $category_last_uploads;
    }
    
}
