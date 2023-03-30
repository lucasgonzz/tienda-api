<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Document;
use App\Http\Controllers\Helpers\BuyerHelper;
use App\Notifications\PaymentUpdated;
use App\Order;
use App\Payment;
use App\User;
use Illuminate\Http\Request;
use MercadoPago\Card;
use MercadoPago\Customer;
use MercadoPago\SDK;

class PaymentController extends Controller
{
    
    function store(Request $request) { 
        $payment = Payment::create([
        	'transaction_amount' => $request->transaction_amount,
        	'token' => $request->token,
        	'description' => $request->description,
        	'installments' => $request->installments,
        	'payment_method_id' => $request->payment_method_id,
        	'issuer' => $request->issuer,
        	'email' => $this->buyer()->email,
        	'doc_type' => $request->doc_type,
            'doc_number' => $request->doc_number,
            'customer_id' => $request->customer_id,
            'card_id' => $request->card_id != 0 ? $request->card_id : null,
        ]);
        $document = $this->saveDocument($payment);
        $customer = $this->saveCustomer($payment);
        $this->saveCard($payment, $customer);
        return response()->json(['payment' => $payment, 'document' => $document], 201);
    }

    function saveCustomer($payment) {
        SDK::setAccessToken(env('MERCADO_PAGO_ACCESS_TOKEN'));
        $customer = BuyerHelper::getCustomer($payment->email);
        if (is_null($customer)) {
            $customer = new Customer();
            $customer->email = $payment->email;
            $customer->save();
        } 
        return $customer;
    }

    function saveCard($payment, $customer) {
        SDK::setAccessToken(env('MERCADO_PAGO_ACCESS_TOKEN'));
        $card                   = new Card();
        $card->token            = $payment->token;
        $card->customer_id      = $customer->id;
        // $card->customer_id      = $customer->id();
        $card->issuer           = ['id' => $payment->issuer];
        $card->payment_method   = ['id' => $payment->payment_method_id];
        $card->save();
    }

    function saveDocument($payment) {
        $buyer = $this->buyer();
        $document = Document::where('buyer_id', $this->buyerId())->first();
        if (is_null($document)) {
            $document = Document::create([
                'buyer_id'  => $this->buyerId(),
                'type'      => $payment->doc_type,
                'number'    => $payment->doc_number,
            ]);
            return $document;
        }
        return null;
    }
    
}
