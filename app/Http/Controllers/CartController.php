<?php

namespace App\Http\Controllers;

use App\Cart;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CartHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CartController extends Controller
{
    function lastCart($commerce_id) {
    	$last_cart = Cart::where('buyer_id', $this->buyerId())
    						->where('user_id', $commerce_id)
                            ->whereNull('order_id')
                            ->orderBy('created_at', 'DESC')
    						->first();
    	if (!is_null($last_cart)) {
            $last_cart = CartHelper::getFullModel($last_cart->id);
    		return response()->json(['has_last_cart' => true ,'last_cart' => $last_cart], 200);
    	} else {
    		return response()->json(['has_last_cart' => false], 200);
    	}
    }

    function fromOrder($order_id) {
        $cart = Cart::where('order_id', $order_id)
                    ->withAll()
                    ->first();
        $cart = CartHelper::getFullModel($cart->id);
        return response()->json(['cart' => $cart], 200);
    }

    function store(Request $request) {
    	$cart = Cart::create([
    		'buyer_id'          => $this->buyerId(),
            'user_id'           => $request->commerce_id,
    	]);

        CartHelper::attachArticles($cart, $request->cart['articles']);

    	$cart = CartHelper::getFullModel($cart->id); 

    	return response()->json(['cart' => $cart], 201);
    }

    function update(Request $request) {
    	$cart = Cart::find($request->id);
        $cart->delivery_zone_id     = $request->delivery_zone_id;
        $cart->payment_card_info_id = $request->payment_card_info_id;
        $cart->payment_method_id    = $request->payment_method_id;
        $cart->payment_id           = $request->payment_id;
        $cart->payment_status       = $request->payment_status;
        $cart->deliver              = $request->deliver;
        $cart->address_id           = $request->address_id;
        $cart->cupon_id             = $request->cupon_id;
        $cart->description          = $request->description;
        $cart->save();
    	$cart->articles()->sync([]);
        $cart_deleted = false;
        if (count($request->articles) >= 1) {
            CartHelper::attachArticles($cart, $request->articles);
        } else {
            $cart->delete();
            $cart_deleted = true;
        }
        if (!$cart_deleted) {
            $cart = CartHelper::getFullModel($cart->id);
            return response()->json(['cart' => $cart], 200);
        }
        return response()->json(['cart' => null], 200);
    }

    function delete($cart_id) {
        $cart = Cart::find($cart_id);
        $cart->articles()->sync([]);
        $cart->delete();
        return response(null, 200);
    }
}
