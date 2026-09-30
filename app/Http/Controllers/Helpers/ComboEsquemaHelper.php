<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * La guarda de compatibilidad de los combos en la tienda (mision combos-y-rangos-de-precio,
 * 16/9/2026).
 *
 * ── Por que existe, y por que es obligatoria ──────────────────────────────────────────────────
 *
 * El esquema de esta base lo gobierna `empresa-api`: `combos.online`, `cart_combo` y `order_combo`
 * las crean SUS migraciones. La tienda, en cambio, la despliega Lucas a mano, por cliente, cuando
 * quiere. O sea que va a haber clientes con la tienda NUEVA corriendo contra una base donde el
 * release de empresa todavia no llego.
 *
 * Sin esta guarda, el primer cliente que actualice la tienda antes que el ERP se queda con la home
 * en 500: `where('online', 1)` sobre una columna que no existe es "Unknown column", y
 * `with('combos')` sobre una tabla que no existe es "Base table or view not found". No es una
 * seccion que se ve vacia: es la tienda caida.
 *
 * Mismo molde y mismo criterio que `ZipnovaEsquemaHelper` y `BuyerTrackingHelper::hayTabla()`.
 *
 * ── Un solo interruptor para los tres objetos ─────────────────────────────────────────────────
 *
 * `disponible()` exige LOS TRES: la columna y las dos tablas. Llegan todos en la misma migracion,
 * asi que en la practica estan los tres o ninguno — pero un esquema a medio aplicar reventaria
 * justo en el POST /orders, con el comprador apretando "Confirmar compra", que es el peor lugar
 * posible para enterarse. Es exactamente la razon por la que `ZipnovaEsquemaHelper::disponible()`
 * mira `carts` Y `orders` en vez de conformarse con una.
 *
 * Con el interruptor en false los combos no existen para nadie: la home no los lista, el carrito
 * no los acepta y el pedido no los copia. Todo lo demas de la tienda funciona igual que en master.
 *
 * ── Memoizado ────────────────────────────────────────────────────────────────────────────────
 *
 * Estaticas: bajo PHP-FPM mueren con el request (una query de information_schema por request como
 * maximo). En consola NO se memoiza, porque el proceso no termina entre casos y un test que
 * esconde el esquema en caliente tiene que ver el cambio.
 */
class ComboEsquemaHelper
{
    /** La tabla del catalogo. Existe desde 2022 en todos los clientes; `online` no. */
    const TABLA_COMBOS = 'combos';

    /** El check "Mostrar en la tienda" del ABM de empresa. tinyint, default 0. */
    const COLUMNA_ONLINE = 'online';

    /** Pivote carrito <-> combo. */
    const TABLA_CART_COMBO = 'cart_combo';

    /** Pivote pedido <-> combo. */
    const TABLA_ORDER_COMBO = 'order_combo';

    /**
     * Precio del combo por lista de precios (mision combos-calculados, 30/9/2026). La crea
     * `empresa-api` y solo se llena para los combos que se calculan desde sus articulos, en
     * cuentas que usan listas. Ver `precios_por_lista_disponible()`.
     */
    const TABLA_PRECIOS_POR_LISTA = 'combo_price_type';

    /** @var bool|null Memo de `disponible()` para este request. */
    private static $disponible = null;

    /** @var bool|null Memo de `precios_por_lista_disponible()` para este request. */
    private static $precios_por_lista = null;

    /** @var bool Para no repetir el aviso del log una vez por linea. */
    private static $aviso_dado = false;

    /**
     * True si esta base ya tiene todo lo que los combos de la tienda necesitan.
     *
     * @return bool
     */
    public static function disponible()
    {
        if (is_null(self::$disponible) || app()->runningInConsole()) {
            self::$disponible = self::medir();
        }

        return self::$disponible;
    }

    /**
     * True si esta base ya tiene la tabla `combo_price_type` (precio del combo por lista).
     *
     * ── Por que es una guarda APARTE de `disponible()` ────────────────────────────────────────
     *
     * `disponible()` apaga los combos ENTEROS y su esquema llego con la mision anterior
     * (combos-y-rangos-de-precio). Esta tabla llega con otra migracion de `empresa-api`
     * (combos-calculados), asi que hay un tercer escenario que la primera guarda no ve: una base
     * con los combos publicables pero SIN precios por lista. Ahi los combos tienen que seguir
     * andando al precio de siempre (`combos.price`), y no apagarse ni, peor, reventar con "Base
     * table or view not found" en la home.
     *
     * Es tambien la direccion inversa de la compatibilidad: el ERP nuevo llena `combos.price` con
     * el precio de la lista por defecto justamente para que una tienda vieja —que solo lee esa
     * columna— siga cobrando bien. Esta guarda es lo mismo pero para la tienda nueva contra un
     * ERP viejo.
     *
     * Mismo criterio de memoria que `disponible()`: estatica por request, y en consola no se
     * memoiza para que un test que esconde la tabla en caliente vea el cambio.
     *
     * @return bool
     */
    public static function precios_por_lista_disponible()
    {
        if (is_null(self::$precios_por_lista) || app()->runningInConsole()) {
            self::$precios_por_lista = self::medir_precios_por_lista();
        }

        return self::$precios_por_lista;
    }

    /**
     * La medicion de `precios_por_lista_disponible()`, con su try/catch: ante la duda, false —
     * que es "el combo cuesta `combos.price`", el comportamiento de siempre.
     *
     * @return bool
     */
    private static function medir_precios_por_lista()
    {
        try {
            return Schema::hasTable(self::TABLA_PRECIOS_POR_LISTA);
        } catch (\Throwable $e) {
            Log::warning('ComboEsquemaHelper: no se pudo leer el esquema de precios por lista, los combos quedan al precio unico.', [
                'excepcion' => get_class($e),
                'mensaje'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * La medicion real, con su try/catch: una base que no contesta el information_schema no puede
     * tumbar la home. Ante la duda, false — que es "la tienda de siempre, sin combos".
     *
     * @return bool
     */
    private static function medir()
    {
        try {
            $hay = Schema::hasTable(self::TABLA_COMBOS)
                && Schema::hasColumn(self::TABLA_COMBOS, self::COLUMNA_ONLINE)
                && Schema::hasTable(self::TABLA_CART_COMBO)
                && Schema::hasTable(self::TABLA_ORDER_COMBO);

            if (!$hay && !self::$aviso_dado) {
                self::$aviso_dado = true;
                Log::info(
                    'ComboEsquemaHelper: esta base todavia no tiene el esquema de combos '
                    .'(combos.online + cart_combo + order_combo). Llega con el release de '
                    .'empresa-api; hasta entonces la tienda no ofrece combos.'
                );
            }

            return $hay;
        } catch (\Throwable $e) {
            Log::warning('ComboEsquemaHelper: no se pudo leer el esquema, los combos quedan apagados.', [
                'excepcion' => get_class($e),
                'mensaje'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Descarta la memoria de este proceso. Para tests que prenden y apagan el esquema.
     *
     * @return void
     */
    public static function olvidar()
    {
        self::$disponible = null;
        self::$precios_por_lista = null;
        self::$aviso_dado = false;
    }
}
