<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use App\Cart;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Avisos del carrito por ajuste de stock (mision carritos-ajuste-stock, tienda-api).
 *
 * empresa-api recorta la cantidad de un renglon del carrito cuando baja el stock y guarda lo que el
 * comprador habia pedido en `article_cart.amount_insuficiente`. Este helper es la parte de la tienda:
 *  - `tope_de_linea()`: espejo del criterio `limit_for_line()` de empresa-api (cuanto se puede
 *    comprar de un renglon). Las dos puntas llevan la misma tabla de casos de test.
 *  - `marcar_puede_agotarse()`: marca cada renglon del carrito con `puede_agotarse` cuando los
 *    carritos activos del comercio, juntos, piden mas que el disponible. Una sola consulta agrupada.
 *  - Utilidades de los renglones "esperando stock" (cantidad 0 con faltante) y del snapshot que usa
 *    el guardado del carrito para conservar el faltante y la casilla del comprador.
 */
class CartStockNoticeHelper
{
    /** Dias sin actividad a partir de los cuales un carrito deja de contar como "activo" (igual que empresa-api). */
    const ACTIVE_CART_DAYS = 15;

    /** Mensaje cuando el carrito no tiene ni una unidad para enviar. */
    const MENSAJE_SIN_UNIDADES = 'Tu carrito no tiene unidades disponibles para enviar.';

    /**
     * Cantidad maxima comprable de un renglon, o null si la tienda no le pone tope.
     *
     * Reglas (plan 2.1, 1 a 7): sin configuracion o con `ignorar_stock` no hay tope; un renglon sin
     * variante de un articulo que tiene variantes no se toca; el disponible es el del accessor
     * `stock` (ya descuenta la reserva web si esta activa); disponible null pone tope 0 solo para
     * articulos sin variante con `stock_null_equal_0`; con consultas activas y disponible 0 el
     * renglon se pide como "Consultar" (sin tope); si no, el tope es `max(0, disponible)`.
     *
     * @param  \App\OnlineConfiguration|null  $configuracion  Configuracion online del comercio.
     * @param  \App\Article  $articulo  Articulo del renglon (con `article_variants` cargadas si se puede).
     * @param  \App\ArticleVariant|null  $variante  Variante del renglon, null si no tiene.
     * @param  bool  $consultas_activas  Resultado de `ConsultaStockHelper::activa($configuracion)`.
     * @return float|null  Tope (0 incluido) o null si no hay tope.
     */
    public static function tope_de_linea($configuracion, $articulo, $variante, $consultas_activas)
    {
        // Regla 1 y 2: sin tienda o ignorando el stock, nada que topar.
        if (!$configuracion || $configuracion->ignorar_stock) {
            return null;
        }

        // Regla 3: renglon sin variante de un articulo con variantes (renglon invalido, no se toca).
        if (!$variante && $articulo->article_variants->isNotEmpty()) {
            return null;
        }

        // Regla 4: disponible del renglon (el accessor ya considera la reserva activa).
        $disponible = $variante ? $variante->stock : $articulo->stock;

        // Regla 5: sin dato de stock solo topa a 0 un articulo sin variante con stock_null_equal_0.
        if ($disponible === null) {
            return (!$variante && $configuracion->stock_null_equal_0) ? 0.0 : null;
        }

        $disponible = (float) $disponible;

        // Regla 6: con consultas activas, stock exactamente 0 se pide como "Consultar".
        if ($consultas_activas && $disponible === 0.0) {
            return null;
        }

        // Regla 7.
        return max(0.0, $disponible);
    }

    /**
     * Dice si un renglon esta "esperando stock": cantidad 0 con faltante pendiente.
     *
     * Acepta una fila cruda de `article_cart` (stdClass), un pivote de Eloquent o un array.
     *
     * @param  object|array|null  $linea
     * @return bool
     */
    public static function es_linea_esperando_stock($linea)
    {
        if (is_null($linea)) {
            return false;
        }
        $cantidad = is_array($linea) ? ($linea['amount'] ?? null) : ($linea->amount ?? null);
        $faltante = is_array($linea) ? ($linea['amount_insuficiente'] ?? null) : ($linea->amount_insuficiente ?? null);

        return is_numeric($cantidad) && (float) $cantidad <= 0
            && is_numeric($faltante) && (float) $faltante > 0;
    }

