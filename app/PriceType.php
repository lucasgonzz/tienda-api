<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class PriceType extends Model
{
    /**
     * `catalogo_restringido_en_tienda` es el interruptor de la lista que decide que articulos ve cada
     * comprador (mision catalogo-por-lista-tienda): lo escribe el ERP y solo lo lee
     * `CatalogoPorListaHelper`. No tiene por que viajar en el JSON: `article.price_types[]` serializa
     * cada lista del articulo para todos los compradores, el visitante incluido, y con la marca
     * revelaria cual de las listas es la restringida. El SPA de la tienda no la lee (cero usos en
     * `tienda-spa/src`, medido el 5/10/2026).
     *
     * 🔴 `$hidden` solo toca la serializacion (`toArray()` / `toJson()`): leer
     * `$lista->getAttribute('catalogo_restringido_en_tienda')` desde PHP sigue andando, que es lo que
     * hace el helper. Si la columna todavia no existe en la base, ocultarla no hace nada.
     *
     * @var array<int, string>
     */
    protected $hidden = ['catalogo_restringido_en_tienda'];

    function sub_categories() {
        return $this->belongsToMany(SubCategory::class)->withPivot('percentage');
    }
}
