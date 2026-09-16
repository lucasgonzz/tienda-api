<?php

namespace App\Http\Controllers\Helpers;

use App\ArticlePriceRange;
use Illuminate\Support\Facades\Schema;

/**
 * El matcheo de los tramos de precio por cantidad de un articulo — UNA SOLA VEZ, en un solo lugar
 * (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * 🔴 SU GEMELO, Y POR QUE TIENE QUE COINCIDIR BORDE POR BORDE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Gemelo 1 (el original, el del ERP): `empresa-spa/src/mixins/vender/article_price_range.js:14-63`.
 * Gemelo 2 (el de esta mision, el que MUESTRA el precio en la tienda): el espejo en
 * `tienda-spa/src/mixins/generals.js`.
 *
 * Este precio se decide DOS VECES: en el navegador para mostrarlo y aca para cobrarlo. Si los
 * criterios difieren en un solo borde —el `Igual` contra el `Mayor o igual`, el empate de
 * `amount`, el `price` nulo—, el comprador ve un numero en la pantalla y le cobran otro. Es la
 * clase de error que ya esta documentada en `APRENDER_NO_PARCHEAR.md:1026` ("el mismo invariante
 * decidido con dos criterios distintos en front y back"), asi que no es una precaucion teorica.
 *
 * EL SERVIDOR ES EL QUE MANDA: el front muestra, este helper cobra. Ante divergencia se corrige el
 * front, nunca al reves.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * LOS CUATRO CRITERIOS, LITERALES. No se "mejoran" de a uno: se cambian en los tres lados o no se
 * cambian.
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 *   1. `'Mayor o igual'` matchea con `cantidad >= range.amount`. `'Igual'` matchea con igualdad
 *      estricta numerica (`3` e `3.0` son lo mismo; `3` y `4` no).
 *   2. Cualquier otro `modo` NO matchea. Nunca un default permisivo: un modo que nadie escribio
 *      todavia no puede empezar a descontar plata solo.
 *   3. Entre los que matchean gana el de MAYOR `amount`; con `amount` igual gana EL PRIMERO DEL
 *      ARRAY. (El `reduce` del gemelo usa `>` estricto, o sea que ante empate se queda con el
 *      acumulador, que es el primero.)
 *   4. Recien sobre el GANADOR se mira el `price`: nulo o cero -> el rango NO aplica y la linea
 *      sale al precio normal. El orden importa y no es un detalle: el gemelo elige primero por
 *      `amount` y despues hace `Number(range.price)`, donde `Number(null) === 0` es falsy. O sea
 *      que un ganador con `price` nulo NO deja competir al segundo — se cae al precio normal.
 *      Filtrar los nulos ANTES del desempate daria otro precio en ese borde exacto.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * DONDE SE USA
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 *   - `CartHelper::get_price()`: al agregar la linea al carrito.
 *   - `CartHelper::resincronizar_precios_por_rango()`: cada vez que cambia la CANTIDAD, que es el
 *     pedido concreto de Lucas ("que en base a las cantidades que el usuario agregue al carrito
 *     sea el precio que le va a aparecer en el carrito").
 */
class ArticlePriceRangeHelper
{
    /** Los dos unicos `modo` que existen. El ABM del ERP los guarda con estas cadenas exactas. */
    const MODO_IGUAL = 'Igual';
    const MODO_MAYOR_O_IGUAL = 'Mayor o igual';

    /** Tabla que crea `empresa-api`. Ver `hay_tabla()`. */
    const TABLA = 'article_price_ranges';

    /** @var bool|null Memo de `hay_tabla()` para este request. */
    private static $hay_tabla = null;

    /**
     * @var array<int, array> Memo de los tramos LEIDOS DE LA BASE, por article_id. Un carrito de
     *                        N lineas cuesta UNA query, no N. Ver `precargar()`.
     */
    private static $rangos_por_articulo = [];

