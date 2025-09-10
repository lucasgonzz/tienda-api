<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Cart;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CartHelper;
use App\Http\Controllers\Helpers\MessageHelper;
use App\Http\Controllers\Helpers\OrderHelper;
use App\Http\Controllers\Helpers\StringHelper;
use App\Jobs\SendOrderCreatedEmail;
use App\Mail\OrderCreated;
use App\Order;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{

    function index() {
        $orders = Order::where('buyer_id', $this->buyerId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll()
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
                        ->orderBy('id', 'DESC')
                        ->where('user_id', $commerce_id)
                        ->with('articles', 'buyer', 'promociones_vinoteca')
                        ->first();
        return response()->json(['order' => $order], 200);
    }

    function store(Request $request) {
        if ($this->no_es_denuevo_por_mercadopago($request)) {
            // $buyer_id = OrderHelper::getBuyerId($request);

            // Log::info('request:');
            // Log::info($request);
            $cart = Cart::find($request->cart_id);
            Log::info('Fecha entrega carrito: '.$cart->fecha_entrega);
        	$order = Order::create([
                'num'                       => $this->num('orders', $request->commerce_id),
                'buyer_id'                  => $request->buyer_id ? $request->buyer_id : $this->buyerId(),
                'seller_id'                 => $request->seller_id ? $request->seller_id : null,
        		// 'buyer_id'                  => $buyer_id,
        		'user_id'                   => $request->commerce_id,
                // 'status'                    => 'unconfirmed',
                'payment_id'                => $cart->payment_id,
                'payment_card_info_id'      => $cart->payment_card_info_id,
                'payment_method_id'         => $cart->payment_method_id,
                'delivery_zone_id'          => $cart->delivery_zone_id,
                'cupon_id'                  => $cart->cupon_id,
        		'percentage_card'           => null,
        		'deliver'                   => $cart->deliver,
                'description'               => $cart->description,
                'order_status_id'           => $this->getModelBy('order_statuses', 'name', 'Sin confirmar', false, 'id'),
                'payment_method_discount'   => OrderHelper::getPaymentMethodDiscount($cart),
                'payment_method_surchage'   => OrderHelper::getPaymentMethodSurchage($cart),
                'address_id'                => OrderHelper::getAddressId($cart),
                'total'                     => $cart->total,
                'fecha_entrega'             => $cart->fecha_entrega,
                'address'                   => $this->get_address($request),
        	]);

            Log::info('order address:');
            Log::info($order->address);


            $cart = CartHelper::getFullModel($cart->id);

            Log::info('Se van a agregar estos articulos al pedido N° '.$order->num);
            foreach ($cart->articles as $article) {
                Log::info($article->name.'. Cantidad: '.$article->pivot->amount.'. Notas: '.$article->pivot->notes);
            }
            
            OrderHelper::attachArticles($cart, $order, $request->dolar_blue);
            OrderHelper::attachPromocionesVinoteca($cart, $order, $request->dolar_blue);
            OrderHelper::updateCurrentCart($cart, $order);
            OrderHelper::deleteOrderCart($cart);

            $order = Order::where('id', $order->id)
                            ->withAll()
                            ->first();
            $order->articles = ArticleHelper::setArticlesVariants($order->articles);
            
            // MessageHelper::sendOrderCreatedMessage($order);

            if (
                !env('NO_ENVIAR_MAILS', false)
                && !is_null($order->user->email)
            ) {
                Log::info('enviando mail');
                if (env('APP_ENV') == 'production') {
                    // Mail::to($order->user)->send(new OrderCreated($order));
                    SendOrderCreatedEmail::dispatch($order, $order->user->email)->onQueue('ecommerce');
                }
            }

            Log::info('Termino');
        	return response(null, 201);
        }
        return response(null, 200);
    }

    function get_address($request) {
        if ($request['selected_buyer']) {

            if (isset($request['selected_buyer']['comercio_city_client'])) {
                return $request['selected_buyer']['comercio_city_client']['address'];
            }

        }
        if ($request['buyer']['address']) {
            return $request['buyer']['address'];
        }
        if ($request['buyer']['comercio_city_client']) {
            Log::info('retun address del comercio_city_client:');
            Log::info($request['buyer']['comercio_city_client']['address']);
            return $request['buyer']['comercio_city_client']['address'];
        }
        return null;
    }


    function no_es_denuevo_por_mercadopago($request) {
        $cart_ya_guardado = Cart::find($request->cart_id);
        return !is_null($cart_ya_guardado);
    }
}
