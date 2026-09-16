<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use App\Combo;
use App\Icon;
use App\PromocionVinoteca;
use App\StockMovement;


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
     * Las dos claves que se agregan a cada combo son el contrato con `tienda-spa`:
     *   - `is_combo`, para que el carrito sepa a que coleccion pertenece la linea (igual que
     *     `is_promocion_vinoteca`).
     *   - `final_price`, que es `price` con otro nombre: el SPA lee el precio de todo lo comprable
     *     por `final_price`, asi que la tarjeta del combo no necesita un caso aparte.
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

        foreach ($combos as $combo) {
            $combo->is_combo = true;
            $combo->final_price = $combo->price;
        }

        return $combos;
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
     * Novedades de la home: articulos NO borrados con movimiento de stock reciente,
     * los mas nuevos primero (el orden lo da la query de movimientos, created_at DESC).
     *
     * Aca habia un whereNotNull('deleted_at') sobre la query del articulo que, contra el
     * global scope de SoftDeletes de Article (deleted_at IS NULL), armaba una condicion
     * imposible: la seccion Novedades llegaba SIEMPRE vacia a la tienda. Los borrados ya
     * los excluye SoftDeletes solo, asi que no hace falta ninguna condicion extra.
     */
    static function getNovedades($commerce_id) {
        $stock_movements = StockMovement::where('user_id', $commerce_id)
                                    ->orderBy('created_at', 'DESC')
                                    ->where('stock_resultante', '>', 0)
                                    ->take(20)
                                    ->get();

        $articulos_novedades = collect();

        foreach ($stock_movements as $stock_movement) {

            /* Un articulo con varios movimientos recientes es UNA novedad. Se saltea por id
               y antes de la query: el contains($article) que habia aca comparaba instancias
               enteras (con relaciones cargadas adentro) y encima pagaba la consulta aunque
               el articulo ya estuviera en la lista. Nunca se noto porque con la condicion
               imposible de arriba este loop no empujaba nada. */
            if ($articulos_novedades->contains('id', $stock_movement->article_id)) {
                continue;
            }

            $article = Article::where('id', $stock_movement->article_id)
                            ->checkStock()
                            ->checkOnline()
                            ->withAll()
                            ->first();

            if (!is_null($article)) {
                $articulos_novedades->push($article);
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
