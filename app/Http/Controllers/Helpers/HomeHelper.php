<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use App\Icon;
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

    static function getNovedades($commerce_id) {
        $stock_movements = StockMovement::where('user_id', $commerce_id)
                                    ->orderBy('created_at', 'DESC')
                                    ->where('stock_resultante', '>', 0)
                                    ->take(20)
                                    ->get();

        $articulos_novedades = collect();

        foreach ($stock_movements as $stock_movement) {
            $article = Article::where('id', $stock_movement->article_id)
                            ->checkStock()
                            ->checkOnline()
                            ->withAll()
                            ->whereNotNull('deleted_at')
                            ->first();

            if (!is_null($article) && !$articulos_novedades->contains($article)) {
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