    /**
     * Foto de los renglones de articulo del carrito tal como estan en la base, por clave de renglon
     * (`articulo:variante`). Sirve para que el guardado completo conserve o resetee el faltante.
     * Vacia si la base todavia no tiene la columna `completar_al_reponer` (comportamiento de hoy).
     *
     * @param  \App\Cart  $cart
     * @return array  clave => ['amount', 'amount_insuficiente', 'completar_al_reponer']
     */
    public static function snapshot_de_lineas(Cart $cart)
    {
        if (!self::hay_casilla()) {
            return [];
        }

        $foto = [];
        foreach (ConsultaStockHelper::lineas_del_carrito($cart) as $linea) {
            $foto[ConsultaStockHelper::clave_linea($linea->article_id, $linea->variant_id)] = [
                'amount' => $linea->amount,
                'amount_insuficiente' => $linea->amount_insuficiente,
                'completar_al_reponer' => $linea->completar_al_reponer ?? 1,
            ];
        }
        return $foto;
    }

    /**
     * Saca del payload del carrito los renglones que estan esperando stock y siguen en 0, para que
     * no pasen por el control de stock (siguen guardandose). La decision se toma con la foto de la
     * base, nunca con lo que dice el navegador sobre el faltante.
     *
     * @param  array  $articulos  Lineas del payload del SPA.
     * @param  array  $previas  Resultado de `snapshot_de_lineas()`.
     * @return array
     */
    public static function sin_lineas_esperando_stock(array $articulos, array $previas)
    {
        if (empty($previas)) {
            return $articulos;
        }

        $resultado = [];
        foreach ($articulos as $articulo) {
            if (!isset($articulo['is_promocion_vinoteca'])) {
                $clave = ConsultaStockHelper::clave_linea(CartHelper::id_de_linea($articulo), $articulo['pivot']['variant_id'] ?? null);
                $cantidad = $articulo['pivot']['amount'] ?? null;
                if (isset($previas[$clave]) && self::es_linea_esperando_stock($previas[$clave])
                    && is_numeric($cantidad) && (float) $cantidad <= 0) {
                    continue;
                }
            }
            $resultado[] = $articulo;
        }
        return $resultado;
    }

    /**
     * Dice si la cantidad del payload es la ORIGINAL pedida de un renglon recortado
     * (`amount + amount_insuficiente` de la base): el valor viejo de una pestaña, no una decision.
     *
     * @param  array  $previa  Entrada de `snapshot_de_lineas()`.
     * @param  mixed  $cantidad  Cantidad que trae el payload.
     * @return bool
     */
    private static function es_cantidad_original(array $previa, $cantidad)
    {
        return is_numeric($previa['amount_insuficiente']) && (float) $previa['amount_insuficiente'] > 0
            && is_numeric($cantidad)
            && (float) $cantidad === (float) $previa['amount'] + (float) $previa['amount_insuficiente'];
    }

    /**
     * Reemplaza en el payload la cantidad vieja de una pestaña (la original pedida de un renglon
     * recortado) por la que realmente queda guardada, para que el control de stock, el envio y el
     * precio trabajen con lo que se va a guardar y no con unidades que se descartan (R2-4).
     *
     * @param  array  $articulos  Lineas del payload del SPA.
     * @param  array  $previas  Resultado de `snapshot_de_lineas()`.
     * @return array  Las mismas lineas, con la cantidad corregida donde corresponde.
     */
    public static function con_cantidades_guardadas(array $articulos, array $previas)
    {
        if (empty($previas)) {
            return $articulos;
        }

        foreach ($articulos as $indice => $articulo) {
            if (isset($articulo['is_promocion_vinoteca'])) {
                continue;
            }
            $clave = ConsultaStockHelper::clave_linea(CartHelper::id_de_linea($articulo), $articulo['pivot']['variant_id'] ?? null);
            if (isset($previas[$clave]) && self::es_cantidad_original($previas[$clave], $articulo['pivot']['amount'] ?? null)) {
                $articulos[$indice]['pivot']['amount'] = $previas[$clave]['amount'];
                if (isset($articulo['amount'])) {
                    $articulos[$indice]['amount'] = $previas[$clave]['amount'];
                }
            }
        }
        return $articulos;
    }

