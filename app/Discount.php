<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Descuento de VENTA del ERP (`discounts`), el mismo que Vender aplica sobre el total.
 *
 * La tabla la crea y la escribe `empresa-api`; la tienda solo la lee, para los ajustes que el
 * comerciante le vinculo a un cliente (`client_discount`, mision descuentos-recargos-por-cliente).
 * La lectura de precios pasa por `AjustesDeClienteHelper` con query builder: este modelo existe
 * para que el esquema quede nombrado de este lado y para los tests.
 *
 * SoftDeletes a proposito: un descuento borrado en el ERP deja de aplicarse en la tienda, pero la
 * fila sigue existiendo (y el pedido que ya lo uso conserva su porcentaje en `discount_order`).
 */
class Discount extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function scopeWithAll($query) {}
}
