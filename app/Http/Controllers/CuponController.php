<?php

namespace App\Http\Controllers;

use App\Cupon;
use Illuminate\Http\Request;

class CuponController extends Controller
{
    /**
     * Cupones del comprador autenticado.
     *
     * 🔴 Guard obligatorio, no defensivo: `cupons.buyer_id` es NULLABLE, asi que sin sesion
     * where('buyer_id', null) se volvia whereNull y devolvia los cupones genericos de todos los
     * comercios, con su `code`. Un cupon es plata.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    function index() {
        $buyer_id = $this->buyerId();

        if (is_null($buyer_id)) {
            return response()->json(['cupons' => []], 200);
        }

        $cupons = Cupon::where('buyer_id', $buyer_id)
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

    /**
     * Marca como leidos los cupones del comprador autenticado.
     *
     * 🔴 Mismo whereNull que index(), pero esta ESCRIBE: sin el guard, un visitante anonimo
     * marcaba como leidos los cupones genericos de todos los comercios.
     *
     * @return \Illuminate\Http\Response
     */
    function setRead() {
        $buyer_id = $this->buyerId();

        if (is_null($buyer_id)) {
            return response(null, 200);
        }

        $cupons = Cupon::where('buyer_id', $buyer_id)
                            ->where('read', 0)
                            ->get();
        foreach ($cupons as $cupon) {
            $cupon->read = 1;
            $cupon->save();
        }
        return response(null, 200);
    }
}
