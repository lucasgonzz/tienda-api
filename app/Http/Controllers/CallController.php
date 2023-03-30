<?php

namespace App\Http\Controllers;

use App\Call;
use App\Notifications\CallCreated;
use App\User;
use Illuminate\Http\Request;

class CallController extends Controller
{

    function waitingCall() {
        $call = Call::where('buyer_id', $this->buyerId())
                        ->where('status', 'unrealized')
                        ->first();
        if ($call) {
            return response()->json(['waiting_call' => true], 200);
        }
        return response()->json(['waiting_call' => false], 200);
    }

    function store(Request $request) {
        $call = Call::create([
            'buyer_id' => $this->buyerId(),
            'user_id'  => $request->commerce_id,
        ]);
        $commerce = User::find($request->commerce_id);
        $commerce->notify(new CallCreated($call));
        return response(null, 201);
    }
}
