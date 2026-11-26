<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * El "envío gratis" de una forma de envío propia (`delivery_zones`) y la foto de lo que se cobró
 * en el pedido (misión truvari-carrito-entrega-pago, 26/11/2026).
 *
 * ── La regla (una sola, espejada en JS en tienda-spa) ─────────────────────────────────────────
 *
 *   a convenir                               -> precio 0, gratis false, a_convenir true
 *   desde > 0 y subtotal >= desde            -> precio 0, gratis, motivo 'monto'
 *   casilla de combos y el carrito tiene uno -> precio 0, gratis, motivo 'combo'
 *   si no                                    -> el `price` de la zona
 *
 * El subtotal es `carts.total` (artículos + promociones + combos, sin envío, sin recargo web y sin
 * recargo ni descuento del medio de pago): el mismo criterio del envío gratis de Zipnova y
 * Andreani. Si aplican monto y combo a la vez, el motivo es 'monto' (orden fijo, determinista).
 *
 * ── Compatibilidad ────────────────────────────────────────────────────────────────────────────
 *
 * Las cuatro columnas las crea la migración de `empresa-api` (la tienda no tiene migraciones):
 * `delivery_zones.envio_gratis_desde` y `envio_gratis_con_combo` (la regla) y
 * `orders.envio_zona_precio` y `envio_zona_gratis_motivo` (la foto). Llegan con el release del ERP,
 * y los dos lados no llegan juntos a producción: ESCRIBIR una columna que no existe revienta el
 * POST /orders, así que la foto sólo se arma con `snapshot_disponible()`. Sin la regla la zona
 * cobra su `price`, como siempre. Mismo molde que `DireccionesEsquemaHelper`: memo estático, sin
 * memo en consola (los tests esconden columnas en caliente) y `medir()` que ante un error da false.
 */
class EnvioZonaPropiaHelper
{
    /** Las formas de envío propias del comercio. */
    const TABLA_ZONAS = 'delivery_zones';

    /** Los pedidos. */
    const TABLA_PEDIDOS = 'orders';

    /** Las dos columnas de la regla en `delivery_zones`. */
    const COLUMNAS_DE_LA_REGLA = ['envio_gratis_desde', 'envio_gratis_con_combo'];

    /** Las dos columnas de la foto en `orders`. */
    const COLUMNAS_DE_LA_FOTO = ['envio_zona_precio', 'envio_zona_gratis_motivo'];

    /** @var bool|null Memo de `regla_disponible()`. */
    private static $regla = null;

    /** @var bool|null Memo de `snapshot_disponible()`. */
    private static $snapshot = null;

    /** @var bool Para no repetir el aviso del log en cada chequeo del mismo proceso. */
    private static $aviso_dado = false;

    /**
     * True si `delivery_zones` tiene las dos columnas de la regla.
     *
     * @return bool
     */
    public static function regla_disponible()
    {
        if (is_null(self::$regla) || app()->runningInConsole()) {
            self::$regla = self::medir(function () {
                return self::tiene_columnas(self::TABLA_ZONAS, self::COLUMNAS_DE_LA_REGLA);
            });

            if (!self::$regla && !self::$aviso_dado) {
                self::$aviso_dado = true;
                Log::info('EnvioZonaPropiaHelper: delivery_zones todavía no tiene el envío gratis; las formas propias cobran su precio como siempre.');
            }
        }

        return self::$regla;
    }

    /**
     * True si `orders` tiene las dos columnas de la foto del envío propio.
     *
     * @return bool
     */
    public static function snapshot_disponible()
    {
        if (is_null(self::$snapshot) || app()->runningInConsole()) {
            self::$snapshot = self::medir(function () {
                return self::tiene_columnas(self::TABLA_PEDIDOS, self::COLUMNAS_DE_LA_FOTO);
            });
        }

        return self::$snapshot;
    }

    /**
     * Resuelve cuánto cobra una forma propia (función pura de sus tres argumentos).
     *
     * @param \App\DeliveryZone|array $zona Modelo o array (tolera "1"/"0", "80000.00" y claves ausentes).
     * @param float|int|string $subtotal `carts.total`: lo que valen las líneas, sin envío.
     * @param bool $tiene_combo El carrito tiene una promoción de vinoteca o un combo.
     * @return array{precio: float, gratis: bool, motivo: string|null, a_convenir: bool}
     */
    public static function resolver($zona, $subtotal, $tiene_combo)
    {
        // El precio de lista de la forma (0 si no es numérico).
        $precio_de_lista = is_numeric(self::leer($zona, 'price')) ? (float) self::leer($zona, 'price') : 0.0;

        // "A convenir" gana sobre todo: nunca se muestra "Gratis" ni se cobra nada online.
        if (DireccionesEsquemaHelper::zona_a_convenir($zona)) {
            return ['precio' => 0.0, 'gratis' => false, 'motivo' => null, 'a_convenir' => true];
        }

        // Mínimo de compra: sólo vale un monto numérico mayor a cero; `>=` como Zipnova.
        $desde = self::leer($zona, 'envio_gratis_desde');
        if (is_numeric($desde) && (float) $desde > 0 && (float) $subtotal >= (float) $desde) {
            return ['precio' => 0.0, 'gratis' => true, 'motivo' => 'monto', 'a_convenir' => false];
        }

        // Combo o promoción en el carrito, con la casilla de la forma tildada.
        if ($tiene_combo && filter_var(self::leer($zona, 'envio_gratis_con_combo'), FILTER_VALIDATE_BOOLEAN)) {
            return ['precio' => 0.0, 'gratis' => true, 'motivo' => 'combo', 'a_convenir' => false];
        }

        return ['precio' => $precio_de_lista, 'gratis' => false, 'motivo' => null, 'a_convenir' => false];
    }

