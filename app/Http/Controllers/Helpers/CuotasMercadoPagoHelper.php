<?php

namespace App\Http\Controllers\Helpers;

use App\Cart;
use App\Cuota;
use App\PaymentMethod;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Las cuotas de Mercado Pago de la tienda (mision `tienda-cuotas-mercado-pago`, 15/10/2026).
 *
 * ── Que hace la feature ───────────────────────────────────────────────────────────────────────
 *
 * El comerciante carga en el ERP las cuotas que ofrece y el recargo de cada cantidad (tabla
 * `cuotas`, por metodo de pago de CUENTA CORRIENTE) y vincula el metodo de pago de la tienda de
 * tipo `MercadoPago` con ese metodo (`payment_methods.current_acount_payment_method_id`). Con el
 * vinculo puesto, el comprador elige en el checkout «contado» o «N cuotas», y `POST
 * mercado-pago/preference` arma la preferencia en consecuencia. Sin vinculo, o sin cuotas cargadas
 * para ese metodo, TODO sale exactamente como antes de esta mision.
 *
 * ── UNA sola fuente del calculo ───────────────────────────────────────────────────────────────
 *
 * Lo usan la lista de metodos (`PaymentMethodController@index`), la preferencia, el carrito, el
 * pedido y el webhook. El calculo no se repite en ningun otro lado: si dos de esos caminos
 * resolvieran el recargo por su cuenta, el comprador veria una cuota, la preferencia cobraria otra
 * y el pedido guardaria una tercera.
 *
 *     neto           = (recargo ?? 0) - (descuento ?? 0)        por fila de `cuotas`; se ofrece si es >= 0
 *     base           = suma de final_price * amount de los items que arma articulos_a_cobrar()
 *     recargo_monto  = round(base * neto / 100, 2)
 *
 * 🔴 EL RECARGO NUNCA SALE DEL BODY. Se resuelve siempre de la base, a partir de la cantidad de
 * cuotas que pide el comprador y del metodo de pago. Mismo criterio que el precio (mision
 * `mp-precio-servidor-y-credenciales-env`): un porcentaje que viaja en el request es un
 * porcentaje que quien arma el request elige.
 *
 * ── Las guardas de esquema (obligatorias) ─────────────────────────────────────────────────────
 *
 * Las columnas las crea una migracion de `empresa-api` y `tienda` se despliega por separado, por
 * cliente, cuando Lucas quiere. Un deploy sube los archivos ANTES de migrar, asi que hay una
 * ventana en la que el codigo nuevo corre contra una base sin las columnas. Leer un atributo que
 * no existe es inofensivo (Eloquent devuelve null); lo que revienta es ESCRIBIRLO
 * (`UPDATE carts SET cuotas = ...` → "Unknown column") y consultar columnas ausentes, y los dos
 * caminos estan en el medio del checkout. Por eso son cuatro guardas, una por grupo de columnas
 * que se escribe junto, con el mismo molde que `ComboEsquemaHelper` y `ZipnovaEsquemaHelper`:
 *
 *  - `vinculo_disponible()`: `payment_methods.current_acount_payment_method_id` (y las columnas de
 *    `cuotas` que se consultan). Sin ella no hay cuotas: la feature entera esta apagada.
 *  - `carrito_disponible()`: `carts.cuotas`.
 *  - `pedido_disponible()`: `orders.cuotas` y `orders.cuotas_recargo`.
 *  - `cobro_disponible()`: `orders.cuotas_cobradas` y `orders.medio_de_pago_cobrado`.
 *
 * Granulares a proposito: un esquema a medio aplicar apaga solo lo que no esta, no todo.
 *
 * ── Memoria ───────────────────────────────────────────────────────────────────────────────────
 *
 * Cada guarda se mide una sola vez por proceso (bajo PHP-FPM, una vez por request: una query de
 * information_schema por columna). A diferencia de `ZipnovaEsquemaHelper`, tampoco se vuelve a
 * medir en consola: un test que quiere simular "esta base no tiene la columna" lo hace con
 * `forzar_esquema()` (nada de DDL adentro de un test: MySQL le hace commit implicito a la
 * transaccion y el rollback del trait no revertiria nada) y limpia con `olvidar()`.
 */
