<?php

namespace App;

use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];

    /**
     * Los tres json del envío por correo (misión zipnova-envios, 14/9/2026), copiados del carrito
     * al crear el pedido. `envio_precio` y `envio_proveedor` son columnas planas, sin cast. Misma
     * forma que en `empresa-api/app/Models/Order.php`.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'envio_cotizacion' => 'array',
        'envio_opcion'     => 'array',
        'envio_destino'    => 'array',
    ];

    /**
     * ⚠️ `envio` NO va en el withAll, a diferencia del ERP: la tabla `envios` la crea
     * `empresa-api` y hay bases de clientes donde todavía no existe. Cargarla de oficio sería un
     * 500 en cada listado de pedidos de esos clientes. Se suma a mano en
     * `OrderController@index/@current` cuando `ZipnovaEsquemaHelper::tabla_envios()` lo confirma.
     */
    function scopeWithAll($query) {
        $query->with('articles.images', 'buyer', 'payment_method', 'delivery_zone', 'cupons', 'order_status', 'articles.article_properties.article_property_values', 'articles.article_properties.article_property_type', 'articles.article_variants.article_property_values.article_property_type', 'articles.discounts');

        /* Mismo criterio que `envio`, y la misma guarda: `order_combo` la crea `empresa-api`.
           Ver `ComboEsquemaHelper`. */
        if (ComboEsquemaHelper::disponible()) {
            $query->with('combos.articles.images');
        }
    }

    /**
     * El envío generado en Zipnova para este pedido: el MÁS NUEVO, porque un pedido puede tener
     * un envío cancelado (o uno que nunca se pudo crear) y después otro que sí viajó. Mismo
     * `latestOfMany()` que `empresa-api`. Solo lectura de este lado (ver `App\Envio`).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    function envio() {
        return $this->hasOne('App\Envio')->latestOfMany();
    }

    function promociones_vinoteca() {
        return $this->belongsToMany('App\PromocionVinoteca')->withPivot('amount', 'price', 'notes');
    }

    /**
     * Los combos del pedido.
     *
     * 🔴 La tabla va EXPLICITA. Por convención Laravel armaría `combo_order` (alfabético), y la
     * que crea `empresa-api` —calcada de `order_promocion_vinoteca`— se llama `order_combo`. Sin
     * este parámetro la relación apunta a una tabla que no existe en ninguna base.
     *
     * 🔴 Nunca se toca sin preguntarle antes a `ComboEsquemaHelper::disponible()`.
     */
    function combos() {
        return $this->belongsToMany('App\Combo', 'order_combo', 'order_id', 'combo_id')->withPivot('amount', 'price', 'notes');
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
