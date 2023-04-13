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
                        ->with('configuration')
                        ->with('online_price_type')
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
