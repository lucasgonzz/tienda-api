<?php

namespace App\Http\Controllers;

use App\Platelet;
use Illuminate\Http\Request;

class PlateletController extends Controller
{
    function index($commerce_id) {
        $platelets = Platelet::where('user_id', $commerce_id)
                                ->get();
        return response()->json(['platelets' => $platelets], 200);
    }
}
