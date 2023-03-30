<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use MercadoPago\Customer;
use MercadoPago\SDK;

class CustomerController extends Controller
{
    function cards($email) {
        SDK::setAccessToken(env('MERCADO_PAGO_ACCESS_TOKEN'));
        $filters = ['email' => $email];
        $customers = Customer::search($filters);
        if ($customers->total >= 1) {
        	$customer = Customer::find_by_id($customers[0]->id);
			return response()->json(['has_cards' => true, 'cards' => $customer->cards, 'customer_id' => $customer->id], 200);
        } 
    	return response()->json(['has_cards' => false], 200);
    }
}
