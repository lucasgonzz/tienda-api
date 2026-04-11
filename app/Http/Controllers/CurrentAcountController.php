<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\CreditAccount;
use App\CurrentAcount;

class CurrentAcountController extends Controller
{
    function getCreditAccounts() {
        $buyer = $this->buyer();

        if (!$buyer || !$buyer->comercio_city_client_id) {
            return response()->json(['credit_accounts' => []], 200);
        }

        $credit_accounts = CreditAccount::where('model_name', 'client')
                                        ->where('model_id', $buyer->comercio_city_client_id)
                                        ->with('moneda')
                                        ->get();

        return response()->json(['credit_accounts' => $credit_accounts], 200);
    }

    function getMovements($credit_account_id, $cantidad_movimientos) {
        $buyer = $this->buyer();

        if (!$buyer || !$buyer->comercio_city_client_id) {
            return response(null, 403);
        }

        $credit_account = CreditAccount::find($credit_account_id);

        if (!$credit_account 
            || $credit_account->model_name != 'client' 
            || $credit_account->model_id != $buyer->comercio_city_client_id) {
            return response(null, 403);
        }

        $models = CurrentAcount::where('credit_account_id', $credit_account_id)
                                ->orderBy('created_at', 'DESC')
                                ->take($cantidad_movimientos)
                                ->with('current_acount_payment_methods')
                                ->with('sale')
                                ->get()
                                ->reverse()
                                ->values();

        return response()->json(['models' => $models], 200);
    }
}
