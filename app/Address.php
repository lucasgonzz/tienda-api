<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $guarded = [];

    /**
     * Columnas que NO viajan en ninguna respuesta de la API.
     *
     * `ajuste_precio_tipo` ('recargo' | 'descuento' | NULL) y `ajuste_precio_porcentaje` las agrega
     * `empresa-api` a la tabla `addresses` (la misma base que comparte esta tienda): son la politica
     * INTERNA de precios de cada sucursal del negocio, el porcentaje que el ERP le suma o resta al
     * precio cuando se vende desde esa sucursal. La tienda no las lee ni las escribe para nada, y
     * son un dato comercial del dueño que no tiene por que ver ningun visitante.
     *
     * Por que hace falta ocultarlas aca: `GET /api/commerce/{id}` es una ruta publica, sin auth, y
     * CommerceController la arma con `User::with('addresses')`, o sea que serializa la relacion
     * `addresses` ENTERA (la lista blanca de ese controlador recorta las columnas de `users`, no las
     * de sus hijos). Y `Buyer::addresses()` (cuenta del comprador, Buyer::withAll()) devuelve esta
     * misma clase, asi que el $hidden tapa las dos puntas de una sola vez.
     *
     * Es compatible con una base que todavia NO tiene las columnas: ocultar un atributo que no
     * existe no hace nada, asi que no hay orden de despliegue que respetar entre empresa-api y esta
     * API. $hidden solo afecta a toArray()/toJson(): leer `$address->ajuste_precio_tipo` desde PHP
     * sigue funcionando.
     *
     * @var array
     */
    protected $hidden = [
        'ajuste_precio_tipo',
        'ajuste_precio_porcentaje',
    ];
}
