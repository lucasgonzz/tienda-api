<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    
    protected $fillable = [
    	'user_id',
    	'client_id',
        'num_sale',
        'percentage_card',
        'debt',
        'special_price_id'
    ];

    public function articles() {
        return $this->belongsToMany('App\Article')->withPivot('amount', 'measurement', 'cost', 'price');
    }

    public function client() {
        return $this->belongsTo('App\Client');
    }

    public function specialPrice() {
        return $this->belongsTo('App\SpecialPrice');
    }
}
