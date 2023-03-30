<?php

namespace App\Http\Controllers;

use App\DeliveryZone;
use Illuminate\Http\Request;

class DeliveryZoneController extends Controller
{

    public function index($commerce_id) {
        $models = DeliveryZone::where('user_id', $commerce_id)
                                ->get();
        return response()->json(['models' => $models], 200);
    }

}
