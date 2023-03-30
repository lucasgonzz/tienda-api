<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\StringHelper;
use App\Message;
use App\Notifications\MessageSend;
use App\User;
use Illuminate\Http\Request;

class HelpController extends Controller
{
    function message(Request $request) {
        $message = Message::create([
            'buyer_id'      => $this->buyerId(),
            'user_id'       => $request->commerce_id,
            'from_buyer'    => true,
            'type'          => 'help',
            'text'          => StringHelper::onlyFirstWordUpperCase($request->message),
        ]);
        $commerce = User::find($request->commerce_id);
        $commerce->notify(new MessageSend($message));
        return response()->json(['message' => $message], 201);
    }
}
