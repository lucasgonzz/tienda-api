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
