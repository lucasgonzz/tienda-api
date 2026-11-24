<?php

namespace App\Http\Controllers\Helpers;

use App\Cart;
use App\Order;
use App\PaymentMethod;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El cobro con tarjeta ADENTRO de la tienda con Mercado Pago (mision `tienda-cobro-tarjeta`,
 * 24/11/2026): el comprador carga la tarjeta en el Card Payment Brick de la propia pagina, el
 * navegador tokeniza con la public key y el servidor crea el pago (`POST /v1/payments`) con ese
 * token. Sin redirigir a Mercado Pago y sin cuenta de Mercado Pago del comprador.
 *
 * ── Reglas que este helper NO puede romper ────────────────────────────────────────────────────
 *
 *  1. EL MONTO LO CALCULA SIEMPRE EL SERVIDOR (`opciones()`): precio web (con el recargo de la
 *     tienda y el del metodo) + envio, y sobre esa base el recargo de la fila de `cuotas`. Un
 *     `transaction_amount` del body no se lee jamas; el `monto_esperado` del comprador solo se
 *     COMPARA (si no coincide, 409 y no se cobra).
 *  2. REGLA PCI: los datos de la tarjeta nunca pasan por nuestro servidor. Solo viaja el token del
 *     Brick, y ni el token ni el body ni los datos de la tarjeta se escriben en ningun log: los logs
 *     llevan solo ids (carrito, pago), estado y `status_detail`.
 *  3. `binary_mode = true`: cada pago queda aprobado o rechazado, nunca «en revision» (un pago en
 *     revision sin pedido seria plata cobrada sin pedido).
 *  4. `X-Idempotency-Key` DETERMINISTICO (`clave_de_idempotencia()`): el reintento del mismo token
 *     (corte de red, deadlock de la transaccion del pedido) devuelve el mismo pago en vez de cobrar
 *     dos veces.
 *  5. El pedido solo nace con el pago aprobado: este helper cobra; `OrderController` crea el pedido
 *     dentro de la misma transaccion y del mismo candado de siempre solo si `cobrar()` devolvio el pago.
 *
 * El cobro va por la fachada `Http` (la misma con la que el webhook consulta pagos) y no por el SDK
 * legado de Mercado Pago: asi se puede falsear con `Http::fake()` y el header de idempotencia es explicito.
 */
class TarjetaMercadoPagoHelper
{
    /** Nombre EXACTO del tipo de metodo de pago (contrato con empresa-api, que lo siembra, y con tienda-spa). */
    const TIPO = 'Tarjeta Mercado Pago';

    /** Base de la API de Mercado Pago. */
    const API = 'https://api.mercadopago.com';

    /** Texto por defecto cuando Mercado Pago rechaza por un motivo que la tabla no conoce. */
    const RECHAZO_POR_DEFECTO = 'Tu tarjeta rechazó el pago. Probá con otra tarjeta o elegí otro medio de pago.';

    /** Tolerancia al comparar montos (un centavo). */
    const TOLERANCIA = 0.01;

    /**
     * True si el metodo de pago es de tipo Tarjeta Mercado Pago (nombre exacto).
     *
     * @param \App\PaymentMethod|null $payment_method
     * @return bool
     */
    public static function es_tarjeta($payment_method)
    {
        return $payment_method instanceof PaymentMethod
            && $payment_method->type
            && $payment_method->type->name === self::TIPO;
    }

    /**
     * La clave de idempotencia del cobro: la misma para el mismo carrito y el mismo token del Brick.
     *
     * @param int $cart_id
     * @param string $token Token de la tarjeta (no se loguea: aca solo se hashea).
     * @return string
     */
    public static function clave_de_idempotencia($cart_id, $token)
    {
        return 'cc-tarjeta-'.$cart_id.'-'.substr(hash('sha256', (string) $token), 0, 40);
    }

