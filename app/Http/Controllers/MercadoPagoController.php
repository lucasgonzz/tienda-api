<?php

namespace App\Http\Controllers;

use App\Cart;
use App\Http\Controllers\Helpers\CartOwnershipHelper;
use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use App\Http\Controllers\Helpers\CuotasMercadoPagoHelper;
use App\Http\Controllers\Helpers\DireccionesEsquemaHelper;
use App\Http\Controllers\Helpers\EnvioCartHelper;
use App\Http\Controllers\Helpers\MercadoPagoCredentialsHelper;
use App\Http\Controllers\Helpers\OnlinePaymentHelper;
use App\Order;
use App\PaymentMethod;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoPagoController extends Controller
{
    /** API de pagos de Mercado Pago: de aca se lee el estado REAL de un pago. */
    const API_PAGOS = 'https://api.mercadopago.com/v1/payments/';

    /**
     * Con que access token se arma la preferencia de este carrito.
     *
     * Esta afuera de `preference()` a proposito: es la unica parte de ese metodo que se puede
     * probar sin salir a la red (`$preference->save()` hace un POST a api.mercadopago.com). Los
     * tests la invocan por reflexion, igual que
     * `empresa-api/tests/Feature/Integraciones/IntegracionesMercadoPagoTest.php` hace con el
     * resolvedor de state del OAuth.
     *
     * @param int $user_id Comercio (owner) que cobra.
     * @param \App\PaymentMethod|null $payment_method Fila de `payment_methods` que eligio el comprador.
     * @return string|null
     */
    protected function access_token_para_cobrar($user_id, $payment_method)
    {
        $credentials = MercadoPagoCredentialsHelper::credentials_for_payment_method($user_id, $payment_method);

        return $credentials['access_token'];
    }

    /**
     * Crea la preferencia de pago de Mercado Pago del carrito.
     *
     * 🔴 EL ACCESS TOKEN SALE DE `MercadoPagoCredentialsHelper`, no derecho de la fila que manda
     * el SPA. Es el mismo orden que usa `empresa-api` (conector conectado -> `payment_methods`),
     * y tiene que seguir siendolo: la base es compartida, asi que si los dos repos resolvieran
     * distinto, un comercio cobraria con una cuenta en el ERP y con otra en la tienda.
     *
     * El fallback no se saca. Hay clientes en produccion cuya unica credencial de Mercado Pago
     * vive en `payment_methods`, y `empresa` y `tienda` nunca llegan a produccion el mismo dia:
     * durante toda esa ventana el conector puede no existir todavia.
     *
     * Lo que se le manda a Mercado Pago ademas de los items (URLs de retorno, retorno automatico,
     * webhook y referencia al carrito) sale de `datos_de_preferencia()`, que es lo que se puede
     * probar sin red.
     *
     * @param Request $request payment_method (fila elegida), cupon, delivery_zone, articles, cart_id (opcional).
     * @return \Illuminate\Http\JsonResponse
     */
    function preference(Request $request) {
        $this->payment_method = PaymentMethod::find($request->payment_method['id']);
        $this->commerce = User::find($request->payment_method['user_id']);

        $access_token = $this->access_token_para_cobrar(
            $request->payment_method['user_id'],
            $this->payment_method
        );

        if (empty($access_token)) {
            Log::error('MercadoPagoController@preference: el comercio '.$request->payment_method['user_id'].' no tiene credencial de Mercado Pago');
            return response()->json([
                'message' => 'El comercio no tiene una cuenta de Mercado Pago conectada.',
            ], 422);
        }

        // El carrito se resuelve ANTES de armar los items: el envio por correo se cobra con lo que
        // el servidor dejo en carts.envio_precio, y eso sale del carrito, no del body.
        $cart = $this->carrito_del_pago($request);

        $articles = $this->articulos_a_cobrar($request, $this->commerce, $this->payment_method, $cart);

        // Cuotas (mision tienda-cuotas-mercado-pago): `contado` / `cuotas` son OPCIONALES en el body y
        // sin ninguno de los dos —o con un metodo que no ofrece cuotas— `$cuotas` queda vacio y la
        // preferencia sale exactamente como antes. El recargo sale de la base, nunca del body.
        //
        // Va ANTES de `SDK::setAccessToken()`: esa llamada hace un GET `/users/me` a Mercado Pago, y un
        // 422 por cuotas invalidas no tiene por que gastar un pedido de red. Nada de lo que corre antes
        // depende del SDK (el carrito y los items salen de la base).
        $cuotas = $this->cuotas_de_la_preferencia($request, $this->payment_method, $articles, $cart);

        if (!is_null($cuotas['error'])) {
            return response()->json(['message' => $cuotas['error']], 422);
        }

        $articles = array_merge($articles, $cuotas['items']);

        \MercadoPago\SDK::setAccessToken($access_token);

        // Crea un objeto de preferencia
        $preference = new \MercadoPago\Preference();

        $items = [];
        foreach ($articles as $article) {
            // Crea un ítem en la preferencia
            $item = new \MercadoPago\Item();
            $item->title = $article['name'];
            $item->quantity = $article['amount'];
            $item->unit_price = $article['final_price'];
            $items[] = $item;
        }
        $preference->items = $items;

        foreach ($this->datos_de_preferencia($request, $this->commerce, $cart) as $campo => $valor) {
            $preference->{$campo} = $valor;
        }

        // Sin cuotas pedidas no se manda `payment_methods`: Mercado Pago ofrece lo que ofrecia siempre.
        if (!empty($cuotas['payment_methods'])) {
            $preference->payment_methods = $cuotas['payment_methods'];
        }

        $preference->save();

        // `Entity::save()` NO tira ante un 4xx de Mercado Pago: devuelve false y deja el detalle en
        // `$preference->error`. Sin mirarlo, esto respondia 201 con `preference_id: null` y el boton
        // de la tienda quedaba muerto sin ninguna señal. Los tres campos que esta mision agrega
        // (`auto_return`, `back_urls`, `notification_url`) son justamente los que MP valida.
        if (!empty($preference->error)) {
            $error = $preference->error;
            $mensaje = isset($error->message) ? (string) $error->message : 'sin detalle';

            Log::error('MercadoPagoController@preference: Mercado Pago rechazo la preferencia', [
                'commerce_id' => $this->commerce->id,
                'cart_id'     => $cart ? $cart->id : null,
                'message'     => $mensaje,
                'causes'      => isset($error->causes) ? $error->causes : null,
            ]);

            return response()->json([
                'message' => 'Mercado Pago rechazo la preferencia de pago: '.$mensaje,
            ], 422);
        }

        Log::info('MercadoPagoController@preference: preferencia creada', [
            'commerce_id'   => $this->commerce->id,
            'cart_id'       => $cart ? $cart->id : null,
            'preference_id' => $preference->id,
            'cuotas'        => $cuotas['cuotas'],
        ]);

        // `init_point` es la URL del Checkout Pro de ESTA preferencia: con ella la tienda manda al
        // comprador derecho a Mercado Pago (`window.location.href`) en el mismo click con el que
        // confirma el pedido. Antes solo se devolvia el `preference_id`, y el SPA tenia que cargar
        // el SDK de Mercado Pago y dejar que dibujara SU boton, que aparecia recien despues de
        // elegir el medio de pago: dos clicks y una espera en el medio para hacer una sola cosa.
        //
        // 🔴 Se agrega, no reemplaza: `preference_id` se sigue devolviendo porque un SPA no
        // desplegado todavia es el que lo lee, y los dos lados nunca llegan a produccion el mismo
        // dia. `sandbox_init_point` viaja para las credenciales de prueba, donde `init_point`
        // apunta al checkout productivo y no sirve.
        return response()->json($this->respuesta_de_preferencia($preference), 201);
    }

    /**
     * Los items que se le cobran al comprador (articulos, cupon y envio), listos para la preferencia.
     *
     * Esta afuera de `preference()` por el mismo motivo que `datos_de_preferencia()`: es lo que se
     * puede probar sin salir a la red. Lo que agrega la mision zipnova-envios es el envio por correo:
     *
     * 🔴 Si el carrito va por Zipnova (`EnvioCartHelper::precio_para_cobrar()` no es null), el
     * envio se cobra con `carts.envio_precio` —el precio que el servidor cotizo al elegir la
     * opcion— y la `delivery_zone` del body se IGNORA aunque venga: la zona y el correo son
     * excluyentes y un body con las dos es un estado viejo del SPA. Si el carrito no va por
     * Zipnova (o no se mando `cart_id`), vale la zona del body como siempre.
     *
     * 🔴 CON CARRITO RESUELTO, LOS ARTICULOS/CUPON/ZONA SALEN DEL CARRITO, NUNCA DEL BODY (mision
     * `mp-precio-servidor-y-credenciales-env`, 16/9/2026). Antes de esta mision `$request->articles`
     * viajaba derecho a `setPrices()`, que solo aplica el recargo del comercio y del medio de pago
     * sobre el `final_price` que le llega — sin volver a mirar la base. O sea que quien arma el
     * request de la preferencia podia cobrar lo que quisiera, sin tocar el carrito para nada: el
     * carrito de $27.999 y la preferencia de $1 se armaban con el mismo POST.
     *
     * El carrito YA tiene, persistidos por el servidor, los precios con los que se mostro y se
     * confirmo (`CartHelper::attachArticles()` los resuelve al agregar cada linea, y
     * `CartHelper::set_total()` los revalida antes de guardar el total): alcanza con leerlos de ahi
     * en vez de confiar en una copia nueva que manda la MISMA request que pide cobrar. Mismo cupon:
     * se usa `$cart->cupon` (lo que de verdad quedo atado al carrito, o sea carritos viejos), nunca
     * `$request->cupon`: ese campo ya no se lee en ningun camino (mision sacar-cupones-y-login-google).
     *
     * 🔴 TRES AGUJEROS QUE DESTAPO EL CHEQUEO INDEPENDIENTE, LOS TRES DE "COBRAR DE MENOS EN
     * SILENCIO" — el mismo problema que esta mision existe para cerrar, asi que no alcanzaba con
     * mirar solo `$cart->articles`:
     *
     *  1. Las promociones de vinoteca viven en OTRA relacion (`$cart->promociones_vinoteca`), que
     *     `CartHelper::set_total()` SI suma al `cart.total` que ve el comprador. Sin agregarlas
     *     aca, un carrito de $6.000 (articulo $1.000 + promocion $5.000) armaba la preferencia
     *     por $1.000: la promocion desaparecia entera, sin error ni warning.
     *  2. `Article` usa `SoftDeletes`, y el scope global de Eloquent excluye automaticamente las
     *     filas borradas de CUALQUIER relacion — incluida `$cart->articles`. Un comercio que da
     *     de baja un articulo agotado mientras un comprador tiene el carrito abierto (un caso
     *     operativo normal, no un ataque) hacia que ese articulo se esfumara del cobro. Por eso
     *     `->articles()->withTrashed()->get()` en vez de la propiedad `$cart->articles`: el
     *     carrito ya tiene el precio acordado: excluirlo cobraria de menos, no cobrar nada es
     *     peor que cobrar lo que ya se habia mostrado.
     *  3. 🔴 LOS COMBOS SON UNA TERCERA COLECCION Y ESTE LUGAR SE OLVIDO (mision
     *     `combos-y-rangos-de-precio`, 16/9/2026). Es EL MISMO bug del punto 1, una coleccion
     *     despues: `CartHelper::set_total()` suma las tres (`:549-553`), pero aca se mergeaban
     *     dos. Un carrito de $6.000 ($1.000 de articulo + $5.000 de combo) armaba la preferencia
     *     por $1.000 — el comprador pagaba $1.000, `order_combo` guardaba el combo igual, y al
     *     confirmar el pedido en el ERP la venta nacia por $6.000 con el stock de los componentes
     *     descontado. Sin error, sin log, sin nada que lo avise.
     *
     *     ⚠️ LA LECCION, que es lo unico que impide que vuelva a pasar con la CUARTA coleccion:
     *     este metodo se recorre entero cada vez que el carrito gana algo comprable. La regla es
     *     "lo que suma `CartHelper::set_total()` es lo que se cobra"; si las dos listas no
     *     coinciden, hay una fuga de plata. `PrecioDeLaPreferenciaSaleDelCarritoTest` clava ese
     *     invariante (la suma de los items de la preferencia == `cart.total`) justamente para que
     *     el olvido sea rojo y no silencio.
     *
     * SIN carrito resuelto (SPA viejo que no manda `cart_id`, o `cart_id` ajeno/inexistente) se
     * mantiene el camino de siempre, sin tocarlo: es un agujero preexistente y ya documentado en
     * `CartHelper::get_price()` ("el cliente fija el precio base... arreglarlo es otra mision"), no
     * uno que esta mision abra. Lo que se cierra es el camino con carrito, que es el que usa
     * cualquier SPA actual.
     *
     * @param Request $request delivery_zone, articles (solo se usan sin carrito resuelto). El `cupon` del body no se lee.
     * @param \App\User $commerce Comercio que cobra (con su online_configuration).
     * @param \App\PaymentMethod $payment_method Fila de `payment_methods` que eligio el comprador.
     * @param \App\Cart|null $cart Carrito que se paga, si se pudo resolver.
     * @return array<int, array{name: string, amount: mixed, final_price: mixed}>
     */
    protected function articulos_a_cobrar(Request $request, $commerce, $payment_method, $cart)
    {
        $online_payment_helper = new OnlinePaymentHelper($commerce, $payment_method);

        $envio_precio = EnvioCartHelper::precio_para_cobrar($cart);

        if ($cart) {
            // Una forma propia "a convenir" (mision formas-envio-direcciones-maps) no se cobra
            // online: no viaja ningun item de envio, ni siquiera uno de $0. El recargo por cuotas
            // tampoco la suma, porque se calcula sobre estos mismos items.
            $delivery_zone = is_null($envio_precio) && $cart->delivery_zone && !DireccionesEsquemaHelper::zona_a_convenir($cart->delivery_zone)
                ? ['price' => $cart->delivery_zone->price]
                : null;

            $cupon = $cart->cupon
                ? ['amount' => $cart->cupon->amount, 'percentage' => $cart->cupon->percentage]
                : null;

            $mapear = function ($item) {
                return [
                    'name'        => $item->name,
                    'amount'      => $item->pivot->amount,
                    'final_price' => $item->pivot->price,
                ];
            };

            // withTrashed(): ver el punto 2 del docblock de arriba.
            $articles = $cart->articles()->withTrashed()->get()->map($mapear)->all();

            // Ver el punto 1 del docblock de arriba. `PromocionVinoteca` tambien usa SoftDeletes,
            // asi que se trae con el mismo `withTrashed()` que los articulos y por el mismo motivo.
            $promociones = $cart->promociones_vinoteca()->withTrashed()->get()->map($mapear)->all();

            // Ver el punto 3 del docblock de arriba. Detras de la guarda de esquema, como TODO
            // acceso a los combos en este repo: sin `cart_combo` esta linea seria "Base table or
            // view not found" con el comprador apretando "Pagar", que es el peor lugar posible
            // para enterarse (`ComboEsquemaHelper`). Sin esquema no hay combos que cobrar y el
            // cobro queda byte por byte como en master.
            //
            // `withTrashed()` por el punto 2: `Combo` tambien usa SoftDeletes, y un combo dado de
            // baja mientras el comprador tiene el carrito abierto no puede esfumarse del cobro.
            $combos = ComboEsquemaHelper::disponible()
                ? $cart->combos()->withTrashed()->get()->map($mapear)->all()
                : [];

            return $online_payment_helper->setPrices($cupon, $delivery_zone, array_merge($articles, $promociones, $combos), $envio_precio);
        }

        $delivery_zone = is_null($envio_precio) ? $request->delivery_zone : null;

        // Sin carrito resuelto no hay ningun cupon que valga: la tienda ya no tiene cupones y un
        // `cupon` armado a mano en el body bajaba el total cobrado. Siempre null en este camino.
        return $online_payment_helper->setPrices(null, $delivery_zone, $request->articles, $envio_precio);
    }

    /**
     * Lo que el comprador pidio en materia de cuotas (`contado` / `cuotas` del body), resuelto
     * contra lo que el SERVIDOR ofrece (mision `tienda-cuotas-mercado-pago`, 15/10/2026).
     *
     * Esta afuera de `preference()` por el mismo motivo que `articulos_a_cobrar()` y
     * `datos_de_preferencia()`: es todo lo de las cuotas que se puede probar sin que
     * `$preference->save()` salga a la red.
     *
     * Las reglas, en este orden:
     *  1. Sin `contado` ni `cuotas` NO SE HACE NADA, ni siquiera una consulta: la preferencia sale
     *     exactamente como antes. Es el camino de un SPA viejo y el de todo comercio sin la feature.
     *  2. Si el metodo de pago no tiene `cuotas_disponibles` (no es Mercado Pago, no esta vinculado,
     *     no hay cuotas cargadas o la base no tiene las columnas), `contado` y `cuotas` se IGNORAN
     *     aunque vengan: la preferencia sale como antes. Es lo que mantiene andando a un SPA que
     *     mande los campos contra un comercio que ya no ofrece cuotas.
     *  3. 🔴 Con carrito resuelto, el metodo de pago tiene que ser del MISMO comercio que el carrito
     *     (`payment_methods.user_id == carts.user_id`), o 422. Sin esto, el id de un metodo
     *     vinculado de OTRO comercio (la lista publica los muestra a todos) cobraba el recargo de ese
     *     otro comercio sobre este carrito. Se compara con el comercio y NO con
     *     `carts.payment_method_id`: el carrito del servidor puede no tener todavia el metodo
     *     guardado cuando se pide la preferencia.
     *  4. `contado` y `cuotas` a la vez → error (422): son dos opciones excluyentes.
     *  5. `cuotas` que no es un entero o que no esta en `cuotas_disponibles` → error (422).
     *  6. `contado: true` → se excluye la tarjeta de credito, sin recargo.
     *  7. `cuotas: N` → se suma el item «Recargo por pago en N cuotas» y se restringen los medios.
     *
     * 🔴 EL PORCENTAJE NUNCA SALE DEL BODY. De `cuotas` se usa solo la cantidad; el recargo se
     * resuelve de la base (una sola lectura de `CuotasMercadoPagoHelper::ofertas()`) y se aplica
     * sobre `$articles`, que ya es lo que el servidor armo desde el carrito (con el envio incluido).
     *
     * 🔴 Si la lectura de las cuotas FALLA con el comprador pidiendo contado o cuotas, es un 422 con
     * un mensaje claro y no un "ignorar": ignorarlo cobraria el precio sin recargo en una compra que
     * el comprador vio en cuotas. Sin esos campos no se lee nada, asi que el checkout de siempre no
     * puede romperse por esto.
     *
     * @param Request $request `contado` (bool, opcional) y `cuotas` (int, opcional).
     * @param \App\PaymentMethod|null $payment_method Fila de `payment_methods` que eligio el comprador.
     * @param array $articles Los items que devolvio `articulos_a_cobrar()`.
     * @param \App\Cart|null $cart El carrito resuelto, si lo hay.
     * @return array{error: string|null, modo: string|null, cuotas: int|null, recargo_neto: float|null, recargo_en_plata: float, items: array, payment_methods: array}
     *         `items` son los items EXTRA a sumar (el recargo, o nada); `payment_methods` es el campo
     *         de la preferencia (vacio = no se manda).
     */
    protected function cuotas_de_la_preferencia(Request $request, $payment_method, array $articles, $cart = null)
    {
        $resultado = [
            'error'            => null,
            'modo'             => null,
            'cuotas'           => null,
            'recargo_neto'     => null,
            'recargo_en_plata' => 0.0,
            'items'            => [],
            'payment_methods'  => [],
        ];

        $quiere_contado = $request->boolean('contado');
        $quiere_cuotas  = $request->filled('cuotas');

        if (!$quiere_contado && !$quiere_cuotas) {
            return $resultado;
        }

        try {
            $ofertas = CuotasMercadoPagoHelper::ofertas($payment_method);
        } catch (\Throwable $e) {
            CuotasMercadoPagoHelper::avisar_falla_de_lectura('preferencia', $payment_method, $e);

            $resultado['error'] = 'No pudimos calcular las cuotas en este momento. Probá de nuevo en un rato.';

            return $resultado;
        }

        if (empty($ofertas)) {
            return $resultado;
        }

        if ($cart && (int) $payment_method->user_id !== (int) $cart->user_id) {
            Log::warning('MercadoPagoController@preference: se pidieron cuotas con un metodo de pago que no es del comercio del carrito', [
                'cart_id'              => $cart->id,
                'comercio_del_carrito' => $cart->user_id,
                'comercio_del_metodo'  => $payment_method->user_id,
                'payment_method_id'    => $payment_method->id,
            ]);

            $resultado['error'] = 'Ese medio de pago no corresponde a este carrito. Volvé a elegir cómo querés pagar.';

            return $resultado;
        }

        if ($quiere_contado && $quiere_cuotas) {
            $resultado['error'] = 'Elegí pagar de contado o en cuotas, no las dos opciones a la vez.';

            return $resultado;
        }

        if ($quiere_cuotas) {
            $cantidad = CuotasMercadoPagoHelper::entero_de_cuotas($request->input('cuotas'));
            $neto = is_null($cantidad) ? null : CuotasMercadoPagoHelper::neto_de($ofertas, $cantidad);

            if (is_null($neto)) {
                $resultado['error'] = 'Esa cantidad de cuotas no está disponible. Volvé a elegir cómo querés pagar.';

                return $resultado;
            }

            $recargo = CuotasMercadoPagoHelper::recargo_en_plata(CuotasMercadoPagoHelper::base_a_cobrar($articles), $neto);
            $item = CuotasMercadoPagoHelper::item_de_recargo($cantidad, $recargo);

            $resultado['modo']             = CuotasMercadoPagoHelper::MODO_CUOTAS;
            $resultado['cuotas']           = $cantidad;
            $resultado['recargo_neto']     = $neto;
            $resultado['recargo_en_plata'] = $recargo;
            $resultado['items']            = is_null($item) ? [] : [$item];
            $resultado['payment_methods']  = CuotasMercadoPagoHelper::restricciones_de_medios(CuotasMercadoPagoHelper::MODO_CUOTAS, $cantidad);

            return $resultado;
        }

        $resultado['modo']            = CuotasMercadoPagoHelper::MODO_CONTADO;
        $resultado['payment_methods'] = CuotasMercadoPagoHelper::restricciones_de_medios(CuotasMercadoPagoHelper::MODO_CONTADO);

        return $resultado;
    }

    /**
     * Lo que se le contesta al SPA cuando la preferencia se creo bien.
     *
     * Esta afuera de `preference()` por el mismo motivo que `access_token_para_cobrar()`: es la
     * unica parte de la respuesta que se puede probar sin salir a la red, porque
     * `$preference->save()` hace un POST a api.mercadopago.com. Los tests la invocan por
     * reflexion con un objeto que imita la preferencia ya guardada.
     *
     * @param object $preference La preferencia del SDK, despues de `save()`.
     * @return array<string, mixed>
     */
    protected function respuesta_de_preferencia($preference)
    {
        return [
            'preference_id'      => isset($preference->id) ? $preference->id : null,
            'init_point'         => isset($preference->init_point) ? $preference->init_point : null,
            'sandbox_init_point' => isset($preference->sandbox_init_point) ? $preference->sandbox_init_point : null,
        ];
    }

    /**
     * El carrito que se esta pagando, si el SPA lo mando y es de quien esta pidiendo.
     *
     * `cart_id` es OPCIONAL: un SPA viejo no lo manda y la preferencia se arma igual, solo que sin
     * referencia al carrito (y entonces el webhook no tiene a que pedido atarle el pago, que es
     * exactamente lo que pasaba antes de esta mision). La propiedad se valida con el mismo criterio
     * que `CartController@update`: sin eso cualquiera podria colgar su pago del carrito de otro.
     *
     * @param Request $request
     * @return \App\Cart|null
     */
    protected function carrito_del_pago(Request $request)
    {
        $cart_id = $request->input('cart_id');

        if (empty($cart_id) || !is_numeric($cart_id)) {
            return null;
        }

        $cart = Cart::find((int) $cart_id);

        if (!CartOwnershipHelper::puede($cart)) {
            Log::warning('MercadoPagoController@preference: se pidio una preferencia con un carrito ajeno, se arma sin referencia', [
                'cart_id' => $cart_id,
            ]);

            return null;
        }

        return $cart;
    }

    /**
     * Lo que viaja en la preferencia ademas de los items.
     *
     * - `back_urls` + `auto_return`: a donde vuelve el comprador despues de pagar. Solo en
     *   produccion, porque Mercado Pago exige que sean URLs publicas y en local no las hay. Se
     *   miran por `app()->environment()` y no por `env('APP_ENV')`: con la configuracion cacheada
     *   `env()` devuelve null y las URLs de retorno desaparecian en silencio. `auto_return` solo
     *   tiene sentido con `back_urls.success` (sin ella Mercado Pago rechaza la preferencia), por
     *   eso van juntas. Si el comercio no tiene `users.online` cargado, no se manda ninguna de las
     *   dos: es mejor que el comprador tenga que apretar "Volver al sitio" que mandarlo a una URL
     *   que no existe.
     * - `notification_url`: el webhook de este mismo backend (`webhook()`), con el comercio en la
     *   query. Sin esto la unica confirmacion del pago era la que traia el navegador del comprador
     *   en la query string de `/pago-exitoso`, que cualquiera puede escribir a mano.
     * - `external_reference` y `metadata.cart_id`: el carrito, para que el webhook sepa a que
     *   pedido atarle el pago. Van los dos porque Mercado Pago devuelve `external_reference`
     *   siempre y `metadata` casi siempre; con uno alcanza.
     *
     * @param Request $request El request de la preferencia (para armar la URL del webhook).
     * @param \App\User $commerce Comercio que cobra.
     * @param \App\Cart|null $cart Carrito que se paga, si se pudo resolver.
     * @return array<string, mixed>
     */
    protected function datos_de_preferencia(Request $request, $commerce, $cart)
    {
        $datos = [];

        if (app()->environment('production')) {
            $online = rtrim((string) $commerce->online, '/');

            if ($online !== '') {
                $datos['back_urls'] = [
                    'success' => $online.'/pago-exitoso',
                    'pending' => $online.'/pago-pendiente',
                    'failure' => $online.'/pago-rechazado',
                ];
                $datos['auto_return'] = 'approved';
            }

            $datos['notification_url'] = $this->url_del_webhook($request, $commerce->id);
        }

        if ($cart) {
            $datos['external_reference'] = (string) $cart->id;
            $datos['metadata'] = [
                'cart_id'     => (int) $cart->id,
                'commerce_id' => (int) $commerce->id,
            ];
        }

        return $datos;
    }

    /**
     * URL publica del webhook, armada desde el request y no desde `APP_URL`.
     *
     * En el shared hosting Laravel se sirve desde `/public` (la SPA le pega a
     * `https://api-x/public/api/...`) y `APP_URL` esta cargada sin ese sufijo; en el VPS nginx
     * reescribe `/public` y la URL limpia funciona. `$request->root()` trae el esquema, el host y
     * el base path REAL con el que llego este request, asi que la URL que se le da a Mercado Pago
     * es la misma que acaba de funcionar para pedir la preferencia.
     *
     * @param Request $request
     * @param int $commerce_id
     * @return string
     */
    protected function url_del_webhook(Request $request, $commerce_id)
    {
        return rtrim($request->root(), '/').'/api/mercado-pago/webhook?commerce_id='.(int) $commerce_id;
    }

    /**
     * Webhook de Mercado Pago: le avisa a este backend que un pago cambio.
     *
     * 🔴 NUNCA CONFIA EN LO QUE TRAE LA NOTIFICACION. La ruta es publica y sin firma verificable
     * desde aca (el secret del webhook vive en el panel de Mercado Pago Developers), asi que el
     * body podria decir cualquier cosa. Lo unico que se toma de la notificacion es el ID del pago;
     * el estado, el monto y el carrito (`external_reference`) se leen de la API de Mercado Pago
     * con la credencial del comercio. Una notificacion inventada, a lo sumo, hace que se consulte
     * un pago que no existe.
     *
     * Formatos que manda Mercado Pago, y se aceptan los dos:
     * - Webhooks: `?type=payment&data.id=123` en la query y `{"type":"payment","data":{"id":"123"}}`
     *   en el body. PHP convierte `data.id` en `data_id` al parsear la query.
     * - IPN (viejo): `?topic=payment&id=123`.
     * Todo lo que no sea un pago (`merchant_order`, `plan`, etc.) se responde 200 y se ignora.
     *
     * Codigos de respuesta: 200 para todo lo que se proceso o se decidio ignorar (Mercado Pago
     * deja de reintentar); 500 SOLO cuando la API de Mercado Pago no contesto, para que reintente.
     *
     * El `commerce_id` viene en la query porque la base puede ser compartida entre varios
     * comercios (shared hosting): el pago se consulta con la credencial de ESE comercio y el
     * carrito tiene que ser de ese mismo comercio, o no se toca.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function webhook(Request $request)
    {
        $commerce_id = (int) $request->query('commerce_id');
        $tipo        = $request->input('type', $request->input('topic'));
        $payment_id  = $request->input('data.id', $request->query('data_id', $request->input('id')));

        // El id va concatenado en una URL que se consulta CON EL TOKEN DEL COMERCIO: solo digitos.
        // Sin esto, `data.id=search?external_reference=55` o `../../merchant_orders/123` harian un
        // GET autenticado a otro recurso y su respuesta se leeria como si fuera un pago.
        if ($tipo !== 'payment' || empty($payment_id) || $commerce_id <= 0 || !ctype_digit((string) $payment_id)) {
            return response()->json(['ignorado' => true, 'motivo' => 'no es una notificacion de pago completa'], 200);
        }

        $access_token = MercadoPagoCredentialsHelper::access_token($commerce_id);

        if (empty($access_token)) {
            // warning y no error: la ruta es publica y cualquiera puede mandar un commerce_id inventado.
            Log::warning('MercadoPagoController@webhook: llego un pago para un comercio sin credencial de Mercado Pago', [
                'commerce_id' => $commerce_id,
                'payment_id'  => $payment_id,
            ]);

            return response()->json(['ignorado' => true, 'motivo' => 'el comercio no tiene credencial'], 200);
        }

        $respuesta = Http::withToken($access_token)
            ->acceptJson()
            ->timeout(20)
            ->get(self::API_PAGOS.$payment_id);

        if ($respuesta->status() === 404) {
            Log::warning('MercadoPagoController@webhook: Mercado Pago no conoce ese pago para este comercio', [
                'commerce_id' => $commerce_id,
                'payment_id'  => $payment_id,
            ]);

            return response()->json(['ignorado' => true, 'motivo' => 'pago inexistente para el comercio'], 200);
        }

        if (!$respuesta->successful()) {
            Log::error('MercadoPagoController@webhook: la API de Mercado Pago no contesto, se pide reintento', [
                'commerce_id' => $commerce_id,
                'payment_id'  => $payment_id,
                'status'      => $respuesta->status(),
            ]);

            return response()->json(['error' => 'Mercado Pago no respondio, reintentar'], 500);
        }

        $pago = $respuesta->json();

        $cart = $this->carrito_del_pago_notificado(is_array($pago) ? $pago : []);

        if (!$cart) {
            Log::warning('MercadoPagoController@webhook: el pago no referencia ningun carrito conocido', [
                'commerce_id'        => $commerce_id,
                'payment_id'         => $payment_id,
                'external_reference' => isset($pago['external_reference']) ? $pago['external_reference'] : null,
            ]);

            return response()->json(['ignorado' => true, 'motivo' => 'sin carrito'], 200);
        }

        if ((int) $cart->user_id !== $commerce_id) {
            Log::error('MercadoPagoController@webhook: el carrito referenciado es de otro comercio, no se toca', [
                'commerce_id' => $commerce_id,
                'cart_id'     => $cart->id,
                'payment_id'  => $payment_id,
            ]);

            return response()->json(['ignorado' => true, 'motivo' => 'carrito de otro comercio'], 200);
        }

        if ($this->ya_tiene_otro_pago_aprobado($cart, $pago)) {
            Log::warning('MercadoPagoController@webhook: el carrito ya tiene un pago aprobado, no se pisa con otro que no lo esta', [
                'commerce_id'       => $commerce_id,
                'cart_id'           => $cart->id,
                'payment_id_actual' => $cart->payment_id,
                'payment_id_nuevo'  => $payment_id,
                'status_nuevo'      => isset($pago['status']) ? $pago['status'] : null,
            ]);

            return response()->json(['ignorado' => true, 'motivo' => 'ya hay un pago aprobado'], 200);
        }

        $cart->payment_id     = isset($pago['id']) ? $pago['id'] : $payment_id;
        $cart->payment_status = isset($pago['status']) ? $pago['status'] : null;
        $cart->save();

        if (!empty($cart->order_id)) {
            $order = Order::find($cart->order_id);

            if ($order) {
                $order->payment_id = $cart->payment_id;
                $this->anotar_cobro_en_el_pedido($order, $cart, $pago);
                $order->save();
            }
        }

        $this->avisar_si_el_cobro_no_coincide_con_las_cuotas($cart, $pago, $commerce_id);

        Log::info('MercadoPagoController@webhook: pago registrado', [
            'commerce_id' => $commerce_id,
            'cart_id'     => $cart->id,
            'order_id'    => $cart->order_id,
            'payment_id'  => $cart->payment_id,
            'status'      => $cart->payment_status,
        ]);

        // Solo `ok`: la ruta es publica y Mercado Pago no lee el cuerpo. Los ids quedan en el log.
        return response()->json(['ok' => true], 200);
    }

    /**
     * Anota en el pedido lo que Mercado Pago cobro de verdad: la cantidad de cuotas
     * (`orders.cuotas_cobradas`) y el tipo de medio (`orders.medio_de_pago_cobrado`). Mision
     * `tienda-cuotas-mercado-pago`.
     *
     * Con Checkout Pro la tienda no puede FORZAR que el comprador pague en las cuotas que eligio
     * (`installments` es un tope y «dinero en cuenta» no se puede excluir), asi que el pedido
     * queda con lo que realmente paso. Solo cuando el pago esta APROBADO: un pago rechazado o
     * pendiente no cobro nada, y anotarlo dejaria en el pedido «3 cuotas con tarjeta» sobre un
     * pedido sin cobrar. Las notificaciones de un mismo pago siguen siendo idempotentes.
     *
     * 🔴 Solo cuando la feature APLICA a ese carrito (`CuotasMercadoPagoHelper::aplica_al_carrito()`:
     * el metodo de pago ofrece cuotas, o el comprador ya habia elegido cuotas). Un comercio sin
     * vinculo ni cuotas cargadas sigue exactamente como antes: su pedido no gana ni el medio ni las
     * cuotas cobradas, y el ERP no empieza a mostrar nada nuevo.
     *
     * No guarda: el llamador ya hace el `save()` del pedido. Sin las columnas (guarda de esquema)
     * no hace nada.
     *
     * @param \App\Order $order
     * @param \App\Cart $cart El carrito del pago.
     * @param array $pago El pago tal como lo devuelve la API de Mercado Pago.
     * @return void
     */
    protected function anotar_cobro_en_el_pedido(Order $order, Cart $cart, array $pago)
    {
        if (!isset($pago['status']) || $pago['status'] !== 'approved') {
            return;
        }

        $atributos = CuotasMercadoPagoHelper::atributos_de_cobro($pago);

        if (empty($atributos) || !CuotasMercadoPagoHelper::aplica_al_carrito($cart)) {
            return;
        }

        foreach ($atributos as $columna => $valor) {
            $order->{$columna} = $valor;
        }
    }

    /**
     * Deja un warning si el comprador eligio N cuotas y Mercado Pago cobro otra cosa (otra
     * cantidad, o un medio que no es tarjeta de credito). No cambia nada del pago: es la alerta
     * para quien despues revise ese pedido. Solo con el pago aprobado (ver
     * `anotar_cobro_en_el_pedido()`).
     *
     * Lee `carts.cuotas`: en una base sin la columna es null y no hace nada.
     *
     * @param \App\Cart $cart
     * @param array $pago El pago tal como lo devuelve la API de Mercado Pago.
     * @param int $commerce_id
     * @return void
     */
    protected function avisar_si_el_cobro_no_coincide_con_las_cuotas(Cart $cart, array $pago, $commerce_id)
    {
        if (!isset($pago['status']) || $pago['status'] !== 'approved') {
            return;
        }

        if (!CuotasMercadoPagoHelper::el_cobro_no_coincide($cart->cuotas, $pago)) {
            return;
        }

        Log::warning('MercadoPagoController@webhook: el comprador eligio cuotas y Mercado Pago cobro distinto', [
            'commerce_id'     => $commerce_id,
            'cart_id'         => $cart->id,
            'order_id'        => $cart->order_id,
            'payment_id'      => isset($pago['id']) ? $pago['id'] : null,
            'cuotas_elegidas' => $cart->cuotas,
            'cuotas_cobradas' => isset($pago['installments']) ? $pago['installments'] : null,
            'medio_cobrado'   => isset($pago['payment_type_id']) ? $pago['payment_type_id'] : null,
        ]);
    }

    /**
     * Un carrito que ya tiene un pago APROBADO no se pisa con la notificacion de OTRO pago que no lo
     * esta. El caso: la primera tarjeta del comprador se rechaza (pago A) y la segunda se aprueba
     * (pago B); si la notificacion de A llega despues que la de B (reintento de MP tras un 500
     * nuestro, o llegada desordenada), sin esta guarda el pedido quedaba con `payment_id = A` y
     * `payment_status = rejected`, y como el webhook es el unico camino que ata el pago al pedido en
     * el flujo de Mercado Pago, nadie lo corregia. Para el MISMO pago las notificaciones siguen
     * siendo idempotentes: siempre se consulta el estado actual y se vuelve a escribir.
     *
     * @param \App\Cart $cart
     * @param array $pago El pago tal como lo devuelve la API de Mercado Pago.
     * @return bool
     */
    protected function ya_tiene_otro_pago_aprobado(Cart $cart, array $pago)
    {
        if ($cart->payment_status !== 'approved' || empty($cart->payment_id)) {
            return false;
        }

        $es_el_mismo_pago = isset($pago['id']) && (string) $pago['id'] === (string) $cart->payment_id;
        $viene_aprobado   = isset($pago['status']) && $pago['status'] === 'approved';

        return !$es_el_mismo_pago && !$viene_aprobado;
    }

    /**
     * El carrito al que pertenece un pago, segun lo que Mercado Pago tiene guardado de el.
     *
     * Primero `external_reference` (lo que `datos_de_preferencia()` escribe), despues
     * `metadata.cart_id`. Las dos las escribio este mismo backend al crear la preferencia; un pago
     * hecho desde afuera no trae ninguna y se ignora.
     *
     * @param array $pago El pago tal como lo devuelve la API de Mercado Pago.
     * @return \App\Cart|null
     */
    protected function carrito_del_pago_notificado(array $pago)
    {
        $cart_id = isset($pago['external_reference']) ? $pago['external_reference'] : null;

        if ((empty($cart_id) || !is_numeric($cart_id)) && isset($pago['metadata']['cart_id'])) {
            $cart_id = $pago['metadata']['cart_id'];
        }

        if (empty($cart_id) || !is_numeric($cart_id)) {
            return null;
        }

        return Cart::find((int) $cart_id);
    }
}
