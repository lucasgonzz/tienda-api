<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Cupon extends Model
{
    protected $guarded = [];

    function buyer() {
        return $this->belongsTo('App\Buyer');
    }

    function user() {
        return $this->belongsTo('App\User');
    }

    function orders() {
        return $this->belongsToMany('App\Order');
    }
}