class CuotasMercadoPagoHelper
{
    /** Nombre del tipo de metodo de pago de la tienda que cobra con Mercado Pago. */
    const TIPO_MERCADO_PAGO = 'MercadoPago';

    /** Las cuatro guardas (claves de `forzar_esquema()`). */
    const GUARDA_VINCULO = 'vinculo';
    const GUARDA_CARRITO = 'carrito';
    const GUARDA_PEDIDO  = 'pedido';
    const GUARDA_COBRO   = 'cobro';

    /** Modos de pago que el comprador puede pedir al armar la preferencia. */
    const MODO_CONTADO = 'contado';
    const MODO_CUOTAS  = 'cuotas';

    /**
     * Tipos de medio que Mercado Pago NO tiene que ofrecer cuando el comprador eligio «N cuotas».
     *
     * Las cuotas son de tarjeta de credito: se excluye todo lo demas que Mercado Pago deja
     * excluir. `account_money` (dinero en cuenta) NO se puede excluir —lo dice la documentacion de
     * Mercado Pago—, asi que el comprador igual podria pagar con eso (ver `restricciones_de_medios()`).
     */
    const TIPOS_EXCLUIDOS_EN_CUOTAS = ['debit_card', 'prepaid_card', 'ticket', 'atm', 'bank_transfer'];

    /** El tipo de medio que se excluye en «contado». */
    const TIPO_TARJETA_DE_CREDITO = 'credit_card';

    /**
     * Las columnas que pide cada guarda: pares [tabla, columna].
     *
     * `vinculo` mira ademas `cuotas.payment_method_id` (la agrego una migracion de empresa-api de
     * julio de 2026): la consulta de `cuotas_disponibles()` filtra por ella, y una base sin esa
     * columna daria "Unknown column" en la ruta publica de metodos de pago.
     */
    const ESQUEMA = [
        self::GUARDA_VINCULO => [
            ['payment_methods', 'current_acount_payment_method_id'],
            ['cuotas', 'payment_method_id'],
        ],
        self::GUARDA_CARRITO => [
            ['carts', 'cuotas'],
        ],
        self::GUARDA_PEDIDO => [
            ['orders', 'cuotas'],
            ['orders', 'cuotas_recargo'],
        ],
        self::GUARDA_COBRO => [
            ['orders', 'cuotas_cobradas'],
            ['orders', 'medio_de_pago_cobrado'],
        ],
    ];

    /** @var array<string, bool> Memo de las guardas, por proceso. */
    private static $memo = [];

    /*
    |---------------------------------------------------------------------------------------------
    | Guardas de esquema
    |---------------------------------------------------------------------------------------------
    */

    /**
     * True si esta base ya tiene el vinculo `payment_methods.current_acount_payment_method_id`.
     * Sin el, la tienda no ofrece cuotas.
     *
     * @return bool
     */
    public static function vinculo_disponible()
    {
        return self::guarda(self::GUARDA_VINCULO);
    }

    /**
     * True si `carts` tiene la columna `cuotas`: se puede guardar la eleccion del comprador.
     *
     * @return bool
     */
    public static function carrito_disponible()
    {
        return self::guarda(self::GUARDA_CARRITO);
    }

    /**
     * True si `orders` tiene `cuotas` y `cuotas_recargo`: se pueden copiar al crear el pedido.
     * Se piden las dos porque se escriben en el mismo `Order::create`.
     *
     * @return bool
     */
    public static function pedido_disponible()
    {
        return self::guarda(self::GUARDA_PEDIDO);
    }