    /**
     * Atributos de pivote que corresponden a un renglon que se vuelve a guardar: conserva faltante y
     * casilla si la cantidad no cambio, los resetea (0 y marcada) si el comprador la cambio.
     *
     * Ronda 2 (R2-4): si el renglon esta recortado (`amount_insuficiente > 0`) y el payload trae la
     * cantidad ORIGINAL pedida (`amount + amount_insuficiente`), es el valor viejo de una pestaña que
     * no se enteró del recorte, no una decision del comprador: se conservan cantidad, faltante y
     * casilla de la base (el `amount` devuelto pisa al del payload en el `attach`).
     *
     * @param  array  $previas  Resultado de `snapshot_de_lineas()`.
     * @param  string  $clave  Clave del renglon (`ConsultaStockHelper::clave_linea`).
     * @param  float|int|string  $cantidad  Cantidad nueva del renglon.
     * @return array  Atributos extra del pivote (vacio si no hay nada que conservar).
     */
    public static function atributos_de_faltante(array $previas, $clave, $cantidad)
    {
        if (!isset($previas[$clave])) {
            return [];
        }
        $previa = $previas[$clave];
        if (self::es_cantidad_original($previa, $cantidad)) {
            // Pestaña vieja: se queda lo que dice la base, incluida la cantidad ya recortada.
            return [
                'amount' => $previa['amount'],
                'amount_insuficiente' => $previa['amount_insuficiente'],
                'completar_al_reponer' => $previa['completar_al_reponer'],
            ];
        }

        if (is_numeric($cantidad) && (float) $cantidad === (float) $previa['amount']) {
            return [
                'amount_insuficiente' => $previa['amount_insuficiente'],
                'completar_al_reponer' => $previa['completar_al_reponer'],
            ];
        }
        return ['amount_insuficiente' => 0, 'completar_al_reponer' => 1];
    }

    /**
     * Foto de cantidades `id de fila => cantidad` de los renglones de un articulo, antes de cambiar
     * una cantidad a mano (ver `resetear_faltante_si_cambio`).
     *
     * @param  \App\Cart  $cart
     * @param  int  $article_id
     * @return array
     */
    public static function cantidades_del_articulo(Cart $cart, $article_id)
    {
        if (!self::hay_casilla()) {
            return [];
        }
        return DB::table('article_cart')->where('cart_id', $cart->id)->where('article_id', $article_id)
            ->pluck('amount', 'id')->all();
    }

    /**
     * Despues de que el comprador cambio la cantidad de un renglon a mano, olvida el faltante y vuelve
     * a marcar la casilla en los renglones cuya cantidad cambio.
     *
     * @param  \App\Cart  $cart
     * @param  int  $article_id
     * @param  array  $antes  Resultado de `cantidades_del_articulo()` tomado antes del cambio.
     * @return void
     */
    public static function resetear_faltante_si_cambio(Cart $cart, $article_id, array $antes)
    {
        if (empty($antes)) {
            return;
        }
        $ahora = DB::table('article_cart')->where('cart_id', $cart->id)->where('article_id', $article_id)
            ->pluck('amount', 'id')->all();
        $cambiaron = [];
        foreach ($ahora as $fila_id => $cantidad) {
            if (isset($antes[$fila_id]) && (float) $antes[$fila_id] !== (float) $cantidad) {
                $cambiaron[] = $fila_id;
            }
        }
        if ($cambiaron) {
            DB::table('article_cart')->whereIn('id', $cambiaron)
                ->update(['amount_insuficiente' => 0, 'completar_al_reponer' => 1]);
        }
    }

    /**
     * Saca de una lista de renglones (filas crudas de `article_cart`) los que estan esperando stock.
     * Es el filtro unico que usan el control de stock de `update-article-amount` y el del cobro.
     *
     * @param  iterable  $lineas  Filas de `ConsultaStockHelper::lineas_del_carrito()`.
     * @return \Illuminate\Support\Collection  Renglones con unidades, reindexados.
     */
    public static function sin_renglones_esperando_stock($lineas)
    {
        return collect($lineas)->reject(fn ($linea) => self::es_linea_esperando_stock($linea))->values();
    }

