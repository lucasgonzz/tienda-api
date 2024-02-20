<?php

namespace App\Http\Controllers;

use App\BuyerMessage;
use App\BuyerMessageDefaultResponse;
use Illuminate\Http\Request;

class BuyerMessageController extends Controller
{
    function index() {
        $models = BuyerMessage::where('buyer_id', $this->buyerId())
                                ->orderBy('created_at', 'ASC')
                                ->with('buyer_message_default_responses')
                                ->get();
        return response()->json(['models' => $models], 200);
    }

    function store(Request $request) {
        foreach ($request->messages as $message) {
            if (!isset($message['id'])) {
                $buyer_message = BuyerMessage::create([
                    'buyer_id'      => $this->buyerId(),
                    'text'          => $message['text'],
                    'from_buyer'    => $message['from_buyer'],
                ]);

                if (isset($message['buyer_message_default_responses'])) {
                    foreach ($message['buyer_message_default_responses'] as $default_message) {
                        BuyerMessageDefaultResponse::create([
                            'buyer_message_id'  => $buyer_message->id,
                            'text'              => $default_message['text'],
                        ]);
                    }
                }
            }
        }
    }
}
