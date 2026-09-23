<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use App\ArticlePriceTypeGroup;
use App\Cart;
use App\Combo;
use App\Cupon;
use App\Http\Controllers\Helpers\AjustesDeClienteHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ClientOfferHelper;
use App\PromocionVinoteca;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CartHelper {

    static function checkPaymentStatus($cart) {
        if (!is_null($cart->payment_id) && !is_null($cart->order_id)) {
            $order = Order::find($cart->order_id);
            $order->payment_id = $cart->payment_id;
            $order->save();
        }
    }

    static function attachCupons($cart, $cupons) {
        $cart->cupons()->detach();
        foreach ($cupons as $cupon) {
            $cart->cupons()->attach($cupon['id']);
        }
    }

    static function attachArticles($cart, $articles) {
        
        if (count($articles) == 0) {
            return;
        }

        $has_price_ranges = CommerceHelper::hasExtencion('lista_de_precios_por_rango_de_cantidad_vendida', null, $articles[0]['user_id']);

        $article_groups = ArticlePriceTypeGroup::with('articles')->get();

        foreach ($articles as $article) {

            if (!isset($article['is_promocion_vinoteca'])) {

                /*
                 * El comercio sale del CARRITO y no del payload. Es el filtro de aislamiento
                 * entre comercios de la oferta personalizada: si saliera de
                 * $articles[0]['user_id'] —que es lo que manda el navegador—, el atacante
                 * elegiria contra que comercio se busca la oferta. `$cart->user_id` lo escribio
                 * el servidor al crear el carrito.
                 */
                $price = Self::get_price($articles, $article, $has_price_ranges, $article_groups, $cart->user_id);

                Log::info('price para guardar: '.$price);

                $cart->articles()->attach($article['id'], [
                                            'price'         => $price,
                                            'cost'          => $article['cost'],
                                            'amount'        => $article['pivot']['amount'],
                                            'notes'         => $article['pivot']['notes'],
                                            'variant_id'    => isset($article['pivot']['variant_id']) ? $article['pivot']['variant_id'] : null,
                                            // 'color_id'      => $article['pivot']['color_id'],
                                            // 'color_id'   => ArticleHelper::getColorId($article),
                                            // 'size_id'    => ArticleHelper::getSizeId($article),
                                        ]);
            }
            
        }
    }

    static function attach_promociones_vinoteca($cart, $promociones_vinoteca) {

        /*
         * Los ajustes del cliente van tambien sobre las promos (decision 2 de Lucas). Mismo
         * criterio que get_price(): el `final_price` del payload ya viene AJUSTADO (la API se lo
         * mando asi al SPA), asi que primero se vuelve a la base y recien despues se aplica el
         * factor, una vez, con los porcentajes de la base.
         *
         * El desajuste va SIEMPRE, tambien sin contrato (sin sesion de cuenta, sin cliente o sin
         * tablas): una promo que este servidor ajusto antes puede volver en el payload de un
         * comprador que ya no tiene los ajustes. Ver get_price(). Sin la clave en el payload no
         * cambia nada y la promo cobra exactamente lo de master.
         */
        $ajustes = AjustesDeClienteHelper::del_comprador($cart->user_id);

        foreach ($promociones_vinoteca as $promo) {

            $promo = AjustesDeClienteHelper::desajustar_linea($promo);

            // if (isset($promo['is_promocion_vinoteca'])) {

                $cart->promociones_vinoteca()->attach($promo['id'], [
                                            'price'         => AjustesDeClienteHelper::ajustar($promo['final_price'], $ajustes),
                                            'cost'          => $promo['cost'],
                                            'amount'        => $promo['pivot']['amount'],
                                            'notes'         => $promo['pivot']['notes'],
                                        ]);
            // }
            
        }
    }

    /**
     * Cuelga del carrito los combos del payload (mision combos-y-rangos-de-precio, 16/9/2026).
     *
     * ── EL PRECIO SALE DE LA BASE, NO DEL PAYLOAD, Y ES A PROPOSITO ──────────────────────────
     * `attach_promociones_vinoteca()` —el molde de este metodo— usa `$promo['final_price']`, o sea
     * el numero que mando el navegador. Eso es el agujero PREEXISTENTE de este repo ("el cliente
     * fija el precio"), documentado en `get_price()`, y su arreglo es otra mision. Pero una
     * coleccion NUEVA no tiene por que nacer con el agujero adentro: `combos.price` es un precio
     * fijo, igual para todos los compradores, sin listas ni recargos de por medio, asi que
     * resolverlo del lado del servidor cuesta UNA query para todo el carrito.
     *
     * Y de paso cierra dos cosas mas, con el mismo `where`: un combo de OTRO comercio y un combo
     * que no esta publicado (`online = 0`) no se pueden meter en el carrito. El comercio sale del
     * CARRITO (`$cart->user_id`, que lo escribio el servidor) y no del payload — mismo criterio
     * que `attachArticles`.
     *
     * Un combo del payload que no matchee nada de eso se saltea en silencio, como hace
     * `attachArticles` con las lineas que no le corresponden.
     *
     * ── 🔴 UN COMBO REPETIDO EN EL PAYLOAD SE CUELGA UNA SOLA VEZ ────────────────────────────
     *
     * Medido antes del arreglo: dos entradas con el mismo `combo_id` en el body dejaban 2 filas en
     * `cart_combo` y un total de 18.000 donde iban 9.000. Cobra de MAS, asi que no es una fuga de
     * plata — pero es plata mal cobrada igual, y es exactamente la forma de bug que
     * `check_repetidos()` existe para tapar en la coleccion de articulos.
     *
     * Gana LA PRIMERA entrada, que es el mismo criterio de `check_repetidos()` ("mantener solo una
     * relacion, la primera"). Las cantidades NO se suman: sumarlas seria inventar una regla que la
     * coleccion de articulos no tiene, y el SPA manda una entrada por combo con su `amount`
     * adentro — un id repetido es un payload roto, no un pedido de dos unidades.
     *
     * ⚠️ Y va ACA y no en `check_repetidos()` a proposito: aquel corre dentro de `getFullModel()`,
     * o sea DESPUES de `set_total()`, asi que aun funcionando dejaria las filas bien y el total
     * mal — que es la mitad que importa. Deduplicando al colgar, el total se calcula una sola vez y
     * ya sale bien.
     *
     * @param  \App\Cart  $cart
     * @param  array|null  $combos
     * @return void
     */
    static function attach_combos($cart, $combos) {

        if (!ComboEsquemaHelper::disponible()) {
            return;
        }

        if (is_null($combos) || !is_array($combos) || count($combos) == 0) {
            return;
        }

        $ids = [];

        foreach ($combos as $combo) {
            if (isset($combo['id']) && is_numeric($combo['id'])) {
                $ids[] = (int) $combo['id'];
            }
        }

        if (count($ids) == 0) {
            return;
        }

        $modelos = Combo::whereIn('id', array_unique($ids))
                        ->where('user_id', $cart->user_id)
                        ->where('online', 1)
                        ->get()
                        ->keyBy('id');

        /* Los que ya se colgaron en esta pasada. Ver el docblock: gana la primera entrada. */
        $ya_colgados = [];

        /* Los ajustes del cliente tambien van sobre los combos (decision 2 de Lucas). Aca el
           precio sale de la BASE (`combos.price`, sin ajustar), asi que el factor se aplica
           derecho y una sola vez. Sin ajustes, `ajustar()` devuelve el precio intacto. */
        $ajustes = AjustesDeClienteHelper::del_comprador($cart->user_id);

        foreach ($combos as $combo) {

            if (!isset($combo['id']) || !$modelos->has((int) $combo['id'])) {
                continue;
            }

            $id = (int) $combo['id'];

            if (isset($ya_colgados[$id])) {
                continue;
            }

            $ya_colgados[$id] = true;

            $modelo = $modelos->get($id);

            $cart->combos()->attach($modelo->id, [
                                        'price'     => AjustesDeClienteHelper::ajustar($modelo->price, $ajustes),
                                        'cost'      => $modelo->cost,
                                        'amount'    => isset($combo['pivot']['amount']) ? $combo['pivot']['amount'] : 1,
                                        'notes'     => isset($combo['pivot']['notes']) ? $combo['pivot']['notes'] : null,
                                    ]);
        }
    }

    /**
     * 🔴 Vuelve a resolver, contra la base, el precio de las lineas que tienen oferta
     * personalizada. Corre ANTES de sumar el total, en todos los caminos que escriben el carrito.
     *
     * ── EL DEFECTO QUE ARREGLA, MEDIDO EN LA TIENDA CORRIENDO ────────────────────────────────
     * `CartController::update_article_amount()` —el boton "Actualizar" de la ficha— cambia el
     * `amount` del pivot con `updateExistingPivot` y NO vuelve a pasar por `get_price()`. Antes
     * de esta mision eso era inofensivo, porque el precio no dependia de la cantidad. Con una
     * oferta por tramos SI depende:
     *
     *   Articulo de $3.948 con tramos 1-5 al 5%, 6-11 al 10% y 12+ al 18%.
     *   El comprador agrega 12 -> se guarda price = 3.237,36 (el tramo del 18%).
     *   Cambia la cantidad a 1 y aprieta Actualizar -> el pivot queda amount = 1 y
     *   price = 3.237,36. La pantalla le mostraba $3.750,60 (el tramo del 5%, que es el que le
     *   corresponde) y el carrito guardaba $3.237,36. Total: 3.237,36 en vez de 3.750,60.
     *
     * O sea que el descuento mas profundo se conseguia con cualquier cantidad, sin manipular
     * nada: agregando 12 y bajando a 1 con un boton de la interfaz. Y la pantalla y el carrito
     * decian numeros distintos, que es el peor sintoma posible en el camino de la plata.
     *
     * ── POR QUE ACA Y NO EN EL CONTROLLER ────────────────────────────────────────────────────
     * `set_total()` es el unico punto por el que pasan TODOS los caminos que escriben el carrito
     * (`store`, `update` y `update_article_amount`). Arreglarlo en uno solo dejaria los otros dos
     * dependiendo de que nadie cambie el orden de las llamadas.
     *
     * ── LA BASE LA DERIVA EL SERVIDOR, NO EL PAYLOAD ─────────────────────────────────────────
     * A diferencia de `get_price()` —que recibe la base del navegador porque asi funciona todo
     * el carrito de este repo—, aca no hay payload: el precio se reconstruye cargando los
     * articulos por el mismo camino que `getFullModel()` y pasandolos por `checkPriceTypes()`,
     * que es exactamente lo que la tienda le mostro al comprador.
     *
     * ── LA ASIMETRIA, Y ES LA PARTE QUE HAY QUE ENTENDER ANTES DE TOCAR ESTO ─────────────────
     * No alcanza con revisar las lineas que HOY tienen oferta: las que hay que corregir son
     * justamente las que la PERDIERON (el comerciante la cancelo, o vencio), que ya no aparecen
     * en ninguna lista de ofertas vigentes. Por eso se recorren todas las lineas del carrito.
     *
     * Y por eso mismo la escritura es asimetrica:
     *   - Linea CON oferta vigente: se escribe el precio del tramo, para arriba o para abajo.
     *     Es el precio que el comprador esta viendo en pantalla.
     *   - Linea SIN oferta: se escribe SOLO si el precio nuevo es MAYOR, o sea unicamente para
     *     deshacer un descuento que ya no corresponde. Nunca para otorgar uno.
     *
     * Esa asimetria es a proposito: para una linea sin oferta, la base que resuelve el servidor
     * es la misma cifra que el carrito guarda hoy, asi que en el caso honesto no se escribe nada
     * y el comportamiento es identico a master. "El cliente fija el precio base" sigue siendo un
     * agujero PREEXISTENTE de este repo y arreglarlo es otra mision; lo unico que se cierra aca
     * es que esta funcionalidad no lo agrande.
     *
     * ── LO BARATO PRIMERO ────────────────────────────────────────────────────────────────────
     * La primera guarda es `hayContrato()`: 0 queries sin sesion o sin cliente del ERP, y la del
     * information_schema memoizada en el resto. Sin las tablas del contrato —o sea, hoy, en
     * todos los clientes— no se carga ni un articulo y esto no cuesta nada.
     *
     * @param  \App\Cart  $cart
     * @param  \ArrayObject|null  $memo  Si viene, deja en 'articulos' lo que cargo y resolvio, para
     *                                   que `resincronizar_ajustes_de_cliente()` no lo vuelva a cargar
     *                                   en el mismo `set_total()`. No cambia nada de lo que hace aca.
     * @return void
     */
    static function resincronizar_precios_de_oferta($cart, $memo = null) {
        try {
            if (!ClientOfferHelper::hayContrato()) {
                return;
            }

            /* Las lineas del carrito, una por una: el tramo se elige con la cantidad de ESA
               linea, igual que en get_price(). Un mismo articulo puede estar dos veces con
               variantes distintas, y cada fila tiene su propia cantidad. */
            $lineas = DB::table('article_cart')->where('cart_id', $cart->id)->get();

            if (count($lineas) == 0) {
                return;
            }

            /* Mismo camino que getFullModel(): withAll() trae las price_types que
               checkPriceTypes() necesita para resolver el precio de este comprador, y de paso
               cuelga la oferta personalizada en los articulos que la tengan. */
            $articulos = Article::whereIn('id', $lineas->pluck('article_id')->unique()->all())
                                ->withAll()
                                ->get();

            if (count($articulos) == 0) {
                return;
            }

            $articulos = ArticleHelper::checkPriceTypes($articulos);

            if (!is_null($memo)) {
                $memo['articulos'] = $articulos;
            }

            /* Los ajustes del cliente (mision descuentos-recargos-por-cliente) van ENCIMA de la
               oferta: el precio de la linea es el de la oferta por el factor. Sin ajustes,
               `ajustar()` devuelve el precio intacto y esto queda byte por byte como antes. */
            $ajustes = AjustesDeClienteHelper::del_comprador($cart->user_id);

            foreach ($lineas as $linea) {
                $articulo = $articulos->firstWhere('id', $linea->article_id);

                if (is_null($articulo)) {
                    continue;
                }

                $tiene_oferta = isset($articulo->oferta_personalizada)
                                && !empty($articulo->oferta_personalizada['precio_aplicado']);

                /* La base la resuelve el servidor: `precio_sin_oferta` cuando hay oferta, y el
                   `final_price` de checkPriceTypes cuando no.

                   🔴 Sin oferta la base es el precio SIN los ajustes del cliente y no
                   `final_price` a secas: checkPriceTypes() ya le aplico el factor a
                   `final_price`, y abajo se aplica otra vez sobre lo que devuelva
                   precioDeLinea(). Con `final_price` el factor iria dos veces. */
                $base = $tiene_oferta && isset($articulo->precio_sin_oferta)
                        ? $articulo->precio_sin_oferta
                        : AjustesDeClienteHelper::precio_sin_ajustes($articulo);

                if (!is_numeric($base)) {
                    continue;
                }

                $precio = AjustesDeClienteHelper::ajustar(ClientOfferHelper::precioDeLinea([
                    'id'                => $articulo->id,
                    'amount'            => $linea->amount,
                    'precio_sin_oferta' => $base,
                    'precio_pausado'    => isset($articulo->precio_pausado) ? $articulo->precio_pausado : null,
                ], $cart->user_id), $ajustes);

                if (is_null($precio) || (float) $precio === (float) $linea->price) {
                    continue;
                }

                /* Sin oferta vigente solo se corrige HACIA ARRIBA: deshacer un descuento que ya
                   no vale, nunca otorgar uno. Ver la asimetria en el docblock. */
                if (!$tiene_oferta && (float) $precio < (float) $linea->price) {
                    continue;
                }

                DB::table('article_cart')->where('id', $linea->id)->update(['price' => $precio]);
            }
        } catch (\Throwable $e) {
            /* Una oferta que falla no puede romper el carrito: se deja el precio que ya estaba,
               que es el comportamiento de master, y queda constancia. */
            Log::warning('CartHelper::resincronizar_precios_de_oferta fallo, el carrito sigue con el precio anterior.', [
                'cart_id'   => $cart->id,
                'excepcion' => get_class($e),
                'mensaje'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * 🔴 Vuelve a resolver, contra la base, el precio de las lineas cuyo articulo tiene TRAMOS POR
     * CANTIDAD. Es el pedido textual de Lucas: "que en base a las cantidades que el usuario
     * agregue al carrito sea el precio que le va a aparecer en el carrito".
     *
     * ── EL HUECO QUE TAPA ────────────────────────────────────────────────────────────────────
     * `CartController::update_article_amount()` —el boton "Actualizar" del carrito— cambia el
     * `amount` del pivot con `updateExistingPivot` y NO vuelve a pasar por `get_price()`. Con un
     * precio que depende de la cantidad eso significa que el comprador agrega 10 unidades al
     * precio del tramo, baja a 1 y se queda con el precio del tramo de 10. Exactamente el mismo
     * defecto que ya tenia la oferta personalizada y que arregla el metodo de arriba; por eso
     * este va al lado y por el mismo camino.
     *
     * ── POR QUE ACA Y NO EN EL CONTROLLER ────────────────────────────────────────────────────
     * `set_total()` es el unico punto por el que pasan TODOS los caminos que escriben el carrito
     * (`store`, `update` y `update_article_amount`). Es la misma razon, palabra por palabra, que
     * la de `resincronizar_precios_de_oferta`.
     *
     * ── EL ORDEN CON LA RESINCRONIZACION DE OFERTAS NO ES INDISTINTO ─────────────────────────
     * Corre DESPUES, y tiene que correr despues. Aquella, para una linea sin oferta vigente,
     * escribe el `final_price` cuando es mayor que lo guardado — o sea que le pisaria el precio
     * del tramo a una linea con descuento por cantidad. Corriendo al final, esta lo deja bien sin
     * importar lo que haya hecho la otra.
     *
     * ── PRECEDENCIA: LA OFERTA PERSONALIZADA GANA ────────────────────────────────────────────
     * Misma decision que en `get_price()` y por los mismos motivos (ver alla el bloque largo). La
     * señal de que una linea es del carril de ofertas es que `checkPriceTypes()` le dejo un
     * `precio_sin_oferta` al articulo: esas lineas se saltean enteras y las sigue gobernando
     * `resincronizar_precios_de_oferta`.
     *
     * ── LA ASIMETRIA, CALCADA DE LA DE OFERTAS ───────────────────────────────────────────────
     *   - Hay tramo para esta cantidad: se escribe su precio, para arriba o para abajo. Es el
     *     numero que el comprador esta viendo en la pantalla.
     *   - No hay tramo (bajo la cantidad y ya no le corresponde ninguno): se vuelve al precio
     *     normal, pero SOLO si es MAYOR que el guardado. O sea unicamente para deshacer un
     *     descuento que ya no corresponde; nunca para otorgar uno.
     *
     * ── 🔴 LO BARATO PRIMERO, Y ESTO NO ES UNA OPTIMIZACION: ES UN INVARIANTE DE COSTO ───────
     *
     * La primera version de este metodo entraba con `ArticlePriceRangeHelper::hay_tabla()` a
     * secas, y esa guarda NO FILTRA A NADIE: la tabla la crea una migracion de noviembre de 2025 y
     * hoy la tienen todos los clientes. O sea que cada comercio —usara tramos o no— pagaba en cada
     * recalculo del carrito una lectura de `article_cart` mas un `whereHas` con `withAll()`
     * encima, en los TRES caminos que escriben el carrito, todo el dia, para descubrir que no
     * habia nada que hacer. Medido sobre un carrito de invitado con un articulo sin tramos:
     * master 4 queries reales, con esa version 6.
     *
     * Y no lo encontro una revision: lo denuncio un test que ya existia —
     * `ResincronizacionDelCarritoTest::test_sin_las_tablas_del_contrato_...`—, que cuenta las
     * queries de `set_total()` justamente para que la resincronizacion de ofertas no le cueste
     * nada al que no la usa. El invariante era de las dos, no de aquella sola.
     *
     * La guarda es `ArticlePriceRangeHelper::hay_tramos()` sobre los articulos DE ESTE CARRITO —
     * ver alla por que es esa pregunta y no "¿este comercio usa tramos?", y por que en `store` y
     * `update` se contesta sin tocar la base. El `whereHas` de mas abajo se queda igual: deja de
     * ser el filtro y pasa a ser lo que siempre debio ser, la lectura de los que SI tienen tramos.
     *
     * @param  \App\Cart  $cart
     * @return bool  True si la funcion paso la guarda y se metio a resolver precios. `set_total()`
     *               lo usa para saber si los pivots que tiene en memoria quedaron viejos: sumar
     *               con ellos daria el total de ANTES de la correccion, que es el defecto exacto
     *               que este metodo existe para evitar.
     */
    static function resincronizar_precios_por_rango($cart) {

        $se_metio = false;

        try {
            /* 🔴 LA GUARDA, Y VA PRIMERO QUE TODO. Ver el bloque del docblock. */
            if (!Self::el_carrito_tiene_tramos($cart)) {
                return false;
            }

            $se_metio = true;

            $lineas = DB::table('article_cart')->where('cart_id', $cart->id)->get();

            if (count($lineas) == 0) {
                return $se_metio;
            }

            /* Solo los articulos CON tramos: el resto de las lineas no se toca ni se mira, asi
               este metodo no puede cambiarle el precio a un carrito que no usa la funcionalidad. */
            $articulos = Article::whereIn('id', $lineas->pluck('article_id')->unique()->all())
                                ->whereHas('article_price_ranges')
                                ->withAll()
                                ->get();

            if (count($articulos) == 0) {
                return $se_metio;
            }

            /* Mismo camino que getFullModel(): checkPriceTypes resuelve el `final_price` de ESTE
               comprador, que es el precio normal al que hay que volver cuando no hay tramo. */
            $articulos = ArticleHelper::checkPriceTypes($articulos);

            /* 🔴 Ver la nota de la vuelta al precio normal, mas abajo: con la extension de rangos
               por CATEGORIA prendida, el precio normal de una linea NO es su `final_price`. */
            $tiene_rangos_por_categoria = CommerceHelper::hasExtencion(
                'lista_de_precios_por_rango_de_cantidad_vendida',
                null,
                $cart->user_id
            );

            /* Los ajustes del cliente del comprador de la sesion, para el precio del tramo. */
            $ajustes = AjustesDeClienteHelper::del_comprador($cart->user_id);

            foreach ($lineas as $linea) {
                $articulo = $articulos->firstWhere('id', $linea->article_id);

                if (is_null($articulo)) {
                    continue;
                }

                /* Precedencia: la linea es del carril de ofertas y la gobierna el otro metodo. */
                if (isset($articulo->precio_sin_oferta) && is_numeric($articulo->precio_sin_oferta)) {
                    continue;
                }

                /* El tramo sale de la base SIN ajustar (AjustesDeClienteHelper no toca
                   `article_price_ranges`), asi que el factor del cliente se aplica aca, una vez.
                   El `final_price` de la vuelta al precio normal, mas abajo, ya viene ajustado de
                   checkPriceTypes() y no se vuelve a multiplicar. */
                $precio = AjustesDeClienteHelper::ajustar(
                    ArticlePriceRangeHelper::precio($articulo->article_price_ranges, $linea->amount),
                    $ajustes
                );

                if (is_null($precio)) {
                    /*
                     * Ningun tramo para esta cantidad: vuelve al precio normal, y solo hacia
                     * arriba. Ver la asimetria del docblock.
                     *
                     * 🔴 Y "el precio normal" depende de por donde siga la cadena de `get_price()`.
                     * Sin la extension de rangos por CATEGORIA, es `final_price` y se puede
                     * escribir con confianza. CON la extension prendida, el eslabon siguiente es
                     * `get_price_range()`, que resuelve un tramo de categoria a partir de las
                     * cantidades de TODO el payload (`check_article_price_type_group` suma las
                     * lineas del mismo grupo de articulos). Reconstruir eso aca —sin payload—
                     * seria una segunda copia del mismo calculo, y una copia que se desincroniza
                     * cobra distinto segun el camino. Asi que en ese caso NO se corrige: se deja
                     * el precio que puso `get_price()`, que es el de master.
                     *
                     * Lo que si sigue valiendo para ese comercio es la rama de arriba: si un tramo
                     * por articulo matchea la cantidad nueva, se escribe. Es exactamente la misma
                     * precedencia que `get_price()`.
                     */
                    if ($tiene_rangos_por_categoria) {
                        continue;
                    }

                    if (!is_numeric($articulo->final_price)) {
                        continue;
                    }

                    if ((float) $articulo->final_price <= (float) $linea->price) {
                        continue;
                    }

                    $precio = (float) $articulo->final_price;
                }

                if ((float) $precio === (float) $linea->price) {
                    continue;
                }

                DB::table('article_cart')->where('id', $linea->id)->update(['price' => $precio]);
            }
        } catch (\Throwable $e) {
            /* Un tramo que falla no puede romper el carrito: queda el precio que ya estaba, que es
               el comportamiento de master, y la falla queda en el log. */
            Log::warning('CartHelper::resincronizar_precios_por_rango fallo, el carrito sigue con el precio anterior.', [
                'cart_id'   => $cart->id,
                'excepcion' => get_class($e),
                'mensaje'   => $e->getMessage(),
            ]);
        }

        return $se_metio;
    }

    /**
     * 🔴 Vuelve a poner cada linea del carrito al precio que le corresponde con los descuentos y
     * recargos que el cliente del comprador tiene vinculados HOY (mision
     * descuentos-recargos-por-cliente). Corre en `set_total()`, despues de las otras dos.
     *
     * ── EL HUECO QUE TAPA ────────────────────────────────────────────────────────────────────
     * `store` y `update` pricean cada linea con el factor del momento (`get_price()`,
     * `attach_combos()`, `attach_promociones_vinoteca()`). Pero `update_article_amount()` —el boton
     * "Actualizar"— no vuelve a pasar por ahi, y el pedido se arma con lo que quedo guardado. Si
     * entre medio el comerciante desvinculo un descuento o le cambio el porcentaje, la linea
     * seguiria cobrando el factor viejo mientras el pedido registra los ajustes nuevos: se rompe
     * la invariante de la mision ("los pivots del pedido son exactamente los ajustes con los que
     * se pricearon sus renglones").
     *
     * ── LA BASE LA DERIVA EL SERVIDOR ────────────────────────────────────────────────────────
     * Igual que `resincronizar_precios_de_oferta`: se cargan los articulos por el mismo camino que
     * `getFullModel()` y se pasan por `checkPriceTypes()`, que es lo que la tienda le muestra al
     * comprador. El precio de la linea es `precio_sin_ajustes_de_cliente × factor` (o sea, el
     * `final_price` que ve en pantalla). Combos y promos, desde su precio en la base.
     *
     * ── SIMETRICA, PERO SOLO CON AJUSTES VIGENTES ────────────────────────────────────────────
     * Con el cliente CON ajustes, cada linea tiene un ajuste que gobierna el servidor — lo mismo
     * que una linea con oferta vigente en `resincronizar_precios_de_oferta` —, asi que se escribe
     * para arriba o para abajo: es el numero que el comprador ve y el que el pedido va a declarar.
     *
     * Con el cliente SIN ajustes no se escribe NADA y no se lee ni una linea. Es a proposito:
     * ahi no hay un ajuste que gobernar, y bajar una linea que quedo por encima del precio de hoy
     * seria otorgar un descuento que nadie autorizo (el lado B de la asimetria de ofertas, fijado
     * por `ResincronizacionDelCarritoTest::test_una_linea_sin_oferta_por_encima_de_la_base_no_se_toca`).
     * El borde que queda, dicho de frente: si el comerciante desvincula TODOS los ajustes de un
     * cliente con el carrito armado, la linea no se corrige aca. Si era un descuento, la sube la
     * resincronizacion de ofertas (asimetria hacia arriba, cuando esas tablas estan). Si era un
     * recargo, queda cobrando el recargo viejo hasta el proximo guardado del carrito (`update`,
     * que el SPA dispara en cada paso del checkout y que vuelve a pricear todo por `get_price()`).
     *
     * ── QUE LINEAS NO SON SUYAS ──────────────────────────────────────────────────────────────
     *   - Con oferta personalizada (`precio_sin_oferta`): las gobierna la resincronizacion de
     *     ofertas, que ya aplica el factor encima de la oferta.
     *   - Con un tramo por ARTICULO que matchea la cantidad: las gobierna la de tramos, idem.
     *   - Con la extension de rangos por CATEGORIA: el precio depende de las cantidades de TODO el
     *     payload (`get_price_range`) y reconstruirlo aca seria una segunda copia del calculo. No se
     *     tocan; valen las que dejo `get_price()` con el factor del ultimo guardado.
     *   - Con precio pausado o sin precio numerico: no hay importe.
     *
     * @param  \App\Cart  $cart
     * @param  \ArrayObject|null  $memo  Lo que ya cargo `resincronizar_precios_de_oferta()` en este
     *                                   mismo `set_total()`. Ver resincronizar_articulos_con_ajustes().
     * @return bool  True si escribio alguna linea (y `set_total()` tiene que releer).
     */
    static function resincronizar_ajustes_de_cliente($cart, $memo = null) {

        $escribio = false;

        try {
            /* Lo barato primero: sin sesion de cuenta o sin cliente del ERP, 0 queries; sin
               tablas, la medicion del esquema (cacheada entre requests). */
            if (!AjustesDeClienteHelper::hayContrato()) {
                return false;
            }

            $ajustes = AjustesDeClienteHelper::del_comprador($cart->user_id);

            /* Sin ajustes vigentes no hay nada que gobernar. Ver el docblock. */
            if (!AjustesDeClienteHelper::tiene_ajustes($ajustes)) {
                return false;
            }

            $escribio = Self::resincronizar_articulos_con_ajustes($cart, $ajustes, $memo) || $escribio;
            $escribio = Self::resincronizar_promociones_con_ajustes($cart, $ajustes) || $escribio;

            if (ComboEsquemaHelper::disponible()) {
                $escribio = Self::resincronizar_combos_con_ajustes($cart, $ajustes) || $escribio;
            }
        } catch (\Throwable $e) {
            /* Un ajuste que falla no puede romper el carrito: queda el precio que ya estaba. */
            Log::warning('CartHelper::resincronizar_ajustes_de_cliente fallo, el carrito sigue con el precio anterior.', [
                'cart_id'   => $cart->id,
                'excepcion' => get_class($e),
                'mensaje'   => $e->getMessage(),
            ]);
        }

        return $escribio;
    }

    /**
     * Las lineas de articulos de `resincronizar_ajustes_de_cliente()`.
     *
     * Los articulos resueltos se REUSAN de `resincronizar_precios_de_oferta()` cuando esa corrio en
     * este mismo `set_total()` (`$memo['articulos']`): son la misma carga (`withAll()` de los ids de
     * las lineas) pasada por el mismo `checkPriceTypes()` para el mismo comprador, y ninguna de las
     * dos resincronizaciones la modifica — solo escriben `article_cart.price`. Las LINEAS si se
     * releen, porque la de ofertas pudo haberlas escrito. Si falta algun id (o la de ofertas no
     * corrio, por no haber contrato de ofertas), se carga como siempre.
     *
     * @param  \App\Cart  $cart
     * @param  array  $ajustes
     * @param  \ArrayObject|null  $memo
     * @return bool
     */
    private static function resincronizar_articulos_con_ajustes($cart, $ajustes, $memo = null) {

        /* Ver el docblock de arriba: con rangos por categoria el precio no se puede reconstruir. */
        if (CommerceHelper::hasExtencion('lista_de_precios_por_rango_de_cantidad_vendida', null, $cart->user_id)) {
            return false;
        }

        $lineas = DB::table('article_cart')->where('cart_id', $cart->id)->get();

        if (count($lineas) == 0) {
            return false;
        }

        $ids = $lineas->pluck('article_id')->unique()->all();

        $articulos = null;

        if (!is_null($memo) && isset($memo['articulos'])) {
            $cargados = $memo['articulos'];
            $faltan = array_diff($ids, $cargados->pluck('id')->all());

            if (count($faltan) == 0) {
                $articulos = $cargados;
            }
        }

        if (is_null($articulos)) {
            $articulos = Article::whereIn('id', $ids)
                                ->withAll()
                                ->get();

            if (count($articulos) == 0) {
                return false;
            }

            $articulos = ArticleHelper::checkPriceTypes($articulos);
        }

        $escribio = false;

        foreach ($lineas as $linea) {
            $articulo = $articulos->firstWhere('id', $linea->article_id);

            if (is_null($articulo)) {
                continue;
            }

            if (isset($articulo->precio_pausado) && $articulo->precio_pausado) {
                continue;
            }

            /* Carril de ofertas. */
            if (isset($articulo->precio_sin_oferta) && is_numeric($articulo->precio_sin_oferta)) {
                continue;
            }

            /* Carril de tramos por articulo, solo si un tramo matchea ESTA cantidad. */
            if (
                $articulo->relationLoaded('article_price_ranges')
                && !is_null(ArticlePriceRangeHelper::precio($articulo->article_price_ranges, $linea->amount))
            ) {
                continue;
            }

            $base = AjustesDeClienteHelper::precio_sin_ajustes($articulo);

            if (!is_numeric($base)) {
                continue;
            }

            $precio = AjustesDeClienteHelper::ajustar($base, $ajustes);

            if ((float) $precio === (float) $linea->price) {
                continue;
            }

            DB::table('article_cart')->where('id', $linea->id)->update(['price' => $precio]);
            $escribio = true;
        }

        return $escribio;
    }

    /**
     * Las lineas de promociones de vinoteca de `resincronizar_ajustes_de_cliente()`. La base es
     * `promocion_vinotecas.final_price` del comercio del carrito.
     *
     * @param  \App\Cart  $cart
     * @param  array  $ajustes
     * @return bool
     */
    private static function resincronizar_promociones_con_ajustes($cart, $ajustes) {

        $lineas = DB::table('cart_promocion_vinoteca')->where('cart_id', $cart->id)->get();

        if (count($lineas) == 0) {
            return false;
        }

        $promos = PromocionVinoteca::whereIn('id', $lineas->pluck('promocion_vinoteca_id')->unique()->all())
                                    ->where('user_id', $cart->user_id)
                                    ->get()
                                    ->keyBy('id');

        return Self::escribir_lineas_de_precio_fijo('cart_promocion_vinoteca', $lineas, 'promocion_vinoteca_id', $promos, 'final_price', $ajustes);
    }

    /**
     * Las lineas de combos de `resincronizar_ajustes_de_cliente()`. La base es `combos.price`.
     *
     * @param  \App\Cart  $cart
     * @param  array  $ajustes
     * @return bool
     */
    private static function resincronizar_combos_con_ajustes($cart, $ajustes) {

        $lineas = DB::table('cart_combo')->where('cart_id', $cart->id)->get();

        if (count($lineas) == 0) {
            return false;
        }

        $combos = Combo::whereIn('id', $lineas->pluck('combo_id')->unique()->all())
                        ->where('user_id', $cart->user_id)
                        ->get()
                        ->keyBy('id');

        return Self::escribir_lineas_de_precio_fijo('cart_combo', $lineas, 'combo_id', $combos, 'price', $ajustes);
    }

    /**
     * Escribe `round(base × factor, 2)` en cada linea de un pivot de precio fijo que no lo tenga.
     *
     * @param  string  $tabla
     * @param  \Illuminate\Support\Collection  $lineas
     * @param  string  $columna_id
     * @param  \Illuminate\Support\Collection  $modelos  keyBy('id')
     * @param  string  $columna_precio
     * @param  array  $ajustes
     * @return bool
     */
    private static function escribir_lineas_de_precio_fijo($tabla, $lineas, $columna_id, $modelos, $columna_precio, $ajustes) {

        $escribio = false;

        foreach ($lineas as $linea) {
            $modelo = $modelos->get((int) $linea->{$columna_id});

            if (is_null($modelo) || !is_numeric($modelo->{$columna_precio})) {
                continue;
            }

            $precio = AjustesDeClienteHelper::ajustar($modelo->{$columna_precio}, $ajustes);

            if ((float) $precio === (float) $linea->price) {
                continue;
            }

            DB::table($tabla)->where('id', $linea->id)->update(['price' => $precio]);
            $escribio = true;
        }

        return $escribio;
    }

    /**
     * ¿Alguna linea de este carrito es de un articulo con tramos cargados? Es la guarda de
     * `resincronizar_precios_por_rango()`, y lo que la vuelve barata es DONDE se la pregunta.
     *
     * `set_total()` carga `$cart->articles` ANTES de llamar a la resincronizacion —esa carga es la
     * que master ya hacia para sumar, nada mas que subida unas lineas—, asi que los ids de las
     * lineas ya estan en memoria y averiguarlos no cuesta ninguna query. El `$cart->load()` de
     * abajo es nada mas una red por si alguien llama a la resincronizacion por fuera de
     * `set_total()`; por el camino real nunca se ejecuta.
     *
     * @param  \App\Cart  $cart
     * @return bool
     */
    private static function el_carrito_tiene_tramos($cart) {

        /* Lo mas barato de todo: sin tabla no hay tramos y no se mira ni el carrito. */
        if (!ArticlePriceRangeHelper::hay_tabla()) {
            return false;
        }

        if (!$cart->relationLoaded('articles')) {
            $cart->load('articles');
        }

        return ArticlePriceRangeHelper::hay_tramos($cart->articles->pluck('id')->unique()->all());
    }

    static function set_total($cart) {
        /* Antes de sumar, el precio de las lineas con oferta se vuelve a resolver contra la
           base. Ver el docblock de arriba: sin esto, cambiar la cantidad desde "Actualizar"
           conservaba el precio del tramo anterior. */
        /* Lo que la resincronizacion de ofertas ya cargo, para que la de ajustes no lo repita. */
        $memo = new \ArrayObject();

        Self::resincronizar_precios_de_oferta($cart, $memo);

        /* 🔴 Esta carga estaba ABAJO, despues de las dos resincronizaciones, y subio a proposito:
           es la MISMA lectura de siempre —la que master hace para sumar— y ahora sirve tambien
           para contestar la guarda de tramos sin pagar una query propia. Va despues de la
           resincronizacion de ofertas para traer los precios que esa ya corrigio. */
        $cart->load('articles');

        /* Y despues —el orden importa, ver el docblock— el de los tramos por cantidad. Si se metio
           a resolver precios, lo que quedo en memoria es de antes de sus escrituras y hay que
           releerlo: sumar con eso daria el total viejo. Solo lo pagan los carritos que de verdad
           tienen tramos. */
        if (Self::resincronizar_precios_por_rango($cart)) {
            $cart->load('articles');
        }

        /* Ultima, porque es la unica simetrica y gobierna solo las lineas que las otras dos no
           gobiernan. Si escribio algo, lo que hay en memoria es de antes y hay que releerlo. */
        if (Self::resincronizar_ajustes_de_cliente($cart, $memo)) {
            $cart->load('articles', 'promociones_vinoteca');

            if (ComboEsquemaHelper::disponible()) {
                $cart->load('combos');
            }
        }

        $total = 0;

        foreach ($cart->articles as $article) {
            $total += $article->pivot->price * $article->pivot->amount; 
        }

        foreach ($cart->promociones_vinoteca as $promo) {
            $total += $promo->pivot->price * $promo->pivot->amount;
        }

        /* La tercera coleccion comprable. Detras de la guarda: sin `cart_combo` esta linea seria
           "Base table or view not found" en el medio del checkout. */
        if (ComboEsquemaHelper::disponible()) {
            foreach ($cart->combos as $combo) {
                $total += $combo->pivot->price * $combo->pivot->amount;
            }
        }

        $cart->total = $total;
        $cart->save();
    }

    /**
     * El precio de una linea del carrito: la cadena de siempre (oferta > tramo > rango por
     * categoria > final_price > resuelto por el servidor) y, al final, los descuentos y recargos
     * del cliente del comprador (mision descuentos-recargos-por-cliente).
     *
     * ── 🔴 POR QUE EL FACTOR NO SE APLICA ADENTRO DE LA CADENA, Y POR QUE SE "DESAJUSTA" PRIMERO ─
     *
     * Desde esta mision, `checkPriceTypes()` le deja a cada articulo el `final_price` YA
     * ajustado (1000 con 10% de descuento y 5% de recargo -> 945), y el SPA reenvia ese mismo
     * objeto en el carrito. Los eslabones de la cadena no son parejos:
     *   - `final_price` del payload y `ranges[].price` (rangos por categoria) llegan AJUSTADOS;
     *   - la oferta (`precio_sin_oferta`), los tramos por articulo (de la base), los combos (de la
     *     base) y el respaldo del servidor llegan SIN ajustar.
     * Aplicar el factor "donde falte" en cada eslabon es exactamente como se termina cobrando
     * 1000 × 0,945 × 0,945 = 893,03 en un camino y 945 en otro. Por eso la regla es una sola:
     * primero se devuelve TODA la linea a su base sin ajustes (`desajustar_linea`, que usa
     * `precio_sin_ajustes_de_cliente`), la cadena corre sobre bases puras como en master, y el
     * factor se aplica UNA vez, aca, con los porcentajes leidos de la base y nunca del payload.
     * Si venis a "simplificar" multiplicando adentro de un eslabon, rompes esa invariante.
     *
     * 🔴 Y el desajuste va SIEMPRE, tambien sin contrato. Sin contrato no hay factor, pero el
     * payload puede traer igual un precio que ESTE servidor ajusto antes: el ERP le saco el
     * cliente al comprador, o el comprador cerro sesion y el SPA conservo los articulos que habia
     * pedido logueado. Ahi `final_price` es 945 y la base de verdad es
     * `precio_sin_ajustes_de_cliente` (1000); cobrar 945 seria regalar el descuento sin dejarlo
     * asentado en el pedido. Una linea sin esa clave (lo normal sin contrato) no cambia en nada:
     * sale byte por byte como en master.
     *
     * @param  array  $articles  todo el payload (la cadena mira las cantidades de las otras lineas)
     * @param  array  $article  la linea
     * @param  bool  $has_price_ranges
     * @param  mixed  $article_groups
     * @param  int|null  $user_id  comercio dueño del CARRITO
     * @return mixed
     */
    static function get_price($articles, $article, $has_price_ranges, $article_groups, $user_id = null) {

        /* Sin contrato, del_comprador() devuelve vacio y ajustar() deja el precio intacto. */
        $precio = Self::get_price_sin_ajustes_de_cliente(
            $articles,
            AjustesDeClienteHelper::desajustar_linea($article),
            $has_price_ranges,
            $article_groups,
            $user_id
        );

        return AjustesDeClienteHelper::ajustar($precio, AjustesDeClienteHelper::del_comprador($user_id));
    }

    /**
     * La cadena de precedencia de una linea, SIN los ajustes del cliente. Es el `get_price()` de
     * antes de la mision descuentos-recargos-por-cliente, intacto salvo el respaldo del servidor
     * (ver `precio_resuelto_por_el_servidor`). Se llama solo desde `get_price()`.
     */
    static function get_price_sin_ajustes_de_cliente($articles, $article, $has_price_ranges, $article_groups, $user_id = null) {

        /*
         * 🔴 La oferta personalizada gana, y la resuelve EL SERVIDOR contra la base.
         *
         * Que se gana: el PORCENTAJE y la VIGENCIA dejan de venir del navegador. Sin esto,
         * el objeto `oferta_personalizada` que el SPA reenvia con el carrito seria la unica
         * fuente del descuento, y cualquiera podria mandarse un 90%, usar una oferta vencida
         * o la de otro cliente — y eso termina en un pedido confirmado, que en esta base es
         * una venta contra la cuenta corriente de una persona.
         *
         * 🔴 Y GANA TAMBIEN CUANDO LA OFERTA YA NO EXISTE. Eso es lo que hace que la frase de
         * arriba sea cierta, y la primera version de este cambio no lo hacia: `precioDeLinea`
         * devolvia null al no encontrar oferta vigente y la linea caia al `final_price` del
         * payload — que en una oferta 'unidad' es el precio QUE EL PROPIO SERVIDOR dejo ya
         * descontado en la respuesta anterior. O sea que el comerciante cancelaba la promocion
         * y la pestaña abierta la seguia cobrando, sin que nadie manipulara nada.
         *
         * Que NO se toca, a proposito: la BASE del precio la sigue mandando el SPA
         * ($article['precio_sin_oferta']), igual que hoy manda $article['final_price'].
         * Reconstruirla en el servidor exigiria duplicar los cuatro casos de
         * ArticleHelper::checkPriceTypes() adentro de un helper de carrito, y una copia que
         * se desincronice cobra distinto segun el camino — peor que el agujero que arregla.
         * "El cliente fija el precio base" es un agujero PREEXISTENTE y su arreglo es otra
         * mision.
         *
         * Molde: get_price_range() de aca abajo, que ya resuelve un tramo desde `amount`
         * del lado del servidor.
         *
         * Sin `precio_sin_oferta` en el payload, precioDeLinea() devuelve null SIEMPRE y las
         * dos ramas de abajo quedan identicas a master: un articulo sin oferta cobra
         * exactamente lo de hoy, byte por byte.
         */
        $precio_con_oferta = ClientOfferHelper::precioDeLinea($article, $user_id);

        if (!is_null($precio_con_oferta)) {
            return $precio_con_oferta;
        }

        /*
         * 🔴 PRECEDENCIA, y esto es una DECISION, no un orden casual. Queda escrita acá porque es
         * lo primero que alguien va a querer cambiar sin saber lo que rompe.
         *
         *   oferta personalizada  >  tramo por ARTICULO  >  tramo por CATEGORIA  >  final_price
         *
         * 1. La oferta personalizada gana. Es un acuerdo con UN comprador concreto, resuelto por
         *    el servidor contra la base, y es el unico eslabon de esta cadena que tiene una
         *    propiedad de seguridad encima: gana TAMBIEN cuando la oferta ya no existe, para que
         *    una pestaña vieja no siga cobrando una promocion cancelada (ver el docblock de
         *    `ClientOfferHelper::precioDeLinea`). Meter el tramo por cantidad antes la anularia.
         *    Lo especifico le gana a lo general, y el comprador con oferta negociada sigue pagando
         *    hoy exactamente lo que pagaba ayer: el cambio es compatible hacia atras.
         *
         *    ⚠️ El borde, dicho de frente: un articulo que TUVO una oferta y ya no la tiene llega
         *    igual con `precio_sin_oferta` en el payload, asi que `precioDeLinea` devuelve la base
         *    y el tramo por cantidad NO se le aplica. Es la eleccion conservadora —el servidor no
         *    otorga un descuento por cantidad sobre una linea que venia del carril de ofertas— y
         *    solo alcanza a los comercios que tienen el contrato de ofertas prendido.
         *
         * 2. El tramo por ARTICULO le gana al tramo por CATEGORIA por el mismo motivo: uno lo
         *    cargó el comerciante para ESE articulo, el otro es el mapeo generico de su categoria.
         *    Y no cambia nada para nadie: sin filas en `article_price_ranges` esta rama devuelve
         *    null y el precio sale por donde salía, byte por byte.
         */
        $precio_por_rango = ArticlePriceRangeHelper::precio_de_articulo(
            isset($article['id']) ? $article['id'] : null,
            Self::cantidad_de_linea($article),
            collect($articles)->pluck('id')->all()
        );

        if (!is_null($precio_por_rango)) {
            return $precio_por_rango;
        }

        if ($has_price_ranges) {

            Log::info('has_price_ranges');
            return Self::get_price_range($articles, $article, $article_groups);
        }

        /* `?? null`: una linea sin la clave `final_price` tiraba "Undefined array key" y
           terminaba en un 500 antes de llegar al respaldo de abajo. */
        $precio = $article['final_price'] ?? null;

        if (is_null($precio)) {
            $precio = Self::precio_resuelto_por_el_servidor($article, $user_id);
        }

        return $precio;
    }

    /**
     * El respaldo de `get_price()` cuando el payload llega con `final_price` en null: el precio
     * del articulo resuelto del lado del servidor para el comprador de ESTE request, con la misma
     * `ArticleHelper::checkPriceTypes()` que usa la tienda para mostrarlo. No es una copia de la
     * logica: es la misma funcion, asi que no se puede desincronizar.
     *
     * ── POR QUE (Fenix, 23/9/2026) ──────────────────────────────────────────────────────────
     * Con la tienda en "solo vinculados", `checkPriceTypes()` le borra los precios al anonimo
     * (`esconder_precios_al_anonimo`). Un comprador que se loguea con articulos pedidos como
     * anonimo en el store del SPA los agrega al carrito con `final_price` null, y eso terminaba
     * en `Column 'price' cannot be null` en `article_cart`: un 500 y un carrito huerfano por
     * intento (catorce en cinco minutos, carritos 5209–5222).
     *
     * Solo actua en el ultimo eslabon de la precedencia (ver `get_price()`), y solo cuando el
     * payload no trae precio: cualquier linea con `final_price` sigue cobrando lo mismo que
     * hoy, byte por byte. Si el comprador tampoco puede ver precios (anonimo en tienda
     * restringida), `checkPriceTypes()` devuelve null otra vez y queda como hoy.
     *
     * El articulo se busca dentro del comercio del CARRITO cuando se conoce (`$user_id`, que lo
     * escribio el servidor), no del que diga el payload.
     *
     * 🔴 La oferta personalizada de tipo 'cantidad': `checkPriceTypes()` -> `ClientOfferHelper::aplicar()`
     * deja `final_price` en la BASE y solo setea `precio_sin_oferta`, porque el tramo depende de
     * la cantidad de la linea y lo resuelve `precioDeLinea()`. Devolver ese `final_price` cobraba
     * de mas. Por eso, si el articulo resuelto trae `precio_sin_oferta`, se vuelve a pasar por
     * `precioDeLinea()` con esa base —exactamente lo que hace `get_price()` con una linea que
     * llega con la base en el payload—, y vale su resultado si no es null.
     *
     * ⚠️ Con la extension de rangos por cantidad vendida este respaldo no se alcanza: una linea
     * sin precio sale por `get_price_range()` en `get_price()` y ahi sigue en null (H9, no se toca).
     *
     * @param  array  $article  la linea del payload
     * @param  int|null  $user_id  comercio dueño del carrito
     * @return mixed  el precio, o null si el servidor tampoco lo puede resolver
     */
    static function precio_resuelto_por_el_servidor($article, $user_id = null) {
        if (!isset($article['id'])) {
            return null;
        }

        /* `price_types` es lo unico que checkPriceTypes() lee del articulo (casos 3 y 4). */
        $query = Article::where('id', $article['id'])->with('price_types');

        if (!is_null($user_id)) {
            $query->where('user_id', $user_id);
        }

        $articulo = $query->first();

        if (is_null($articulo)) {
            return null;
        }

        $articulos = ArticleHelper::checkPriceTypes(collect([$articulo]));

        $resuelto = $articulos->first();

        /* 🔴 La base SIN los ajustes del cliente: checkPriceTypes() ya le aplico el factor a
           `final_price`, y `get_price()` lo aplica al final de la cadena. Sin ajustes,
           `precio_sin_ajustes()` devuelve `final_price` tal cual, como antes. */
        $precio = AjustesDeClienteHelper::precio_sin_ajustes($resuelto);

        if (isset($resuelto->precio_sin_oferta) && is_numeric($resuelto->precio_sin_oferta)) {
            $precio_con_oferta = ClientOfferHelper::precioDeLinea(
                array_merge($article, ['precio_sin_oferta' => $resuelto->precio_sin_oferta]),
                $user_id
            );

            if (!is_null($precio_con_oferta)) {
                $precio = $precio_con_oferta;
            }
        }

        Log::info('get_price: final_price null en el payload del articulo '.$articulo->id.', resuelto por el servidor: '.var_export($precio, true));

        return $precio;
    }

    /**
     * La cantidad de una linea del payload. `pivot.amount` es la que efectivamente se guarda en
     * `article_cart` (ver `attachArticles`), asi que es la que tiene que decidir el tramo; el
     * `amount` plano queda de respaldo porque es el que mira `get_price_range()` y hay payloads
     * que mandan uno solo de los dos.
     *
     * @param  array  $article
     * @return float
     */
    static function cantidad_de_linea($article) {
        if (isset($article['pivot']['amount']) && is_numeric($article['pivot']['amount'])) {
            return (float) $article['pivot']['amount'];
        }

        if (isset($article['amount']) && is_numeric($article['amount'])) {
            return (float) $article['amount'];
        }

        return 0.0;
    }

    static function get_price_range($articles, $article, $article_groups) {

        $price = null;

        foreach ($article['ranges'] as $range) {

            $amount = Self::check_article_price_type_group($articles, $article, $article_groups);
            
            if (
                (
                    is_null($range['min'])
                    || $amount >= $range['min']
                )
                &&
                (
                    is_null($range['max'])
                    || $amount <= $range['max']
                )
            ) {
                Log::info('Entro con rango min: '.$range['min'].' y max: '.$range['max']);
                Log::info('rango price: '.$range['price']);
                $price = $range['price'];
            }
        }
        return $price;
    }

    static function check_article_price_type_group($articles, $article, $article_groups) {
        
        $amount = (float) $article['amount'];

        $group = $article_groups->first(function ($group) use ($article) {
            return $group->articles->contains('id', $article['id']);
        });

        $otrosArticulosRelacionados = [];

        if ($group) {
            foreach ($group->articles as $groupArticle) {
                $articleVendiendose = collect($articles)->firstWhere('id', $groupArticle->id);

                if ($articleVendiendose && $articleVendiendose['id'] != $article['id']) {
                    Log::info($article['name'].' comparte grupo con '.$articleVendiendose['name']);
                    $amount += (float) $articleVendiendose['amount'];
                    $otrosArticulosRelacionados[] = $articleVendiendose;
                }
            }
        }

        return $amount;
        // return [
        //     'total_amount' => $amount,
        //     'otros_articulos_relacionados' => $otrosArticulosRelacionados
        // ];
    }

    static function getFullModel($id) {
        $model = Cart::where('id', $id)
                        ->withAll()
                        ->with(['articles' => function($query) {
                            $query->withAll();
                        }])
                        ->first();
                        
        $model->articles = ArticleHelper::setArticlesVariants($model->articles);
        $model->articles = ArticleHelper::checkPriceTypes($model->articles);
        $model->promociones_vinoteca = ArticleHelper::set_promociones_vinoteca($model->promociones_vinoteca);

        /* Los combos vuelven marcados igual que las promos, para que el SPA sepa a que coleccion
           pertenece cada linea del carrito. `final_price` es `price` con el nombre que el SPA ya
           usa para todo lo comprable. */
        if (ComboEsquemaHelper::disponible()) {
            foreach ($model->combos as $combo) {
                $combo->is_combo = true;
                $combo->final_price = $combo->price;
            }

            AjustesDeClienteHelper::aplicar_a_precios_fijos($model->combos, $model->user_id);
        }

        /* Las promos y los combos vuelven con el precio ajustado, su base y sus badges, igual que
           los articulos (que ya pasaron por checkPriceTypes). La base viaja para que el proximo
           guardado del carrito no le aplique el factor dos veces (ver get_price()). */
        AjustesDeClienteHelper::aplicar_a_precios_fijos($model->promociones_vinoteca, $model->user_id);

        $model = Self::check_repetidos($model);

        return $model;
    }

    static function check_repetidos($cart) {
        $articulosAgrupados = $cart->articles
                ->groupBy('id')
                ->filter(function ($grupo) {
                    return $grupo->count() > 1; // Solo procesar duplicados
                });

        foreach ($articulosAgrupados as $articuloId => $articulosDuplicados) {
            
            // Obtener todas las relaciones duplicadas del artículo con este cart
            $relacionesDuplicadas = $articulosDuplicados->pluck('pivot.id');


            // Mantener solo una relación (la primera)
            $relacionAPreservar = $relacionesDuplicadas->shift();

            // Eliminar las relaciones duplicadas restantes
            DB::table('article_cart')
                ->where('cart_id', $cart->id)
                ->where('article_id', $articuloId)
                ->whereIn('id', $relacionesDuplicadas)
                ->delete();
        }
        return $cart;
    }

}