    /**
     * True si `orders` tiene `cuotas_cobradas` y `medio_de_pago_cobrado`: el webhook puede anotar
     * lo que Mercado Pago cobro de verdad.
     *
     * @return bool
     */
    public static function cobro_disponible()
    {
        return self::guarda(self::GUARDA_COBRO);
    }

    /**
     * Descarta lo medido y lo forzado de este proceso. Para tests: llamarlo en `setUp()` y en
     * `tearDown()`.
     *
     * @return void
     */
    public static function olvidar()
    {
        self::$memo = [];
    }

    /**
     * Fuerza el resultado de una o varias guardas, sin tocar el esquema. SOLO PARA TESTS: es la
     * forma de probar "esta base todavia no tiene la columna" sin hacer DDL.
     *
     * @param array<string, bool> $guardas Por ejemplo `['carrito' => false]`; las claves son las GUARDA_*.
     * @return void
     */
    public static function forzar_esquema(array $guardas)
    {
        foreach ($guardas as $guarda => $disponible) {
            self::$memo[$guarda] = (bool) $disponible;
        }
    }

    /**
     * El valor de una guarda, midiendolo la primera vez. Ante la duda (la base no contesta el
     * information_schema), false: la tienda de siempre, sin cuotas.
     *
     * @param string $guarda Una de las GUARDA_*.
     * @return bool
     */
    private static function guarda($guarda)
    {
        if (!array_key_exists($guarda, self::$memo)) {
            self::$memo[$guarda] = self::medir(self::ESQUEMA[$guarda]);
        }

        return self::$memo[$guarda];
    }