    /**
     * El texto para el comprador de un rechazo de Mercado Pago, segun su `status_detail`.
     *
     * @param string|null $status_detail
     * @return string
     */
    public static function mensaje_de_rechazo($status_detail)
    {
        $mensajes = [
            'cc_rejected_insufficient_amount'     => 'Tu tarjeta no tiene fondos suficientes. Probá con otra tarjeta o elegí otro medio de pago.',
            'cc_rejected_bad_filled_card_number'  => 'Revisá el número de la tarjeta e intentá de nuevo.',
            'cc_rejected_bad_filled_date'         => 'Revisá la fecha de vencimiento e intentá de nuevo.',
            'cc_rejected_bad_filled_security_code' => 'Revisá el código de seguridad e intentá de nuevo.',
            'cc_rejected_bad_filled_other'        => 'Revisá los datos de la tarjeta e intentá de nuevo.',
            'cc_rejected_call_for_authorize'      => 'Tenés que autorizar el pago con tu banco. Llamá al número que figura en tu tarjeta o probá con otra.',
            'cc_rejected_card_disabled'           => 'Tu tarjeta no está activa. Llamá a tu banco para activarla o probá con otra tarjeta.',
            'cc_rejected_duplicated_payment'      => 'Ya hiciste un pago por ese importe. Si necesitás volver a pagar, usá otra tarjeta u otro medio de pago.',
            'cc_rejected_high_risk'               => 'No pudimos procesar el pago por seguridad. Elegí otro medio de pago.',
            'cc_rejected_max_attempts'            => 'Llegaste al límite de intentos permitidos. Probá con otra tarjeta u otro medio de pago.',
        ];

        return isset($mensajes[$status_detail]) ? $mensajes[$status_detail] : self::RECHAZO_POR_DEFECTO;
    }

    /**
     * Las opciones de pago de un carrito: «1 pago» y una por fila de cuotas del comercio, con el
     * total que se cobra y el valor de cada cuota. TODO sale del servidor.
     *
     * Base = precio web (con el `online_price_surchage` de la tienda y el descuento/recargo del
     * metodo) + envio, la misma lista de items que el Checkout Pro
     * (`OnlinePaymentHelper::items_del_carrito()`). El recargo de cada opcion es el neto de su fila de
     * `cuotas` (recargo - descuento) sobre esa base. Siempre existe «1 pago» (regla 4: sin recargo
     * adicional) salvo que el comercio cargue una fila de 1 cuota, que se respeta.
     *
     * TIRA si la base falla al leer las cuotas: quien llama decide (montos: 503; cobro: 503).
     *
     * @param \App\Cart $cart
     * @param \App\User $commerce Con su `online_configuration`.
     * @param \App\PaymentMethod $payment_method De tipo Tarjeta Mercado Pago.
     * @return array<int, array{cuotas: int, valor_cuota: float, total: float}> Ordenadas por cuotas.
     */
    public static function opciones(Cart $cart, $commerce, PaymentMethod $payment_method)
    {
        $items = OnlinePaymentHelper::items_del_carrito($cart, $commerce, $payment_method);
        $base  = CuotasMercadoPagoHelper::base_a_cobrar($items);

        // Netos por cantidad de cuotas: «1 pago» sin recargo salvo que haya una fila de 1 cuota.
        $netos = [1 => 0.0];

        foreach (CuotasMercadoPagoHelper::ofertas($payment_method) as $oferta) {
            $netos[(int) $oferta['cantidad_cuotas']] = (float) $oferta['neto'];
        }

        ksort($netos);

        $opciones = [];

        foreach ($netos as $cuotas => $neto) {
            $total = round($base + CuotasMercadoPagoHelper::recargo_en_plata($base, $neto), 2);

            $opciones[] = [
                'cuotas'      => (int) $cuotas,
                'valor_cuota' => CuotasMercadoPagoHelper::valor_de_cuota($total, $cuotas),
                'total'       => $total,
            ];
        }

        return $opciones;
    }

    /**
     * Si el carrito ya tiene un pedido pagado con ESE pago aprobado, la respuesta 201 con ese pedido
     * (doble clic o reintento del navegador: ni se cobra ni se crea otro pedido; crear otro borraria
     * el anterior, porque `OrderHelper::deleteOrderCart()` borra el pedido viejo del carrito).
     *
     * @param \App\Cart $cart
     * @return \Illuminate\Http\JsonResponse|null Null si no hay pedido pagado.
     */
    public static function respuesta_si_ya_tiene_pedido_pagado(Cart $cart)
    {
        if (empty($cart->order_id) || empty($cart->payment_id) || $cart->payment_status !== 'approved') {
            return null;
        }

        $order = Order::find($cart->order_id);

        if (!$order || (int) $order->payment_id !== (int) $cart->payment_id) {
            return null;
        }

        return response()->json(['order_id' => $order->id], 201);
    }

