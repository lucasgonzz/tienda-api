<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{

	protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('cupon', 'articles.images', 'articles', 'articles.colors', 'articles.sizes', 'payment_method.type', 'delivery_zone');
    }

    function articles() {
    	return $this->belongsToMany('App\Article')->withPivot('price', 'amount', 'variant_id');
    }

    function cupon() {
        return $this->belongsTo('App\Cupon');
    }

    function payment_method() {
        return $this->belongsTo('App\PaymentMethod');
    }

    function delivery_zone() {
        return $this->belongsTo('App\DeliveryZone');
    }
}
