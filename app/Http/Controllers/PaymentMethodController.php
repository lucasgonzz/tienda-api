<?php

namespace App\Http\Controllers;

use App\PaymentMethod;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{

    public function index($commerce_id) {
        $payment_methods = PaymentMethod::where('user_id', $commerce_id)
                                        ->with('type')
                                        ->with('payment_method_installments')
                                        ->get();
        return response()->json(['payment_methods' => $payment_methods], 200);
    }

}
