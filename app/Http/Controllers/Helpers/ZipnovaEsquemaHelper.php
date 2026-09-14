<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * La guarda de compatibilidad de los envíos por correo (misión zipnova-envios, 14/9/2026).
 *
 * ── Por qué existe ────────────────────────────────────────────────────────────────────────────
 *
 * El esquema de esta base lo gobierna `empresa-api`: las columnas `carts/orders.envio_*` y la
 * tabla `envios` las crean SUS migraciones. La tienda, en cambio, la despliega Lucas por cliente
 * cuando quiere, y los dos lados nunca llegan a producción el mismo día. O sea que esta tienda va
 * a correr días o semanas contra bases que todavía no tienen nada de esto (hoy: todas).
 *
 * Leer un atributo que no existe en la fila es inofensivo (Eloquent devuelve null). Lo que
 * revienta es ESCRIBIRLO (`UPDATE carts SET envio_opcion = ...` → "Unknown column") y hacer
 * eager load de `envios` ("Base table or view not found"). Y los dos caminos están en el medio
 * del checkout: sin esta guarda, un cliente con la tienda nueva y el ERP viejo no podría guardar
 * un carrito. Mismo criterio que `PlatformConnector::find_for_user_and_slug()` con
 * `platform_connectors` y que `ClientOfferHelper::hayTablas()`.
 *
 * ── Cómo se usa ───────────────────────────────────────────────────────────────────────────────
 *
 * Todo acceso a `envio_*` que escriba, y todo `with('envio')`, pregunta primero acá. Si falta el
 * esquema, la integración se reporta como no disponible (`commerce.envios_zipnova: false`, 422
 * `sin_zipnova` al cotizar) y el resto del checkout sigue exactamente como en master.
 *
 * Memoizado en estáticas: bajo PHP-FPM se resetean al terminar el request, que es lo que se
 * quiere (una query de information_schema por request como máximo). En consola (phpunit,
 * tinker) el proceso no termina entre casos, así que se relee siempre — igual que
 * `ClientOfferHelper::hayTablas()`; una suite que esconde el esquema en caliente tiene que ver
 * el cambio.
 */
class ZipnovaEsquemaHelper
{
    /** Tablas y columna testigo. Con `envio_opcion` en `carts` y en `orders` está toda la migración. */
    const TABLA_CARTS = 'carts';
    const TABLA_ORDERS = 'orders';
    const COLUMNA_TESTIGO = 'envio_opcion';
    const TABLA_ENVIOS = 'envios';

    /** @var bool|null Memo de `disponible()` para este request. */
    private static $disponible = null;

    /** @var bool|null Memo de `tabla_envios()` para este request. */
    private static $tabla_envios = null;

    /**
     * True si `carts` y `orders` tienen las columnas de envío: se puede cotizar, guardar la
     * opción en el carrito y copiarla al pedido.
     *
     * Se miran las DOS tablas y no solo `carts`: la migración las agrega en un mismo `up()`, pero
     * el pedido se escribe con `Order::create([... envio_* ...])` y un esquema a medio aplicar
     * reventaría justo en el POST /orders, que es el peor lugar. La segunda query cuesta nada.
     *
     * @return bool
     */
    public static function disponible()
    {
        if (is_null(self::$disponible) || app()->runningInConsole()) {
            self::$disponible = self::medir(function () {
                return Schema::hasColumn(self::TABLA_CARTS, self::COLUMNA_TESTIGO)
                    && Schema::hasColumn(self::TABLA_ORDERS, self::COLUMNA_TESTIGO);
            });
        }

        return self::$disponible;
    }

    /**
     * True si existe la tabla `envios`: se puede hacer `with('envio')` en los pedidos para que
     * "Mis pedidos" muestre el seguimiento.
     *
     * @return bool
     */
    public static function tabla_envios()
    {
        if (is_null(self::$tabla_envios) || app()->runningInConsole()) {
            self::$tabla_envios = self::medir(function () {
                return Schema::hasTable(self::TABLA_ENVIOS);
            });
        }

        return self::$tabla_envios;
    }

    /**
     * Descarta la memoria del proceso. Para tests que cambian el esquema en caliente.
     *
     * @return void
     */
    public static function olvidar()
    {
        self::$disponible = null;
        self::$tabla_envios = null;
    }

    /**
     * Corre el chequeo de esquema sin dejar que una falla de conexión se convierta en un 500:
     * si no se puede preguntar, la integración no está disponible y se deja rastro.
     *
     * @param callable $chequeo
     * @return bool
     */
    private static function medir(callable $chequeo)
    {
        try {
            return (bool) $chequeo();
        } catch (\Throwable $e) {
            Log::warning('ZipnovaEsquemaHelper: no se pudo consultar el esquema de envíos, la integración se reporta como no disponible: ' . $e->getMessage());

            return false;
        }
    }
}
