<?php

namespace App\Http\Controllers;

use App\BuyerAddress;
use App\Cart;
use App\DeliveryZone;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CartHelper;
use App\Http\Controllers\Helpers\CartOwnershipHelper;
use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use App\Http\Controllers\Helpers\CuotasMercadoPagoHelper;
use App\Http\Controllers\Helpers\DireccionesEsquemaHelper;
use App\Http\Controllers\Helpers\EnvioCartHelper;
use App\Order;
use Illuminate\Http\Exceptions\HttpResponseException;
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

        /* Catalogo por lista (mision catalogo-por-lista-tienda, 5/10/2026): las lineas que el
           comprador no puede ver por su lista de precios se deciden ANTES de sincronizar el envio, y
           no adentro de attachArticles() despues del save(). El envio arma de las lineas del payload
           todo lo que firma y cobra (el items_hash, el peso y el subtotal que cotiza Zipnova,
           envio_precio): si ahi entran las que el carrito descarta, se cotiza un paquete que no es el
           del carrito. Ver CartHelper::payload_con_las_lineas_que_se_guardan().

           `$cart->user_id` ya esta puesto en el modelo (sin guardar) y es lo unico que necesita la
           decision. Sin lista restringida `$no_disponibles` es vacio sin ninguna query. */
        $articulos_del_payload = $request->cart['articles'];

        $no_disponibles = CartHelper::articulos_no_disponibles($cart, $articulos_del_payload);

        $articulos_que_quedan = CartHelper::sin_lineas_no_disponibles($articulos_del_payload, $no_disponibles);

        /* Si la lista descarto TODO lo que se pedia y no hay promociones de vinoteca ni combos, no hay
           ninguna linea que guardar y el carrito no se crea: `200 {cart: null, articulos_no_disponibles}`,
           igual que el PUT que se queda sin lineas (que ya borra el carrito y responde `cart: null`). Antes
           quedaba un carrito vivo, sin lineas y con total 0, que `lastCart` devolvia como "ultimo carrito"
           y que el comprador nunca armo (hallazgo B6 de la revision independiente). Va antes de crear
           nada: ni el carrito ni su registro en la sesion existen. Sin descartes este `if` no entra y el
           camino es el de siempre, incluido el POST de un carrito vacio. */
        if (!empty($no_disponibles) && CartHelper::se_quedaria_sin_lineas($request->cart, $articulos_que_quedan)) {
            return response()->json($this->con_articulos_no_disponibles(['cart' => null], $no_disponibles), 200);
        }

        // Persistir opciones de checkout elegidas antes de confirmar (envío/retiro, pago, etc.)
        $this->sync_checkout_fields($cart, CartHelper::payload_con_las_lineas_que_se_guardan(
            $request->cart,
            $articulos_que_quedan
        ));
        $cart->save();

        // Las lineas descartadas ya estan calculadas arriba: attachArticles() no las vuelve a consultar.
        CartHelper::attachArticles($cart, $articulos_del_payload, $no_disponibles);
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

    	return response()->json($this->con_articulos_no_disponibles(['cart' => $cart], $no_disponibles), 201);
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

        /* Catalogo por lista (mision catalogo-por-lista-tienda, 5/10/2026). Las lineas de articulos
           que el comprador no puede ver por su lista se deciden ACA, antes de tocar el carrito, y no
           adentro de attachArticles() despues del sync([]) de abajo: asi el resto del metodo trabaja
           con el payload ya limpio y sus ramas de siempre deciden solas. Si no queda ninguna linea
           (ni articulos, ni promociones, ni combos), el carrito se borra igual que el carrito vacio
           de hoy, en vez de quedar vivo con total 0 y sin lineas.

           Sin lista restringida `$no_disponibles` es vacio sin ninguna query, `$articulos` es el
           MISMO array del request y todo lo de abajo corre exactamente como antes. */
        $no_disponibles = count($request->articles) >= 1
            ? CartHelper::articulos_no_disponibles($cart, $request->articles)
            : [];

        $articulos = CartHelper::sin_lineas_no_disponibles($request->articles, $no_disponibles);

        /* El envio se sincroniza con las lineas que de verdad se guardan, no con las del payload
           crudo: si no, una linea descartada entra en la cotizacion de Zipnova y en el items_hash.
           Sin descartes `$request->all()` pasa tal cual. */
        $this->sync_checkout_fields($cart, CartHelper::payload_con_las_lineas_que_se_guardan($request->all(), $articulos));
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
            count($articulos) >= 1
            || count($request->promociones_vinoteca) >= 1
            || count($combos) >= 1
        ) {

            if (count($articulos) >= 1) {
                // Ya vienen limpias: se le pasa el resultado de arriba para no volver a consultar.
                CartHelper::attachArticles($cart, $articulos, []);
            }

            if (count($request->promociones_vinoteca) >= 1) {
                CartHelper::attach_promociones_vinoteca($cart, $request->promociones_vinoteca);
            }

            if (count($combos) >= 1) {
                CartHelper::attach_combos($cart, $combos);
            }

            /* 🔴 `attach_combos()` descarta los combos sin precio vendible (NULL o <= 0). Si el
               payload traia SOLO combos y se descartaron todos, el carrito quedo sin una sola
               linea: es un carrito vacio y se comporta como tal (se borra, igual que cuando el
               payload llega con los tres arrays vacios). Sin esto quedaba vivo, con total 0 y sin
               lineas, y el comprador no podia deshacerse de el. Solo se mira cuando el payload no
               traia articulos ni promociones: el camino de los articulos no cambia. */
            $solo_combos_y_todos_descartados = count($articulos) == 0
                && count($request->promociones_vinoteca) == 0
                && (!ComboEsquemaHelper::disponible() || !$cart->combos()->exists());

            if ($solo_combos_y_todos_descartados) {
                $cart->delete();
                $cart_deleted = true;
            } else {
                CartHelper::set_total($cart);
            }
        } else {
            $cart->delete();
            $cart_deleted = true;
        }
        if (!$cart_deleted) {
            $cart = CartHelper::getFullModel($cart->id);
            return response()->json($this->con_articulos_no_disponibles(['cart' => $cart], $no_disponibles), 200);
        }
        return response()->json($this->con_articulos_no_disponibles(['cart' => null], $no_disponibles), 200);
    }

    /**
     * Suma a la respuesta del carrito la clave `articulos_no_disponibles` —`[{id, name}]`, las
     * lineas que no se guardaron porque el comprador no puede ver esos articulos por su lista de
     * precios— SOLO si hubo alguna (mision catalogo-por-lista-tienda, contrato C3 con tienda-spa).
     *
     * 🔴 Sin descartes la respuesta es byte a byte la de antes: la clave NO aparece, ni siquiera
     * vacia. Un SPA viejo la ignora de todas formas, pero cualquier clave nueva en el camino del
     * 100% de los compradores es un cambio de contrato que nadie pidio.
     *
     * @param  array  $respuesta
     * @param  array  $no_disponibles
     * @return array
     */
    private function con_articulos_no_disponibles(array $respuesta, $no_disponibles)
    {
        if (!empty($no_disponibles)) {
            $respuesta['articulos_no_disponibles'] = array_values($no_disponibles);
        }

        return $respuesta;
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
     * Guarda `cuotas` (misión tienda-cuotas-mercado-pago) solo si el método de pago elegido la
     * ofrece (`CuotasMercadoPagoHelper::sincronizar_carrito()`).
     *
     * Al final sincroniza el envío por correo (`$data['envio']`, misión zipnova-envios): si el
     * comprador eligió una opción de Zipnova, el servidor la re-cotiza cuando hace falta y deja el
     * precio en `carts.envio_precio`; si eligió retiro o una zona propia, limpia esas columnas.
     * Va acá y no después de adjuntar los artículos a propósito: un 422 de envío se lanza ANTES
     * del `save()`, así que no deja el carrito a medias. En una base sin las columnas de envío
     * el helper no hace nada (`ZipnovaEsquemaHelper`).
     *
     * Misión formas-envio-direcciones-maps (19/10/2026): la forma de envío propia tiene que ser una
     * zona DEL COMERCIO del carrito (`zona_del_comercio()`), y la dirección de entrega elegida viaja
     * en `$data['direccion_envio']` (`sync_direccion_envio()`), con la misma regla de "el 422 antes
     * del save()".
     *
     * @param \App\Cart $cart
     * @param array $data Payload del carrito enviado por tienda-spa
     * @return void
     */
    function sync_checkout_fields($cart, $data) {
        $cart->delivery_zone_id     = $this->zona_del_comercio($cart, isset($data['delivery_zone_id']) ? $data['delivery_zone_id'] : null);
        $cart->payment_card_info_id = isset($data['payment_card_info_id']) ? $data['payment_card_info_id'] : null;
        $cart->payment_method_id    = isset($data['payment_method_id']) ? $data['payment_method_id'] : null;
        $cart->payment_id           = isset($data['payment_id']) ? $data['payment_id'] : null;
        $cart->payment_status       = isset($data['payment_status']) ? $data['payment_status'] : null;
        $cart->deliver              = isset($data['deliver']) ? $data['deliver'] : 0;
        $cart->address_id           = isset($data['address_id']) ? $data['address_id'] : null;
        // La tienda ya no tiene cupones (14/10/2026, misión tiendas-demo-y-limpieza-tienda): el carrito se
        // guarda siempre sin cupón, venga lo que venga en el pedido.
        $cart->cupon_id             = null;
        $cart->description          = isset($data['description']) ? $data['description'] : null;
        $cart->fecha_entrega        = !empty($data['fecha_entrega']) ? $data['fecha_entrega'] : null;

        // Cuotas de Mercado Pago (mision tienda-cuotas-mercado-pago): `carts.cuotas` queda con lo que
        // el comprador eligio SOLO si el metodo de pago que se acaba de copiar arriba ofrece esa
        // cantidad; si no, NULL. Detras de la guarda de esquema: sin la columna no hace nada.
        CuotasMercadoPagoHelper::sincronizar_carrito($cart, is_array($data) ? $data : []);

        $this->sync_direccion_envio($cart, is_array($data) ? $data : []);

        EnvioCartHelper::sincronizar($cart, is_array($data) ? $data : []);
    }

    /**
     * El `delivery_zone_id` del payload, solo si es una forma de envío propia DEL COMERCIO del
     * carrito; si no, null.
     *
     * 🔴 Antes se copiaba cualquier id tal cual. Como la base es compartida entre todos los comercios
     * de la instancia, un carrito podía quedar atado a la zona de OTRO comercio (o a una que no
     * existe), y el precio de esa zona viajaba a la preferencia de Mercado Pago y al pedido del ERP.
     * Ahora también importa por las formas "a convenir": la que decide si se cobra el envío tiene que
     * ser una del comercio que vende.
     *
     * No es un 422 a propósito: un id que no es del comercio es un estado viejo del store del SPA (o
     * un payload armado a mano), y el carrito se guarda igual, sin forma propia elegida.
     *
     * @param \App\Cart $cart Con `user_id` ya puesto.
     * @param mixed $delivery_zone_id
     * @return int|null
     */
    private function zona_del_comercio($cart, $delivery_zone_id) {
        if (!is_numeric($delivery_zone_id) || (int) $delivery_zone_id <= 0) {
            return null;
        }

        $id = DeliveryZone::where('id', (int) $delivery_zone_id)
            ->where('user_id', $cart->user_id)
            ->value('id');

        if (is_null($id)) {
            Log::warning('CartController: se descarto una forma de envio que no es del comercio del carrito', [
                'cart_id'          => $cart->id,
                'commerce_id'      => $cart->user_id,
                'delivery_zone_id' => $delivery_zone_id,
            ]);

            return null;
        }

        return (int) $id;
    }

    /**
     * Copia al carrito la dirección de entrega elegida (misión formas-envio-direcciones-maps,
     * 19/10/2026): `carts.buyer_address_id` y la foto en `carts.envio_direccion`, con las claves de
     * `BuyerAddress::snapshot()`.
     *
     * `$data['direccion_envio']` puede venir de dos formas:
     *   - `{buyer_address_id}`: una dirección guardada del comprador con sesión de cuenta. Se lee de
     *     la base y tiene que ser SUYA; si no (o si no hay sesión de cuenta), 422 `direccion`.
     *   - el objeto entero (invitado, o el vendedor cargando el pedido de un cliente): se normaliza con
     *     las mismas reglas que el ABM (`BuyerAddress::normalizar()`); si le faltan datos, 422
     *     `direccion` con `errors` por campo.
     *
     * Las reglas de compatibilidad:
     *   - Sin el esquema (`DireccionesEsquemaHelper`) no hace nada: es la tienda de siempre.
     *   - Con `deliver != 1` limpia las dos columnas: sin envío no hay dirección de entrega.
     *   - Si el payload NO trae la clave `direccion_envio` (un SPA viejo), no toca nada.
     *   - `direccion_envio: null` (o vacío) limpia.
     *
     * Como el resto de `sync_checkout_fields()`, corre ANTES del `save()`: un 422 de acá no deja el
     * carrito escrito.
     *
     * @param \App\Cart $cart
     * @param array $data
     * @return void
     * @throws HttpResponseException 422 `direccion`.
     */
    private function sync_direccion_envio($cart, array $data) {
        if (!DireccionesEsquemaHelper::disponible()) {
            return;
        }

        $deliver = isset($data['deliver']) ? (int) $data['deliver'] : 0;

        if ($deliver !== 1) {
            $cart->buyer_address_id = null;
            $cart->envio_direccion = null;

            return;
        }

        if (!array_key_exists('direccion_envio', $data)) {
            return;
        }

        $direccion = $data['direccion_envio'];

        if (!is_array($direccion) || count($direccion) === 0) {
            $cart->buyer_address_id = null;
            $cart->envio_direccion = null;

            return;
        }

        if (isset($direccion['buyer_address_id']) && $direccion['buyer_address_id'] !== '') {
            $guardada = $this->direccion_guardada_del_comprador($direccion['buyer_address_id']);

            if (is_null($guardada)) {
                Log::warning('CartController: se pidio una direccion guardada que no es del comprador de la sesion', [
                    'cart_id'          => $cart->id,
                    'buyer_address_id' => $direccion['buyer_address_id'],
                    'buyer_id'         => $this->buyerId(),
                ]);

                $this->fallar_direccion('Esa dirección ya no está en tu cuenta. Elegí otra o cargala de nuevo.');
            }

            $cart->buyer_address_id = $guardada->id;
            $cart->envio_direccion = $guardada->como_snapshot();

            return;
        }

        $normalizada = BuyerAddress::normalizar($direccion);

        if (count($normalizada['errores']) > 0) {
            $this->fallar_direccion('Faltan datos de la dirección de entrega.', $normalizada['errores']);
        }

        $cart->buyer_address_id = null;
        $cart->envio_direccion = BuyerAddress::snapshot($normalizada['datos'], null);
    }

    /**
     * Una dirección guardada del comprador con sesión de CUENTA, o null. Nunca la identidad de
     * checkout del invitado: esa no tiene libreta de direcciones.
     *
     * @param mixed $buyer_address_id
     * @return \App\BuyerAddress|null
     */
    private function direccion_guardada_del_comprador($buyer_address_id) {
        $buyer_id = $this->buyerId();

        if (is_null($buyer_id) || !is_numeric($buyer_address_id)) {
            return null;
        }

        return BuyerAddress::where('id', (int) $buyer_address_id)
            ->where('buyer_id', $buyer_id)
            ->first();
    }

    /**
     * Corta el guardado del carrito con un 422 `direccion` (mismo mecanismo que
     * `EnvioCartHelper::fallar()`: la respuesta sale tal cual, sin pasar por el handler).
     *
     * @param string $message
     * @param array $errores `{campo: [mensaje]}`
     * @return void
     * @throws HttpResponseException
     */
    private function fallar_direccion($message, array $errores = []) {
        $body = ['codigo' => 'direccion', 'message' => $message];

        if (count($errores) > 0) {
            $body['errors'] = $errores;
        }

        throw new HttpResponseException(response()->json($body, 422));
    }
}
