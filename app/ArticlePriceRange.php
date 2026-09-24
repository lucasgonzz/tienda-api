<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Un tramo de precio por cantidad de UN articulo (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * La tabla la crea `empresa-api` (migracion `2025_11_04_123716_create_article_price_ranges_table`)
 * y este repo solo la LEE: aca no hay migraciones y el deploy de la tienda no corre `migrate`.
 * Columnas: `article_id`, `modo` ('Igual' | 'Mayor o igual'), `amount` decimal(10,2),
 * `price` decimal(20,2) NULLABLE, `porcentaje` decimal(8,2) NULLABLE, `temporal_id`.
 *
 * 🔴 UNA OFERTA POR CANTIDAD TIENE DOS FORMAS, Y SON EXCLUYENTES (mision
 * oferta-por-cantidad-porcentaje, 24/9/2026). Hasta esta mision `price` era la unica:
 *
 *   - `price`: precio unitario FIJO Y ABSOLUTO. Cortocircuita la cadena normal de precios (listas,
 *     recargos, descuentos por medio de pago). El numero cargado en el ERP se asume CON IVA.
 *     Gana SIEMPRE cuando es mayor a cero, aunque la fila tambien traiga porcentaje.
 *   - `porcentaje`: descuento sobre el precio que la linea IBA A TENER, o sea que sigue al precio
 *     del articulo en vez de reemplazarlo. Usable solo entre 0 y 100, los dos excluidos.
 *
 * Cual de las dos aplica lo decide `ArticlePriceRangeHelper::precio()`, una sola vez y en un solo
 * lugar; el criterio literal, con sus tres puntas y sus bordes, esta en el docblock de esa clase.
 *
 * 🔴 `porcentaje` LA MIGRA `empresa-api` Y ESTE REPO NO. El esquema llega a cada cliente con el
 * release del ERP, pero la tienda la despliega Lucas a mano, sitio por sitio: va a haber clientes
 * con la tienda nueva contra una base SIN la columna durante dias. Eloquent devuelve null para un
 * atributo que no existe en la tabla, asi que LEER `$fila->porcentaje` es seguro. Lo que reventaria
 * es NOMBRARLA EN UNA QUERY (`whereNotNull`, un `select` explicito, un `orderBy`): eso es un
 * "Unknown column" en el medio del carrito. Nadie de este repo la nombra en una query.
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
