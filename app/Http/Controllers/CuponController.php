<?php

namespace App\Http\Controllers;

use App\Cupon;
use Illuminate\Http\Request;

class CuponController extends Controller
{
    function index() {
        $cupons = Cupon::where('buyer_id', $this->buyerId())
                        ->where('valid', true)
                        ->with('user')
                        ->get();
        return response()->json(['cupons' => $cupons], 200);
    }

    function search($commerce_id, $code) {
        $cupon = Cupon::where('user_id', $commerce_id)
                        ->where('code', $code)
                        ->where('valid', 1)
                        ->first();
        return response()->json(['cupon' => $cupon], 200);
    }

    function setRead() {
        $cupons = Cupon::where('buyer_id', $this->buyerId())
                            ->where('read', 0)
                            ->get();
        foreach ($cupons as $cupon) {
            $cupon->read = 1;
            $cupon->save();
        }
        return response(null, 200);
    }
}
