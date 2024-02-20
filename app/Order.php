<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('articles.images', 'buyer', 'payment_method', 'delivery_zone', 'cupons', 'order_status', 'articles.article_properties.article_property_values', 'articles.article_properties.article_property_type', 'articles.article_variants.article_property_values.article_property_type', 'articles.discounts');
    }

    function articles() {
    	return $this->belongsToMany('App\Article')->withPivot('amount', 'price', 'variant_id', 'notes');
    }

    function cupons() {
        return $this->belongsToMany('App\Cupon');
    }

    function order_status() {
        return $this->belongsTo('App\OrderStatus');
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
