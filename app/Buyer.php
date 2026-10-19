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

    /**
     * Las direcciones de entrega guardadas por el comprador (misión formas-envio-direcciones-maps,
     * 19/10/2026). No es `addresses()`: esa es la tabla de sucursales del ERP, de un flujo viejo.
     *
     * 🔴 La tabla la crea `empresa-api` y hay bases sin ella: nunca se carga sin preguntarle antes
     * a `DireccionesEsquemaHelper::disponible()`, y por eso NO va en el `withAll()`.
     */
    function buyer_addresses() {
        return $this->hasMany('App\BuyerAddress');
    }

    /**
     * True si el comprador es una CUENTA (tiene contraseña o `provider_id`) y no una ficha de
     * invitado (misión formas-envio-direcciones-maps, 19/10/2026).
     *
     * Es el mismo criterio que `BuyerController::esFichaDeInvitado()`, dado vuelta: la ficha que
     * deja un checkout de invitado no tiene credencial, y `BuyerController::login()` igual le abre
     * el guard `buyer` (no hay nada que proteger). Por eso "estar logueado" no alcanza para tener
     * libreta de direcciones: solo una cuenta guarda, lista y elige direcciones guardadas.
     *
     * `password` y `provider_id` están en `$hidden`: el SPA no los ve. Lo que el SPA espeja es la
     * respuesta 422 `sin_cuenta` de `/api/buyer/addresses`.
     *
     * @return bool
     */
    public function es_cuenta() {
        $con_password = !is_null($this->password) && $this->password !== '';
        $con_provider = !is_null($this->provider_id) && $this->provider_id !== '';

        return $con_password || $con_provider;
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