    /**
     * La medicion real de una guarda, con su try/catch: una base que no contesta no puede tumbar el
     * checkout.
     *
     * @param array<int, array{0: string, 1: string}> $columnas
     * @return bool
     */
    private static function medir(array $columnas)
    {
        try {
            foreach ($columnas as $par) {
                if (!Schema::hasColumn($par[0], $par[1])) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('CuotasMercadoPagoHelper: no se pudo leer el esquema, las cuotas quedan apagadas.', [
                'excepcion' => get_class($e),
                'mensaje'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Las cuotas que se ofrecen
    |---------------------------------------------------------------------------------------------
    */

    /**
     * True si el metodo de pago es de tipo Mercado Pago.
     *
     * @param \App\PaymentMethod|null $payment_method
     * @return bool
     */
    public static function es_mercado_pago($payment_method)
    {
        return $payment_method instanceof PaymentMethod
            && $payment_method->type
            && $payment_method->type->name === self::TIPO_MERCADO_PAGO;
    }

    /**
     * Las cuotas que la tienda ofrece para un metodo de pago, tal como viajan al SPA.
     *
     * Solo si el metodo es de Mercado Pago, esta vinculado con un metodo de cuenta corriente y la
     * columna del vinculo existe; en cualquier otro caso, `[]`. Las filas de `cuotas` que valen
     * son las del comercio (`user_id`) cargadas PARA ESE metodo de cuenta corriente: las genericas
     * (`payment_method_id` NULL) y las de otro metodo no se ofrecen.
     *
     * 🔴 NUNCA TIRA. Es lo que lee la lista PUBLICA de metodos de pago, o sea el checkout de TODA la
     * tienda: una falla de la base en esta consulta no puede tumbar los metodos de pago por una
     * feature opcional. Ante cualquier excepcion devuelve `[]` (el metodo no ofrece cuotas, como
     * antes de esta mision) y deja un `Log::warning` con el comercio y el mensaje, sin credenciales.
     *
     * @param \App\PaymentMethod|null $payment_method
     * @return array<int, array{cantidad_cuotas: int, recargo: string}> Ordenadas por cantidad de
     *         cuotas; `recargo` es el neto en porcentaje, con dos decimales ("10.00").
     */
    public static function cuotas_disponibles($payment_method)
    {
        try {
            return array_map(function ($oferta) {
                return [
                    'cantidad_cuotas' => $oferta['cantidad_cuotas'],
                    'recargo'         => number_format($oferta['neto'], 2, '.', ''),
                ];
            }, self::ofertas($payment_method));
        } catch (\Throwable $e) {
            self::avisar_falla_de_lectura('cuotas_disponibles', $payment_method, $e);

            return [];
        }
    }

    /**
     * El recargo neto (porcentaje) de una cantidad de cuotas ofrecida para un metodo, resuelto de
     * la base. Null si esa cantidad no se ofrece: nada de lo que el comprador mande vale por si
     * solo.
     *
     * ⚠️ A diferencia de `cuotas_disponibles()`, TIRA si la base falla: quien la llama decide que
     * hacer (la preferencia corta con un 422 para no cobrar de menos; el carrito y el pedido se
     * quedan sin cuotas).
     *
     * @param \App\PaymentMethod|null $payment_method
     * @param int $cantidad_cuotas
     * @return float|null
     */
    public static function recargo_neto($payment_method, $cantidad_cuotas)
    {
        return self::neto_de(self::ofertas($payment_method), $cantidad_cuotas);
    }

    /**
     * El neto de una cantidad dentro de una lista de ofertas ya leida (`ofertas()`). Pura: sirve
     * para no volver a consultar la base cuando la lista ya esta en mano.
     *
     * @param array<int, array{cantidad_cuotas: int, neto: float}> $ofertas
     * @param int $cantidad_cuotas
     * @return float|null Null si esa cantidad no esta en la lista.
     */
    public static function neto_de(array $ofertas, $cantidad_cuotas)
    {
        foreach ($ofertas as $oferta) {
            if ($oferta['cantidad_cuotas'] === (int) $cantidad_cuotas) {
                return $oferta['neto'];
            }
        }

        return null;
    }

    /**
     * Deja constancia de una falla al leer las cuotas, sin credenciales: solo el comercio, el
     * metodo, la clase de la excepcion y su mensaje.
     *
     * @param string $donde
     * @param \App\PaymentMethod|null $payment_method
     * @param \Throwable $e
     * @return void
     */
    public static function avisar_falla_de_lectura($donde, $payment_method, \Throwable $e)
    {
        Log::warning('CuotasMercadoPagoHelper: no se pudieron leer las cuotas ('.$donde.'), se sigue como si el metodo no ofreciera cuotas', [
            'commerce_id'       => $payment_method instanceof PaymentMethod ? $payment_method->user_id : null,
            'payment_method_id' => $payment_method instanceof PaymentMethod ? $payment_method->id : null,
            'excepcion'         => get_class($e),
            'mensaje'           => $e->getMessage(),
        ]);
    }

    /**
     * Lo que el comprador pidio en `cuotas`, como entero >= 1; null si no es un entero valido.
     * Acepta `3`, `"3"` y `3.0` (un JSON puede traer cualquiera de los tres); rechaza `"abc"`,
     * `2.5`, `0`, negativos, booleanos y arrays.
     *
     * @param mixed $valor
     * @return int|null
     */
    public static function entero_de_cuotas($valor)
    {
        if (is_string($valor) && ctype_digit(trim($valor))) {
            $valor = (int) trim($valor);
        } elseif (is_float($valor) && floor($valor) === $valor) {
            $valor = (int) $valor;
        }

        return is_int($valor) && $valor >= 1 ? $valor : null;
    }

    /**
     * Las filas de `cuotas` que se ofrecen para un metodo, ya filtradas y con el neto calculado.
     *
     * Reglas (plan §2): cantidad >= 1, neto = (recargo ?? 0) - (descuento ?? 0) >= 0, ordenadas por
     * cantidad y UNA sola por cantidad. Con la misma cantidad cargada mas de una vez vale la fila
     * mas vieja (menor id) DE LAS QUE TIENEN NETO >= 0: el filtro de neto va antes del dedupe, asi
     * que una fila de neto negativo no tapa a una valida de la misma cantidad. Es un descuido de
     * carga del comerciante y la tienda no tiene como adivinar cual quiso.
     *
     * ⚠️ TIRA si la base falla (es la lectura cruda): `cuotas_disponibles()` la envuelve para el
     * listado publico; la preferencia, el carrito y el pedido la envuelven cada uno a su manera.
     *
     * @param \App\PaymentMethod|null $payment_method
     * @return array<int, array{cantidad_cuotas: int, neto: float}>
     */
    public static function ofertas($payment_method)
    {
        $vinculo = self::vinculo_de($payment_method);

        if (is_null($vinculo) || empty($payment_method->user_id)) {
            return [];
        }

        $filas = Cuota::where('user_id', $payment_method->user_id)
            ->where('payment_method_id', $vinculo)
            ->where('cantidad_cuotas', '>=', 1)
            ->orderBy('cantidad_cuotas')
            ->orderBy('id')
            ->get(['id', 'cantidad_cuotas', 'recargo', 'descuento']);

        $ofertas = [];

        foreach ($filas as $fila) {
            $cantidad = (int) $fila->cantidad_cuotas;
            $neto = round((float) $fila->recargo - (float) $fila->descuento, 2);

            // `-0.0` (por ejemplo 0 - 0 redondeado) no es negativo: se normaliza antes de comparar.
            $neto = $neto + 0.0;

            if ($neto < 0 || isset($ofertas[$cantidad])) {
                continue;
            }

            $ofertas[$cantidad] = ['cantidad_cuotas' => $cantidad, 'neto' => $neto];
        }

        return array_values($ofertas);
    }

    /**
     * El id del metodo de cuenta corriente al que esta vinculado el metodo de la tienda; null si
     * no aplica (no es Mercado Pago, no tiene vinculo o la columna no existe).
     *
     * @param \App\PaymentMethod|null $payment_method
     * @return int|null
     */
    private static function vinculo_de($payment_method)
    {
        if (!self::es_mercado_pago($payment_method) || !self::vinculo_disponible()) {
            return null;
        }

        $vinculo = (int) $payment_method->current_acount_payment_method_id;

        return $vinculo > 0 ? $vinculo : null;
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El calculo
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Lo que se cobra en total de los items de la preferencia: la suma de `final_price * amount`,
     * redondeada a dos decimales. Es la base sobre la que se calcula el recargo.
     *
     * @param array $items Los que devuelve `MercadoPagoController::articulos_a_cobrar()`.
     * @return float
     */
    public static function base_a_cobrar(array $items)
    {
        $base = 0.0;

        foreach ($items as $item) {
            $base += (float) $item['final_price'] * (float) $item['amount'];
        }

        return round($base, 2);
    }

    /**
     * El recargo en plata: `round(base * neto / 100, 2)`.
     *
     * @param float $base Lo que se cobra sin el recargo.
     * @param float $neto Porcentaje neto de la fila de cuotas.
     * @return float
     */
    public static function recargo_en_plata($base, $neto)
    {
        return round((float) $base * (float) $neto / 100, 2);
    }

    /**
     * El item «Recargo por pago en N cuotas» que se suma a los de la preferencia. Null si el
     * recargo en plata es 0 (cuotas sin recargo): un item de $0 lo rechaza Mercado Pago.
     *
     * @param int $cantidad_cuotas
     * @param float $recargo_en_plata
     * @return array{name: string, amount: int, final_price: float}|null
     */
    public static function item_de_recargo($cantidad_cuotas, $recargo_en_plata)
    {
        $recargo_en_plata = round((float) $recargo_en_plata, 2);

        if ($recargo_en_plata <= 0) {
            return null;
        }

        return [
            'name'        => 'Recargo por pago en '.(int) $cantidad_cuotas.((int) $cantidad_cuotas === 1 ? ' cuota' : ' cuotas'),
            'amount'      => 1,
            'final_price' => $recargo_en_plata,
        ];
    }

    /**
     * REFERENCIA DE LA FORMULA del valor de cada cuota que ve el comprador: `round(total_cobrado / N, 2)`.
     *
     * 🔴 Hoy NO la usa ningun camino de la API: la cuenta que ve el comprador la hace `tienda-spa`
     * por su lado (informativa: lo que se cobra lo resuelve la preferencia en el servidor) y esta
     * funcion existe para dejar la formula escrita y para que los tests la fijen. Si el SPA cambia
     * la suya, esta es la que hay que mirar para que las dos sigan dando lo mismo.
     *
     * @param float $total_cobrado Base mas recargo.
     * @param int $cantidad_cuotas
     * @return float
     */
    public static function valor_de_cuota($total_cobrado, $cantidad_cuotas)
    {
        return round((float) $total_cobrado / max(1, (int) $cantidad_cuotas), 2);
    }

    /**
     * Lo que se le pide a Mercado Pago en el campo `payment_methods` de la preferencia. FUNCION
     * PURA: no mira ni la base ni el request.
     *
     *  - contado → sin tarjeta de credito: `excluded_payment_types = [{id: credit_card}]`.
     *  - N cuotas → `installments = N` (tope de cuotas que ofrece Mercado Pago) y se excluye todo
     *    lo que no es tarjeta de credito salvo `account_money`, que Mercado Pago no deja excluir.
     *  - cualquier otro modo → `[]`: no se manda `payment_methods` y la preferencia sale como hoy.
     *
     * 🔴 Es un array asociativo de PHP y no un objeto: el SDK declara el atributo
     * `Preference::$payment_methods` sin tipo y lo serializa tal cual con `json_encode` (su propio
     * ejemplo `samples/checkout-buttons/full-preference` lo arma asi), igual que `back_urls`. La
     * lista de tipos excluidos va como lista de arrays `['id' => ...]`, que `json_encode` convierte
     * en `[{"id": "..."}]`.
     *
     * @param string|null $modo MODO_CONTADO, MODO_CUOTAS o null.
     * @param int|null $cantidad_cuotas Solo para MODO_CUOTAS.
     * @return array<string, mixed>
     */
    public static function restricciones_de_medios($modo, $cantidad_cuotas = null)
    {
        if ($modo === self::MODO_CONTADO) {
            return [
                'excluded_payment_types' => [['id' => self::TIPO_TARJETA_DE_CREDITO]],
            ];
        }

        if ($modo === self::MODO_CUOTAS && !is_null($cantidad_cuotas)) {
            return [
                'installments'           => (int) $cantidad_cuotas,
                'excluded_payment_types' => array_map(function ($tipo) {
                    return ['id' => $tipo];
                }, self::TIPOS_EXCLUIDOS_EN_CUOTAS),
            ];
        }

        return [];
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Carrito y pedido
    |---------------------------------------------------------------------------------------------
    */

    /**
     * La cantidad de cuotas que se puede guardar en el carrito: la que pidio el comprador SI el
     * metodo de pago elegido la ofrece, null en cualquier otro caso (sin pedir, metodo que no
     * ofrece cuotas, cantidad que no esta cargada, metodo de otro comercio).
     *
     * ⚠️ TIRA si la base falla: el unico llamador que escribe el carrito es `sincronizar_carrito()`, que
     * lo atrapa para que una falla de esta feature opcional no rompa el guardado del carrito.
     *
     * @param int|null $payment_method_id `carts.payment_method_id`.
     * @param int|string|null $cuotas Lo que mando el SPA.
     * @param int|null $commerce_id Comercio del carrito: el metodo tiene que ser suyo.
     * @return int|null
     */
    public static function cuotas_para_el_carrito($payment_method_id, $cuotas, $commerce_id)
    {
        $cantidad = self::entero_de_cuotas($cuotas);

        if (is_null($cantidad) || empty($payment_method_id) || !self::vinculo_disponible()) {
            return null;
        }

        $payment_method = PaymentMethod::with('type')->find($payment_method_id);

        if (!$payment_method || (int) $payment_method->user_id !== (int) $commerce_id) {
            return null;
        }

        return is_null(self::recargo_neto($payment_method, $cantidad)) ? null : $cantidad;
    }

    /**
     * Copia al carrito lo que el comprador eligio en cuotas, detras de la guarda de esquema. Se
     * asigna SIEMPRE (como el resto de los campos de `sync_checkout_fields`): una escritura del
     * carrito sin `cuotas` —un SPA viejo, o el comprador que cambio de metodo de pago— deja la
     * columna en NULL.
     *
     * 🔴 NUNCA ROMPE EL GUARDADO DEL CARRITO: si leer las cuotas falla, el carrito se guarda sin
     * cuotas (`cuotas = NULL`) y queda un `Log::warning`. El comprador sigue pagando como antes.
     *
     * @param \App\Cart $cart Con `payment_method_id` y `user_id` ya puestos.
     * @param array $data El payload del carrito.
     * @return void
     */
    public static function sincronizar_carrito(Cart $cart, array $data)
    {
        if (!self::carrito_disponible()) {
            return;
        }

        try {
            $cart->cuotas = self::cuotas_para_el_carrito(
                $cart->payment_method_id,
                isset($data['cuotas']) ? $data['cuotas'] : null,
                $cart->user_id
            );
        } catch (\Throwable $e) {
            self::avisar_falla_de_lectura('carrito', self::metodo_para_el_aviso($cart), $e);

            $cart->cuotas = null;
        }
    }

    /**
     * Un metodo "de mentira" con solo el comercio y el id del carrito, para que
     * `avisar_falla_de_lectura()` tenga que loguear aunque no se haya podido leer el metodo.
     *
     * @param \App\Cart $cart
     * @return \App\PaymentMethod
     */
    private static function metodo_para_el_aviso(Cart $cart)
    {
        $metodo = new PaymentMethod();
        $metodo->id = $cart->payment_method_id;
        $metodo->user_id = $cart->user_id;

        return $metodo;
    }

    /**
     * Las columnas de cuotas que `OrderController@store` copia del carrito al pedido, en el mismo
     * estilo que `EnvioCartHelper::atributos_para_pedido()`. Vacio si la base no tiene las
     * columnas o si el carrito no eligio cuotas: el `Order::create` de siempre no cambia.
     *
     * `cuotas_recargo` es el neto en porcentaje, resuelto de la base AHORA: es el mismo criterio
     * con el que el pedido guarda el porcentaje de recargo/descuento del metodo de pago. Si el
     * comerciante saco esa cantidad de cuotas entre la preferencia y el pedido no hay recargo que
     * resolver: se deja constancia en el log y el pedido nace sin cuotas (el webhook igual anota
     * lo que Mercado Pago cobro).
     *
     * 🔴 NUNCA ROMPE LA CREACION DEL PEDIDO: si leer las cuotas falla, devuelve `[]` (el pedido
     * nace sin cuotas, como antes de esta mision) y deja un `Log::warning`.
     *
     * @param \App\Cart $cart
     * @return array<string, mixed>
     */
    public static function atributos_para_pedido(Cart $cart)
    {
        if (!self::pedido_disponible()) {
            return [];
        }

        $cantidad = self::entero_de_cuotas($cart->cuotas);

        if (is_null($cantidad)) {
            return [];
        }

        try {
            $payment_method = $cart->payment_method_id
                ? PaymentMethod::with('type')->find($cart->payment_method_id)
                : null;

            $neto = $payment_method && (int) $payment_method->user_id === (int) $cart->user_id
                ? self::recargo_neto($payment_method, $cantidad)
                : null;
        } catch (\Throwable $e) {
            self::avisar_falla_de_lectura('pedido', self::metodo_para_el_aviso($cart), $e);

            return [];
        }

        if (is_null($neto)) {
            Log::warning('CuotasMercadoPagoHelper: el carrito eligio cuotas que el metodo ya no ofrece, el pedido se crea sin cuotas', [
                'cart_id'           => $cart->id,
                'payment_method_id' => $cart->payment_method_id,
                'cuotas'            => $cantidad,
            ]);

            return [];
        }

        return [
            'cuotas'         => $cantidad,
            'cuotas_recargo' => $neto,
        ];
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Webhook
    |---------------------------------------------------------------------------------------------
    */

    /**
     * True si la feature de cuotas aplica a ESTE carrito: el comprador ya habia elegido cuotas
     * (`carts.cuotas`), o el metodo de pago del carrito ofrece cuotas (`cuotas_disponibles` no
     * vacio, o sea contado entre las opciones). Es lo que decide si el webhook anota lo que se
     * cobro: sin vinculo ni cuotas cargadas todo queda exactamente como antes de esta mision y el
     * pedido NO gana ni el medio ni las cuotas cobradas.
     *
     * `carts.cuotas` cuenta aunque el comerciante haya sacado las cuotas despues: si el comprador
     * las eligio es porque en ese momento el metodo las ofrecia.
     *
     * 🔴 NUNCA TIRA: si leer las cuotas falla, es false (no se anota) y queda un `Log::warning`; el
     * webhook sigue y responde 200 como siempre.
     *
     * @param \App\Cart $cart
     * @return bool
     */
    public static function aplica_al_carrito(Cart $cart)
    {
        try {
            if (!is_null(self::entero_de_cuotas($cart->cuotas))) {
                return true;
            }

            if (empty($cart->payment_method_id)) {
                return false;
            }

            $payment_method = PaymentMethod::with('type')->find($cart->payment_method_id);

            if (!$payment_method || (int) $payment_method->user_id !== (int) $cart->user_id) {
                return false;
            }

            return count(self::ofertas($payment_method)) > 0;
        } catch (\Throwable $e) {
            self::avisar_falla_de_lectura('webhook', self::metodo_para_el_aviso($cart), $e);

            return false;
        }
    }

    /**
     * Lo que el webhook anota en el pedido de lo que Mercado Pago cobro de verdad. Vacio sin la
     * guarda de cobro, o si el pago no trae ni cuotas ni tipo de medio.
     *
     * @param array $pago El pago tal como lo devuelve la API de Mercado Pago.
     * @return array<string, mixed>
     */
    public static function atributos_de_cobro(array $pago)
    {
        if (!self::cobro_disponible()) {
            return [];
        }

        $atributos = [];

        if (isset($pago['installments']) && is_numeric($pago['installments'])) {
            $atributos['cuotas_cobradas'] = (int) $pago['installments'];
        }

        if (isset($pago['payment_type_id']) && is_string($pago['payment_type_id']) && $pago['payment_type_id'] !== '') {
            $atributos['medio_de_pago_cobrado'] = substr($pago['payment_type_id'], 0, 40);
        }

        return $atributos;
    }

    /**
     * True si lo que Mercado Pago cobro no es lo que el comprador eligio: eligio N cuotas y el
     * pago salio en otra cantidad o con otro tipo de medio (por ejemplo con dinero en cuenta, que
     * Mercado Pago no deja excluir). Es una alerta, no un error: el pago es valido.
     *
     * @param int|null $cuotas_elegidas `carts.cuotas`.
     * @param array $pago El pago tal como lo devuelve la API de Mercado Pago.
     * @return bool
     */
    public static function el_cobro_no_coincide($cuotas_elegidas, array $pago)
    {
        $elegidas = self::entero_de_cuotas($cuotas_elegidas);

        if (is_null($elegidas)) {
            return false;
        }

        $cobradas = isset($pago['installments']) && is_numeric($pago['installments']) ? (int) $pago['installments'] : null;
        $medio    = isset($pago['payment_type_id']) ? $pago['payment_type_id'] : null;

        return $cobradas !== $elegidas || $medio !== self::TIPO_TARJETA_DE_CREDITO;
    }
}
