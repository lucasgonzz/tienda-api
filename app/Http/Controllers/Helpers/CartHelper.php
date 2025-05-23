<?php

namespace App\Http\Controllers\Helpers;

use App\ArticlePriceTypeGroup;
use App\Cart;
use App\Cupon;
use App\Http\Controllers\Helpers\ArticleHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        
        if (count($articles) == 0) {
            return;
        }

        $has_price_ranges = CommerceHelper::hasExtencion('lista_de_precios_por_rango_de_cantidad_vendida', null, $articles[0]['user_id']);

        $article_groups = ArticlePriceTypeGroup::with('articles')->get();

        foreach ($articles as $article) {

            if (!isset($article['is_promocion_vinoteca'])) {

                $price = Self::get_price($articles, $article, $has_price_ranges, $article_groups);

                Log::info('price para guardar: '.$price);

                $cart->articles()->attach($article['id'], [
                                            'price'         => $price,
                                            'cost'          => $article['cost'],
                                            'amount'        => $article['pivot']['amount'],
                                            'notes'         => $article['pivot']['notes'],
                                            'variant_id'    => isset($article['pivot']['variant_id']) ? $article['pivot']['variant_id'] : null,
                                            // 'color_id'      => $article['pivot']['color_id'],
                                            // 'color_id'   => ArticleHelper::getColorId($article),
                                            // 'size_id'    => ArticleHelper::getSizeId($article),
                                        ]);
            }
            
        }
    }

    static function attach_promociones_vinoteca($cart, $promociones_vinoteca) {
        
        foreach ($promociones_vinoteca as $promo) {

            // if (isset($promo['is_promocion_vinoteca'])) {

                $cart->promociones_vinoteca()->attach($promo['id'], [
                                            'price'         => $promo['final_price'],
                                            'cost'          => $promo['cost'],
                                            'amount'        => $promo['pivot']['amount'],
                                            'notes'         => $promo['pivot']['notes'],
                                        ]);
            // }
            
        }
    }

    static function set_total($cart) {
        $cart->load('articles');
        
        $total = 0;

        foreach ($cart->articles as $article) {
            $total += $article->pivot->price * $article->pivot->amount; 
        }

        foreach ($cart->promociones_vinoteca as $promo) {
            $total += $promo->pivot->price * $promo->pivot->amount; 
        }

        $cart->total = $total;
        $cart->save();
    }

    static function get_price($articles, $article, $has_price_ranges, $article_groups) {

        if ($has_price_ranges) {

            Log::info('has_price_ranges');
            return Self::get_price_range($articles, $article, $article_groups);
        }

        return $article['final_price'];
    }

    static function get_price_range($articles, $article, $article_groups) {

        $price = null;

        foreach ($article['ranges'] as $range) {

            $amount = Self::check_article_price_type_group($articles, $article, $article_groups);
            
            if (
                (
                    is_null($range['min'])
                    || $amount >= $range['min']
                )
                &&
                (
                    is_null($range['max'])
                    || $amount <= $range['max']
                )
            ) {
                Log::info('Entro con rango min: '.$range['min'].' y max: '.$range['max']);
                Log::info('rango price: '.$range['price']);
                $price = $range['price'];
            }
        }
        return $price;
    }

    static function check_article_price_type_group($articles, $article, $article_groups) {
        
        $amount = (float) $article['amount'];

        $group = $article_groups->first(function ($group) use ($article) {
            return $group->articles->contains('id', $article['id']);
        });

        $otrosArticulosRelacionados = [];

        if ($group) {
            foreach ($group->articles as $groupArticle) {
                $articleVendiendose = collect($articles)->firstWhere('id', $groupArticle->id);

                if ($articleVendiendose && $articleVendiendose['id'] != $article['id']) {
                    Log::info($article['name'].' comparte grupo con '.$articleVendiendose['name']);
                    $amount += (float) $articleVendiendose['amount'];
                    $otrosArticulosRelacionados[] = $articleVendiendose;
                }
            }
        }

        return $amount;
        // return [
        //     'total_amount' => $amount,
        //     'otros_articulos_relacionados' => $otrosArticulosRelacionados
        // ];
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
        $model->promociones_vinoteca = ArticleHelper::set_promociones_vinoteca($model->promociones_vinoteca);
        $model = Self::check_repetidos($model);

        return $model;
    }

    static function check_repetidos($cart) {
        $articulosAgrupados = $cart->articles
                ->groupBy('id')
                ->filter(function ($grupo) {
                    return $grupo->count() > 1; // Solo procesar duplicados
                });

        foreach ($articulosAgrupados as $articuloId => $articulosDuplicados) {
            
            // Obtener todas las relaciones duplicadas del artículo con este cart
            $relacionesDuplicadas = $articulosDuplicados->pluck('pivot.id');


            // Mantener solo una relación (la primera)
            $relacionAPreservar = $relacionesDuplicadas->shift();

            // Eliminar las relaciones duplicadas restantes
            DB::table('article_cart')
                ->where('cart_id', $cart->id)
                ->where('article_id', $articuloId)
                ->whereIn('id', $relacionesDuplicadas)
                ->delete();
        }
        return $cart;
    }

}
