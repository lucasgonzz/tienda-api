<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Http\Controllers\Helpers\OnlinePaymentHelper;
use App\PaymentMethod;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaywayController extends Controller
{

    function getToken(Request $request) {
        $this->payment_method = PaymentMethod::find($request->payment_method_id);
        $keys_data = [
            'public_key' => $this->payment_method->public_key,
            'private_key' => $this->payment_method->access_token,
        ];

        $ambient = 'test';//valores posibles: 'test' , 'prod' o 'qa'
        $connector = new \Decidir\Connector($keys_data, $ambient);

        $data = array(
            "card_number" => $request->card_number,
            "card_expiration_month" => $request->card_expiration_month,
            "card_expiration_year" => $request->card_expiration_year, 
            "card_holder_name" => $request->card_holder_name,
            "card_holder_birthday" => $request->card_holder_birthday,
            "card_holder_door_number" => $request->card_holder_door_number,
            "security_code" => $request->security_code,
            "card_holder_identification" => array(
                "type" => "dni",
                "number" => $request->number
            )
        );

        $response = $connector->token()->token($data);
        return response()->json(['model' => $response->get('cardholder',null)['name']], 201);
    }

    function payment(Request $request) {
        $this->payment_method = PaymentMethod::find($request->payment_method['id']);
        $this->commerce = User::find($request->payment_method['user_id']);
        $buyer = Buyer::find($request->buyer_id);
        
        $keys_data = [
            'public_key' => $this->payment_method->public_key,
            'private_key' => $this->payment_method->access_token,
        ];

        $ambient = 'test';//valores posibles: 'test' , 'prod' o 'qa'
        $connector = new \Decidir\Connector($keys_data, $ambient);

        $site_transaction_id = time().'_'.rand(0,1000);
        Log::info('site_transaction_id: '.$site_transaction_id);


        $online_payment_helper = new OnlinePaymentHelper($this->commerce, $this->payment_method);

        $articles = $online_payment_helper->setPrices($request->cupon, $request->delivery_zone, $request->articles);

        foreach ($articles as $article) {
            $total = $article['final_price'] *  $article['amount'];
        }

        $data = [
            'site_transaction_id'   => $site_transaction_id,
            'token'                 => $request->token,
            'customer'              => array(
                'id'                => 'customer', 
                'email'             => $buyer->email,
                'ip_address'        => null,
            ),
            'payment_method_id'     => 1,
            'bin'                   => $request->bin,
            'amount'                => $total,
            'currency'              => 'ARS',
            'installments'          => $request->installments,
            'description'           => 'Hola',
            'establishment_name'    => 'GOBBI HUGO FRANCISCO',
            'payment_type'          => 'single',
            'sub_payments'          => array(),
        ];

        Log::info('data: ');
        Log::info($data);

        try {
            $response = $connector->payment()->ExecutePayment($data);
            $response->getId();
            $response->getToken();
            $response->getUser_id();
            $response->getPayment_method_id();
            $response->getBin();
            $response->getAmount();
            $response->getCurrency();
            $response->getInstallments();
            $response->getPayment_type();
            $response->getDate_due();
            $response->getSub_payments();
            $response->getStatus();
            $response->getStatus_details()->ticket;
            $response->getStatus_details()->card_authorization_code;
            $response->getStatus_details()->address_validation_code;
            $response->getStatus_details()->error;
            $response->getDate();
            $response->getEstablishment_name();
            // $response->getFraud_detection();
            // $response->getAggregate_data();
            // $response->getSite_id();
            Log::info('TOdo bien');
            Log::info($response);
        } catch( \Exception $e ) {
            Log::info('Hubo un error');
            Log::info($e->getData());
        }
    }
}
