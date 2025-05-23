<?php

namespace App\Http\Controllers\Helpers;

use App\Buyer;
use App\Cart;
use App\Cupon;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Order;
use App\Payment;
use App\User;
use Illuminate\Support\Facades\Log;


class OrderHelper {

    static function getBuyerId($request) {
        $buyer_id = null;
        $commerce = User::find($request->commerce_id);
        if (!$commerce->online_configuration->register_to_buy) {
            if (!isset($request->buyer['id'])) {
                $buyer = Buyer::create([
                    'name'   => $request->buyer['name'],
                    'email'  => $request->buyer['email'],
                    'phone'  => $request->buyer['phone'],
                ]);
                $buyer_id = $buyer->id;
            } else {
                $buyer_id = $request->buyer['id'];
            }
        } else {
            $buyer_id = UserHelper::buyerId();
        }
        return $buyer_id;
    }

    static function updateCurrentCart($cart, $order) {
        $cart = Cart::find($cart->id);
        if ($cart) {
            $cart->order_id = $order->id;
            $cart->save();
        }
    }

    static function attachCupons($cart, $order) {
        Log::info('Cupones del carrito: '.count($cart->cupons));
        foreach ($cart->cupons as $cupon) {
            $order->cupons()->attach($cupon->id);
        }
    }

    // static function setArticlesKeyAndVariant($orders) {
    //     foreach ($orders as $order) {
    //         $order->articles = ArticleHelper::setArticlesKeyAndVariant($order->articles);
    //     }
    //     return $orders;
    // }

    static function getAddressId($cart) {
        return $cart->deliver ? $cart->address_id : null;
    }

    static function getPaymentMethodDiscount($cart) {
        return !is_null($cart->payment_method) && !is_null($cart->payment_method->discount) ? $cart->payment_method->discount : null;
    }

    static function getPaymentMethodSurchage($cart) {
        return !is_null($cart->payment_method) && !is_null($cart->payment_method->surchage) ? $cart->payment_method->surchage : null;
    }

    static function deleteOrderCart($cart) {
        if (!is_null($cart->order_id)) {
            $order = Order::find($cart->order_id);
            if (!is_null($order)) {
                $order->articles()->detach();
                $order->delete();
            }
        }
    }

    static function updateOrderPayment($cart, $order) {
        if ($cart['payment_method'] == 'tarjeta') {
            $payment = Payment::find($cart['payment_id']);
            $payment->order_id = $order->id;
            $payment->save();
        }
    }

    static function attachArticles($cart, $order, $dolar_blue) {
        foreach ($cart->articles as $article) {
            // Log::info('cart pivot price: '.$article->pivot->price);
            $price = $article->pivot->price;
            $user = User::find($order->user_id);
            if (!is_null($user->online_configuration->online_price_surchage)) {
                $price += $price * (float)$user->online_configuration->online_price_surchage / 100;
            }

            if (!Self::articulo_ya_cargado($order, $article)) {
                $order->articles()->attach([$article->id => [
                                                'amount'      => $article->pivot->amount,
                                                'cost'        => $article->pivot->cost,
                                                'notes'       => $article->pivot->notes,
                                                'price'       => $price,
                                                'variant_id'  => $article->pivot->variant_id,
                                            ]]);
            }
            // Log::info($article->name.' variant_id: '.$article->pivot->variant_id);
        }
    }

    static function attachPromocionesVinoteca($cart, $order, $dolar_blue) {

        foreach ($cart->promociones_vinoteca as $promo) {

            $price = $promo->pivot->price;

            if (!Self::promo_ya_cargada($order, $promo)) {
                $order->promociones_vinoteca()->attach([$promo->id => [
                                                'amount'      => $promo->pivot->amount,
                                                'cost'        => $promo->pivot->cost,
                                                'notes'       => $promo->pivot->notes,
                                                'price'       => $price,
                                            ]]);
            }
            // Log::info($article->name.' variant_id: '.$article->pivot->variant_id);
        }
    }

    static function promo_ya_cargada($order, $promo) {
        $order->load('promociones_vinoteca');
        $order_promo = $order->promociones_vinoteca()->where('promocion_vinoteca_id', $promo->id)->first();
        return !is_null($order_promo);
    }

    static function articulo_ya_cargado($order, $article) {
        $order->load('articles');
        $order_article = $order->articles()->where('article_id', $article->id)->first();
        return !is_null($order_article);
    }
}
