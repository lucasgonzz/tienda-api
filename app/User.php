<?php

namespace App;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    public $timestamps = false;
    
    protected $guarded = [];


    /**
     * Columnas del comercio que nunca pueden viajar en una respuesta.
     *
     * 🔴 Este modelo se serializa en rutas PUBLICAS: CommerceController@commerce
     * (GET /api/commerce/{id}, sin auth) y CuponController@index via ->with('user'). Hasta el
     * 15/8/2026 el $hidden tenia solo password y remember_token, y la respuesta publica incluia
     * las 122 columnas restantes de la tabla `users` del ERP. Medido con la API levantada.
     *
     * Lo que salia, y por que cada uno importa:
     *   - google_custom_search_api_key: una API key de Google EN TEXTO PLANO. Es lo peor de la
     *     lista, peor que los tokens de Mercado Pago, que al menos viajan cifrados.
     *   - visible_password / prev_password: contraseñas del comercio en claro.
     *   - articles_export_key / clave_eliminar_article: secretos operativos.
     *   - base_de_datos / session_id: el nombre de la base del cliente. No es una credencial,
     *     pero es el mapa para atacarlo.
     *
     * ⚠️ `api_url` NO se oculta, aunque tenga la misma pinta. El SPA lo necesita para abrir los
     * PDF de cuenta corriente contra empresa-api (views/CuentaCorriente.vue:170 y
     * components/cuenta-corriente/Table.vue:144). Ocultarlo rompe esos botones sin ningun error
     * visible: los dos hacen `if (!base) return`. Y no es un secreto: es el hostname publico del
     * ERP del cliente.
     *   - precio_por_cuenta / precio_plan / total_a_pagar / total_mensualidad / plan_discount:
     *     lo que ComercioCity le cobra a ese cliente, publicado en la vidriera del cliente.
     *
     * Esta lista es una red de seguridad, no la defensa principal: `users` es una tabla cajon de
     * sastre del ERP que crece con cada funcionalidad, asi que una lista negra sola se pudre. La
     * defensa principal es la proyeccion explicita de CommerceController@commerce, que manda
     * solo las columnas que el SPA usa. Y para que la red no envejezca sin que nadie se entere,
     * hay un test que falla cuando aparece una columna nueva con pinta de secreto:
     * tests/Feature/Seguridad/SerializacionDeSecretosTest.php.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
        'visible_password',
        'prev_password',
        'google_custom_search_api_key',
        'articles_export_key',
        'clave_eliminar_article',
        'base_de_datos',
        'session_id',
        'precio_por_cuenta',
        'precio_plan',
        'precio_ecommerce',
        'precio_mercado_libre',
        'precio_tienda_nube',
        'total_a_pagar',
        'total_mensualidad',
        'plan_discount',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function extencions() {
        return $this->belongsToMany('App\ExtencionEmpresa');
    }

    public function addresses() {
        return $this->hasMany('App\Address');
    }

    public function configuration() {
        return $this->hasOne('App\UserConfiguration');
    }

    public function schedules() {
        return $this->hasMany('App\Schedule');
    }

    public function articles() {
        return $this->hasMany('App\Article');
    }

    public function articles_sub_user() {
        return $this->hasMany('App\Article', 'sub_user_id');
    }

    public function employees() {
        return $this->hasMany('App\User', 'owner_id');
    }

    public function collections() {
        $status = auth()->user()->status;
        if ($status == 'admin' || $status == 'super') {
            return $this->hasMany('App\Collection', 'admin_id');
        } else {
            return $this->hasMany('App\Collection', 'commerce_id');
        }
    }

    public function owner() {
        return $this->belongsTo('App\User', 'id');  
    }

    public function admin() {
        return $this->belongsTo('App\User', 'id');  
    }

    public function commerces() {
        return $this->hasMany('App\User', 'admin_id');
    }

    public function online_configuration() {
        return $this->hasOne('App\OnlineConfiguration');
    }
}
