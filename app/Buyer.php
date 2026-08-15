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

    /**
     * 🔴 Sin este $hidden, el hash de la contraseña de los compradores viaja por la API.
     *
     * Medido el 15/8/2026: GET /api/buyer/search/{query}/{commerce_id} es una ruta PUBLICA que
     * devuelve la base de compradores del comercio, y cada comprador venia con `password`
     * (hash bcrypt), `remember_token`, `verification_code` y `visible_password`. Tambien salian
     * por GET /api/user, POST /login y POST /register, y por toda respuesta que serialice un
     * pedido, porque Order::withAll() incluye la relacion `buyer`.
     *
     * La causa era facil de pasar por alto: Illuminate\Foundation\Auth\User NO trae $hidden. El
     * `$hidden = ['password', 'remember_token']` que uno recuerda vive en el App\User de cada
     * proyecto (aca esta, en app/User.php), y este modelo simplemente nunca lo declaro.
     *
     * $hidden solo afecta la serializacion: el acceso desde PHP sigue igual, asi que
     * Hash::check($request->current_password, $buyer->password) de BuyerController@updatePassword
     * y el attempt() de AuthController@login siguen funcionando sin cambios.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'verification_code',
        'visible_password',
        'provider_id',
    ];

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