    /**
     * True si la base de este cliente ya tiene `article_price_ranges`.
     *
     * La tabla existe desde una migracion de noviembre de 2025 y hoy la tienen todos los clientes,
     * pero el eager load vive en `Article::scopeWithAll()`, que esta en el camino de TODOS los
     * listados de la tienda: sin tabla, eso no seria "la seccion de ofertas vacia" sino la tienda
     * entera en 500. Una query de information_schema memoizada por request es barata al lado de
     * eso. Mismo criterio y mismo molde que `ZipnovaEsquemaHelper::disponible()`.
     *
     * 🔴 En consola NO se memoiza: bajo PHP-FPM la estatica muere con el request (que es lo que se
     * quiere), pero en phpunit o tinker el proceso sigue vivo y un test que esconde el esquema en
     * caliente tiene que ver el cambio.
     *
     * @return bool
     */
    public static function hay_tabla()
    {
        if (is_null(self::$hay_tabla) || app()->runningInConsole()) {
            self::$hay_tabla = Schema::hasTable(self::TABLA);
        }

        return self::$hay_tabla;
    }

    /**
     * El precio del tramo de UN articulo, con los tramos LEIDOS DE LA BASE y no del payload.
     *
     * 🔴 Y esa es toda la gracia de este metodo. `CartHelper::get_price()` recibe el articulo tal
     * cual lo manda el navegador, asi que sus `article_price_ranges` son texto que escribio el
     * cliente: resolver el precio con eso seria dejar que cualquiera se invente un tramo de $1 y
     * lo cobre. "El cliente fija el precio base" ya es un agujero PREEXISTENTE de este repo
     * (ver el docblock de `CartHelper::get_price()`), pero una funcionalidad nueva no lo agranda.
     *
     * @param  mixed  $article_id
     * @param  mixed  $cantidad
     * @param  array  $ids_del_lote  Los ids del resto de las lineas, para traerlas todas juntas.
     * @return float|null
     */
    public static function precio_de_articulo($article_id, $cantidad, array $ids_del_lote = [])
    {
        if (!self::hay_tabla()) {
            return null;
        }

        $article_id = is_numeric($article_id) ? (int) $article_id : 0;

        if ($article_id <= 0) {
            return null;
        }

        $ids_del_lote[] = $article_id;
        self::precargar($ids_del_lote);

        return self::precio(self::$rangos_por_articulo[$article_id], $cantidad);
    }

    /**
     * Trae de la base los tramos de los articulos que todavia no estan en memoria. Los que no
     * tienen ninguno quedan memoizados como array vacio, asi no se vuelven a consultar.
     *
     * ⚠️ `orderBy('id')` no es decorativo: el criterio 3 dice "ante `amount` igual, el primero del
     * array", y para que eso signifique lo mismo aca y en el navegador las dos listas tienen que
     * venir en el mismo orden. La relacion `Article::article_price_ranges()` ordena igual.
     *
     * @param  array  $ids
     * @return void
     */
    private static function precargar(array $ids)
    {
        $faltantes = [];

        foreach ($ids as $id) {
            $id = is_numeric($id) ? (int) $id : 0;

            if ($id > 0 && !array_key_exists($id, self::$rangos_por_articulo)) {
                $faltantes[$id] = $id;
            }
        }

        if (count($faltantes) == 0) {
            return;
        }

        foreach ($faltantes as $id) {
            self::$rangos_por_articulo[$id] = [];
        }

        $filas = ArticlePriceRange::whereIn('article_id', array_values($faltantes))
                                    ->orderBy('id', 'ASC')
                                    ->get();

        foreach ($filas as $fila) {
            self::$rangos_por_articulo[(int) $fila->article_id][] = $fila;
        }
    }

