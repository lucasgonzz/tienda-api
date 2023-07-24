<?php

namespace App\Http\Controllers;

use App\Credential;
use App\Http\Controllers\Helpers\OnlinePaymentHelper;
use App\PaymentMethod;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoPagoController extends Controller
{
    function preference(Request $request) {
        $this->payment_method = PaymentMethod::find($request->payment_method['id']);
        \MercadoPago\SDK::setAccessToken($this->payment_method->access_token);

        // Crea un objeto de preferencia
        $preference = new \MercadoPago\Preference();
        $this->commerce = User::find($request->payment_method['user_id']);

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

        $preference->back_urls = [
            'success' => $this->commerce->online.'/pago-exitoso',
            'pending' => $this->commerce->online.'/pago-pendiente',
            'failure' => $this->commerce->online.'/pago-rechazado',
        ];

        $preference->save();

        return response()->json(['preference_id' => $preference->id], 201);
    }
}
