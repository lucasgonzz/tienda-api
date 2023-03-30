<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{

    protected $hidden = ['access_token'];

    function type() {
        return $this->belongsTo('App\PaymentMethodType', 'payment_method_type_id');
    }
}