    /**
     * Cobra el carrito con la tarjeta tokenizada por el Brick. Orden de los pasos: NINGUNO que corta
     * llega a cobrar.
     *
     *  a. el metodo sale de la base: tiene que ser de tipo tarjeta y del comercio del carrito (422 `metodo_invalido`);
     *  b. credenciales del comercio (422 `sin_credencial`);
     *  c. las cuotas tienen que estar entre las opciones del servidor (422 `cuotas_invalidas`);
     *  d. `monto_esperado` contra el total de esa opcion (409 `monto_cambio` con las opciones de hoy);
     *  e. carrito ya cobrado sin pedido: se reusa ese pago SIN volver a cobrar;
     *  f. con N > 1 cuotas, Mercado Pago tiene que ofrecerlas sin interes para el comprador (422 `cuotas_con_interes`);
     *  g. `POST /v1/payments`;
     *  h. se interpreta la respuesta (aprobado / rechazado / en proceso / sin respuesta).
     *
     * @param Request $request `payment_method_id`, `cuotas`, `monto_esperado`, `tarjeta{token, payment_method_id, issuer_id, payer}`, `device_id`.
     * @param \App\Cart $cart El carrito, ya con candado.
     * @return array|\Illuminate\Http\JsonResponse El pago aprobado como `['pago' => [...], 'cuotas' => N, 'metodo' => PaymentMethod, 'access_token' => ...]`,
     *         o la respuesta de error lista para devolver (no se crea pedido).
     */
    public static function cobrar(Request $request, Cart $cart)
    {
        // a. El metodo sale de la base y tiene que ser de tarjeta y de ESTE comercio.
        $payment_method = is_numeric($request->input('payment_method_id'))
            ? PaymentMethod::with('type')->find((int) $request->input('payment_method_id'))
            : null;

        if (!self::es_tarjeta($payment_method) || (int) $payment_method->user_id !== (int) $cart->user_id) {
            return self::error('metodo_invalido', 'Ese medio de pago no corresponde a este carrito. Volvé a elegir cómo querés pagar.', 422);
        }

        $token = (string) $request->input('tarjeta.token');

        if ($token === '') {
            return self::error('tarjeta_incompleta', 'Completá los datos de la tarjeta para pagar.', 422);
        }

        // b. Credenciales: nunca las del body.
        $credenciales = MercadoPagoCredentialsHelper::credentials_for_payment_method($cart->user_id, $payment_method);
        $access_token = $credenciales['access_token'];

        if (empty($access_token)) {
            Log::error('TarjetaMercadoPagoHelper: el comercio no tiene credencial de Mercado Pago para cobrar con tarjeta', ['commerce_id' => $cart->user_id, 'cart_id' => $cart->id]);

            return self::error('sin_credencial', 'El comercio no tiene una cuenta de Mercado Pago conectada.', 422);
        }

        $commerce = ContextoDeComercio::comercio($cart->user_id);

        // c. Las cuotas, contra lo que el servidor ofrece.
        try {
            $opciones = self::opciones($cart, $commerce, $payment_method);
        } catch (\Throwable $e) {
            CuotasMercadoPagoHelper::avisar_falla_de_lectura('cobro_con_tarjeta', $payment_method, $e);

            return self::error('cuotas_no_disponibles', 'No pudimos calcular las cuotas en este momento. Probá de nuevo en un rato.', 503);
        }

        $cuotas = CuotasMercadoPagoHelper::entero_de_cuotas($request->input('cuotas'));
        $opcion = null;

        foreach ($opciones as $candidata) {
            if ($candidata['cuotas'] === $cuotas) {
                $opcion = $candidata;
            }
        }

        if (is_null($opcion)) {
            return self::error('cuotas_invalidas', 'Esa cantidad de cuotas no está disponible. Volvé a elegir cómo querés pagar.', 422);
        }

        // d. El monto que vio el comprador tiene que ser el de hoy. Nunca se cobra lo que mande el body.
        $monto_esperado = $request->input('monto_esperado');

        if (!is_numeric($monto_esperado) || abs((float) $monto_esperado - $opcion['total']) > self::TOLERANCIA) {
            return response()->json([
                'codigo'   => 'monto_cambio',
                'message'  => 'El total cambió. Revisalo antes de pagar.',
                'opciones' => $opciones,
            ], 409);
        }

        // e. Pago aprobado en el carrito pero sin pedido (se cobro y la transaccion del pedido fallo): se reusa.
        if ($cart->payment_status === 'approved' && !empty($cart->payment_id)) {
            $recuperado = self::pago_ya_aprobado($cart, $access_token, $opcion['total']);

            if ($recuperado instanceof \Symfony\Component\HttpFoundation\Response) {
                return $recuperado;
            }

            if (is_array($recuperado)) {
                return ['pago' => $recuperado, 'cuotas' => $cuotas, 'metodo' => $payment_method, 'access_token' => $access_token];
            }
        }

        // f. En cuotas, Mercado Pago no le puede sumar SU interes al comprador.
        if ($cuotas > 1) {
            $sin_interes = self::cuotas_sin_interes($request, $access_token, $opcion['total'], $cuotas);

            if ($sin_interes instanceof \Symfony\Component\HttpFoundation\Response) {
                return $sin_interes;
            }
        }

        // g. El cobro.
        $cuerpo = [
            'transaction_amount' => $opcion['total'],
            'token'              => $token,
            'installments'       => $cuotas,
            'payment_method_id'  => (string) $request->input('tarjeta.payment_method_id'),
            'payer'              => ['email' => (string) $request->input('tarjeta.payer.email')],
            'binary_mode'        => true,
            'external_reference' => (string) $cart->id,
            'description'        => 'Compra en '.($commerce ? $commerce->name : 'la tienda'),
            'metadata'           => [
                'cart_id'     => (int) $cart->id,
                'commerce_id' => (int) $cart->user_id,
                'origen'      => 'tarjeta_en_la_tienda',
            ],
        ];

        if (is_numeric($request->input('tarjeta.issuer_id'))) {
            $cuerpo['issuer_id'] = (int) $request->input('tarjeta.issuer_id');
        }

        if ($request->filled('tarjeta.payer.identification.number')) {
            $cuerpo['payer']['identification'] = [
                'type'   => (string) $request->input('tarjeta.payer.identification.type'),
                'number' => (string) $request->input('tarjeta.payer.identification.number'),
            ];
        }

        // El webhook solo en produccion (Mercado Pago exige una URL publica), igual que la preferencia.
        if (app()->environment('production')) {
            $cuerpo['notification_url'] = OnlinePaymentHelper::url_del_webhook($request, $cart->user_id);
        }

        $cabeceras = ['X-Idempotency-Key' => self::clave_de_idempotencia($cart->id, $token)];

        if ($request->filled('device_id') && is_string($request->input('device_id'))) {
            $cabeceras['X-meli-session-id'] = $request->input('device_id');
        }

        try {
            $respuesta = self::http($access_token)->withHeaders($cabeceras)->post(self::API.'/v1/payments', $cuerpo);
        } catch (ConnectionException $e) {
            Log::warning('TarjetaMercadoPagoHelper: Mercado Pago no contesto al cobrar', ['cart_id' => $cart->id]);

            return self::sin_respuesta();
        }

        // h. La respuesta.
        if ($respuesta->serverError()) {
            Log::warning('TarjetaMercadoPagoHelper: Mercado Pago contesto con error de servidor al cobrar', ['cart_id' => $cart->id, 'http' => $respuesta->status()]);

            return self::sin_respuesta();
        }

        $pago = $respuesta->json();
        $pago = is_array($pago) ? $pago : [];

        if ($respuesta->clientError() && !isset($pago['status'])) {
            Log::warning('TarjetaMercadoPagoHelper: Mercado Pago rechazo el pedido de cobro', ['cart_id' => $cart->id, 'http' => $respuesta->status()]);

            return self::error('pago_rechazado', self::RECHAZO_POR_DEFECTO, 422);
        }

        $estado = isset($pago['status']) ? $pago['status'] : null;

        Log::info('TarjetaMercadoPagoHelper: respuesta del cobro', [
            'cart_id'       => $cart->id,
            'payment_id'    => isset($pago['id']) ? $pago['id'] : null,
            'status'        => $estado,
            'status_detail' => isset($pago['status_detail']) ? $pago['status_detail'] : null,
        ]);

        if ($estado === 'approved') {
            // Alarma de la pregunta P1: si Mercado Pago le sumo SU interes, el pago sigue (ya esta cobrado).
            $pagado = isset($pago['transaction_details']['total_paid_amount']) ? (float) $pago['transaction_details']['total_paid_amount'] : null;

            if (!is_null($pagado) && $pagado > $opcion['total'] + self::TOLERANCIA) {
                Log::warning('TarjetaMercadoPagoHelper: Mercado Pago le cobro interes al comprador (activar las cuotas sin interes en la cuenta del comercio)', [
                    'cart_id'    => $cart->id,
                    'payment_id' => isset($pago['id']) ? $pago['id'] : null,
                    'cuotas'     => $cuotas,
                ]);
            }

            return ['pago' => $pago, 'cuotas' => $cuotas, 'metodo' => $payment_method, 'access_token' => $access_token];
        }

        if ($estado === 'rejected') {
            return self::error('pago_rechazado', self::mensaje_de_rechazo(isset($pago['status_detail']) ? $pago['status_detail'] : null), 422);
        }

        // En proceso / pendiente (con binary_mode no deberia pasar): se cancela para que no quede cobrado sin pedido.
        if (!empty($pago['id'])) {
            self::cancelar_pago($pago['id'], $access_token, $cart->id);
        }

        return self::error('pago_no_confirmado', 'No pudimos confirmar el pago al instante y lo cancelamos; no se te cobró. Probá de nuevo o con otra tarjeta.', 422);
    }

