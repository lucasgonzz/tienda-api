<?php

namespace App\Http\Controllers\Helpers;
use MercadoPago\SDK;
use MercadoPago\Customer;

class BuyerHelper {

    static function addMercadoPagoCards($buyer) {
        if (env('APP_ENV') == 'local') {
            return $buyer;
        } else {
            $customer = Self::getCustomer($buyer->email);
            if (is_null($customer)) {
                $buyer->cards = [];
            } else {
                $buyer->cards = $customer->cards;
            }
            return $buyer;
        }
    }

    static function getCustomer($email) {
        SDK::setAccessToken(env('MERCADO_PAGO_ACCESS_TOKEN'));
        $filters = ['email' => $email];
        $customers = Customer::search($filters);
        if ($customers->total >= 1) {
            $customer = Customer::find_by_id($customers[0]->id);
            return $customer;
        } 
        return null;
    }
    
}
