<?php

namespace App\Http\Controllers;

use App\Cart;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CartHelper;
use App\Http\Controllers\Helpers\CartOwnershipHelper;
use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use App\Http\Controllers\Helpers\EnvioCartHelper;
use App\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 🔴 Las rutas de este controller son PUBLICAS a proposito y no pueden dejar de serlo: la tienda
 * permite comprar sin registrarse, y hay caminos del SPA que guardan el carrito antes de que el
 * comprador se identifique (CardPaymentMethod.vue:52 al elegir el medio de pago,
 * mixins/articles.js:36 al sacar un articulo). Meterlas detras de auth:buyer rompe la compra de
 * invitado, que es el flujo mas usado de la tienda.
 *
 * Lo que si se hace es acotar el alcance: cada metodo comprueba que el carrito sea de quien lo
 * pide, via CartOwnershipHelper (sesion para el invitado, buyer_id para el logueado). El porque
 * completo, incluido lo que se midio, esta en el docblock de ese helper.
 */
class CartController extends Controller
{
    /**
     * Ultimo carrito abierto del comprador.
     *
     * Es la pieza con la que el SPA reconstruye el carrito al volver de Mercado Pago
     * (App.vue -> mixins/app.js -> cart/getLastCart), asi que romperla rompe el medio de pago
     * dominante.
     *
     * 🔴 Antes hacia where('buyer_id', $this->buyerId()) y `carts.buyer_id` es NULLABLE, o sea
     * que sin sesion Eloquent lo convertia en whereNull y devolvia el carrito de OTRO invitado.
     * Medido con curl. Y encima la tienda dependia de ese bug para funcionar: como el buyer_id
     * nunca se rellenaba tras el login del invitado, el whereNull era la unica forma de volver a
     * encontrar el carrito propio. Por eso aca no alcanza con cortar: hay que buscar por los
     * carritos de la sesion y adoptarlos.
     *
     * @param  int  $commerce_id
     * @return \Illuminate\Http\JsonResponse
     */
    function lastCart($commerce_id) {
        $buyer_id = $this->buyerId();
        $ids      = CartOwnershipHelper::ids();

        // Sin comprador y sin carritos en la sesion no hay nada que devolver. Nunca se cae a
        // whereNull('buyer_id'): eso es lo que devolvia el carrito ajeno.
        if (is_null($buyer_id) && empty($ids)) {
            return response()->json(['has_last_cart' => false], 200);
        }

        $last_cart = Cart::where('user_id', $commerce_id)
                            ->whereNull('order_id')
                            ->where(function($q) use ($buyer_id, $ids) {
                                if (!is_null($buyer_id)) {
                                    $q->orWhere('buyer_id', $buyer_id);
                                }
                                if (!empty($ids)) {
                                    $q->orWhereIn('id', $ids);
                                }
                            })
                            ->orderBy('created_at', 'DESC')
    						->first();

    	if (!is_null($last_cart)) {
            // El invitado que se identifico en el checkout deja de depender de la sesion para
            // encontrar su carrito la proxima vez.
            CartOwnershipHelper::adoptar($last_cart);

            $last_cart = CartHelper::getFullModel($last_cart->id);
    		return response()->json(['has_last_cart' => true ,'last_cart' => $last_cart], 200);
    	} else {
    		return response()->json(['has_last_cart' => false], 200);
    	}
    }

    /**
     * Carrito de un pedido ya confirmado.
     *
     * 🔴 Era IDOR puro: Cart::where('order_id', $order_id) sin comprobar nada. Medido con curl sin
     * sesion: 200 con el carrito completo de otro comprador, enumerable por order_id.
     *
     * Este NO se cierra por sesion, y la distincion importa: mixins/messages.js:59 lo llama desde
     * un mensaje viejo, o sea desde una sesion distinta de la que creo ese carrito. Se cierra por
     * el pedido, cuya columna buyer_id es NOT NULL — asi que where('buyer_id', null) matchea cero
     * filas y el caso sin sesion queda cubierto por la propia consulta.
     *
     * @param  int  $order_id
     * @return \Illuminate\Http\JsonResponse
     */
    function fromOrder($order_id) {
        $order = Order::where('id', $order_id)
                        ->where('buyer_id', $this->buyerId())
                        ->first();

        if (is_null($order)) {
            return response()->json(['cart' => null], 403);
        }

        $cart = Cart::where('order_id', $order_id)->first();

        // Antes esto reventaba con 500 cuando el pedido no tenia carrito asociado:
        // CartHelper::getFullModel($cart->id) sobre null.
        if (is_null($cart)) {
            return response()->json(['cart' => null], 200);
        }

        $cart = CartHelper::getFullModel($cart->id);
        return response()->json(['cart' => $cart], 200);
    }

