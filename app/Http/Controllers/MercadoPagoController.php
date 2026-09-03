<?php

namespace App\Http\Controllers;

use App\Credential;
use App\Http\Controllers\Helpers\MercadoPagoCredentialsHelper;
use App\Http\Controllers\Helpers\OnlinePaymentHelper;
use App\PaymentMethod;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoPagoController extends Controller
{
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
     * @param Request $request payment_method (fila elegida), cupon, delivery_zone, articles.
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

        if (env('APP_ENV') == 'production') {
            $preference->back_urls = [
                'success' => $this->commerce->online.'/pago-exitoso',
                'pending' => $this->commerce->online.'/pago-pendiente',
                'failure' => $this->commerce->online.'/pago-rechazado',
            ];
        }


        $preference->save();

        return response()->json(['preference_id' => $preference->id], 201);
    }
}