    /**
     * Sanea en silencio los faltantes viejos de los renglones SIN tope (ronda 2, R2-1).
     *
     * Si el negocio pasa a ignorar el stock (o el articulo deja de tener tope) despues de que
     * empresa-api recorto un renglon, el faltante quedaria colgado y el comprador veria "ahora hay 0
     * disponibles". Por eso, a los renglones con `amount_insuficiente > 0` cuyo `tope_de_linea()` es
     * null se les devuelve la cantidad (con la casilla marcada o ausente: `amount + faltante`) o solo
     * se olvida el faltante (casilla desmarcada). Excepcion: con consultas activas y stock 0 el renglon
     * sigue esperando la reposicion (CA17). Sin mail ni Message y sin tocar `carts.updated_at`
     * (se escribe directo en `article_cart`). No agrega consultas cuando el carrito ya trae sus articulos y
     * ninguno tiene faltante; si no los trae, hace una sola consulta con las variantes.
     *
     * @param  \App\Cart  $cart  Carrito (con o sin `articles` cargados).
     * @return bool  true si cambio alguna fila (quien ya cargo el carrito tiene que releerlo).
     */
    public static function sanear_faltantes_sin_tope(Cart $cart)
    {
        $articulos = $cart->relationLoaded('articles')
            ? $cart->articles
            : $cart->articles()->with('article_variants')->wherePivot('amount_insuficiente', '>', 0)->get();

        $con_faltante = $articulos->filter(fn ($articulo) =>
            is_numeric($articulo->pivot->amount_insuficiente ?? null) && (float) $articulo->pivot->amount_insuficiente > 0);
        if ($con_faltante->isEmpty()) {
            return false;
        }

        $configuracion = ConsultaStockHelper::configuracion($cart);
        $consultas_activas = ConsultaStockHelper::activa($configuracion);
        $cambio = false;

        foreach ($con_faltante as $articulo) {
            $pivote = $articulo->pivot;
            $variante_id = $pivote->variant_id;
            $variante = $variante_id ? $articulo->article_variants->firstWhere('id', $variante_id) : null;
            if ($variante_id && !$variante) {
                continue;
            }
            if (self::tope_de_linea($configuracion, $articulo, $variante, $consultas_activas) !== null) {
                continue;
            }

            // "Consultar con 0": con consultas activas y stock exactamente 0 el renglon no tiene tope
            // solo porque se pide a consultar, pero sigue esperando la reposicion (CA17): no se sanea.
            $disponible = $variante ? $variante->stock : $articulo->stock;
            if ($consultas_activas && $disponible !== null && (float) $disponible === 0.0) {
                continue;
            }

            // Casilla marcada (o columna ausente): las unidades vuelven; desmarcada: solo se olvida el faltante.
            // El pivote cargado por la relacion no trae el id de la fila, asi que se la ubica por
            // carrito + articulo + variante (el renglon es unico por esa clave) y se suma en la base.
            $completar = (bool) ($pivote->completar_al_reponer ?? 1);
            // 🔴 `amount` va ANTES que `amount_insuficiente`: MySQL evalua el SET de izquierda a derecha.
            $cambios = [];
            if ($completar) {
                $cambios['amount'] = DB::raw('amount + amount_insuficiente');
            }
            $cambios['amount_insuficiente'] = 0;
            $fila = DB::table('article_cart')->where('cart_id', $cart->id)->where('article_id', $articulo->id)
                ->where('amount_insuficiente', '>', 0);
            $variante_id ? $fila->where('variant_id', $variante_id) : $fila->whereNull('variant_id');
            $fila->update($cambios);
            $cambio = true;
        }

        return $cambio;
    }

    /**
     * Corta el pedido si el carrito no tiene ninguna unidad para enviar: todos sus renglones de
     * articulo estan esperando stock y no hay promociones ni combos. Responde 422 con mensaje claro.
     *
     * @param  \App\Cart  $cart
     * @return void
     */
    public static function validar_hay_unidades_para_enviar(Cart $cart)
    {
        $lineas = ConsultaStockHelper::lineas_del_carrito($cart);
        if ($lineas->isEmpty() || $lineas->contains(fn ($linea) => !self::es_linea_esperando_stock($linea))) {
            return;
        }
        if ($cart->promociones_vinoteca()->exists()
            || (ComboEsquemaHelper::disponible() && $cart->combos()->exists())) {
            return;
        }
        ConsultaStockHelper::fallar(self::MENSAJE_SIN_UNIDADES);
    }

    /**
     * Guarda la casilla "completar al reponer" de un renglon del carrito.
     *
     * El controlador ya comprobo que la base tenga la columna (422 `no_disponible` si no). Falla con
     * 422 `consulta_stock` si el carrito ya se envio como pedido o el renglon (articulo + variante) ya no esta.
     *
     * @param  \App\Cart  $cart  Carrito ya bloqueado y con pertenencia comprobada.
     * @param  array  $datos  `id` (articulo), `variant_id` (o null) y `completar_al_reponer`.
     * @return void
     */
    public static function cambiar_casilla(Cart $cart, array $datos)
    {
        if (!is_null($cart->order_id)) {
            ConsultaStockHelper::fallar('Este carrito ya se envió como pedido.');
        }

        // Solo el renglon de esa variante (null = renglon sin variante).
        $renglon = DB::table('article_cart')->where('cart_id', $cart->id)
            ->where('article_id', $datos['id'])->where('variant_id', $datos['variant_id'] ?? null);
        if (!$renglon->exists()) {
            ConsultaStockHelper::fallar('El artículo o la variante ya no está en tu carrito.');
        }
        $renglon->update(['completar_al_reponer' => (bool) $datos['completar_al_reponer']]);
    }

