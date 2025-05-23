<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\StringHelper;
use App\Message;
use App\Notifications\MessageSend;
use App\User;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    function index() {
        $messages = Message::where('buyer_id', $this->buyerId())
                            ->with('article.images')
                            ->with('article.colors')
                            ->with('article.sizes')
                            ->with(['article.questions' => function($query) {
                                $query->whereHas('answer')->with('answer');
                            }])
                            ->get();
        return response()->json(['messages' => $messages], 200);
    }

    function setRead() {
        $messages = Message::where('buyer_id', $this->buyerId())
                            ->where('read', 0)
                            ->where('from_buyer', 0)
                            ->get();
        foreach ($messages as $message) {
            $message->read = 1;
            $message->save();
        }
        return response(null, 200);
    }

    function store(Request $request) {
        $message = Message::create([
            'buyer_id'      => $this->buyerId(),
            'user_id'       => $request->commerce_id,
            'from_buyer'    => true,
            'text'          => StringHelper::onlyFirstWordUpperCase($request->text),
        ]);
        $commerce = User::find($request->commerce_id);
        // $commerce->notify(new MessageSend($message));
        return response()->json(['message' => $message], 201);
    }

}
