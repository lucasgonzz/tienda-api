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
        if (env('APP_ENV') == 'local') {
            // sleep(3);
        }
    	$cart = Cart::create([
    		'buyer_id'          => $this->buyerId(),
            'user_id'           => $request->commerce_id,
    	]);

        CartHelper::attachArticles($cart, $request->cart['articles']);
        CartHelper::attach_promociones_vinoteca($cart, $request->cart['promociones_vinoteca']);

        CartHelper::set_total($cart);

    	$cart = CartHelper::getFullModel($cart->id); 

    	return response()->json(['cart' => $cart], 201);
    }

    function update(Request $request) {
        if (env('APP_ENV') == 'local') {
            // sleep(3);
        }
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
        $cart->fecha_entrega        = $request->fecha_entrega;
        $cart->save();
        CartHelper::checkPaymentStatus($cart);
        $cart->articles()->sync([]);
    	$cart->promociones_vinoteca()->sync([]);
        $cart_deleted = false;

        if (
            count($request->articles) >= 1
            || count($request->promociones_vinoteca) >= 1
        ) {
            
            if (count($request->articles) >= 1) {
                CartHelper::attachArticles($cart, $request->articles);
            } 
            
            if (count($request->promociones_vinoteca) >= 1) {
                CartHelper::attach_promociones_vinoteca($cart, $request->promociones_vinoteca);
            }
            
            CartHelper::set_total($cart);
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

    function update_article_amount(Request $request, $cart_id) {
        $cart = Cart::find($cart_id);

        if ($request->is_promocion_vinoteca) {

            $cart->promociones_vinoteca()->updateExistingPivot($request->id, [
                'amount'    => $request->amount,
            ]);

        } else {

            $cart->articles()->updateExistingPivot($request->id, [
                'amount'    => $request->amount,
            ]);
        }
        

        CartHelper::set_total($cart);

        $cart = CartHelper::getFullModel($cart->id);
        return response()->json(['cart' => $cart], 200);
    }

    function delete($cart_id) {
        $cart = Cart::find($cart_id);
        $cart->articles()->sync([]);
        $cart->promociones_vinoteca()->sync([]);
        $cart->delete();
        return response(null, 200);
    }
}
