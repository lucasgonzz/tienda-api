<?php

namespace App\Http\Controllers\Helpers;

use App\PriceType;
use App\User;
use Illuminate\Support\Facades\DB;

/**
 * Que precio le corresponde a un combo segun la LISTA DE PRECIOS del comprador (mision
 * combos-calculados, 30/9/2026).
 *
 * ── La decision de Lucas ──────────────────────────────────────────────────────────────────────
 *
 * "El combo respeta la lista del comprador, con las mismas reglas que un articulo". Un combo
 * calculado desde sus articulos tiene un precio por cada lista (tabla `combo_price_type`, la
 * escribe `empresa-api`), y la tienda tiene que elegir la lista del comprador EXACTAMENTE como lo
 * hace `ArticleHelper::checkPriceTypes()` para un articulo. Si la eleccion difiriera, el mismo
 * comprador veria el taladro a un precio y el combo del taladro calculado con otra lista.
 *
 * ── La eleccion de la lista, caso por caso (espejo de `checkPriceTypes`) ─────────────────────
 *
 *   1. El comercio tiene la extension `lista_de_precios_por_rango_de_cantidad_vendida`: el
 *      articulo NO cambia de lista (esa rama solo arma los `ranges` y deja el `final_price` de la
 *      columna, o sea el de la lista por defecto). El combo tampoco: `combos.price`.
 *   2. Comprador logueado con un `Client` del ERP que tiene lista asignada: ESA lista. (El caso de
 *      `use_archivos_de_intercambio` de los articulos toma el precio de un archivo por
 *      `provider_code`, que un combo no tiene: para el combo vale la lista del cliente, que es lo
 *      mas cercano y lo que ve el resto del catalogo de ese comprador.)
 *   3. Nadie con lista propia (anonimo, o logueado sin cliente / sin lista): la de `position` mas
 *      alta del comercio. Para el ANONIMO no cuentan las listas `ocultar_al_publico` (aunque tengan
 *      la position mas alta); para el logueado si, como en el articulo.
 *   4. Si el comercio esta configurado para que el anonimo NO vea precios, o todas sus listas estan
 *      ocultas: se usa `combos.price`, que es lo que la tienda hacia hasta hoy con los combos.
 *      El articulo en ese caso se queda sin precio; el combo nunca tuvo esa proteccion y esta
 *      mision no la agrega (queda anotado como hallazgo, no como cambio).
 *
 * Una vez elegida la lista se busca la fila `(combo, lista)` en `combo_price_type`. Si no hay fila
 * —combo manual, cuenta sin listas, lista nueva que todavia no se recalculo— se usa `combos.price`,
 * que en empresa es SIEMPRE el precio de la lista por defecto. Por eso el fallback es seguro y por
 * eso una tienda vieja que solo lee esa columna sigue cobrando bien.
 *
 * ── Que NO hace ──────────────────────────────────────────────────────────────────────────────
 *
 *   - No aplica los descuentos/recargos por cliente (`AjustesDeClienteHelper`): eso va ENCIMA, lo
 *     hace cada llamador, y una sola vez. Este helper devuelve la BASE.
 *   - No aplica oferta personalizada ni tramos por cantidad: el combo nunca los tuvo.
 *   - No mira el payload: el precio sale de la base y de la sesion, nunca de lo que mande el
 *     navegador.
 *
 * ── Esquema ─────────────────────────────────────────────────────────────────────────────────
 *
 * `combo_price_type` la crea `empresa-api`. Sin la tabla (`ComboEsquemaHelper::precios_por_lista_
 * disponible()`) esto devuelve `combos.price` para todos y NO ejecuta ninguna otra consulta.
 */
