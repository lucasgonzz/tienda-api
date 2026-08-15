<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Cart;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CartHelper;
use App\Http\Controllers\Helpers\CartOwnershipHelper;
use App\Http\Controllers\Helpers\MessageHelper;
use App\Http\Controllers\Helpers\OrderHelper;
use App\Http\Controllers\Helpers\StringHelper;
use App\Jobs\BroadcastOrderCreated;
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

        // 'buyer.comercio_city_client' eager-loaded para que el fallback de teléfono
        // del mensaje de WhatsApp en Thanks.vue tenga el dato disponible
        $order = Order::where('user_id', $commerce_id)
                        ->orderBy('id', 'DESC')
                        ->with('articles', 'buyer.comercio_city_client', 'promociones_vinoteca');

        if (!is_null($buyer_id)) {
            $order = $order->where('buyer_id', $buyer_id);
        } else {
            // Sin sesión de cuenta, el único pedido que esta sesión puede ver es el que ELLA
            // acaba de crear. Es el caso del comprador que hizo checkout con una cuenta que tiene
            // contraseña: BuyerController@store ya no lo loguea (era una toma de cuenta por
            // email), así que acá no hay buyer_id — pero la página de gracias tiene que andar
            // igual. Resolverlo por buyer_id sería reabrir la puerta que se acaba de cerrar.
            $pedidos = $this->pedidosDeLaSesion();

            if (empty($pedidos)) {
                return response()->json(['order' => null], 200);
            }

            $order = $order->whereIn('id', $pedidos);
        }

        return response()->json(['order' => $order->first()], 200);
    }

    /**
     * Crea el pedido.
     *
     * 🔴 Dos agujeros que tenia, y ninguno se arreglaba con auth:buyer solo:
     *
     *   1. `'buyer_id' => $request->buyer_id ? ... : ...` — el comprador al que se le atribuye el
     *      pedido venia DEL REQUEST. Cualquiera podia crear un pedido a nombre de otro.
     *      Ahora el buyer_id del request solo se respeta si quien pide es un VENDEDOR
     *      (buyers.seller_id no nulo), que es el unico caso legitimo: el vendedor de Golonorte
     *      cargando el pedido de un cliente (mixins/cart.js:268 lo manda siempre, por eso se
     *      IGNORA en vez de rechazar el request — asi el SPA viejo cacheado no se rompe).
     *   2. `Cart::find($request->cart_id)` sin comprobar de quien era el carrito: se podia
     *      convertir el carrito de otro en un pedido.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    function store(Request $request) {
        if ($this->no_es_denuevo_por_mercadopago($request)) {
            // $buyer_id = OrderHelper::getBuyerId($request);

            // Log::info('request:');
            // Log::info($request);
            $cart = Cart::find($request->cart_id);

            if (!CartOwnershipHelper::puede($cart)) {
                Log::warning('OrderController@store: se intento crear un pedido con un carrito ajeno', [
                    'cart_id' => $request->cart_id,
                ]);
                return response(null, 403);
            }

            $buyer_id = $this->resolverBuyerIdDelPedido($request);

            // orders.buyer_id es NOT NULL: sin identidad el insert reventaba en 500. Se corta
            // antes y se dice que pasa.
            if (is_null($buyer_id)) {
                return response()->json(['error' => 'No hay comprador identificado para este pedido'], 401);
            }

            Log::info('Fecha entrega carrito: '.$cart->fecha_entrega);
        	$order = Order::create([
                'num'                       => $this->num('orders', $request->commerce_id),
                'buyer_id'                  => $buyer_id,
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

            // Aviso por broadcast al sistema del comercio (empresa-spa) de que entro un pedido
            // nuevo. Es el reemplazo de la linea comentada mas arriba
            // (MessageHelper::sendOrderCreatedMessage): aquella ademas le mandaba un correo al
            // comprador y escribia en `messages`, y por eso rompia el guardado del pedido. Esta
            // emite SOLO por broadcast (ver App\Notifications\OrderCreated).
            //
            // 🔴 Va ULTIMO, despues del despacho de los mails, y el orden NO es indistinto:
            // los dos son callbacks de Application::terminate(), que los corre en un while sin
            // try/catch, o sea que si uno tira los siguientes NO corren. Ademas, con
            // QUEUE_CONNECTION=sync el envio a Pusher es sincronico y su cliente Guzzle tiene
            // timeout de 30 segundos: si el aviso fuera primero, un Pusher caido le meteria esa
            // espera al mail de confirmacion del comprador, o se lo llevaria puesto si el proceso
            // muere antes. El aviso es para la pantalla del comercio; el mail es del comprador, y
            // el comprador va primero.
            //
            // El try/catch de aca cubre solo el REGISTRO del callback (dispatchAfterResponse no
            // ejecuta nada: solo apila un closure en terminating()). La proteccion real vive en el
            // catch de BroadcastOrderCreated::handle(), que es donde corre el aviso. Se deja igual
            // porque el pedido YA esta creado a esta altura y nada del aviso puede devolverle un
            // error al comprador.
            try {
                BroadcastOrderCreated::dispatchAfterResponse($order->id, $order->num, $order->user_id);
            } catch (\Throwable $e) {
                Log::error('OrderController@store: fallo el despacho del aviso por broadcast, el pedido igual se creo bien', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }

            Log::info('Termino');

            // Se registra el pedido como propio de esta sesion. Lo necesita la pagina de gracias
            // cuando la compra se hizo con identidad de checkout y no con sesion de cuenta: ver
            // current() mas arriba y Controller@checkoutBuyerId.
            $pedidos = $this->pedidosDeLaSesion();
            $pedidos[] = (int) $order->id;
            session()->put(self::CLAVE_PEDIDOS, array_values(array_slice(array_unique($pedidos), -10)));

            // Devuelve el id del pedido recien creado. Antes esto era un `response(null, 201)`
            // con el cuerpo VACIO, y eso dejaba ciego al evento `checkout_complete` del tracking
            // (mision tracking-buyers-tienda) justo en el medio de pago dominante: en la rama de
            // Mercado Pago el SPA (mixins/cart.js) nunca llama a getCurrentOrder, asi que no tenia
            // de donde sacar el id y el evento se iba sin order_id. Sin order_id no hay como atar
            // la venta al recorrido del comprador, que es lo unico que cierra el embudo.
            //
            // El cambio es ESTRICTAMENTE ADITIVO y por eso es admisible tocar este controller:
            // pasar de cuerpo vacio a cuerpo con una clave no rompe al SPA viejo (ignora el
            // cuerpo del 201) ni al SPA nuevo contra una API vieja (lee el id de forma
            // defensiva). El status sigue siendo 201 y no se saca ni se renombra nada.
        	return response()->json(['order_id' => $order->id], 201);
        }
        return response(null, 200);
    }

    /**
     * Decide a que comprador se le atribuye el pedido.
     *
     * El `buyer_id` del request SOLO se respeta si quien pide es un vendedor. Es el unico caso
     * legitimo en el que alguien carga un pedido a nombre de otro: el vendedor de Golonorte
     * eligiendo un cliente en components/payment/components/SellerSelectClient.vue.
     *
     * Para todos los demas se usa la identidad de la sesion: el comprador logueado, o la identidad
     * de checkout que dejo BuyerController@store para el invitado.
     *
     * ⚠️ Se IGNORA el buyer_id ajeno en vez de rechazar el request a proposito. mixins/cart.js:268
     * lo manda SIEMPRE (viene del store del carrito, que es null salvo en el flujo de vendedor),
     * asi que rechazar rompería al SPA viejo cacheado en cuanto ese valor viniera con algo.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int|null
     */
    function resolverBuyerIdDelPedido($request) {
        $propio = $this->checkoutBuyerId();

        if ($request->buyer_id) {
            $vendedor = $this->buyer();

            if (!is_null($vendedor) && !is_null($vendedor->seller_id)) {
                /*
                 * 🔴 Ser vendedor NO alcanza: hay que comprobar que el comprador sea de SU
                 * comercio. Sin esto, un vendedor del comercio A carga un pedido a nombre de un
                 * cliente del comercio B, y como la tienda comparte base con el ERP, ese pedido
                 * confirmado termina en una venta contra la cuenta corriente de esa persona.
                 *
                 * Es el mismo criterio que BuyerController@search: el comercio sale del vendedor,
                 * nunca del request. Ese se aplico bien y este se habia olvidado — lo encontro la
                 * revision independiente del diff.
                 */
                $comprador = Buyer::find($request->buyer_id);

                if (!is_null($comprador) && (int) $comprador->user_id === (int) $vendedor->user_id) {
                    return (int) $comprador->id;
                }

                Log::warning('OrderController@store: un vendedor intento cargar un pedido a nombre de un comprador de otro comercio', [
                    'buyer_id_pedido'  => $request->buyer_id,
                    'comercio_pedido'  => is_null($comprador) ? null : $comprador->user_id,
                    'comercio_vendedor'=> $vendedor->user_id,
                ]);

                return $propio;
            }

            Log::warning('OrderController@store: se ignoro un buyer_id del request de alguien que no es vendedor', [
                'buyer_id_pedido' => $request->buyer_id,
                'buyer_id_real'   => $propio,
            ]);
        }

        return $propio;
    }

    /**
     * Resuelve la dirección que se guarda en el pedido.
     *
     * Prioridad 1: el campo `address` de primer nivel del request. Es la dirección que el
     * comprador VIO en el formulario del checkout y aceptó al confirmar. Gana siempre, incluso
     * si el Buyer o el Client del ERP tienen otra guardada: si el comprador escribió "Sarmiento
     * 123", el pedido va a "Sarmiento 123" y no a donde vivía hace dos años.
     *
     * El resto son fallbacks defensivos para requests que no traigan ese campo (SPA viejo
     * cacheado, reintentos de Mercado Pago). No se eliminan, pero en el flujo normal no se usan.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    function get_address($request) {
        // Prioridad 1: el campo address de primer nivel del request
        // (la dirección que el comprador vio y escribió en el formulario del checkout)
        if (isset($request['address']) && trim($request['address']) !== '') {
            return trim($request['address']);
        }

        // Fallbacks defensivos para requests antiguos o reintentos que no traigan address
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
