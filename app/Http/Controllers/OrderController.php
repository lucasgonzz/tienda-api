<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Cart;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CartHelper;
use App\Http\Controllers\Helpers\MessageHelper;
use App\Http\Controllers\Helpers\OrderHelper;
use App\Http\Controllers\Helpers\StringHelper;
use App\Notifications\OrderCreated;
use App\Order;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{

    function index() {
        $orders = Order::where('buyer_id', $this->buyerId())
                        ->orderBy('created_at', 'DESC')
                        ->with('articles.images')
                        ->paginate(6);
        return response()->json(['orders' => $orders], 200);
    }

    function confirmed($commerce_id) {
        $order = Order::where('buyer_id', $this->buyerId())
                        ->where('user_id', $commerce_id)
                        ->where('status', 'confirmed')
                        ->first();
        // $order->articles = ArticleHelper::setArticlesKeyAndVariant($order->articles);
        return response()->json(['order' => $order], 200);
    }

    function current($commerce_id) {
        $order = Order::where('buyer_id', $this->buyerId())
                        ->where('user_id', $commerce_id)
                        ->where(['status' => ['unconfirmed', 'confirmed']])
                        ->first();
        return response()->json(['order' => $order], 200);
    }

    function store(Request $request) {
        $cart = Cart::find($request->cart_id);
    	$order = Order::create([
            'num'               => $this->num('orders', $request->commerce_id),
    		'buyer_id'          => $this->buyerId(),
    		'user_id'           => $request->commerce_id,
            'status'            => 'unconfirmed',
            'payment_id'        => $cart->payment_id,
            'payment_method_id' => $cart->payment_method_id,
            'delivery_zone_id'  => $cart->delivery_zone_id,
            'cupon_id'          => $cart->cupon_id,
    		'percentage_card'   => null,
    		'deliver'           => $cart->deliver,
            'description'       => $cart->description,
            'order_status_id'   => $this->getModelBy('order_statuses', 'name', 'Sin confirmar', false, 'id'),
            'address_id'        => OrderHelper::getAddressId($cart),
    	]);

        $cart = CartHelper::getFullModel($cart->id);
        OrderHelper::attachArticles($cart, $order, $request->dolar_blue);
        OrderHelper::updateCurrentCart($cart, $order);
        OrderHelper::deleteOrderCart($cart);

        $order = Order::where('id', $order->id)
                        ->withAll()
                        ->first();
        $order->articles = ArticleHelper::setArticlesRelationsFromPivot($order->articles);
        MessageHelper::sendOrderCreatedMessage($order);
        $this->sendAddModelNotification('order', $order->id, false, $order->user_id);
        Auth::guard('buyer')->user()->notify(new OrderCreated($order));
    	return response(null, 201);
    }
}