    function store(Request $request) {
        if (env('APP_ENV') == 'local') {
            // sleep(3);
        }
        // `new` y no `create`: los campos del checkout (incluido el envío por correo, que puede
        // cortar con 422) se sincronizan ANTES del primer INSERT. Con `create` cada 422 de envío
        // dejaba un carrito vacío huérfano en la base.
    	$cart = new Cart([
    		'buyer_id'          => $this->buyerId(),
            'user_id'           => $request->commerce_id,
    	]);

        // Persistir opciones de checkout elegidas antes de confirmar (envío/retiro, pago, etc.)
        $this->sync_checkout_fields($cart, $request->cart);
        $cart->save();

        CartHelper::attachArticles($cart, $request->cart['articles']);
        CartHelper::attach_promociones_vinoteca($cart, $request->cart['promociones_vinoteca']);

        // `combos` es OPCIONAL en el body y su ausencia nunca es un error: un SPA viejo —o uno
        // nuevo corriendo contra una base sin el esquema de combos— simplemente no la manda.
        // Por eso se lee con isset y no como `$request->cart['combos']` a secas, que en un
        // payload viejo seria "Undefined array key".
        CartHelper::attach_combos($cart, isset($request->cart['combos']) ? $request->cart['combos'] : []);

        CartHelper::set_total($cart);

        // Queda registrado como propio de esta sesion. Es lo unico que ata un carrito de invitado
        // a quien lo creo: no tiene buyer_id con el cual acotarlo.
        CartOwnershipHelper::registrar($cart->id);

    	$cart = CartHelper::getFullModel($cart->id);

    	return response()->json(['cart' => $cart], 201);
    }

    /**
     * 🔴 Antes hacia Cart::find($request->id) a secas: cualquiera podia reescribir los articulos,
     * el total y los datos de pago del carrito de otro, y con arrays vacios borrarselo.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    function update(Request $request) {
        if (env('APP_ENV') == 'local') {
            // sleep(3);
        }
    	$cart = Cart::find($request->id);

        if (is_null($cart)) {
            return response()->json(['cart' => null], 404);
        }

        if (!CartOwnershipHelper::puede($cart)) {
            return response()->json(['cart' => null], 403);
        }

        CartOwnershipHelper::adoptar($cart);

        $this->sync_checkout_fields($cart, $request->all());
        $cart->save();
        CartHelper::checkPaymentStatus($cart);
        $cart->articles()->sync([]);
    	$cart->promociones_vinoteca()->sync([]);

        // La clave `combos` es opcional (ver `store`): sin ella el carrito queda como siempre.
        $combos = is_array($request->combos) ? $request->combos : [];

        if (ComboEsquemaHelper::disponible()) {
            $cart->combos()->sync([]);
        }

        $cart_deleted = false;

        if (
            count($request->articles) >= 1
            || count($request->promociones_vinoteca) >= 1
            || count($combos) >= 1
        ) {

            if (count($request->articles) >= 1) {
                CartHelper::attachArticles($cart, $request->articles);
            }

            if (count($request->promociones_vinoteca) >= 1) {
                CartHelper::attach_promociones_vinoteca($cart, $request->promociones_vinoteca);
            }

            if (count($combos) >= 1) {
                CartHelper::attach_combos($cart, $combos);
            }

            CartHelper::set_total($cart);
        } else {
            $cart->delete();
            $cart_deleted = true;
        }
        if (!$cart_deleted) {
            $cart = CartHelper::getFullModel($cart->id);
            return response()->json(['cart' => $cart], 200);
        }
        return response()->json(['cart' => null], 200);
    }

    /**
     * 🔴 Antes hacia Cart::find($cart_id) a secas: se podian cambiar las cantidades del carrito de
     * otro. Y con un id inexistente reventaba en 500 sobre null (medido).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $cart_id
     * @return \Illuminate\Http\JsonResponse
     */
    function update_article_amount(Request $request, $cart_id) {
        $cart = Cart::find($cart_id);

        if (is_null($cart)) {
            return response()->json(['cart' => null], 404);
        }

        if (!CartOwnershipHelper::puede($cart)) {
            return response()->json(['cart' => null], 403);
        }

        // `is_combo` apunta al tercer pivote, igual que `is_promocion_vinoteca` apunta al segundo.
        // Va detras de la guarda de esquema: sin `cart_combo` no hay a que apuntar, y un payload
        // con `is_combo` contra una base vieja no puede reventar el carrito.
        if ($request->is_combo) {

            if (ComboEsquemaHelper::disponible()) {
                $cart->combos()->updateExistingPivot($request->id, [
                    'amount'    => $request->amount,
                ]);
            }

        } else if ($request->is_promocion_vinoteca) {

            $cart->promociones_vinoteca()->updateExistingPivot($request->id, [
                'amount'    => $request->amount,
            ]);

        } else {

            $cart->articles()->updateExistingPivot($request->id, [
                'amount'    => $request->amount,
            ]);
        }


        // 🔴 Y acá adentro está el arreglo del hueco que abre la misión combos-y-rangos-de-precio:
        // `set_total()` vuelve a resolver el precio de las líneas con tramos por cantidad
        // (`CartHelper::resincronizar_precios_por_rango`). Este método cambia el `amount` sin
        // volver a pasar por `get_price()`, así que sin eso el comprador se quedaba con el precio
        // del tramo anterior. Es el pedido textual de Lucas.
        CartHelper::set_total($cart);

        // Este camino cambia las lineas SIN pasar por sync_checkout_fields: el precio de envio
        // por correo que quedo guardado se cotizo para OTRAS cantidades (un carrito de 100
        // unidades con envio gratis por umbral, bajado a 1, seguia gratis). Se invalida la
        // cotizacion —la direccion queda— y el proximo cart/save del SPA vuelve a cotizar.
        EnvioCartHelper::invalidar_cotizacion($cart);

        $cart = CartHelper::getFullModel($cart->id);
        return response()->json(['cart' => $cart], 200);
    }