class ComboPrecioHelper
{
    /**
     * El precio base (sin ajustes de cliente) de cada combo para el comprador de esta sesion.
     *
     * Una consulta a `combo_price_type` para todos los combos, mas las que cuesta resolver la lista
     * (una vez por llamada, no por combo).
     *
     * @param  iterable  $combos  Modelos `App\Combo` (con su columna `price`).
     * @param  int|string  $commerce_id  Comercio dueño (el de la pagina o el del carrito, nunca el
     *                                   del payload).
     * @return array<int, mixed>  [combo_id => precio]. El valor conserva el tipo que trae la base
     *                            (decimal como string), igual que `combos.price` hoy.
     */
    static function precios_base($combos, $commerce_id) {

        $precios = [];
        $ids = [];

        foreach ($combos as $combo) {
            $precios[$combo->id] = $combo->price;
            $ids[] = $combo->id;
        }

        /* La guarda va ANTES de cualquier consulta: sin la tabla, o sin combos, esto no le cuesta
           nada al comercio y devuelve exactamente lo de siempre. */
        if (count($ids) == 0 || !ComboEsquemaHelper::precios_por_lista_disponible()) {
            return $precios;
        }

        $price_type_id = self::lista_del_comprador($commerce_id);

        if (is_null($price_type_id)) {
            return $precios;
        }

        /* Ascendente por id: si por algun error hubiera dos filas de la misma lista, gana la mas
           nueva (empresa reescribe borrando e insertando, asi que la mas nueva es la vigente). */
        $filas = DB::table(ComboEsquemaHelper::TABLA_PRECIOS_POR_LISTA)
                        ->whereIn('combo_id', $ids)
                        ->where('price_type_id', $price_type_id)
                        ->orderBy('id', 'ASC')
                        ->get();

        foreach ($filas as $fila) {
            /* `price` es NOT NULL en la tabla y la consulta ya filtro por los ids de arriba: toda
               fila que llega pisa el precio de un combo que esta en `$precios`. */
            $precios[$fila->combo_id] = $fila->price;
        }

        return $precios;
    }

    /**
     * ¿El precio resuelto de un combo se puede cobrar? Solo si es numerico y mayor que cero.
     *
     * 🔴 Un combo publicado con precio NULL o 0 se podia comprar a $0: empresa deja `combos.price`
     * en 0.00 en un combo calculado sin articulos o con componentes sin precio, y la tienda lo
     * ofrecia y lo cobraba igual. La home no lo lista y el carrito no lo acepta.
     *
     * @param  mixed  $precio  El valor de `precios_base()` (decimal como string, o null).
     * @return bool
     */
    static function es_vendible($precio) {
        return is_numeric($precio) && (float) $precio > 0;
    }

    /**
     * El id de la lista de precios que le toca al comprador de esta sesion, o null si el combo
     * tiene que cobrar `combos.price`. Ver el docblock de la clase por cada caso.
     *
     * @param  int|string  $commerce_id
     * @return int|null
     */
    static function lista_del_comprador($commerce_id) {

        $commerce = User::find($commerce_id);

        if (is_null($commerce)) {
            return null;
        }

        /* Caso 1: con esta extension el articulo conserva el `final_price` de la columna. */
        if (CommerceHelper::hasExtencion('lista_de_precios_por_rango_de_cantidad_vendida', $commerce)) {
            return null;
        }

        $buyer = auth('buyer')->user();

        /* Caso 2: el comprador logueado con lista propia. */
        if (
            !is_null($buyer)
            && !is_null($buyer->comercio_city_client)
            && !is_null($buyer->comercio_city_client->price_type)
        ) {
            return $buyer->comercio_city_client->price_type->id;
        }

        $es_anonimo = is_null($buyer);

        /* Caso 4: la tienda no le deja ver precios al visitante sin login. */
        if ($es_anonimo && !ArticleHelper::anonimo_puede_ver_precios($commerce_id)) {
            return null;
        }

        /* Caso 3: la de `position` mas alta. El desempate por id DESC no lo hace el articulo
           (`checkPriceTypes` no desempata), pero es el mismo criterio con el que empresa elige la
           lista por defecto al escribir `combos.price`: asi, ante una configuracion con dos listas
           en la misma position, el combo cobra lo mismo con y sin fila por lista. */
        $query = PriceType::where('user_id', $commerce_id)
                            ->whereNotNull('position');

        if ($es_anonimo) {
            /* Las ocultas al publico no juegan para el anonimo. Loose a proposito: NULL y 0 son
               "visible", igual que el `!= 1` de checkPriceTypes. */
            $query->where(function ($sub_query) {
                $sub_query->whereNull('ocultar_al_publico')
                            ->orWhere('ocultar_al_publico', '!=', 1);
            });
        }

        $lista = $query->orderBy('position', 'DESC')
                        ->orderBy('id', 'DESC')
                        ->first();

        return is_null($lista) ? null : $lista->id;
    }
}