    /**
     * El precio unitario que le corresponde a `$cantidad` segun los tramos de `$rangos`, o null si
     * ningun tramo aplica (y entonces la linea sale al precio normal).
     *
     * @param  mixed  $rangos    Coleccion de Eloquent, array de arrays o array de objetos. Puede venir
     *                           de la base (`$article->article_price_ranges`) o del payload del SPA.
     * @param  mixed  $cantidad  La cantidad de ESA linea.
     * @return float|null
     */
    public static function precio($rangos, $cantidad)
    {
        $rango = self::rango($rangos, $cantidad);

        if (is_null($rango)) {
            return null;
        }

        $price = self::valor($rango, 'price');

        /* Criterio 4, y va sobre el GANADOR: `price` es nullable en la base y el gemelo hace
           Number(null) === 0, que es falsy. Nulo, no numerico o cero -> el rango no aplica.

           El `<=` y no `==` es deliberado: un tramo con precio negativo es un dato imposible de
           cargar con sentido (el ABM del articulo usa un input numerico), pero si llegara a
           existir, aceptarlo seria cobrar plata al reves. Con `==` este lado lo aceptaba y el
           gemelo del SPA lo descartaba, o sea que volvia a haber dos criterios para la misma
           regla. Los dos descartan, y descartan hacia el lado seguro. */
        if (is_null($price) || !is_numeric($price) || (float) $price <= 0.0) {
            return null;
        }

        return (float) $price;
    }

    /**
     * El tramo ganador para `$cantidad`, sin mirarle el `price` todavia. Separado de `precio()`
     * para que el orden de los criterios 3 y 4 quede explicito y no se pueda invertir sin querer.
     *
     * @param  mixed  $rangos
     * @param  mixed  $cantidad
     * @return mixed|null El elemento tal cual vino (modelo o array), o null si ninguno matchea.
     */
    public static function rango($rangos, $cantidad)
    {
        if (is_null($rangos) || !is_numeric($cantidad)) {
            return null;
        }

        /* Una coleccion de Eloquent, un array de arrays o un array de objetos: los tres llegan
           aca segun el camino (base o payload). `all()` normaliza el primero y deja pasar el
           resto. */
        if (is_object($rangos) && method_exists($rangos, 'all')) {
            $rangos = $rangos->all();
        }

        if (!is_array($rangos) || count($rangos) == 0) {
            return null;
        }

        $cantidad = (float) $cantidad;
        $ganador = null;

        foreach ($rangos as $rango) {

            $modo = self::valor($rango, 'modo');
            $amount = self::valor($rango, 'amount');

            if (!is_numeric($amount)) {
                continue;
            }

            $amount = (float) $amount;

            /* Criterios 1 y 2: los dos modos conocidos, y nada mas que esos dos. */
            if ($modo === self::MODO_MAYOR_O_IGUAL) {
                $matchea = $cantidad >= $amount;
            } else if ($modo === self::MODO_IGUAL) {
                $matchea = $cantidad === $amount;
            } else {
                $matchea = false;
            }

            if (!$matchea) {
                continue;
            }

            /* Criterio 3: gana el de mayor `amount`, y el `>` estricto deja ganar al PRIMERO ante
               empate — igual que el `reduce` del gemelo. */
            if (is_null($ganador) || $amount > (float) self::valor($ganador, 'amount')) {
                $ganador = $rango;
            }
        }

        return $ganador;
    }

    /**
     * Lee una clave de un tramo venga como venga: modelo de Eloquent, stdClass o array.
     *
     * @param  mixed   $rango
     * @param  string  $clave
     * @return mixed|null
     */
    private static function valor($rango, $clave)
    {
        if (is_array($rango)) {
            return array_key_exists($clave, $rango) ? $rango[$clave] : null;
        }

        if (is_object($rango)) {
            return isset($rango->{$clave}) ? $rango->{$clave} : null;
        }

        return null;
    }

    /**
     * Descarta la memoria de este proceso. Para tests que prenden y apagan el esquema.
     *
     * @return void
     */
    public static function olvidar()
    {
        self::$hay_tabla = null;
        self::$rangos_por_articulo = [];
    }
}