    /**
     * Marca `puede_agotarse` en cada articulo del carrito cuando, sumando su cantidad con la de los
     * otros carritos activos del comercio (sin pedido y con actividad en los ultimos 15 dias), se
     * supera el disponible. Solo si la opcion `avisar_puede_agotarse` esta prendida y la columna
     * existe. Sin revelar cuantos. Una sola consulta agrupada para todos los renglones.
     *
     * @param  \App\Cart  $cart  Carrito completo (`getFullModel`) con sus articulos.
     * @return void
     */
    public static function marcar_puede_agotarse($cart)
    {
        $configuracion = ConsultaStockHelper::configuracion($cart);
        if (!self::opcion_puede_agotarse_prendida($configuracion) || $cart->articles->isEmpty()) {
            return;
        }

        $consultas_activas = ConsultaStockHelper::activa($configuracion);

        // Tope de cada renglon (null = sin tope, nunca avisa).
        $topes = [];
        foreach ($cart->articles as $articulo) {
            $variante_id = $articulo->pivot->variant_id;
            $variante = $variante_id ? $articulo->article_variants->firstWhere('id', $variante_id) : null;
            $articulo->puede_agotarse = false;
            if ($variante_id && !$variante) {
                continue;
            }
            $tope = self::tope_de_linea($configuracion, $articulo, $variante, $consultas_activas);
            if ($tope !== null) {
                $topes[ConsultaStockHelper::clave_linea($articulo->id, $variante_id)] = $tope;
            }
        }
        if (empty($topes)) {
            return;
        }

        // Lo que piden los otros carritos activos del comercio, agrupado por renglon.
        $ids = $cart->articles->pluck('id')->unique()->all();
        $otros = DB::table('article_cart')
            ->join('carts', 'carts.id', '=', 'article_cart.cart_id')
            ->where('carts.user_id', $cart->user_id)
            ->whereNull('carts.order_id')
            ->where('carts.id', '!=', $cart->id)
            ->where('carts.updated_at', '>=', Carbon::now()->subDays(self::ACTIVE_CART_DAYS))
            ->whereIn('article_cart.article_id', $ids)
            ->groupBy('article_cart.article_id', 'article_cart.variant_id')
            ->selectRaw('article_cart.article_id, article_cart.variant_id, SUM(article_cart.amount) AS total')
            ->get();

        $pedido_por_otros = [];
        foreach ($otros as $fila) {
            $pedido_por_otros[ConsultaStockHelper::clave_linea($fila->article_id, $fila->variant_id)] = (float) $fila->total;
        }

        foreach ($cart->articles as $articulo) {
            $clave = ConsultaStockHelper::clave_linea($articulo->id, $articulo->pivot->variant_id);
            if (isset($topes[$clave])) {
                $propio = (float) $articulo->pivot->amount;
                $articulo->puede_agotarse = ($propio + ($pedido_por_otros[$clave] ?? 0.0)) > $topes[$clave];
            }
        }
    }

    /**
     * Dice si la base tiene la casilla `article_cart.completar_al_reponer` (chequeo cacheado por request).
     *
     * @return bool
     */
    public static function hay_casilla()
    {
        return ConsultaStockHelper::hay_columna('article_cart', 'completar_al_reponer');
    }

    /**
     * Dice si la opcion `avisar_puede_agotarse` existe en la base y esta prendida para el comercio.
     *
     * La existencia de la columna se lee del propio modelo ya cargado (sin consulta extra al
     * esquema) y se memoriza por request en la misma clave que usa `hay_columna()`, de modo que el
     * aviso no suma consultas cuando la opcion esta apagada.
     *
     * @param  \App\OnlineConfiguration|null  $configuracion
     * @return bool
     */
    private static function opcion_puede_agotarse_prendida($configuracion)
    {
        if (!$configuracion) {
            return false;
        }
        $clave = 'tienda.consulta_stock.online_configurations.avisar_puede_agotarse';
        $atributos = request()->attributes;
        if (!$atributos->has($clave)) {
            $atributos->set($clave, array_key_exists('avisar_puede_agotarse', $configuracion->getAttributes()));
        }

        return $atributos->get($clave) && (bool) $configuracion->avisar_puede_agotarse;
    }
}
