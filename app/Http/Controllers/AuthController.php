<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Cupon;
use App\Http\Controllers\Helpers\BuyerHelper;
use App\Http\Controllers\Helpers\CuponHelper;
use App\Http\Controllers\Helpers\StringHelper;
use App\Http\Controllers\Helpers\TwilioHelper;
use App\Mail\PasswordReset;
use App\Notifications\VerificationCode;
use App\User;
use Carbon\Carbon;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Twilio\Rest\Client;

class AuthController extends Controller
{

    function login(Request $request) {
    	if (Auth::guard('buyer')->attempt(['email' => $request->email, 'user_id' => $request->commerce_id, 'password' => $request->password], $request->remember)) {
            $buyer = Self::getFullBuyer();
            return response()->json(['buyer' => $buyer], 200);
        }
    	return response(null, 403);
    }

    function social($provider, $commerce_id) {
        $social_user = Socialite::driver($provider)->stateless()->user();
        $buyer = Buyer::where('provider_id', $social_user->id)
                        ->where('user_id', $commerce_id)
                        ->first();
        $new_buyer = false;
        if (is_null($buyer)) {
            $name = explode(' ', $social_user->getName());
            $buyer = Buyer::create([
                'email' => $social_user->getEmail(),
                'avatar' => $social_user->getAvatar(),
                'name' => StringHelper::modelName($name[0], true),
                'surname' => StringHelper::modelName($name[count($name)-1], true),
                'provider_id' => $social_user->id,
                // 'phone' => $social_user->getPhone(),
                'user_id' => $commerce_id,
            ]);
            $new_buyer = true;
        }
        Auth::guard('buyer')->login($buyer);
        $buyer = Self::getFullBuyer();
        return response()->json(['new_buyer' => $new_buyer, 'buyer' => $buyer], 200);
    }

    function getFullBuyer($id = null) {
        if (is_null($id)) {
            $id = $this->buyerId();
        }
        $buyer = Buyer::where('id', $id)
                        // ->with('document')
                        ->with('addresses')
                        ->first();
        Self::setLastLogin($buyer);
        // $buyer = BuyerHelper::addMercadoPagoCards($buyer);
        return $buyer;
    }

    function logout() {
    	Auth::guard('buyer')->logout();
    	return response(null, 200);
    }

    function register(Request $request) {
        // return response()->json(['asd' => $this->isBuyerRegistered($request)], 200);
        if (!$this->isBuyerRegistered($request)) {
            $this->deleteIfExist($request->phone);
            // TwilioHelper::sendVerificationCode($this->getNumber($request->phone));        
            $code = rand(100000, 999999);
        	$buyer = Buyer::create([
                'num'               => $this->num('buyers', $request->commerce_id),
        		'name'	            => ucwords(strtolower($request->name)),
        		'surname'           => ucwords(strtolower($request->surname)),
                'phone'             => $this->getNumber($request->phone),
        		'email'             => $request->email,
        		'password'          => bcrypt($request->password),
                'verification_code' => $code,
        		'user_id'           => isset($request->commerce_id) ? $request->commerce_id : null,
        	]);
            $commerce = User::find($request->commerce_id);
            $buyer->notify(new VerificationCode($code, $commerce));
            Auth::guard('buyer')->login($buyer);
            return response()->json(['buyer' => $buyer], 201);
        }
    	return response(null, 200);
    }

    function verifyCode(Request $request) {
        $buyer = Buyer::find($this->buyerId());
        if ($buyer->verification_code == $request->verification_code) {
            $buyer->verification_code = null;
            $buyer->save();
            return response()->json(['verified' => true], 200);
        } 
        return response()->json(['verified' => false], 200);
    }

    function resendVerificationCode(Request $request) {
        $buyer = Buyer::find($this->buyerId())
                        ->first();
        $commerce = User::find($buyer->user_id);
        $buyer->notify(new VerificationCode($buyer->verification_code, $commerce));
        // TwilioHelper::sendVerificationCode($this->getNumber($request->phone));  
        return response(null, 200);      
    }

    function isBuyerRegistered($request) {
        $buyer = Buyer::where('phone', $this->getNumber($request->phone))
                        ->whereNull('verification_code');
        if (isset($request->commerce_id)) {
            $buyer = $buyer->where('user_id', $request->commerce_id);
        }
        $buyer = $buyer->first();
        // return $buyer;
        return !is_null($buyer);
    }

    function deleteIfExist($phone) {
        $buyer = Buyer::where('phone', $this->getNumber($phone))
                        ->first();
        if (!is_null($buyer)) {
            $buyer->delete();
        }
    }

    static function setLastLogin($buyer) {
        $buyer->last_login = Carbon::now();
        $buyer->save();
    }

    function checkCupons($buyer) {
        $cupon_for_new_buyers = Cupon::where('type', 'for_new_buyers');
        if (!is_null($buyer->user_id)) {
            $cupon_for_new_buyers = $cupon_for_new_buyers->where('user_id', $buyer->user_id);
        }
        $cupon_for_new_buyers = $cupon_for_new_buyers->first();
        if (!is_null($cupon_for_new_buyers)) {
            $cupon = Cupon::create([
                        'amount'            => CuponHelper::getAmount($cupon_for_new_buyers),
                        'percentage'        => CuponHelper::getPercentage($cupon_for_new_buyers),
                        'min_amount'        => CuponHelper::getMinAmount($cupon_for_new_buyers),
                        'expiration_date'   => CuponHelper::getExpirationDate($cupon_for_new_buyers),
                        'buyer_id'          => $buyer->id,
                        'user_id'           => $buyer->user_id ? $buyer->user_id : null,
                    ]);
            // CuponHelper::sendCuponNotification($cupon);
        }
    }
}
