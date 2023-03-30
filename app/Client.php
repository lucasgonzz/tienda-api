<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    
    protected $fillable = [
    	'name',
    	'user_id',
    ];
    
    public function sales() {
        return $this->hasMany('App\Sale');
    }
    
    public function price_type() {
        return $this->belongsTo('App\PriceType');
    }
}
