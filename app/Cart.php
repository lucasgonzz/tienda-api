<?php

namespace App;

use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{

	protected $guarded = [];

    /**
     * Los tres json del envío por correo (misión zipnova-envios, 14/9/2026). Los escribe esta
     * tienda durante el checkout (`EnvioCartHelper`) y `OrderController@store` los copia al pedido.
     * Misma forma que en `empresa-api/app/Models/Cart.php`: la base es compartida.
     *
     * ⚠️ Las columnas las crea una migración de `empresa-api` y hay bases de clientes donde
     * todavía no están. Leer un atributo ausente devuelve null sin consultar nada, pero
     * ESCRIBIRLO revienta el UPDATE: por eso toda escritura pasa por `ZipnovaEsquemaHelper`.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'envio_cotizacion' => 'array',
        'envio_opcion'     => 'array',
        'envio_destino'    => 'array',
    ];

    /**
     * ⚠️ `combos` va detrás de su guarda de esquema, igual que `envio` en `Order`: la tabla
     * `cart_combo` la crea `empresa-api` y hay bases de clientes donde todavía no existe. Cargarla
     * de oficio sería un 500 en cada operación del carrito de esos clientes — o sea, la compra
     * caída entera. Ver `ComboEsquemaHelper`.
     */
    function scopeWithAll($query) {
        $query->with('cupon', 'articles.images', 'articles', 'articles.colors', 'articles.sizes', 'payment_method.type', 'payment_method.payment_method_installments', 'delivery_zone', 'promociones_vinoteca.images');

        if (ComboEsquemaHelper::disponible()) {
            $query->with('combos.articles.images');
        }
    }

    function articles() {
        return $this->belongsToMany('App\Article')->withPivot('price', 'amount', 'variant_id', 'amount_insuficiente', 'notes')->orderBy('name', 'ASC');
    }

    function promociones_vinoteca() {
        return $this->belongsToMany('App\PromocionVinoteca')->withPivot('price', 'amount', 'notes');
    }

    /**
     * La tercera colección comprable del carrito, hermana de `promociones_vinoteca`.
     * Pivote `cart_combo` (el nombre por convención ya coincide).
     *
     * 🔴 Nunca se toca sin preguntarle antes a `ComboEsquemaHelper::disponible()`.
     */
    function combos() {
        return $this->belongsToMany('App\Combo')->withPivot('price', 'amount', 'notes');
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
