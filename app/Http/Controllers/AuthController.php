<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Http\Controllers\Helpers\AjustesDeClienteHelper;
use App\Http\Controllers\Helpers\BuyerHelper;
use App\Http\Controllers\Helpers\TwilioHelper;
use App\Mail\PasswordReset;
use App\Notifications\VerificationCode;
use App\User;
use Carbon\Carbon;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
     * Ordena la sesion cuando alguien entra con su cuenta en este navegador.
     *
     * Auth::guard('buyer')->login() hace session->migrate(true), que regenera el id de sesion pero
     * CONSERVA los atributos. Eso es lo que le permite al invitado no perder su carrito al
     * identificarse en el checkout — y es justo lo que hay que ordenar cuando el que entra puede
     * ser otra persona.
     *
     * 🔴 La primera version de esto BORRABA la lista de carritos, y eso rompio la compra del
     * invitado que se loguea con su propia cuenta: 403 en PUT /api/carts y en POST /api/orders,
     * medido contra master por el revisor de merge. El traspaso resuelve los dos casos con un
     * criterio que no necesita adivinar quien es el humano; el detalle esta en el docblock de
     * CartOwnershipHelper::traspasarAlCompradorQueEntra.
     *
     * La identidad de checkout y la lista de pedidos SI se borran siempre: son de la compra
     * anterior y no tienen nada que hacer en la sesion de quien acaba de entrar.
     *
     * 🔴 Se llama SOLO desde el login de una cuenta (`login`; hasta el 14/10/2026 tambien desde el
     * login con Google, que se saco), nunca desde el
     * BuyerController@store del checkout de invitado: ahi no hay nada que ordenar y tocar la
     * sesion en el ultimo paso de la compra es todo riesgo y ningun beneficio.
     *
     * @return void
     */
    function limpiarRastrosDeOtroComprador() {
        \App\Http\Controllers\Helpers\CartOwnershipHelper::traspasarAlCompradorQueEntra($this->buyerId());

        session()->forget([
            self::CLAVE_CHECKOUT,
            self::CLAVE_PEDIDOS,
        ]);
    }

    /*
     * `social($provider, $commerce_id)` (login con Google por Laravel Socialite, con las
     * credenciales de cada comercio de online_configurations) se saco el 14/10/2026 junto con su
     * ruta y con GoogleLoginHelper (mision tiendas-demo-y-limpieza-tienda): la tienda ya no tiene
     * login social. Las columnas `google_*` de online_configurations y `buyers.provider_id` quedan.
     */

    function getFullBuyer($id = null) {
        if (is_null($id)) {
            $id = $this->buyerId();
        }
        $buyer = Buyer::where('id', $id)
                        // ->with('document')
                        ->withAll()
                        ->first();
        Self::setLastLogin($buyer);
        // Ajustes del cliente para el desplegable del nombre (login, registro y login social).
        // Va despues de setLastLogin a proposito y como relacion en memoria: ver el helper.
        AjustesDeClienteHelper::colgar_del_comprador($buyer);
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
}