    /**
     * 🔴 Este era el peor de los cuatro, porque destruye: Cart::find($cart_id) sin comprobar nada.
     * Medido el 15/8/2026 con curl sin ninguna sesion — se borro el carrito de otro comprador y
     * se verifico en la base que la fila ya no estaba.
     *
     * @param  int  $cart_id
     * @return \Illuminate\Http\Response
     */
    function delete($cart_id) {
        $cart = Cart::find($cart_id);
        if (is_null($cart)) {
            // El carrito ya no existe (ej: carrito de invitado que nunca se
            // persistio, o ya fue borrado antes). No hay nada que hacer.
            // Se mantiene el 200: mixins/cart.js:355 lo llama despues de confirmar el pedido y
            // no puede saber si el carrito sigue vivo.
            return response(null, 200);
        }

        if (!CartOwnershipHelper::puede($cart)) {
            return response(null, 403);
        }

        $cart->articles()->sync([]);
        $cart->promociones_vinoteca()->sync([]);

        if (ComboEsquemaHelper::disponible()) {
            $cart->combos()->sync([]);
        }

        $cart->delete();
        return response(null, 200);
    }

    /**
     * Copia al carrito los campos del paso de checkout (entrega, pago, notas, etc.).
     *
     * Al final sincroniza el envío por correo (`$data['envio']`, misión zipnova-envios): si el
     * comprador eligió una opción de Zipnova, el servidor la re-cotiza cuando hace falta y deja el
     * precio en `carts.envio_precio`; si eligió retiro o una zona propia, limpia esas columnas.
     * Va acá y no después de adjuntar los artículos a propósito: un 422 de envío se lanza ANTES
     * del `save()`, así que no deja el carrito a medias. En una base sin las columnas de envío
     * el helper no hace nada (`ZipnovaEsquemaHelper`).
     *
     * @param \App\Cart $cart
     * @param array $data Payload del carrito enviado por tienda-spa
     * @return void
     */
    function sync_checkout_fields($cart, $data) {
        $cart->delivery_zone_id     = isset($data['delivery_zone_id']) ? $data['delivery_zone_id'] : null;
        $cart->payment_card_info_id = isset($data['payment_card_info_id']) ? $data['payment_card_info_id'] : null;
        $cart->payment_method_id    = isset($data['payment_method_id']) ? $data['payment_method_id'] : null;
        $cart->payment_id           = isset($data['payment_id']) ? $data['payment_id'] : null;
        $cart->payment_status       = isset($data['payment_status']) ? $data['payment_status'] : null;
        $cart->deliver              = isset($data['deliver']) ? $data['deliver'] : 0;
        $cart->address_id           = isset($data['address_id']) ? $data['address_id'] : null;
        $cart->cupon_id             = isset($data['cupon_id']) ? $data['cupon_id'] : null;
        $cart->description          = isset($data['description']) ? $data['description'] : null;
        $cart->fecha_entrega        = !empty($data['fecha_entrega']) ? $data['fecha_entrega'] : null;

        EnvioCartHelper::sincronizar($cart, is_array($data) ? $data : []);
    }
}
