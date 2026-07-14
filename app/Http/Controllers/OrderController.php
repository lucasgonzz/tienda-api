<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Cart;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CartHelper;
use App\Http\Controllers\Helpers\MessageHelper;
use App\Http\Controllers\Helpers\OrderHelper;
use App\Http\Controllers\Helpers\StringHelper;
use App\Jobs\SendOrderEmails;
use App\Order;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{

    /**
     * Lista paginada de pedidos del buyer autenticado.
     * Si no hay sesión de buyer activa (buyerId() === null), Eloquent convertiría
     * el where('buyer_id', null) en whereNull('buyer_id') y devolvería pedidos de
     * OTROS compradores (fuga de datos). Por eso se corta antes con un guard explícito.
     * @return \Illuminate\Http\JsonResponse ['orders' => paginación de Order, o estructura vacía si no hay sesión]
     */
    function index() {
        // ID del buyer autenticado (null si no hay sesión activa en el guard 'buyer')
        $buyer_id = $this->buyerId();

        // Guard: sin buyer autenticado no se ejecuta la query, se responde vacío directamente
        if (is_null($buyer_id)) {
            return response()->json(['orders' => ['data' => []]], 200);
        }

        $orders = Order::where('buyer_id', $buyer_id)
                        ->orderBy('created_at', 'DESC')
                        ->withAll()
                        ->paginate(6);
        return response()->json(['orders' => $orders], 200);
    }

    /**
     * Devuelve el pedido confirmado del buyer autenticado para un comercio.
     * Mismo guard que index()/current(): evita el whereNull('buyer_id') implícito
     * cuando no hay sesión de buyer activa.
     * @param int $commerce_id ID del comercio (user_id) al que pertenece el pedido
     * @return \Illuminate\Http\JsonResponse ['order' => Order|null]
     */
    function confirmed($commerce_id) {
        // ID del buyer autenticado (null si no hay sesión activa)
        $buyer_id = $this->buyerId();

        // Guard: sin buyer autenticado, nunca se ejecuta la query con buyer_id null
        if (is_null($buyer_id)) {
            return response()->json(['order' => null], 200);
        }

        $order = Order::where('buyer_id', $buyer_id)
                        ->where('user_id', $commerce_id)
                        ->where('status', 'confirmed')
                        ->first();
        // $order->articles = ArticleHelper::setArticlesKeyAndVariant($order->articles);
        return response()->json(['order' => $order], 200);
    }

    /**
     * Devuelve el último pedido del buyer autenticado para un comercio (usado en la
     * página de "gracias" del checkout y para armar el mensaje de WhatsApp).
     * Guard explícito: si no hay buyer autenticado (por ejemplo, checkout de invitado
     * que hace logout apenas creado el pedido), se responde 200 con order null en vez
     * de dejar que Eloquent resuelva where('buyer_id', null) como whereNull('buyer_id')
     * y devuelva el pedido de otro comprador.
     * Se devuelve 200 (no 401) a propósito: el SPA trata order === null como estado
     * válido y no debe disparar el interceptor de sesión expirada.
     * @param int $commerce_id ID del comercio (user_id) al que pertenece el pedido
     * @return \Illuminate\Http\JsonResponse ['order' => Order|null]
     */
    function current($commerce_id) {
        // ID del buyer autenticado (null si no hay sesión activa en el guard 'buyer')
        $buyer_id = $this->buyerId();

        // Guard: sin buyer autenticado no se ejecuta la query, se responde order null
        if (is_null($buyer_id)) {
            return response()->json(['order' => null], 200);
        }

        // 'buyer.comercio_city_client' eager-loaded para que el fallback de teléfono
        // del mensaje de WhatsApp en Thanks.vue tenga el dato disponible
        $order = Order::where('buyer_id', $buyer_id)
                        ->where('user_id', $commerce_id)
                        ->orderBy('id', 'DESC')
                        ->with('articles', 'buyer.comercio_city_client', 'promociones_vinoteca')
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
                'address_id'                => 0,
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

            // Mails del pedido (aviso al comercio + confirmacion al comprador). Se despachan
            // DESPUES de la respuesta HTTP: el comprador ve su confirmacion al instante y no espera
            // los round-trips de SMTP. Que se envien o no, y a que casillas, lo decide la
            // Configuracion Online del comercio (ya no hay ninguna variable de entorno de por medio).
            //
            // El try/catch es deliberadamente redundante con el que ya tiene SendOrderEmails::handle():
            // el pedido YA esta creado en la base a esta altura, y bajo ninguna circunstancia un
            // problema con el correo puede devolver un error al comprador y dejarlo sin saber si su
            // compra entro (bug real: rompia el checkout en tienda-spa).
            try {
                SendOrderEmails::dispatchAfterResponse($order->id);
            } catch (\Exception $e) {
                Log::error('OrderController@store: fallo el despacho de los mails, el pedido igual se creo bien', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
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
