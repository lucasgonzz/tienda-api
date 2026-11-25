<?php

namespace Tests\Feature\TarjetaMercadoPago;

use App\Article;
use App\Buyer;
use App\Cart;
use App\DeliveryZone;
use App\Order;
use App\PaymentMethod;
use App\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\CuotasMercadoPago\ArmaCuotasMercadoPago;

/**
 * Fixtures de los tests de la mision `tienda-cobro-tarjeta` (24/11/2026): el cobro con tarjeta
 * ADENTRO de la tienda con Mercado Pago (Card Payment Brick + `POST /v1/payments`).
 *
 * Reusa las de `tienda-cuotas-mercado-pago` (`ArmaCuotasMercadoPago`: metodo de cuenta corriente,
 * filas de `cuotas`, tipos de pago) y le suma el escenario E1 del PLAN.
 *
 * ── El escenario E1 ───────────────────────────────────────────────────────────────────────────
 *
 *  - Comercio C con `online_price_surchage = 5`.
 *  - Articulo A, 1 unidad en el carrito, `pivot.price` 100.000 (contado). Envio por zona propia de
 *    10.000 (`deliver = 1`).
 *  - Metodo T de tipo `Tarjeta Mercado Pago` (sin discount ni surchage), vinculado al metodo de
 *    cuenta corriente «Tarjeta», con cuotas 3 -> recargo 10 y 6 -> recargo 20 (descuento 0).
 *  - Metodo MP de tipo `MercadoPago` con `access_token = TEST-AT-C` y `public_key = TEST-PK-C`
 *    (valores FALSOS de test; sin vinculo de cuotas). Es de donde sale la credencial con la que se
 *    cobra: el metodo T no guarda credenciales propias.
 *  - Metodos «Transferencia» y «Efectivo» con `discount = 10`.
 *
 * ── Lo que NO hace ────────────────────────────────────────────────────────────────────────────
 *
 *  - NO crea la columna ni el esquema (DDL rompe `DatabaseTransactions`): la base de testing ya
 *    tiene el de `empresa-api`.
 *  - NO asume que la fila `payment_method_types` de `Tarjeta Mercado Pago` exista: la base de
 *    testing no la tiene (la crea el seeder de empresa-api en produccion), asi que `tipoDePago()` la
 *    crea aca y el rollback la deshace.
 *  - NUNCA sale a Mercado Pago: `falsearMercadoPago()` atiende TODA llamada a `api.mercadopago.com`
 *    con un guion; lo que no esta en el guion lo frena `HttpFactorySinSalida` y hace fallar al test.
 */
trait ArmaCobroConTarjeta
{
    use ArmaCuotasMercadoPago;

    /** Nombre exacto del tipo de metodo de pago nuevo (contrato con empresa-api y tienda-spa). */
    protected static $TIPO_TARJETA = 'Tarjeta Mercado Pago';

    /** Credenciales FALSAS del comercio del escenario. */
    protected static $ACCESS_TOKEN_DE_PRUEBA = 'TEST-AT-C';
    protected static $PUBLIC_KEY_DE_PRUEBA   = 'TEST-PK-C';

    /** @var \App\PaymentMethod T: el metodo de tipo Tarjeta Mercado Pago. */
    protected $metodo_tarjeta;

    /** @var \App\PaymentMethod MP: el metodo de tipo MercadoPago (Checkout Pro) que guarda la credencial. */
    protected $metodo_mp;

    /** @var \App\PaymentMethod «Transferencia», discount 10. */
    protected $metodo_transferencia;

    /** @var \App\PaymentMethod «Efectivo», discount 10. */
    protected $metodo_efectivo;

    /** @var \App\DeliveryZone La zona propia de 10.000. */
    protected $zona_propia;

    /** @var \App\Buyer El comprador de la sesion (identidad de checkout). */
    protected $comprador;

    /** @var array<int, array> Todo pedido HTTP a Mercado Pago que atendio el guion (metodo, url, headers, body). */
    protected $llamadas_a_mp = [];

