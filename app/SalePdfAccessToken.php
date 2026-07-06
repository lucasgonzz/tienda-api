<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Token de un solo uso para autorizar la descarga del PDF de una venta desde la tienda.
 * Tabla compartida con empresa-api (sin migración propia en tienda-api).
 */
class SalePdfAccessToken extends Model
{
    protected $guarded = [];
}
