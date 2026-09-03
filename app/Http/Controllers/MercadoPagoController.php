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
     * Crea la preferencia de pago de Mercado Pago del carrito.
     *
     * 🔴 EL ACCESS TOKEN SALE DE `MercadoPagoCredentialsHelper`, no de la fila que manda el SPA.
     * Es el mismo orden que usa `empresa-api` (conector conectado -> `payment_methods`), y tiene
     * que seguir siendolo: la base es compartida, asi que si los dos repos resolvieran distinto,
     * un comercio cobraria con una cuenta en el ERP y con otra en la tienda.
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

        $access_token = MercadoPagoCredentialsHelper::access_token($request->payment_method['user_id']);

        /*
         * Ultima red: la credencial de la fila que el comprador eligio, que es de donde salia el
         * token hasta esta mision. Solo se usa cuando el helper no encontro NADA — o sea, cuando
         * el comercio no tiene conector conectado y tampoco una fila de tipo "MercadoPago" con
         * token (por ejemplo si `payment_method_types` de ese cliente no tiene esa fila con ese
         * nombre exacto, o si tiene dos metodos de MercadoPago y el primero quedo sin token).
         *
         * No es una divergencia con `empresa-api`: cuando el helper devuelve algo, gana el helper
         * y los dos repos cobran con la misma cuenta. Este paso solo cubre el caso en el que,
         * sin el, un comercio que hoy cobra dejaria de cobrar.
         */
        if (empty($access_token) && $this->payment_method) {
            $access_token = $this->payment_method->access_token;
        }

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
