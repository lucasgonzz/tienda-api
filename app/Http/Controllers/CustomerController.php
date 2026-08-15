<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use MercadoPago\Customer;
use MercadoPago\SDK;

class CustomerController extends Controller
{
    /**
     * Tarjetas guardadas en Mercado Pago del comprador autenticado.
     *
     * 🔴 Era un IDOR contra la API de Mercado Pago: la ruta tomaba el email de la URL y consultaba
     * con el access token de la plataforma, sin auth y sin comprobar nada. Cualquiera con el mail
     * de otra persona veia sus tarjetas guardadas.
     *
     * El parametro $email se mantiene en la firma por compatibilidad de la ruta (el SPA viejo
     * cacheado podria armar la URL igual), pero se IGNORA: el email sale siempre del comprador
     * autenticado. Se verifico que hoy no la llama nadie — cero coincidencias de
     * `customers/cards` en todo tienda-spa/src —, asi que ademas de cerrarla, esto no rompe nada.
     *
     * @param  string  $email  ignorado a proposito, ver arriba
     * @return \Illuminate\Http\JsonResponse
     */
    function cards($email) {
        $buyer = $this->buyer();

        if (is_null($buyer) || empty($buyer->email)) {
            return response()->json(['has_cards' => false], 200);
        }

        SDK::setAccessToken(env('MERCADO_PAGO_ACCESS_TOKEN'));
        $filters = ['email' => $buyer->email];
        $customers = Customer::search($filters);
        if ($customers->total >= 1) {
        	$customer = Customer::find_by_id($customers[0]->id);
			return response()->json(['has_cards' => true, 'cards' => $customer->cards, 'customer_id' => $customer->id], 200);
        }
    	return response()->json(['has_cards' => false], 200);
    }
}
