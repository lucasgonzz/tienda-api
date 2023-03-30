<?php

namespace App;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
// use ChristianKuri\LaravelFavorite\Traits\Favoriteability;

class Buyer extends Authenticatable implements MustVerifyEmail
{
    use Notifiable;
    // use Favoriteability;
    
    protected $guarded = [];

    // function cards() {
    //     return $this->hasMany('App\Cards');
    // }
    

    public function scopeWithAll($query){
        $query->with('addresses', 'comercio_city_client')
               ->with(['messages' => function($q) {
                    $q->orderBy('id', 'DESC')
                    ->with('article.images');
                }]);
    }

    public function comercio_city_client() {
        return $this->belongsTo('App\Client', 'comercio_city_client_id');
    }
    
    public function configuration() {
    	return $this->hasOne('App\Configuration');
    }

    function document() {
        return $this->hasOne('App\Document');
    }

    function views() {
        return $this->hasMany('App\View');
    }

    function addresses() {
        return $this->hasMany('App\Address');
    }

    public function messages() {
        return $this->hasMany('App\Message');
    }

    public function user() {
        return $this->belongsTo('App\User');
    }

    public function commerce() {
        return $this->hasMany('App\User');
    }
}
