<?php

namespace App\Http\Controllers;

use App\User;
use App\Workday;
use Illuminate\Http\Request;

class CommerceController extends Controller
{

    function commerce($commerce_id) {
        $commerce = User::where('id', $commerce_id)
                        ->with('addresses')
                        ->with('extencions')
                        ->with('online_configuration.online_price_type')
                        ->with('online_configuration.online_template')
                        ->first();
        return response()->json(['commerce' => $commerce], 200);
    }

    function workdays($commerce_id) {
        $workdays = Workday::with(['schedules' => function($q) use ($commerce_id) {
                                $q->where('user_id', $commerce_id);
                            }])
                            ->get();
        return response()->json(['workdays' => $workdays], 200);
    }
}
