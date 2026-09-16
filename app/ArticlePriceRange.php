<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Un tramo de precio por cantidad de UN articulo (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * La tabla la crea `empresa-api` (migracion `2025_11_04_123716_create_article_price_ranges_table`)
 * y este repo solo la LEE: aca no hay migraciones y el deploy de la tienda no corre `migrate`.
 * Columnas: `article_id`, `modo` ('Igual' | 'Mayor o igual'), `amount` decimal(10,2),
 * `price` decimal(20,2) NULLABLE, `temporal_id`.
 *
 * 🔴 `price` es un precio unitario FIJO Y ABSOLUTO, no un porcentaje ni una lista: cortocircuita
 * la cadena normal de precios (listas, recargos, descuentos por medio de pago). El numero cargado
 * en el ERP se asume CON IVA.
 *
 * ⚠️ Sin `user_id`: el aislamiento por comercio lo da el articulo (`article_price_ranges.article_id`
 * -> `articles.user_id`). Nunca consultar esta tabla suelta filtrando por comercio.
 *
 * Quien decide cual de los tramos aplica es `ArticlePriceRangeHelper`, una sola vez y en un solo
 * lugar. Este modelo es nada mas el transporte.
 */
class ArticlePriceRange extends Model
{
    protected $guarded = [];

    function article() {
        return $this->belongsTo('App\Article');
    }
}
