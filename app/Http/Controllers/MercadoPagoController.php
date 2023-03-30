<?php

namespace App\Http\Controllers;

use App\Credential;
use App\PaymentMethod;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoPagoController extends Controller
{
    function preference(Request $request) {
        $payment_method = PaymentMethod::find($request->payment_method['id']);
        \MercadoPago\SDK::setAccessToken($payment_method->access_token);

        // Crea un objeto de preferencia
        $preference = new \MercadoPago\Preference();

        $articles = $this->setPrices($request->cupon, $request->delivery_zone, $request->articles);
        $items = [];
        foreach ($articles as $article) {
            // Crea un ítem en la preferencia
            $item = new \MercadoPago\Item();
            $item->title = $article['name'];
            $item->quantity = $article['amount'];
            $item->unit_price = $article['final_price'];
            $items[] = $item;
        }
        $preference->items = $items;

        $commerce = User::find($request->payment_method['user_id']);
        $preference->back_urls = [
            'success' => $commerce->online.'/pago-exitoso',
            'pending' => $commerce->online.'/pago-pendiente',
            'failure' => $commerce->online.'/pago-rechazado',
        ];

        $preference->save();

        return response()->json(['preference_id' => $preference->id], 201);
    }

    function setPrices($cupon, $delivery_zone, $articles) {
        if (!is_null($cupon)) {
            // $new_articles = [];
            $index = 0;
            if (!is_null($cupon['amount'])) {
                $total_amount = $cupon['amount'];
                foreach ($articles as $article) {
                    $price = $article['final_price'] * $article['amount'];
                    if ($price > $total_amount) {
                        Log::info('Descontando '.$total_amount.' a '.$article['name'].' price: '.$price);
                        $price -= $total_amount;
                        $total_amount = 0;
                        $price = $price / $article['amount'];
                        $new_article = [
                            'name'      => $article['name'],
                            'amount'    => $article['amount'],
                            'price'     => $price,
                        ];
                        $articles[$index] = $new_article;
                    } else {
                        $price -=  1;
                        Log::info('No alcanzo. Descontando '.$price.' a total_amount: '.$total_amount);
                        $total_amount -= $price;
                        Log::info('total_amount: '.$total_amount);
                        $new_price = 1 / $article['amount'];
                        $new_article = [
                            'name'      => $article['name'],
                            'amount'    => $article['amount'],
                            'price'     => $new_price,
                        ];
                        $articles[$index] = $new_article;
                    }
                    $index++;
                }
            } else {
                foreach ($articles as $article) {
                    Log::info($article['name'].' $'.$article['final_price'].' x '.$article['amount']);
                    $new_article = [
                        'name'      => $article['name'],
                        'amount'    => $article['amount'],
                    ];
                    Log::info('Descontando '.$cupon['percentage'].'% a '.$article['final_price']);
                    $new_article['price'] = floatval($article['final_price']) - floatval($article['final_price']) * floatval($cupon['percentage']) / 100;
                    $articles[$index] = $new_article;
                    Log::info('Quedo en $'.$articles[$index]['price']);
                    $index++;
                }
            }
            
            Log::info('---------Quedaron asi-----------');
            foreach ($articles as $article) {
                Log::info($article['name'].' $'.$article['final_price'].' x '.$article['amount']);
            }
        } 
        if (!is_null($delivery_zone)) {
            $article = $articles[0];
            $price = $article['final_price'] * $article['amount'];
            $price += $delivery_zone['price'];
            $new_article = [
                'name'      => $article['name'],
                'amount'    => $article['amount'],
            ];
            $new_article['price'] = $price / $article['amount'];
            $articles[0] = $new_article;
        }
        return $articles;
    }
}
