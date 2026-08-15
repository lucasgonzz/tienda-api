<?php

namespace App\Http\Controllers;

use App\Notifications\AddedModel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    function buyerId() {
        if (Auth::guard('buyer')->check()) {
            return Auth::guard('buyer')->id();
        }
        return null;
    }

    /** Clave de la identidad de checkout dentro de la sesion. Ver checkoutBuyerId(). */
    const CLAVE_CHECKOUT = 'checkout_buyer_id';

    /** Clave de los pedidos creados por esta sesion. Ver pedidosDeLaSesion(). */
    const CLAVE_PEDIDOS = 'pedidos_propios';

    /**
     * Comprador al que se le atribuye un pedido de checkout, que NO es lo mismo que un comprador
     * autenticado.
     *
     * ── El agujero que esto cierra ────────────────────────────────────────────────────────────
     *
     * BuyerController@store (POST /api/buyer, publica por el flujo de invitado) buscaba el
     * comprador por email + comercio y lo logueaba en el guard 'buyer' sin pedir nada mas. O sea
     * que sabiendo el mail de alguien te convertias en esa persona: sus pedidos, sus mensajes y
     * su CUENTA CORRIENTE. Medido el 15/8/2026: se entro a una cuenta con contraseña mandando
     * solo el email, y GET /api/user devolvio esa cuenta.
     *
     * Sacar el login de ahi no se podia: la atribucion del pedido de invitado depende de el
     * (OrderController@store resuelve el buyer_id desde la sesion, y orders.buyer_id es NOT NULL),
     * asi que sin login el checkout de invitado se caia entero.
     *
     * ── La separacion ────────────────────────────────────────────────────────────────────────
     *
     * Ahora hay dos cosas distintas donde antes habia una:
     *   - sesion de CUENTA (Auth::guard('buyer')): solo se abre con contraseña, o para un registro
     *     de invitado que no tiene ninguna. Es la que habilita /api/user, /orders, /messages,
     *     /credit-accounts.
     *   - identidad de CHECKOUT (esta clave de sesion): solo sirve para terminar la compra que se
     *     esta haciendo. No abre ninguna ruta de lectura de la cuenta.
     *
     * @return int|null
     */
    function checkoutBuyerId() {
        $buyer_id = $this->buyerId();

        if (!is_null($buyer_id)) {
            return (int) $buyer_id;
        }

        $de_checkout = session()->get(self::CLAVE_CHECKOUT);

        return is_null($de_checkout) ? null : (int) $de_checkout;
    }

    /**
     * Ids de pedido creados por esta sesion.
     *
     * Los necesita la pagina de gracias: cuando el comprador termina una compra con identidad de
     * checkout (no con sesion de cuenta), OrderController@current no lo puede resolver por
     * buyer_id sin volver a abrir la puerta que checkoutBuyerId() cerro. Se resuelve por el
     * pedido concreto que esta sesion acaba de crear, que es lo unico que legitimamente puede ver.
     *
     * @return int[]
     */
    function pedidosDeLaSesion() {
        $ids = session()->get(self::CLAVE_PEDIDOS, []);

        return is_array($ids) ? $ids : [];
    }

    function num($table, $user_id) {
        $last = DB::table($table)
                    ->where('user_id', $user_id)
                    ->orderBy('num', 'DESC')
                    ->first();
        if (is_null($last) || is_null($last->num)) {
            return 1;
        }
        return $last->num + 1;
    }

    function getModelBy($table, $prop_name, $prop_value, $from_user = false, $prop_to_return = null, $return_0 = false) {
        $model = DB::table($table)
                    ->where($prop_name, $prop_value);
        if ($from_user) {
            $model = $model->where('user_id', $this->userId());
        }
        $model = $model->first();
        if (!is_null($model) && !is_null($prop_to_return)) {
            return $model->{$prop_to_return};
        } 
        if ($return_0) {
            return 0;
        }
        return $model;
    }

    function sendAddModelNotification($model_name, $model_id, $check_added_by = true, $for_user_id = null) {
        if (is_null($for_user_id)) {
            $for_user_id = $this->userId();
        }
        $this->buyer()->notify(new AddedModel($model_name, $model_id, $check_added_by, $for_user_id));
    }

    // function getObject($array) {
    //     if (is_array($array)) {
    //         $object = new stdClass();
    //         foreach ($array as $key => $value) {
    //             $object->$key = $value;
    //             if (is_array($value)) {
    //                 foreach ($value as $key => $value_) {
                        
    //                 }
    //             }
    //         }
    //         return $object;
    //     }
    //     return $array;
    // }

    function buyer() {
        if (Auth::guard('buyer')->check()) {
            return Auth::guard('buyer')->user();
        } 
        return null;
    }

    function isLogin() {
        return Auth::guard('buyer')->check();
    }

    function getNumber($phone) {
        if (substr($phone, 0, 1) == '9') {
            $phone = substr($phone, 1);
        }
        return '+549'.$phone;
    }
}