    /**
     * Pide la devolucion de un pago aprobado cuando el pedido no se pudo crear. Nunca tira.
     *
     * @param array $cobro Lo que devolvio `cobrar()` (`pago`, `access_token`).
     * @param int $cart_id Solo para el log.
     * @return void
     */
    public static function devolver_pago(array $cobro, $cart_id)
    {
        $payment_id = isset($cobro['pago']['id']) ? $cobro['pago']['id'] : null;

        if (empty($payment_id)) {
            return;
        }

        try {
            $respuesta = self::http($cobro['access_token'])
                ->withHeaders(['X-Idempotency-Key' => 'cc-devolucion-'.$payment_id])
                ->post(self::API.'/v1/payments/'.$payment_id.'/refunds', []);

            Log::error('TarjetaMercadoPagoHelper: se cobro pero el pedido no se pudo crear; se pidio la devolucion', [
                'cart_id'    => $cart_id,
                'payment_id' => $payment_id,
                'devolucion_http' => $respuesta->status(),
            ]);
        } catch (\Throwable $e) {
            Log::error('TarjetaMercadoPagoHelper: se cobro, el pedido no se pudo crear Y la devolucion fallo: devolver a mano', [
                'cart_id'    => $cart_id,
                'payment_id' => $payment_id,
            ]);
        }
    }