    /**
     * Lo que cobra la forma propia del carrito, mirando el carrito vivo (sin exigir `deliver`, que
     * es lo que hacía `items_del_carrito()` antes de esta misión).
     *
     * @param \App\Cart $cart
     * @return array|null El resultado de `resolver()`, o null si el carrito no tiene forma propia.
     */
    public static function resolver_del_carrito($cart)
    {
        $zona = $cart->delivery_zone;

        if (!$zona) {
            return null;
        }

        // Sin las columnas de la regla la forma cobra su precio, como siempre.
        if (!self::regla_disponible()) {
            return self::resolver(self::sin_regla($zona), $cart->total, false);
        }

        return self::resolver($zona, $cart->total, self::carrito_tiene_combo($cart));
    }

    /**
     * Lo que cobra la forma propia del carrito, sólo si es un envío a domicilio.
     *
     * @param \App\Cart $cart
     * @return array|null Null sin forma propia o con `deliver` distinto de 1.
     */
    public static function para_carrito($cart)
    {
        if ((int) $cart->deliver !== 1) {
            return null;
        }

        return self::resolver_del_carrito($cart);
    }

    /**
     * Las columnas de la foto que se copian al crear el pedido.
     *
     * 🔴 Vacío sin las columnas de `orders` (base vieja: escribirlas reventaría el pedido), sin forma
     * propia, con envío por correo (esa plata es `envio_precio`) o con forma a convenir.
     *
     * @param \App\Cart $cart
     * @return array [] o ['envio_zona_precio' => float, 'envio_zona_gratis_motivo' => 'monto'|'combo'|null]
     */
    public static function atributos_para_pedido($cart)
    {
        if (!self::snapshot_disponible()) {
            return [];
        }

        if (!is_null(EnvioCartHelper::opcion_de($cart))) {
            return [];
        }

        $resuelto = self::para_carrito($cart);

        if (is_null($resuelto) || $resuelto['a_convenir']) {
            return [];
        }

        return [
            'envio_zona_precio'        => round((float) $resuelto['precio'], 2),
            'envio_zona_gratis_motivo' => $resuelto['motivo'],
        ];
    }

    /**
     * Lo que se cobró de envío propio en un pedido: la foto si la tiene y, si no (pedido viejo o
     * base sin columnas), el precio vivo de la forma como hasta ahora.
     *
     * @param \App\Order $order
     * @return float|null Null si el pedido no tiene forma propia; 0 si es a convenir.
     */
    public static function precio_del_pedido($order)
    {
        $foto = $order->getAttribute('envio_zona_precio');

        if (is_numeric($foto)) {
            return (float) $foto;
        }

        $zona = $order->delivery_zone;

        if (!$zona) {
            return null;
        }

        if (DireccionesEsquemaHelper::zona_a_convenir($zona)) {
            return 0.0;
        }

        return (float) $zona->price;
    }

    /**
     * Descarta la memoria del proceso. Para tests que cambian el esquema en caliente.
     *
     * @return void
     */
    public static function olvidar()
    {
        self::$regla = null;
        self::$snapshot = null;
        self::$aviso_dado = false;
    }

    /**
     * True si el carrito tiene al menos una promoción de vinoteca o un combo con cantidad > 0.
     *
     * @param \App\Cart $cart
     * @return bool
     */
    private static function carrito_tiene_combo($cart)
    {
        $con_cantidad = function ($lineas) {
            return $lineas->filter(function ($linea) {
                return (float) $linea->pivot->amount > 0;
            })->isNotEmpty();
        };

        if ($con_cantidad($cart->promociones_vinoteca()->get())) {
            return true;
        }

        // Los combos van detrás de su guarda de esquema (sin `cart_combo` no hay combos).
        return ComboEsquemaHelper::disponible() && $con_cantidad($cart->combos()->get());
    }

    /**
     * La zona sin las claves de la regla, para una base que no las tiene.
     *
     * @param \App\DeliveryZone $zona
     * @return array
     */
    private static function sin_regla($zona)
    {
        return [
            'price'            => $zona->price,
            'costo_a_convenir' => $zona->costo_a_convenir,
        ];
    }

    /**
     * Lee una clave de una zona que puede ser modelo o array.
     *
     * @param \App\DeliveryZone|array $zona
     * @param string $clave
     * @return mixed Null si no está.
     */
    private static function leer($zona, $clave)
    {
        if (is_array($zona)) {
            return isset($zona[$clave]) ? $zona[$clave] : null;
        }

        return is_object($zona) ? $zona->{$clave} : null;
    }

    /**
     * True si la tabla tiene todas las columnas.
     *
     * @param string $tabla
     * @param array $columnas
     * @return bool
     */
    private static function tiene_columnas($tabla, array $columnas)
    {
        foreach ($columnas as $columna) {
            if (!Schema::hasColumn($tabla, $columna)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Corre el chequeo sin dejar que una falla de conexión se convierta en un 500: ante la duda,
     * false, que es "la tienda de siempre".
     *
     * @param callable $chequeo
     * @return bool
     */
    private static function medir(callable $chequeo)
    {
        try {
            return (bool) $chequeo();
        } catch (\Throwable $e) {
            Log::warning('EnvioZonaPropiaHelper: no se pudo consultar el esquema, se toma como no disponible: ' . $e->getMessage());

            return false;
        }
    }
}
