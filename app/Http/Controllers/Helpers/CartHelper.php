<?php

namespace App\Http\Controllers\Helpers;

use App\Cart;
use App\Cupon;
use App\Http\Controllers\Helpers\ArticleHelper;

class CartHelper {

    static function checkPaymentStatus($cart) {
        if (!is_null($cart->payment_id) && !is_null($cart->order_id)) {
            $order = Order::find($cart->order_id);
            $order->payment_id = $cart->payment_id;
            $order->save();
        }
    }

    static function attachCupons($cart, $cupons) {
        $cart->cupons()->detach();
        foreach ($cupons as $cupon) {
            $cart->cupons()->attach($cupon['id']);
        }
    }

    static function attachArticles($cart, $articles) {
        foreach ($articles as $article) {
            $cart->articles()->attach($article['id'], [
                                        'price'         => $article['final_price'],
                                        'amount'        => $article['pivot']['amount'],
                                        'notes'         => $article['pivot']['notes'],
                                        'variant_id'    => isset($article['pivot']['variant_id']) ? $article['pivot']['variant_id'] : null,
                                        // 'color_id'      => $article['pivot']['color_id'],
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
        $model->articles = ArticleHelper::setArticlesVariants($model->articles);
        $model->articles = ArticleHelper::checkPriceTypes($model->articles);
        return $model;
    }

}