    /**
     * Anota en el carrito el pago aprobado, el metodo y las cuotas. Va dentro de la transaccion del
     * pedido: `Order::create` lee de aca `payment_id`, `payment_method_id` y `cuotas`.
     *
     * `carts.cuotas` queda con N si N > 1 y en NULL con 1 pago (como el resto de la tienda), y solo
     * si la base tiene la columna.
     *
     * @param \App\Cart $cart
     * @param array $cobro Lo que devolvio `cobrar()`.
     * @return void
     */
    public static function anotar_en_el_carrito(Cart $cart, array $cobro)
    {
        $cart->payment_id        = $cobro['pago']['id'];
        $cart->payment_status    = 'approved';
        $cart->payment_method_id = $cobro['metodo']->id;

        if (CuotasMercadoPagoHelper::carrito_disponible()) {
            $cart->cuotas = $cobro['cuotas'] > 1 ? $cobro['cuotas'] : null;
        }

        $cart->unsetRelation('payment_method');
        $cart->save();
    }

    /**
     * El cliente HTTP hacia Mercado Pago, con la credencial del comercio y timeouts acotados.
     *
     * @param string $access_token
     * @return \Illuminate\Http\Client\PendingRequest
     */
    private static function http($access_token)
    {
        return Http::withToken($access_token)->acceptJson()->connectTimeout(5)->timeout(25);
    }

    /**
     * Una respuesta de error con `codigo` y `message` para el comprador.
     *
     * @param string $codigo
     * @param string $mensaje
     * @param int $status
     * @return \Illuminate\Http\JsonResponse
     */
    private static function error($codigo, $mensaje, $status)
    {
        return response()->json(['codigo' => $codigo, 'message' => $mensaje], $status);
    }

