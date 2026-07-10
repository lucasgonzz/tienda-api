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

        // Persistir opciones de checkout elegidas antes de confirmar (envío/retiro, pago, etc.)
        $this->sync_checkout_fields($cart, $request->cart);
        $cart->save();

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
        $this->sync_checkout_fields($cart, $request->all());
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
        if (is_null($cart)) {
            // El carrito ya no existe (ej: carrito de invitado que nunca se
            // persistio, o ya fue borrado antes). No hay nada que hacer.
            return response(null, 200);
        }
        $cart->articles()->sync([]);
        $cart->promociones_vinoteca()->sync([]);
        $cart->delete();
        return response(null, 200);
    }

    /**
     * Copia al carrito los campos del paso de checkout (entrega, pago, notas, etc.).
     *
     * @param \App\Cart $cart
     * @param array $data Payload del carrito enviado por tienda-spa
     * @return void
     */
    function sync_checkout_fields($cart, $data) {
        $cart->delivery_zone_id     = isset($data['delivery_zone_id']) ? $data['delivery_zone_id'] : null;
        $cart->payment_card_info_id = isset($data['payment_card_info_id']) ? $data['payment_card_info_id'] : null;
        $cart->payment_method_id    = isset($data['payment_method_id']) ? $data['payment_method_id'] : null;
        $cart->payment_id           = isset($data['payment_id']) ? $data['payment_id'] : null;
        $cart->payment_status       = isset($data['payment_status']) ? $data['payment_status'] : null;
        $cart->deliver              = isset($data['deliver']) ? $data['deliver'] : 0;
        $cart->address_id           = isset($data['address_id']) ? $data['address_id'] : null;
        $cart->cupon_id             = isset($data['cupon_id']) ? $data['cupon_id'] : null;
        $cart->description          = isset($data['description']) ? $data['description'] : null;
        $cart->fecha_entrega        = !empty($data['fecha_entrega']) ? $data['fecha_entrega'] : null;
    }
}
