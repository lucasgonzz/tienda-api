<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('articles.images', 'articles', 'articles.colors', 'articles.sizes', 'buyer', 'payment_method', 'delivery_zone', 'cupons');
    }

    function articles() {
    	return $this->belongsToMany('App\Article')->withPivot('amount', 'price', 'variant_id');
    }

    function cupons() {
        return $this->belongsToMany('App\Cupon');
    }

    function buyer() {
        return $this->belongsTo('App\Buyer');
    }

    function payment_method() {
        return $this->belongsTo('App\PaymentMethod');
    }

    function delivery_zone() {
        return $this->belongsTo('App\DeliveryZone');
    }

    function user() {
        return $this->belongsTo('App\User');
    }
}
