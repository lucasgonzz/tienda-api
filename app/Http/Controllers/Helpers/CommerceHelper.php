<?php

namespace App\Http\Controllers\Helpers;

use App\User;


class CommerceHelper
{
    static function hasExtencion($extencion_slug, $commerce = null, $commerce_id = null) {
        
        if (is_null($commerce)) {
            $commerce = User::find($commerce_id);
        }
        
        $has_extencion = false;
        foreach ($commerce->extencions as $extencion) {
            if ($extencion->slug == $extencion_slug) {
                $has_extencion = true;
            }
        }
        return $has_extencion;
    }
}

