<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{

	protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('cupon', 'articles.images', 'articles', 'articles.colors', 'articles.sizes', 'payment_method.type', 'payment_method.payment_method_installments', 'delivery_zone', 'promociones_vinoteca.images');
    }

    function articles() {
        return $this->belongsToMany('App\Article')->withPivot('price', 'amount', 'variant_id', 'amount_insuficiente', 'notes');
    }

    function promociones_vinoteca() {
        return $this->belongsToMany('App\PromocionVinoteca')->withPivot('price', 'amount', 'notes');
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