    /**
     * 503: Mercado Pago no contesto. El reintento con el mismo token usa la misma clave de idempotencia.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private static function sin_respuesta()
    {
        return self::error('pago_sin_respuesta', 'No pudimos confirmar el pago con Mercado Pago. Volvé a tocar Pagar: si ya se había cobrado, no se cobra dos veces.', 503);
    }

    /**
     * El pago que el carrito ya tiene aprobado, si sigue siendo valido para cobrar ESTE total.
     *
     * @param \App\Cart $cart Con `payment_id` y `payment_status = approved`.
     * @param string $access_token
     * @param float $total El total de la opcion elegida.
     * @return array|\Illuminate\Http\JsonResponse|null El pago, un 503 si Mercado Pago no contesta, o null si no sirve (se cobra de nuevo).
     */
    private static function pago_ya_aprobado(Cart $cart, $access_token, $total)
    {
        try {
            $respuesta = self::http($access_token)->get(self::API.'/v1/payments/'.(int) $cart->payment_id);
        } catch (ConnectionException $e) {
            return self::sin_respuesta();
        }

        if (!$respuesta->successful()) {
            return $respuesta->serverError() ? self::sin_respuesta() : null;
        }

        $pago = $respuesta->json();

        if (is_array($pago)
            && isset($pago['status']) && $pago['status'] === 'approved'
            && isset($pago['external_reference']) && (string) $pago['external_reference'] === (string) $cart->id
            && isset($pago['transaction_amount']) && abs((float) $pago['transaction_amount'] - $total) <= self::TOLERANCIA) {
            return $pago;
        }

        return null;
    }

    /**
     * Verifica con Mercado Pago que la tarjeta tenga ESA cantidad de cuotas SIN interes para el comprador.
     *
     * @param Request $request Aporta `tarjeta.payment_method_id` y `tarjeta.issuer_id`.
     * @param string $access_token
     * @param float $total
     * @param int $cuotas
     * @return \Illuminate\Http\JsonResponse|null Null si se puede cobrar en esas cuotas.
     */
    private static function cuotas_sin_interes(Request $request, $access_token, $total, $cuotas)
    {
        $consulta = [
            'amount'            => $total,
            'payment_method_id' => (string) $request->input('tarjeta.payment_method_id'),
        ];

        if (is_numeric($request->input('tarjeta.issuer_id'))) {
            $consulta['issuer.id'] = (int) $request->input('tarjeta.issuer_id');
        }

        try {
            $respuesta = self::http($access_token)->get(self::API.'/v1/payment_methods/installments', $consulta);
        } catch (ConnectionException $e) {
            return self::sin_respuesta();
        }

        if (!$respuesta->successful()) {
            return self::sin_respuesta();
        }

        $medios = $respuesta->json();

        foreach (is_array($medios) ? $medios : [] as $medio) {
            foreach (isset($medio['payer_costs']) && is_array($medio['payer_costs']) ? $medio['payer_costs'] : [] as $costo) {
                if (isset($costo['installments']) && (int) $costo['installments'] === (int) $cuotas
                    && isset($costo['installment_rate']) && (float) $costo['installment_rate'] == 0.0) {
                    return null;
                }
            }
        }

        return self::error(
            'cuotas_con_interes',
            'Esta tarjeta no tiene '.$cuotas.' cuotas sin interés en este comercio. Elegí 1 pago u otra cantidad de cuotas.',
            422
        );
    }

    /**
     * Cancela un pago que quedo en proceso. Nunca tira: si falla queda en el log para revisar a mano.
     *
     * @param int|string $payment_id
     * @param string $access_token
     * @param int $cart_id Solo para el log.
     * @return void
     */
    private static function cancelar_pago($payment_id, $access_token, $cart_id)
    {
        try {
            $respuesta = self::http($access_token)->put(self::API.'/v1/payments/'.$payment_id, ['status' => 'cancelled']);

            if (!$respuesta->successful()) {
                Log::error('TarjetaMercadoPagoHelper: no se pudo cancelar un pago en proceso', ['cart_id' => $cart_id, 'payment_id' => $payment_id, 'http' => $respuesta->status()]);
            }
        } catch (\Throwable $e) {
            Log::error('TarjetaMercadoPagoHelper: no se pudo cancelar un pago en proceso', ['cart_id' => $cart_id, 'payment_id' => $payment_id]);
        }
    }
}
