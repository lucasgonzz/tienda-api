<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * La guarda de compatibilidad de las direcciones del comprador y de las formas de envío propias
 * (misión formas-envio-direcciones-maps, 19/10/2026).
 *
 * ── Por qué existe ────────────────────────────────────────────────────────────────────────────
 *
 * El esquema de esta base lo gobierna `empresa-api`: la tabla `buyer_addresses`, las columnas
 * `carts/orders.buyer_address_id` + `envio_direccion`, `delivery_zones.costo_a_convenir` y
 * `online_configurations.ofrecer_envio_zipnova` las crean SUS migraciones (2026_10_19_1000*). La
 * tienda la despliega Lucas por cliente cuando quiere, y los dos lados nunca llegan a producción el
 * mismo día: esta tienda va a correr contra bases que todavía no tienen nada de esto.
 *
 * Leer un atributo que no existe en la fila es inofensivo (Eloquent devuelve null). Lo que revienta
 * es ESCRIBIRLO (`UPDATE carts SET envio_direccion = ...` → "Unknown column") y consultar una tabla
 * que no existe ("Base table or view not found"). Y los dos caminos están en el medio del checkout.
 * Mismo molde y mismo criterio que `ZipnovaEsquemaHelper` y `ComboEsquemaHelper`.
 *
 * ── Tres interruptores ────────────────────────────────────────────────────────────────────────
 *
 *  - `disponible()`: la tabla de direcciones y la columna `envio_direccion` en `carts` Y en
 *    `orders` (el contrato del plan). Prende los endpoints `/api/buyer/addresses`, la clave
 *    `direccion_envio` del carrito, la copia al pedido y `commerce.direcciones_de_envio`. Se miran
 *    las dos tablas y no solo `carts` por la misma razón que en `ZipnovaEsquemaHelper`: un esquema
 *    a medio aplicar reventaría justo en el POST /orders, que es el peor lugar.
 *  - `costo_a_convenir_disponible()`: la columna suelta de `delivery_zones`. Sin ella ninguna forma
 *    propia es "a convenir" y el precio guardado se cobra como siempre.
 *  - `ofrecer_envio_zipnova_disponible()`: la columna suelta de `online_configurations`. Sin ella
 *    Zipnova se ofrece como siempre (si el conector está conectado).
 *
 * Son tres y no uno porque llegan en migraciones distintas: un cliente puede tener una y no otra.
 *
 * ── Memoizado ────────────────────────────────────────────────────────────────────────────────
 *
 * Estáticas: bajo PHP-FPM mueren con el request (una query de information_schema por chequeo y por
 * request como máximo). En consola NO se memoiza, porque el proceso no termina entre casos y un
 * test que esconde el esquema en caliente tiene que ver el cambio (`SinEsquemaDeDireccionesTest`).
 */
class DireccionesEsquemaHelper
{
    /** La tabla de direcciones del comprador. */
    const TABLA_DIRECCIONES = 'buyer_addresses';

    /** La foto de la dirección de entrega en `carts` y en `orders` (json). */
    const COLUMNA_SNAPSHOT = 'envio_direccion';

    /** Las formas de envío propias del comercio. */
    const TABLA_ZONAS = 'delivery_zones';

    /** "El costo se coordina con el comprador": la forma no cobra nada online. */
    const COLUMNA_A_CONVENIR = 'costo_a_convenir';

    /** La configuración de la tienda del comercio. */
    const TABLA_CONFIGURACION = 'online_configurations';

    /** "Ofrecer envío por correo con Zipnova" (default 1; null o ausente = se ofrece). */
    const COLUMNA_OFRECER_ZIPNOVA = 'ofrecer_envio_zipnova';

    /** @var bool|null Memo de `disponible()`. */
    private static $disponible = null;

    /** @var bool|null Memo de `costo_a_convenir_disponible()`. */
    private static $costo_a_convenir = null;

    /** @var bool|null Memo de `ofrecer_envio_zipnova_disponible()`. */
    private static $ofrecer_envio_zipnova = null;

    /** @var bool Para no repetir el aviso del log en cada chequeo del mismo proceso. */
    private static $aviso_dado = false;

    /**
     * True si esta base ya tiene las direcciones del comprador: la tabla y la foto en carritos y
     * pedidos.
     *
     * @return bool
     */
    public static function disponible()
    {
        if (is_null(self::$disponible) || app()->runningInConsole()) {
            self::$disponible = self::medir(function () {
                return Schema::hasTable(self::TABLA_DIRECCIONES)
                    && Schema::hasColumn('carts', self::COLUMNA_SNAPSHOT)
                    && Schema::hasColumn('orders', self::COLUMNA_SNAPSHOT);
            });

            if (!self::$disponible && !self::$aviso_dado) {
                self::$aviso_dado = true;
                Log::info(
                    'DireccionesEsquemaHelper: esta base todavía no tiene el esquema de direcciones del comprador '
                    .'(buyer_addresses + carts/orders.envio_direccion). Llega con el release de empresa-api; '
                    .'hasta entonces la tienda usa el checkout de siempre.'
                );
            }
        }

        return self::$disponible;
    }

    /**
     * True si `delivery_zones` tiene la columna `costo_a_convenir`.
     *
     * @return bool
     */
    public static function costo_a_convenir_disponible()
    {
        if (is_null(self::$costo_a_convenir) || app()->runningInConsole()) {
            self::$costo_a_convenir = self::medir(function () {
                return Schema::hasColumn(self::TABLA_ZONAS, self::COLUMNA_A_CONVENIR);
            });
        }

        return self::$costo_a_convenir;
    }

    /**
     * True si `online_configurations` tiene la columna `ofrecer_envio_zipnova`.
     *
     * @return bool
     */
    public static function ofrecer_envio_zipnova_disponible()
    {
        if (is_null(self::$ofrecer_envio_zipnova) || app()->runningInConsole()) {
            self::$ofrecer_envio_zipnova = self::medir(function () {
                return Schema::hasColumn(self::TABLA_CONFIGURACION, self::COLUMNA_OFRECER_ZIPNOVA);
            });
        }

        return self::$ofrecer_envio_zipnova;
    }

    /**
     * True si la forma de envío propia es "a convenir": no cobra nada online y su `price` vale 0 en
     * todo total (pantalla, mail, Mercado Pago, `OnlinePaymentHelper`).
     *
     * Acepta el modelo (`$cart->delivery_zone`, `$order->delivery_zone`) o el array que manda el
     * SPA en el body (`delivery_zone` de la preferencia sin carrito, Payway). Sin la columna nunca es
     * a convenir: es la tienda de siempre.
     *
     * @param \App\DeliveryZone|array|null $zona
     * @return bool
     */
    public static function zona_a_convenir($zona)
    {
        if (empty($zona) || !self::costo_a_convenir_disponible()) {
            return false;
        }

        if (is_array($zona)) {
            $valor = isset($zona[self::COLUMNA_A_CONVENIR]) ? $zona[self::COLUMNA_A_CONVENIR] : null;
        } elseif (is_object($zona)) {
            $valor = $zona->{self::COLUMNA_A_CONVENIR};
        } else {
            return false;
        }

        return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Descarta la memoria del proceso. Para tests que cambian el esquema en caliente.
     *
     * @return void
     */
    public static function olvidar()
    {
        self::$disponible = null;
        self::$costo_a_convenir = null;
        self::$ofrecer_envio_zipnova = null;
        self::$aviso_dado = false;
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
            Log::warning('DireccionesEsquemaHelper: no se pudo consultar el esquema, se toma como no disponible: ' . $e->getMessage());

            return false;
        }
    }
}