    /** @var array<int, array> Cola de respuestas de `POST /v1/payments` (la ultima se repite). */
    protected $cola_de_pagos = [];

    /** @var array<int, array> Cola de respuestas de `GET /v1/payments/search` (la ultima se repite). */
    protected $cola_de_busquedas = [];

    /** @var array<int, string> Todo lo que se logueo desde `capturarLogs()` (nivel, mensaje y contexto). */
    protected $logs_capturados = [];

    /*
    |---------------------------------------------------------------------------------------------
    | El escenario
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Arma el escenario E1 completo (ver el docblock del trait).
     *
     * @return void
     */
    protected function armarCobroConTarjeta()
    {
        $this->olvidarCuotas();

        $this->assertTrue(
            \App\Http\Controllers\Helpers\CuotasMercadoPagoHelper::vinculo_disponible()
            && \App\Http\Controllers\Helpers\CuotasMercadoPagoHelper::carrito_disponible()
            && \App\Http\Controllers\Helpers\CuotasMercadoPagoHelper::pedido_disponible()
            && \App\Http\Controllers\Helpers\CuotasMercadoPagoHelper::cobro_disponible(),
            'La base de testing no tiene las columnas de cuotas de empresa-api (migracion 2026_10_15_100000).'
        );

        $this->comercio = $this->comercioConTienda();
        $this->comercio->online_configuration()->update([
            'online_price_surchage' => 5,
            'register_to_buy'       => false,
            'pausar_tienda_online'  => false,
            'stock_null_equal_0'    => false,
        ]);
        $this->comercio->unsetRelation('online_configuration');

        $this->articulo = $this->articuloPublicado($this->comercio, ['final_price' => 100000.0, 'name' => 'Articulo A']);

        // El metodo de cuenta corriente «Tarjeta» y sus cuotas (3 -> 10 %, 6 -> 20 %).
        $this->metodo_cc_id = $this->metodoDeCuentaCorriente('Tarjeta (test tarjeta mp)');
        $this->cuotasDelMetodo([[3, 10], [6, 20]]);

        $this->metodo_tarjeta = $this->metodoDeTarjeta($this->comercio, $this->metodo_cc_id);
        $this->metodo_mp      = $this->metodoMercadoPagoConCredencial($this->comercio);

        // `armarCuotas()` no se usa: el metodo MP del escenario NO esta vinculado a cuotas.
        $this->payment_method = $this->metodo_mp;

        $this->metodo_transferencia = $this->metodoConDescuento($this->comercio, 'Transferencia', 'Transferencia', 10);
        $this->metodo_efectivo      = $this->metodoConDescuento($this->comercio, 'Efectivo', 'Efectivo', 10);

        // DeliveryZone no declara $fillable/$guarded: propiedad por propiedad.
        $zona = new DeliveryZone();
        $zona->name    = 'Zona propia (test tarjeta mp)';
        $zona->price   = 10000;
        $zona->user_id = $this->comercio->id;
        $zona->save();
        $this->zona_propia = $zona;

        $this->comprador = Buyer::create([
            'name'    => 'Comprador de prueba tarjeta',
            'email'   => 'tarjeta-'.Str::random(8).'@test.local',
            'user_id' => $this->comercio->id,
        ]);

        if (!DB::table('order_statuses')->where('name', 'Sin confirmar')->exists()) {
            DB::table('order_statuses')->insert(['name' => 'Sin confirmar', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->llamadas_a_mp   = [];
        $this->cola_de_pagos   = [];
        $this->logs_capturados = [];
    }

    /**
     * Un metodo de pago de la tienda de tipo `Tarjeta Mercado Pago`, vinculado a cuotas.
     *
     * @param \App\User $comercio
     * @param int|null $vinculo `payment_methods.current_acount_payment_method_id`.
     * @return \App\PaymentMethod
     */
    protected function metodoDeTarjeta(User $comercio, $vinculo = null)
    {
        $payment_method = new PaymentMethod;
        $payment_method->name                             = 'Tarjeta de crédito o débito';
        $payment_method->user_id                          = $comercio->id;
        $payment_method->payment_method_type_id           = $this->tipoDePago(self::$TIPO_TARJETA)->id;
        $payment_method->current_acount_payment_method_id = $vinculo;
        $payment_method->save();

        return $payment_method;
    }

    /**
     * El metodo de tipo MercadoPago del escenario, con la credencial FALSA de C y sin vinculo.
     *
     * @param \App\User $comercio
     * @return \App\PaymentMethod
     */
    protected function metodoMercadoPagoConCredencial(User $comercio)
    {
        $payment_method = $this->metodoMercadoPago($comercio, null);
        $payment_method->access_token = self::$ACCESS_TOKEN_DE_PRUEBA;
        $payment_method->public_key   = self::$PUBLIC_KEY_DE_PRUEBA;
        $payment_method->save();

        return $payment_method;
    }

    /**
     * Un metodo de pago de la tienda con descuento (Transferencia, Efectivo).
     *
     * @param \App\User $comercio
     * @param string $nombre
     * @param string $tipo Nombre del tipo.
     * @param float $descuento Porcentaje.
     * @return \App\PaymentMethod
     */
    protected function metodoConDescuento(User $comercio, $nombre, $tipo, $descuento)
    {
        $payment_method = new PaymentMethod;
        $payment_method->name                   = $nombre;
        $payment_method->user_id                = $comercio->id;
        $payment_method->payment_method_type_id = $this->tipoDePago($tipo)->id;
        $payment_method->discount               = $descuento;
        $payment_method->save();

        return $payment_method;
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Carrito y sesion
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Crea el carrito del escenario por el endpoint publico: 1 unidad de A, el metodo elegido y,
     * si `$con_envio`, la zona propia de 10.000 con `deliver = 1`.
     *
     * Chequea que el precio guardado sea el de lista sin recargo (`carts.total` = 100.000): si no,
     * la fixture esta mal y los montos de los tests no significan nada.
     *
     * @param bool $con_envio
     * @param \App\PaymentMethod|null $metodo El de tarjeta si no se pasa.
     * @param array $extra Campos del carrito.
     * @return \App\Cart
     */
    protected function carritoDeTarjeta($con_envio = true, ?PaymentMethod $metodo = null, array $extra = [])
    {
        $metodo = $metodo ?: $this->metodo_tarjeta;

        $campos = ['payment_method_id' => $metodo->id];

        if ($con_envio) {
            $campos['deliver']          = 1;
            $campos['delivery_zone_id'] = $this->zona_propia->id;
        }

        $cart = $this->carritoDelArticulo($this->articulo, 1, array_merge($campos, $extra));

        $this->assertEquals(100000.0, (float) $cart->total, 'Fixture: el carrito guarda el precio de lista, sin el recargo de la tienda.');

        return $cart;
    }

    /**
     * Crea un carrito por `POST /api/carts` con una linea de `$articulo`.
     *
     * @param \App\Article $articulo
     * @param int $cantidad
     * @param array $extra Campos del carrito.
     * @return \App\Cart
     */
    protected function carritoDelArticulo(Article $articulo, $cantidad = 1, array $extra = [])
    {
        $respuesta = $this->crearCarrito($this->comercio, array_merge([
            'articles' => [$this->lineaDelPayload($articulo, $cantidad)],
        ], $extra));

        $respuesta->assertStatus(201);

        return Cart::findOrFail((int) $respuesta->json('cart.id'));
    }

    /**
     * La sesion del comprador: el carrito es suyo y esta identificado para el checkout.
     *
     * @param \App\Cart $cart
     * @return $this
     */
    protected function sesionDelComprador(Cart $cart)
    {
        return $this->withSession([
            'carritos_propios'  => [$cart->id],
            'checkout_buyer_id' => $this->comprador->id,
        ]);
    }

    /**
     * La cantidad de pedidos del comercio del escenario.
     *
     * @return int
     */
    protected function pedidosDelComercio()
    {
        return Order::where('user_id', $this->comercio->id)->count();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Los pedidos de la tienda
    |---------------------------------------------------------------------------------------------
    */

    /**
     * `POST /api/mercado-pago/tarjeta/montos`, con el carrito en la sesion.
     *
     * @param \App\Cart $cart
     * @param \App\PaymentMethod|null $metodo El de tarjeta si no se pasa.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedirMontos(Cart $cart, ?PaymentMethod $metodo = null)
    {
        $metodo = $metodo ?: $this->metodo_tarjeta;

        return $this->sesionDelComprador($cart)->postJson('/api/mercado-pago/tarjeta/montos', [
            'cart_id'           => $cart->id,
            'payment_method_id' => $metodo->id,
        ]);
    }

    /**
     * El cuerpo de `POST /api/mercado-pago/tarjeta/pagar` tal como lo manda la SPA: el mismo de
     * `/orders` mas el metodo, las cuotas, el monto que vio el comprador y el token del Brick.
     * Por defecto: 3 cuotas de E1 (126.500) con el token `tok_1`.
     *
     * @param \App\Cart $cart
     * @param array $extra Pisa (en profundidad) lo que se pase.
     * @return array
     */
    protected function cuerpoDePago(Cart $cart, array $extra = [])
    {
        return array_replace_recursive([
            'cart_id'           => $cart->id,
            'commerce_id'       => $this->comercio->id,
            'address'           => 'San Martin 100',
            'payment_method_id' => $this->metodo_tarjeta->id,
            'cuotas'            => 3,
            'monto_esperado'    => 126500,
            'tarjeta'           => [
                'token'             => 'tok_1',
                'payment_method_id' => 'visa',
                'issuer_id'         => 310,
                'payer'             => [
                    'email'          => 'comprador@test.local',
                    'identification' => ['type' => 'DNI', 'number' => '30123456'],
                ],
            ],
        ], $extra);
    }

    /**
     * `POST /api/mercado-pago/tarjeta/pagar` con la sesion del comprador.
     *
     * @param \App\Cart $cart
     * @param array $extra Ver `cuerpoDePago()`.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pagarConTarjeta(Cart $cart, array $extra = [])
    {
        return $this->sesionDelComprador($cart)->postJson('/api/mercado-pago/tarjeta/pagar', $this->cuerpoDePago($cart, $extra));
    }

    /**
     * `POST /api/orders` del carrito, con la sesion del comprador (el camino de siempre).
     *
     * @param \App\Cart $cart
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedirPedidoComun(Cart $cart)
    {
        return $this->sesionDelComprador($cart)->postJson('/api/orders', [
            'cart_id'     => $cart->id,
            'commerce_id' => $this->comercio->id,
            'address'     => 'San Martin 100',
        ]);
    }

    /**
     * Las opciones de E1 con envio: 1 pago, 3 y 6 cuotas.
     * Cuentas: precio web 105.000 + envio 10.000 = 115.000. 3 cuotas: +11.500 = 126.500 / 3. 6 cuotas: +23.000 = 138.000 / 6.
     *
     * @return array<int, array{cuotas: int, valor_cuota: float, total: float}>
     */
    protected function opcionesDeE1ConEnvio()
    {
        return [
            ['cuotas' => 1, 'valor_cuota' => 115000.00, 'total' => 115000.00],
            ['cuotas' => 3, 'valor_cuota' => 42166.67,  'total' => 126500.00],
            ['cuotas' => 6, 'valor_cuota' => 23000.00,  'total' => 138000.00],
        ];
    }

    /**
     * Compara dos listas de opciones numero a numero (tolerancia de medio centavo).
     *
     * @param array $esperadas
     * @param array|null $reales
     * @return void
     */
    protected function assertOpciones(array $esperadas, $reales)
    {
        $this->assertIsArray($reales, 'La respuesta tiene que traer `opciones`.');
        $this->assertCount(count($esperadas), $reales);

        foreach ($esperadas as $indice => $esperada) {
            $this->assertEquals($esperada['cuotas'], $reales[$indice]['cuotas'], 'Cuotas de la opcion '.$indice);
            $this->assertEqualsWithDelta($esperada['valor_cuota'], $reales[$indice]['valor_cuota'], 0.005, 'valor_cuota de la opcion '.$indice);
            $this->assertEqualsWithDelta($esperada['total'], $reales[$indice]['total'], 0.005, 'total de la opcion '.$indice);
        }
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Mercado Pago falseado
    |---------------------------------------------------------------------------------------------
    */

    /**
     * El `GET /v1/payment_methods/installments` con la forma REAL de la API: una lista de medios y,
     * dentro, `payer_costs` con `installments` e `installment_rate` (0 = sin interes para el comprador).
     *
     * @param array<int, float> $tasas Mapa cantidad de cuotas => `installment_rate`. Default: 1, 3 y 6 sin interes.
     * @return array
     */
    protected function respuestaDeInstallments(array $tasas = [1 => 0, 3 => 0, 6 => 0])
    {
        $costos = [];

        foreach ($tasas as $cuotas => $tasa) {
            $costos[] = ['installments' => (int) $cuotas, 'installment_rate' => $tasa, 'recommended_message' => $cuotas.' cuotas'];
        }

        return [[
            'payment_method_id' => 'visa',
            'payment_type_id'   => 'credit_card',
            'issuer'            => ['id' => '310', 'name' => 'Banco de prueba'],
            'payer_costs'       => $costos,
        ]];
    }

    /**
     * Un pago como lo devuelve `POST /v1/payments` / `GET /v1/payments/{id}`.
     *
     * @param \App\Cart $cart
     * @param array $campos Pisa los valores por defecto (aprobado, 3 cuotas, tarjeta de credito, 126.500).
     * @return array
     */
    protected function pagoDeMp(Cart $cart, array $campos = [])
    {
        $monto = isset($campos['transaction_amount']) ? $campos['transaction_amount'] : 126500;

        return array_merge([
            'id'                  => 9001,
            'status'              => 'approved',
            'status_detail'       => 'accredited',
            'transaction_amount'  => $monto,
            'installments'        => 3,
            'payment_type_id'     => 'credit_card',
            'payment_method_id'   => 'visa',
            'external_reference'  => (string) $cart->id,
            // La marca propia del carrito: la que escribe nuestro cobro (y tambien la preferencia de Checkout Pro).
            'metadata'            => ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id, 'origen' => 'tarjeta_en_la_tienda'],
            'transaction_details' => ['total_paid_amount' => $monto],
        ], $campos);
    }

    /**
     * Respuesta de guion: un cuerpo con su status.
     *
     * @param array $cuerpo
     * @param int $status
     * @return array
     */
    protected function respuestaDeMp(array $cuerpo, $status = 200)
    {
        return ['status' => $status, 'body' => $cuerpo];
    }

    /**
     * Respuesta de guion: Mercado Pago no contesta (se corta la conexion).
     *
     * @return array
     */
    protected function sinRespuestaDeMp()
    {
        return ['conexion_cortada' => true];
    }

    /**
     * Respuesta de guion: un fallo de transporte SIN respuesta que NO es `ConnectException` (por
     * ejemplo cURL 56, «Failure with receiving network data»): Guzzle lo tira como `RequestException`.
     *
     * @return array
     */
    protected function transporteCortadoDeMp()
    {
        return ['request_exception' => true];
    }

    /**
     * La respuesta de `GET /v1/payments/search` con esos pagos.
     *
     * @param array<int, array> $pagos Pagos tal como los devuelve la API.
     * @return array Respuesta de guion.
     */
    protected function busquedaDeMp(array $pagos = [])
    {
        return $this->respuestaDeMp(['results' => $pagos, 'paging' => ['total' => count($pagos), 'limit' => 30, 'offset' => 0]]);
    }

    /**
     * Instala el guion de Mercado Pago. TODA llamada a `api.mercadopago.com` pasa por aca y queda en
     * `$this->llamadas_a_mp` (metodo, url, headers, body decodificado y crudo) ANTES de contestar, de
     * modo que tambien se ven las que terminan en conexion cortada.
     *
     * Rutas atendidas:
     *  - `POST /v1/payments`                       -> cola `pagos` (la ultima respuesta se repite).
     *  - `GET  /v1/payment_methods/installments`   -> `installments`.
     *  - `GET  /v1/payments/{id}`                  -> `consulta`.
     *  - `GET  /v1/payments/search`                -> cola `busquedas` (la ultima se repite; por defecto sin resultados).
     *  - `PUT  /v1/payments/{id}`                  -> `cancelacion` (el cancelar del pago en proceso).
     *  - `POST /v1/payments/{id}/refunds`          -> `devolucion`.
     * Cualquier otra se frena (y hace fallar al test).
     *
     * @param array $guion Claves `pagos` (array de respuestas), `installments`, `consulta`, `cancelacion`, `devolucion`.
     * @return void
     */
    protected function falsearMercadoPago(array $guion = [])
    {
        $guion = array_merge([
            'pagos'        => [],
            'busquedas'    => [$this->busquedaDeMp([])],
            'installments' => $this->respuestaDeMp($this->respuestaDeInstallments()),
            // null = «automatica»: `GET /v1/payments/{id}` devuelve el pago con ese id que haya en las busquedas o
            // en la cola de cobros; si no hay, 404.
            'consulta'     => null,
            'cancelacion'  => $this->respuestaDeMp(['id' => 9001, 'status' => 'cancelled']),
            'devolucion'   => $this->respuestaDeMp(['id' => 1, 'status' => 'approved']),
        ], $guion);

        // Registro de los pagos conocidos por id, para la consulta automatica.
        $conocidos = [];

        foreach ($guion['pagos'] as $respuesta_de_pago) {
            if (isset($respuesta_de_pago['body']['id'])) {
                $conocidos[(string) $respuesta_de_pago['body']['id']] = $respuesta_de_pago['body'];
            }
        }

        foreach ($guion['busquedas'] as $respuesta_de_busqueda) {
            foreach (isset($respuesta_de_busqueda['body']['results']) ? $respuesta_de_busqueda['body']['results'] : [] as $pago_buscado) {
                $conocidos[(string) $pago_buscado['id']] = $pago_buscado;
            }
        }

        $this->cola_de_pagos     = $guion['pagos'];
        $this->cola_de_busquedas = $guion['busquedas'];

        Http::fake(function ($request) use ($guion, $conocidos) {
            $url    = $request->url();
            $metodo = strtoupper($request->method());

            if (strpos($url, 'api.mercadopago.com') === false) {
                return null;
            }

            $this->llamadas_a_mp[] = [
                'metodo'  => $metodo,
                'url'     => $url,
                'headers' => $request->headers(),
                'body'    => $request->data(),
                'crudo'   => $request->body(),
            ];

            $camino = parse_url($url, PHP_URL_PATH);

            if ($metodo === 'POST' && preg_match('#/v1/payments$#', $camino)) {
                $respuesta = count($this->cola_de_pagos) > 1 ? array_shift($this->cola_de_pagos) : (isset($this->cola_de_pagos[0]) ? $this->cola_de_pagos[0] : null);
            } elseif ($metodo === 'GET' && preg_match('#/v1/payments/search$#', $camino)) {
                $respuesta = count($this->cola_de_busquedas) > 1 ? array_shift($this->cola_de_busquedas) : (isset($this->cola_de_busquedas[0]) ? $this->cola_de_busquedas[0] : null);
            } elseif ($metodo === 'GET' && preg_match('#/v1/payment_methods/installments$#', $camino)) {
                $respuesta = $guion['installments'];
            } elseif ($metodo === 'GET' && preg_match('#/v1/payments/\d+$#', $camino)) {
                if (!is_null($guion['consulta'])) {
                    $respuesta = $guion['consulta'];
                } else {
                    $id_consultado = substr($camino, strrpos($camino, '/') + 1);
                    $respuesta = isset($conocidos[$id_consultado]) ? $this->respuestaDeMp($conocidos[$id_consultado]) : $this->respuestaDeMp([], 404);
                }
            } elseif ($metodo === 'PUT' && preg_match('#/v1/payments/\d+$#', $camino)) {
                $respuesta = $guion['cancelacion'];
            } elseif ($metodo === 'POST' && preg_match('#/v1/payments/\d+/refunds$#', $camino)) {
                $respuesta = $guion['devolucion'];
            } else {
                $respuesta = null;
            }

            if (is_null($respuesta)) {
                return null;
            }

            if (!empty($respuesta['conexion_cortada'])) {
                throw new ConnectionException('cURL error 28: Operation timed out (falso de test)');
            }

            if (!empty($respuesta['request_exception'])) {
                throw new \GuzzleHttp\Exception\RequestException('cURL error 56: Failure with receiving network data (falso de test)', new \GuzzleHttp\Psr7\Request($metodo, $url));
            }

            return Http::response($respuesta['body'], $respuesta['status']);
        });
    }

    /**
     * Las llamadas que recibio Mercado Pago, filtradas.
     *
     * @param string $metodo `GET`, `POST`, `PUT`.
     * @param string $regex Expresion regular sobre el camino de la URL (sin delimitadores), por ejemplo `/v1/payments$`.
     * @return array<int, array>
     */
    protected function llamadasA($metodo, $regex)
    {
        return array_values(array_filter($this->llamadas_a_mp, function ($llamada) use ($metodo, $regex) {
            return $llamada['metodo'] === strtoupper($metodo)
                && preg_match('#'.$regex.'#', parse_url($llamada['url'], PHP_URL_PATH)) === 1;
        }));
    }

    /**
     * Los cobros (`POST /v1/payments`) que se intentaron.
     *
     * @return array<int, array>
     */
    protected function cobrosAMp()
    {
        return $this->llamadasA('POST', '/v1/payments$');
    }

    /**
     * La clave de idempotencia que se mando en un cobro (header `X-Idempotency-Key`).
     *
     * @param array $llamada Un elemento de `cobrosAMp()`.
     * @return string|null
     */
    protected function claveDeIdempotencia(array $llamada)
    {
        foreach ($llamada['headers'] as $nombre => $valores) {
            if (strtolower($nombre) === 'x-idempotency-key') {
                return is_array($valores) ? $valores[0] : $valores;
            }
        }

        return null;
    }

    /**
     * La clave de idempotencia que tiene que mandar el servidor para un carrito y un token.
     *
     * @param int $cart_id
     * @param string $token
     * @return string
     */
    protected function claveEsperada($cart_id, $token)
    {
        return 'cc-tarjeta-'.$cart_id.'-'.substr(hash('sha256', $token), 0, 40);
    }

    /**
     * Anota todo lo que se loguea de aca en adelante (nivel, mensaje y contexto) en `$this->logs_capturados`.
     *
     * @return void
     */
    protected function capturarLogs()
    {
        $this->logs_capturados = [];

        app('events')->listen(MessageLogged::class, function (MessageLogged $evento) {
            $this->logs_capturados[] = $evento->level.' '.$evento->message.' '.json_encode($evento->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });
    }

    /**
     * True si algun array (en cualquier profundidad) tiene esa clave.
     *
     * @param array $datos
     * @param string $clave
     * @return bool
     */
    protected function tieneLaClave(array $datos, $clave)
    {
        foreach ($datos as $k => $valor) {
            if ($k === $clave) {
                return true;
            }

            if (is_array($valor) && $this->tieneLaClave($valor, $clave)) {
                return true;
            }
        }

        return false;
    }
}
