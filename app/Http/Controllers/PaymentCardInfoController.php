<?php

namespace App\Http\Controllers;

use App\PaymentCardInfo;
use Illuminate\Http\Request;

class PaymentCardInfoController extends Controller
{
    function store(Request $request) {
        $model = PaymentCardInfo::create([
            'token'             => $request->token,
            'bin'               => $request->bin,
            'installments'      => $request->installments,
        ]);
        return response()->json(['model' => $model], 201);
    }
}
