<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Cupon;
use App\Http\Controllers\Helpers\BuyerHelper;
use App\Http\Controllers\Helpers\CuponHelper;
use App\Http\Controllers\Helpers\GoogleLoginHelper;
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
            $this->limpiarRastrosDeOtroComprador();
            $buyer = Self::getFullBuyer();
            return response()->json(['buyer' => $buyer], 200);
        }
    	return response(null, 403);
    }

    /**
     * Borra de la sesion lo que dejo el comprador anterior en este mismo navegador.
     *
     * Auth::guard('buyer')->login() hace session->migrate(true), que regenera el id de sesion pero
     * CONSERVA los atributos. Eso es lo que le permite al invitado no perder su carrito al
     * identificarse en el checkout — y es justo lo que no queremos cuando el que entra es otra
     * persona con su cuenta.
     *
     * 🔴 Se llama SOLO desde el login de una cuenta (login y social), nunca desde el
     * BuyerController@store del checkout de invitado: ahi el carrito de la sesion es del mismo que
     * esta comprando y borrarlo le rompe la compra en el ultimo paso.
     *
     * @return void
     */
    function limpiarRastrosDeOtroComprador() {
        session()->forget([
            \App\Http\Controllers\Helpers\CartOwnershipHelper::CLAVE,
            self::CLAVE_CHECKOUT,
            self::CLAVE_PEDIDOS,
        ]);
    }

    function social($provider, $commerce_id) {
        // Login con Google (prompt 590, grupo 164): las credenciales ya no son globales del
        // .env, se resuelven por comercio desde online_configurations. Si el comercio no tiene
        // el login con Google habilitado o le faltan credenciales, se corta aca mismo con un
        // error controlado, sin caer a las credenciales viejas del .env.
        if ($provider == 'google' && !GoogleLoginHelper::apply($commerce_id)) {
            return response()->json(['error' => 'El login con Google no esta disponible para este comercio'], 422);
        }
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
        $this->limpiarRastrosDeOtroComprador();
        $buyer = Self::getFullBuyer();
        return response()->json(['new_buyer' => $new_buyer, 'buyer' => $buyer], 200);
    }

    function getFullBuyer($id = null) {
        if (is_null($id)) {
            $id = $this->buyerId();
        }
        $buyer = Buyer::where('id', $id)
                        // ->with('document')
                        ->withAll()
                        ->first();
        Self::setLastLogin($buyer);
        // $buyer = BuyerHelper::addMercadoPagoCards($buyer);
        return $buyer;
    }

    /**
     * Cierra la sesion del comprador desde el boton de salir del nav.
     *
     * 🔴 Hasta el 15/8/2026 esto solo hacia el logout del guard y NO invalidaba la sesion. Con la
     * titularidad del carrito viviendo en la sesion (CartOwnershipHelper), eso significaba que en
     * un dispositivo compartido —la tablet del negocio, la PC del local— el comprador que entraba
     * despues heredaba `carritos_propios` del anterior: podia modificarle y borrarle los carritos,
     * y lastCart le podia devolver el carrito abierto del otro con sus datos de checkout adentro.
     *
     * Lo mismo con la identidad de checkout: sin invalidar, despues de salir un POST /orders
     * seguia atribuyendole el pedido al comprador identificado en el checkout anterior.
     *
     * Se invalida igual que BuyerController@logout, que ya lo hacia bien.
     *
     * @return \Illuminate\Http\Response
     */
    function logout() {
    	Auth::guard('buyer')->logout();
        try {
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        } catch (\Exception $e) {
            // Silenciar: si la sesion ya no existe, no hay nada que invalidar.
        }
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
                'barrio'            => ucwords(strtolower($request->barrio)),
                'ciudad'            => ucwords(strtolower($request->ciudad)),
        		'address'            => ucwords(strtolower($request->address)),
                'phone'             => $this->getNumber($request->phone),
        		'email'             => $request->email,
        		'password'          => bcrypt($request->password),
                'verification_code' => null,
                // 'verification_code' => $code,
        		'user_id'           => isset($request->commerce_id) ? $request->commerce_id : null,
        	]);
            $commerce = User::find($request->commerce_id);
            // $buyer->notify(new VerificationCode($code, $commerce));
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
