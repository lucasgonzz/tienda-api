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
 *   4. Recien sobre el GANADOR se le pregunta el MODO — y el modo tiene tres valores, no dos
 *      (mision oferta-por-cantidad-porcentaje, 24/9/2026). En orden, sin empates posibles:
 *
 *        4.a. `price > 0`  -> PRECIO FIJO. Gana SIEMPRE, aunque el tramo tambien traiga
 *             porcentaje. Es el valor absoluto que el comercio escribio pensando en un numero, y
 *             es lo UNICO que existia hasta esta mision: cualquier fila vieja de cualquier
 *             cliente se sigue comportando exactamente igual que antes. Por eso gana, y por eso
 *             el orden no se invierte "para simplificar": invertirlo le cambiaria el precio a
 *             filas que hoy ya estan cobrando bien en produccion.
 *
 *        4.b. Si no, `porcentaje > 0 && porcentaje < 100` -> PORCENTAJE sobre el precio que la
 *             linea IBA A TENER (`$precio_base`). El 100 queda AFUERA a proposito: dejaria el
 *             precio en cero, y un articulo regalado no es un descuento por cantidad, es un dato
 *             mal cargado. Es el mismo lado seguro que el `<= 0` del precio fijo, y el mismo
 *             borde que ya descarta `CriterioDeOfertaPorCantidadHelper` en `empresa-api`.
 *
 *        4.c. Cualquier otra cosa -> NINGUNO: el rango NO aplica y la linea sale al precio
 *             normal. Nunca un default permisivo.
 *
 *      🔴 Y el ORDEN entre el criterio 3 y el 4 tampoco se toca: el ganador se elige SOLO por
 *      `amount`, sin mirarle ni el `price` ni el `porcentaje`, y recien sobre el ganador se
 *      pregunta el modo. El gemelo elige primero por `amount` y despues hace
 *      `Number(range.price)`, donde `Number(null) === 0` es falsy. O sea que un ganador sin valor
 *      usable NO deja competir al segundo — se cae al precio normal. Filtrar los tramos sin valor
 *      ANTES del desempate daria otro precio en ese borde exacto, y esta MEDIDO: el 16/9/2026,
 *      $3.000 mostrados contra $3.948 cobrados.
 *
 * 🔴 LA COLUMNA `porcentaje` LA MIGRA `empresa-api`, NO ESTE REPO, y llega a cada cliente con el
 * release del ERP mientras la tienda la despliega Lucas a mano, sitio por sitio. O sea que va a
 * haber clientes con la tienda nueva contra una base SIN la columna durante dias. Eloquent
 * devuelve null para un atributo que no existe en la tabla, asi que LEERLA es seguro y el tramo
 * cae solo en el criterio 4.c. Lo que reventaria es NOMBRARLA EN UNA QUERY: nada de
 * `whereNotNull('porcentaje')`, ni un `select` explicito, ni un `orderBy`. Este helper no la
 * nombra en ningun lado y no tiene que empezar a hacerlo.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * DONDE SE USA
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 *   - `CartHelper::get_price()`: al agregar la linea al carrito.
 *   - `CartHelper::resincronizar_precios_por_rango()`: cada vez que cambia la CANTIDAD, que es el
 *     pedido concreto de Lucas ("que en base a las cantidades que el usuario agregue al carrito
 *     sea el precio que le va a aparecer en el carrito"). Ese metodo entra por `hay_tramos()`, que
 *     es la guarda que le impide costarle una sola query al comercio que no usa la funcionalidad.
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
     * ¿Alguno de estos articulos tiene tramos cargados? Es la GUARDA BARATA de
     * `CartHelper::resincronizar_precios_por_rango()`, y su molde es
     * `ClientOfferHelper::hayContrato()`: una pregunta que se contesta en cero o una query y corta
     * en seco antes de tocar nada caro.
     *
     * ── POR QUE HACE FALTA UNA GUARDA, SI YA ESTA `hay_tabla()` ──────────────────────────────
     *
     * Porque `hay_tabla()` no filtra a nadie: `article_price_ranges` la crea una migracion de
     * noviembre de 2025 y hoy la tienen TODOS los clientes. La hermana de esa resincronizacion
     * —la de ofertas personalizadas— corta en 0 queries cuando el comercio no usa el contrato; sin
     * esta, la de tramos cargaba las lineas del carrito y hacia un `whereHas` con `withAll()` en
     * CADA recalculo, en los tres caminos que escriben el carrito, para descubrir que no habia
     * nada que hacer. Medido sobre un carrito de invitado con un articulo sin tramos: master 4
     * queries reales, esta rama 6.
     *
     * ── POR QUE ESTA PREGUNTA Y NO "¿ESTE COMERCIO USA TRAMOS?" ──────────────────────────────
     *
     * La version por comercio es mas cara y menos precisa. Mas cara porque esta tabla NO tiene
     * `user_id` (el aislamiento lo da `articles.user_id`, ver el modelo), asi que preguntar por el
     * comercio obliga a cruzar contra el catalogo entero. Y menos precisa porque la respuesta que
     * importa no es "este comercio carga tramos" sino "hay algo que resincronizar en ESTE
     * carrito": un comercio con tramos en diez articulos y un carrito sin ninguno de esos diez no
     * tiene nada que resincronizar. Aca es un `whereIn` de uno a veinte ids contra el indice de
     * `article_id`.
     *
     * ── Y EN LOS DOS CAMINOS MAS USADOS NO CUESTA NI UNA QUERY ───────────────────────────────
     *
     * `store` y `update` pasan por `attachArticles()` -> `get_price()` -> `precio_de_articulo()`,
     * que ya llamo a `precargar()` con TODOS los ids del carrito. Cuando `set_total()` pregunta,
     * la respuesta ya esta en memoria. El unico camino que paga una query es
     * `update_article_amount()` —el boton "Actualizar"—, y ahi es exactamente la query que la
     * funcion iba a hacer igual, adelantada y sin el `withAll()` detras.
     *
     * ⚠️ El memo es por proceso: bajo PHP-FPM muere con el request, pero en phpunit el proceso
     * sigue vivo entre casos. Un test que carga tramos DESPUES de haber preguntado tiene que
     * llamar a `olvidar()` en el medio.
     *
     * @param  array  $ids  Los `article_id` de las lineas del carrito.
     * @return bool
     */
    public static function hay_tramos(array $ids)
    {
        if (!self::hay_tabla()) {
            return false;
        }

        $limpios = [];

        foreach ($ids as $id) {
            $id = is_numeric($id) ? (int) $id : 0;

            if ($id > 0) {
                $limpios[$id] = $id;
            }
        }

        if (count($limpios) == 0) {
            return false;
        }

        self::precargar($limpios);

        foreach ($limpios as $id) {
            if (count(self::$rangos_por_articulo[$id]) > 0) {
                return true;
            }
        }

        return false;
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
     * @param  mixed  $precio_base   El precio que la linea iba a tener si ningun tramo matcheara,
     *                               EN LA MISMA ESCALA en la que el llamador va a usar el
     *                               resultado. Solo lo necesita el modo PORCENTAJE. Ver `precio()`.
     * @return float|null
     */
    public static function precio_de_articulo($article_id, $cantidad, array $ids_del_lote = [], $precio_base = null)
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

        return self::precio(self::$rangos_por_articulo[$article_id], $cantidad, $precio_base);
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
     * 🔴 `$precio_base` es el precio que la linea IBA A TENER si ningun tramo hubiera matcheado,
     * y tiene que venir EN LA MISMA ESCALA en la que el llamador va a usar el resultado. Sin el,
     * un tramo por porcentaje no tiene sobre que aplicarse y cae al precio normal (que es el lado
     * seguro): el default `null` existe para que un llamador viejo que solo conoce el precio fijo
     * siga dando exactamente lo mismo que antes, byte por byte. Los tres llamadores de
     * `CartHelper` lo pasan; cada uno dice de donde saca su numero.
     *
     * @param  mixed  $rangos       Coleccion de Eloquent, array de arrays o array de objetos. Puede
     *                              venir de la base (`$article->article_price_ranges`) o del
     *                              payload del SPA.
     * @param  mixed  $cantidad     La cantidad de ESA linea.
     * @param  mixed  $precio_base  El precio normal de la linea, para el modo PORCENTAJE.
     * @return float|null
     */
    public static function precio($rangos, $cantidad, $precio_base = null)
    {
        $rango = self::rango($rangos, $cantidad);

        if (is_null($rango)) {
            return null;
        }

        /* Criterio 4.a — PRECIO FIJO, y gana primero.

           `price` es nullable en la base y el gemelo hace Number(null) === 0, que es falsy. Nulo,
           no numerico o cero -> no hay precio fijo y se sigue con el porcentaje.

           El `<=` y no `==` es deliberado: un tramo con precio negativo es un dato imposible de
           cargar con sentido (el ABM del articulo usa un input numerico), pero si llegara a
           existir, aceptarlo seria cobrar plata al reves. Con `==` este lado lo aceptaba y el
           gemelo del SPA lo descartaba, o sea que volvia a haber dos criterios para la misma
           regla. Los dos descartan, y descartan hacia el lado seguro. */
        $price = self::valor($rango, 'price');

        if (!is_null($price) && is_numeric($price) && (float) $price > 0.0) {
            return (float) $price;
        }

        /* Criterio 4.b — PORCENTAJE.

           ⚠️ `porcentaje` se LEE y no se consulta: contra una base sin la columna (cliente con la
           tienda nueva y el ERP viejo) Eloquent devuelve null y el tramo cae solo en el criterio
           4.c. Nombrarla en una query seria un "Unknown column" en el medio del carrito. */
        $porcentaje = self::valor($rango, 'porcentaje');

        if (!self::es_porcentaje_usable($porcentaje)) {
            return null;
        }

        /* Sin precio base no hay a que aplicarle el porcentaje. Devolver 0 seria regalar el
           articulo; devolver null lo manda al precio normal, que es el lado seguro. */
        if (!is_numeric($precio_base) || (float) $precio_base <= 0.0) {
            return null;
        }

        /* 🔴 El redondeo a centavos no es cosmetico y va acá, no en el llamador. Son dos motivos:
           el gemelo del SPA muestra `Math.round(base * (1 - p / 100) * 100) / 100` (el mismo molde
           que `precio_con_oferta_por_cantidad()`), asi que sin esto pantalla y servidor dirian
           numeros distintos por fracciones de centavo; y `article_cart.price` es `double(20,2)`,
           o sea que MySQL redondea igual al escribir — y entonces la comparacion
           `(float) $precio === (float) $linea->price` de `resincronizar_precios_por_rango()` no
           daria nunca igual y cada recalculo del carrito escribiria una fila al pedo. */
        return round((float) $precio_base * (1 - ((float) $porcentaje / 100)), 2);
    }

    /**
     * True si el valor es un porcentaje de descuento USABLE: mayor a 0 y menor a 100.
     *
     * 🔴 Es el criterio 4.b, y es el gemelo literal de
     * `CriterioDeOfertaPorCantidadHelper::es_porcentaje_usable()` de `empresa-api` (quien
     * PERSISTE) y del mismo borde en `tienda-spa/src/mixins/generals.js` (quien MUESTRA). El 100
     * queda afuera en los tres: dejaria el precio en cero, y un articulo regalado no es un
     * descuento por cantidad, es un dato mal cargado.
     *
     * Una cadena vacia, `null` o texto no numerico no son positivos, asi que no son usables — el
     * mismo lado seguro de siempre.
     *
     * @param  mixed  $valor
     * @return bool
     */
    private static function es_porcentaje_usable($valor)
    {
        if (is_null($valor) || !is_numeric($valor)) {
            return false;
        }

        $valor = (float) $valor;

        return $valor > 0.0 && $valor < 100.0;
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
