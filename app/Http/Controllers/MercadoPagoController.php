<?php

namespace App\Http\Controllers;

use App\Cart;
use App\Http\Controllers\Helpers\CartOwnershipHelper;
use App\Http\Controllers\Helpers\MercadoPagoCredentialsHelper;
use App\Http\Controllers\Helpers\OnlinePaymentHelper;
use App\Order;
use App\PaymentMethod;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoPagoController extends Controller
{
    /** API de pagos de Mercado Pago: de aca se lee el estado REAL de un pago. */
    const API_PAGOS = 'https://api.mercadopago.com/v1/payments/';

    /**
     * Con que access token se arma la preferencia de este carrito.
     *
     * Esta afuera de `preference()` a proposito: es la unica parte de ese metodo que se puede
     * probar sin salir a la red (`$preference->save()` hace un POST a api.mercadopago.com). Los
     * tests la invocan por reflexion, igual que
     * `empresa-api/tests/Feature/Integraciones/IntegracionesMercadoPagoTest.php` hace con el
     * resolvedor de state del OAuth.
     *
     * @param int $user_id Comercio (owner) que cobra.
     * @param \App\PaymentMethod|null $payment_method Fila de `payment_methods` que eligio el comprador.
     * @return string|null
     */
    protected function access_token_para_cobrar($user_id, $payment_method)
    {
        $credentials = MercadoPagoCredentialsHelper::credentials_for_payment_method($user_id, $payment_method);

        return $credentials['access_token'];
    }

    /**
     * Crea la preferencia de pago de Mercado Pago del carrito.
     *
     * 🔴 EL ACCESS TOKEN SALE DE `MercadoPagoCredentialsHelper`, no derecho de la fila que manda
     * el SPA. Es el mismo orden que usa `empresa-api` (conector conectado -> `payment_methods`),
     * y tiene que seguir siendolo: la base es compartida, asi que si los dos repos resolvieran
     * distinto, un comercio cobraria con una cuenta en el ERP y con otra en la tienda.
     *
     * El fallback no se saca. Hay clientes en produccion cuya unica credencial de Mercado Pago
     * vive en `payment_methods`, y `empresa` y `tienda` nunca llegan a produccion el mismo dia:
     * durante toda esa ventana el conector puede no existir todavia.
     *
     * Lo que se le manda a Mercado Pago ademas de los items (URLs de retorno, retorno automatico,
     * webhook y referencia al carrito) sale de `datos_de_preferencia()`, que es lo que se puede
     * probar sin red.
     *
     * @param Request $request payment_method (fila elegida), cupon, delivery_zone, articles, cart_id (opcional).
     * @return \Illuminate\Http\JsonResponse
     */
    function preference(Request $request) {
        $this->payment_method = PaymentMethod::find($request->payment_method['id']);
        $this->commerce = User::find($request->payment_method['user_id']);

        $access_token = $this->access_token_para_cobrar(
            $request->payment_method['user_id'],
            $this->payment_method
        );

        if (empty($access_token)) {
            Log::error('MercadoPagoController@preference: el comercio '.$request->payment_method['user_id'].' no tiene credencial de Mercado Pago');
            return response()->json([
                'message' => 'El comercio no tiene una cuenta de Mercado Pago conectada.',
            ], 422);
        }

        \MercadoPago\SDK::setAccessToken($access_token);

        // Crea un objeto de preferencia
        $preference = new \MercadoPago\Preference();

        $online_payment_helper = new OnlinePaymentHelper($this->commerce, $this->payment_method);

        $articles = $online_payment_helper->setPrices($request->cupon, $request->delivery_zone, $request->articles);

        $items = [];
        foreach ($articles as $article) {
            // Crea un ítem en la preferencia
            $item = new \MercadoPago\Item();
            $item->title = $article['name'];
            $item->quantity = $article['amount'];
            $item->unit_price = $article['final_price'];
            $items[] = $item;
        }
        $preference->items = $items;

        $cart = $this->carrito_del_pago($request);

        foreach ($this->datos_de_preferencia($request, $this->commerce, $cart) as $campo => $valor) {
            $preference->{$campo} = $valor;
        }

        $preference->save();

        Log::info('MercadoPagoController@preference: preferencia creada', [
            'commerce_id'   => $this->commerce->id,
            'cart_id'       => $cart ? $cart->id : null,
            'preference_id' => $preference->id,
        ]);

        return response()->json(['preference_id' => $preference->id], 201);
    }

    /**
     * El carrito que se esta pagando, si el SPA lo mando y es de quien esta pidiendo.
     *
     * `cart_id` es OPCIONAL: un SPA viejo no lo manda y la preferencia se arma igual, solo que sin
     * referencia al carrito (y entonces el webhook no tiene a que pedido atarle el pago, que es
     * exactamente lo que pasaba antes de esta mision). La propiedad se valida con el mismo criterio
     * que `CartController@update`: sin eso cualquiera podria colgar su pago del carrito de otro.
     *
     * @param Request $request
     * @return \App\Cart|null
     */
    protected function carrito_del_pago(Request $request)
    {
        $cart_id = $request->input('cart_id');

        if (empty($cart_id) || !is_numeric($cart_id)) {
            return null;
        }

        $cart = Cart::find((int) $cart_id);

        if (!CartOwnershipHelper::puede($cart)) {
            Log::warning('MercadoPagoController@preference: se pidio una preferencia con un carrito ajeno, se arma sin referencia', [
                'cart_id' => $cart_id,
            ]);

            return null;
        }

        return $cart;
    }

    /**
     * Lo que viaja en la preferencia ademas de los items.
     *
     * - `back_urls` + `auto_return`: a donde vuelve el comprador despues de pagar. Solo en
     *   produccion, porque Mercado Pago exige que sean URLs publicas y en local no las hay. Se
     *   miran por `app()->environment()` y no por `env('APP_ENV')`: con la configuracion cacheada
     *   `env()` devuelve null y las URLs de retorno desaparecian en silencio. `auto_return` solo
     *   tiene sentido con `back_urls.success` (sin ella Mercado Pago rechaza la preferencia), por
     *   eso van juntas. Si el comercio no tiene `users.online` cargado, no se manda ninguna de las
     *   dos: es mejor que el comprador tenga que apretar "Volver al sitio" que mandarlo a una URL
     *   que no existe.
     * - `notification_url`: el webhook de este mismo backend (`webhook()`), con el comercio en la
     *   query. Sin esto la unica confirmacion del pago era la que traia el navegador del comprador
     *   en la query string de `/pago-exitoso`, que cualquiera puede escribir a mano.
     * - `external_reference` y `metadata.cart_id`: el carrito, para que el webhook sepa a que
     *   pedido atarle el pago. Van los dos porque Mercado Pago devuelve `external_reference`
     *   siempre y `metadata` casi siempre; con uno alcanza.
     *
     * @param Request $request El request de la preferencia (para armar la URL del webhook).
     * @param \App\User $commerce Comercio que cobra.
     * @param \App\Cart|null $cart Carrito que se paga, si se pudo resolver.
     * @return array<string, mixed>
     */
    protected function datos_de_preferencia(Request $request, $commerce, $cart)
    {
        $datos = [];

        if (app()->environment('production')) {
            $online = rtrim((string) $commerce->online, '/');

            if ($online !== '') {
                $datos['back_urls'] = [
                    'success' => $online.'/pago-exitoso',
                    'pending' => $online.'/pago-pendiente',
                    'failure' => $online.'/pago-rechazado',
                ];
                $datos['auto_return'] = 'approved';
            }

            $datos['notification_url'] = $this->url_del_webhook($request, $commerce->id);
        }

        if ($cart) {
            $datos['external_reference'] = (string) $cart->id;
            $datos['metadata'] = [
                'cart_id'     => (int) $cart->id,
                'commerce_id' => (int) $commerce->id,
            ];
        }

        return $datos;
    }

    /**
     * URL publica del webhook, armada desde el request y no desde `APP_URL`.
     *
     * En el shared hosting Laravel se sirve desde `/public` (la SPA le pega a
     * `https://api-x/public/api/...`) y `APP_URL` esta cargada sin ese sufijo; en el VPS nginx
     * reescribe `/public` y la URL limpia funciona. `$request->root()` trae el esquema, el host y
     * el base path REAL con el que llego este request, asi que la URL que se le da a Mercado Pago
     * es la misma que acaba de funcionar para pedir la preferencia.
     *
     * @param Request $request
     * @param int $commerce_id
     * @return string
     */
    protected function url_del_webhook(Request $request, $commerce_id)
    {
        return rtrim($request->root(), '/').'/api/mercado-pago/webhook?commerce_id='.(int) $commerce_id;
    }

    /**
     * Webhook de Mercado Pago: le avisa a este backend que un pago cambio.
     *
     * 🔴 NUNCA CONFIA EN LO QUE TRAE LA NOTIFICACION. La ruta es publica y sin firma verificable
     * desde aca (el secret del webhook vive en el panel de Mercado Pago Developers), asi que el
     * body podria decir cualquier cosa. Lo unico que se toma de la notificacion es el ID del pago;
     * el estado, el monto y el carrito (`external_reference`) se leen de la API de Mercado Pago
     * con la credencial del comercio. Una notificacion inventada, a lo sumo, hace que se consulte
     * un pago que no existe.
     *
     * Formatos que manda Mercado Pago, y se aceptan los dos:
     * - Webhooks: `?type=payment&data.id=123` en la query y `{"type":"payment","data":{"id":"123"}}`
     *   en el body. PHP convierte `data.id` en `data_id` al parsear la query.
     * - IPN (viejo): `?topic=payment&id=123`.
     * Todo lo que no sea un pago (`merchant_order`, `plan`, etc.) se responde 200 y se ignora.
     *
     * Codigos de respuesta: 200 para todo lo que se proceso o se decidio ignorar (Mercado Pago
     * deja de reintentar); 500 SOLO cuando la API de Mercado Pago no contesto, para que reintente.
     *
     * El `commerce_id` viene en la query porque la base puede ser compartida entre varios
     * comercios (shared hosting): el pago se consulta con la credencial de ESE comercio y el
     * carrito tiene que ser de ese mismo comercio, o no se toca.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function webhook(Request $request)
    {
        $commerce_id = (int) $request->query('commerce_id');
        $tipo        = $request->input('type', $request->input('topic'));
        $payment_id  = $request->input('data.id', $request->query('data_id', $request->input('id')));

        if ($tipo !== 'payment' || empty($payment_id) || $commerce_id <= 0) {
            return response()->json(['ignorado' => true, 'motivo' => 'no es una notificacion de pago completa'], 200);
        }

        $access_token = MercadoPagoCredentialsHelper::access_token($commerce_id);

        if (empty($access_token)) {
            Log::error('MercadoPagoController@webhook: llego un pago para un comercio sin credencial de Mercado Pago', [
                'commerce_id' => $commerce_id,
                'payment_id'  => $payment_id,
            ]);

            return response()->json(['ignorado' => true, 'motivo' => 'el comercio no tiene credencial'], 200);
        }

        $respuesta = Http::withToken($access_token)
            ->acceptJson()
            ->timeout(20)
            ->get(self::API_PAGOS.$payment_id);

        if ($respuesta->status() === 404) {
            Log::warning('MercadoPagoController@webhook: Mercado Pago no conoce ese pago para este comercio', [
                'commerce_id' => $commerce_id,
                'payment_id'  => $payment_id,
            ]);

            return response()->json(['ignorado' => true, 'motivo' => 'pago inexistente para el comercio'], 200);
        }

        if (!$respuesta->successful()) {
            Log::error('MercadoPagoController@webhook: la API de Mercado Pago no contesto, se pide reintento', [
                'commerce_id' => $commerce_id,
                'payment_id'  => $payment_id,
                'status'      => $respuesta->status(),
            ]);

            return response()->json(['error' => 'Mercado Pago no respondio, reintentar'], 500);
        }

        $pago = $respuesta->json();

        $cart = $this->carrito_del_pago_notificado(is_array($pago) ? $pago : []);

        if (!$cart) {
            Log::warning('MercadoPagoController@webhook: el pago no referencia ningun carrito conocido', [
                'commerce_id'        => $commerce_id,
                'payment_id'         => $payment_id,
                'external_reference' => isset($pago['external_reference']) ? $pago['external_reference'] : null,
            ]);

            return response()->json(['ignorado' => true, 'motivo' => 'sin carrito'], 200);
        }

        if ((int) $cart->user_id !== $commerce_id) {
            Log::error('MercadoPagoController@webhook: el carrito referenciado es de otro comercio, no se toca', [
                'commerce_id' => $commerce_id,
                'cart_id'     => $cart->id,
                'payment_id'  => $payment_id,
            ]);

            return response()->json(['ignorado' => true, 'motivo' => 'carrito de otro comercio'], 200);
        }

        $cart->payment_id     = isset($pago['id']) ? $pago['id'] : $payment_id;
        $cart->payment_status = isset($pago['status']) ? $pago['status'] : null;
        $cart->save();

        if (!empty($cart->order_id)) {
            $order = Order::find($cart->order_id);

            if ($order) {
                $order->payment_id = $cart->payment_id;
                $order->save();
            }
        }

        Log::info('MercadoPagoController@webhook: pago registrado', [
            'commerce_id' => $commerce_id,
            'cart_id'     => $cart->id,
            'order_id'    => $cart->order_id,
            'payment_id'  => $cart->payment_id,
            'status'      => $cart->payment_status,
        ]);

        return response()->json([
            'ok'       => true,
            'cart_id'  => $cart->id,
            'order_id' => $cart->order_id,
            'status'   => $cart->payment_status,
        ], 200);
    }

    /**
     * El carrito al que pertenece un pago, segun lo que Mercado Pago tiene guardado de el.
     *
     * Primero `external_reference` (lo que `datos_de_preferencia()` escribe), despues
     * `metadata.cart_id`. Las dos las escribio este mismo backend al crear la preferencia; un pago
     * hecho desde afuera no trae ninguna y se ignora.
     *
     * @param array $pago El pago tal como lo devuelve la API de Mercado Pago.
     * @return \App\Cart|null
     */
    protected function carrito_del_pago_notificado(array $pago)
    {
        $cart_id = isset($pago['external_reference']) ? $pago['external_reference'] : null;

        if ((empty($cart_id) || !is_numeric($cart_id)) && isset($pago['metadata']['cart_id'])) {
            $cart_id = $pago['metadata']['cart_id'];
        }

        if (empty($cart_id) || !is_numeric($cart_id)) {
            return null;
        }

        return Cart::find((int) $cart_id);
    }
}
