<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use App\ArticlePriceTypeGroup;
use App\Cart;
use App\Cupon;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ClientOfferHelper;
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
        
        foreach ($promociones_vinoteca as $promo) {

            // if (isset($promo['is_promocion_vinoteca'])) {

                $cart->promociones_vinoteca()->attach($promo['id'], [
                                            'price'         => $promo['final_price'],
                                            'cost'          => $promo['cost'],
                                            'amount'        => $promo['pivot']['amount'],
                                            'notes'         => $promo['pivot']['notes'],
                                        ]);
            // }
            
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
     * @return void
     */
    static function resincronizar_precios_de_oferta($cart) {
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

            foreach ($lineas as $linea) {
                $articulo = $articulos->firstWhere('id', $linea->article_id);

                if (is_null($articulo)) {
                    continue;
                }

                $tiene_oferta = isset($articulo->oferta_personalizada)
                                && !empty($articulo->oferta_personalizada['precio_aplicado']);

                /* La base la resuelve el servidor: `precio_sin_oferta` cuando hay oferta, y el
                   `final_price` de checkPriceTypes cuando no. */
                $base = $tiene_oferta && isset($articulo->precio_sin_oferta)
                        ? $articulo->precio_sin_oferta
                        : $articulo->final_price;

                if (!is_numeric($base)) {
                    continue;
                }

                $precio = ClientOfferHelper::precioDeLinea([
                    'id'                => $articulo->id,
                    'amount'            => $linea->amount,
                    'precio_sin_oferta' => $base,
                    'precio_pausado'    => isset($articulo->precio_pausado) ? $articulo->precio_pausado : null,
                ], $cart->user_id);

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

    static function set_total($cart) {
        /* Antes de sumar, el precio de las lineas con oferta se vuelve a resolver contra la
           base. Ver el docblock de arriba: sin esto, cambiar la cantidad desde "Actualizar"
           conservaba el precio del tramo anterior. */
        Self::resincronizar_precios_de_oferta($cart);

        $cart->load('articles');

        $total = 0;

        foreach ($cart->articles as $article) {
            $total += $article->pivot->price * $article->pivot->amount; 
        }

        foreach ($cart->promociones_vinoteca as $promo) {
            $total += $promo->pivot->price * $promo->pivot->amount; 
        }

        $cart->total = $total;
        $cart->save();
    }

    static function get_price($articles, $article, $has_price_ranges, $article_groups, $user_id = null) {

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

        if ($has_price_ranges) {

            Log::info('has_price_ranges');
            return Self::get_price_range($articles, $article, $article_groups);
        }

        return $article['final_price'];
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
