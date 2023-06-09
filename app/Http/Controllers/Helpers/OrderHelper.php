<?php

namespace App\Http\Controllers\Helpers;

use App\Cart;
use App\Cupon;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Order;
use App\Payment;
use App\User;
use Illuminate\Support\Facades\Log;


class OrderHelper
{

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
        return !is_null($cart->payment_method->discount) ? $cart->payment_method->discount : null;
    }

    static function getPaymentMethodSurchage($cart) {
        return !is_null($cart->payment_method->surchage) ? $cart->payment_method->surchage : null;
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
            Log::info('cart pivot price: '.$article->pivot->price);
            $price = $article->pivot->price;
            $user = User::find($order->user_id);
            if (!is_null($user->online_configuration->online_price_surchage)) {
                $price += $price * (float)$user->online_configuration->online_price_surchage / 100;
            }
            $order->articles()->attach([$article->id => [
                                            'amount'      => $article->pivot->amount,
                                            'cost'        => $article->pivot->cost,
                                            'price'       => $price,
                                            'variant_id'  => $article->pivot->variant_id,
                                        ]]);
        }
    }
}
