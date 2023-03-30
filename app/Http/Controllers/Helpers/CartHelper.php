<?php

namespace App\Http\Controllers\Helpers;

use App\Cart;
use App\Cupon;
use App\Http\Controllers\Helpers\ArticleHelper;

class CartHelper {

    static function attachCupons($cart, $cupons) {
        $cart->cupons()->detach();
        foreach ($cupons as $cupon) {
            $cart->cupons()->attach($cupon['id']);
        }
    }

    static function attachArticles($cart, $articles) {
        foreach ($articles as $article) {
            $cart->articles()->attach($article['id'], [
                                        'price'      => $article['final_price'],
                                        'amount'     => $article['pivot']['amount'],
                                        'color_id'   => $article['pivot']['color_id'],
                                        'size_id'    => isset($article['pivot']['size_id']) ? $article['pivot']['size_id'] : null,
                                        // 'color_id'   => ArticleHelper::getColorId($article),
                                        // 'size_id'    => ArticleHelper::getSizeId($article),
                                    ]);
        }
    }

    static function getFullModel($id) {
        $model = Cart::where('id', $id)
                        ->withAll()
                        ->with(['articles' => function($query) {
                            $query->withAll();
                        }])
                        ->first();
        $model->articles = ArticleHelper::setArticlesRelationsFromPivot($model->articles);
        $model->articles = ArticleHelper::checkPriceTypes($model->articles);
        return $model;
    }

}
