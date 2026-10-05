<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use App\PriceType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QUE ARTICULOS VE CADA COMPRADOR SEGUN SU LISTA DE PRECIOS (mision catalogo-por-lista-tienda,
 * 5/10/2026). Es el UNICO lugar de la tienda donde se decide el catalogo: los listados, la ficha,
 * los favoritos, las categorias, el carrito y el pedido preguntan aca y en ningun otro lado.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * EL PEDIDO, Y POR QUE "TIENE PRECIO EN LA LISTA" NO ALCANZA
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Ferretotal vende a minoristas y a mayoristas con dos listas ya creadas, y hay articulos que solo
 * quiere venderle al minorista. Todos los articulos tienen precio en las dos listas (el mayorista
 * de un articulo "solo minorista" queda a costo), asi que mirar si hay precio no decide nada: hace
 * falta una decision EXPLICITA del comerciante. Son dos columnas que crea `empresa-api` (migraciones
 * 2026_10_05_160000 y 2026_10_05_160100), NULL por defecto:
 *
 *   - `price_types.catalogo_restringido_en_tienda`: el interruptor de la LISTA. `= 1` restringida.
 *   - `article_price_type.visible_en_tienda`: por ARTICULO y por LISTA. `= 1` habilitado.
 *
 * Las decisiones de Lucas (cerradas, no se reabren):
 *   1. El comprador cuya lista efectiva es restringida ve SOLO los articulos habilitados para esa
 *      lista.
 *   2. En una lista restringida los articulos nacen SIN habilitar (NULL).
 *   3. La guarda de carrito y pedido va en la misma mision (ver `no_visibles_del_carrito()`).
 *
 * 🔴 SIEMPRE se compara con `= 1`, nunca con `!= 0`: NULL tiene que valer "no" en las dos columnas,
 * y `!= 0` dejaria pasar el NULL en PHP (loose) y no en SQL. Una sola regla en los dos lados.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * LA LISTA EFECTIVA DEL COMPRADOR — Y POR QUE ES LA MISMA FUNCION QUE ELIGE EL PRECIO
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Se calcula UNA vez por request (`eleccion_de_lista()`), y `ArticleHelper::checkPriceTypes()` la
 * toma de aca en sus casos 3 y 4. Esa es la garantia de que "el precio que ve el comprador" y "los
 * articulos que ve" salen de la misma lista: si la eleccion se copiara en dos lugares, el dia que
 * alguien cambie uno el mayorista veria un catalogo con los precios de otra lista.
 *
 *   - Comprador LOGUEADO con un `Client` del ERP que tiene lista -> esa lista. `Client.price_type_id`
 *     en 0 es "sin lista": la relacion `price_type` da null (no hay lista con id 0) y cae al punto
 *     siguiente, exactamente como ya lo hacia `checkPriceTypes()`.
 *   - Si no (visitante, o logueado sin cliente / sin lista) -> la de `position` mas alta del
 *     comercio. Para el VISITANTE no cuentan las `ocultar_al_publico`; para el logueado si cuentan
 *     (caso 4 de `checkPriceTypes()`, tal cual).
 *   - Si el comercio no tiene listas con `position` -> no hay lista efectiva y no se filtra nada.
 *
 * El comprador sale SIEMPRE de la SESION (`auth('buyer')`), nunca del payload ni de la URL: si
 * saliera del request, cualquiera elegiria que catalogo ver mandando otro id.
 *
 * ── El catalogo NO depende de COMO se calcula el precio ──────────────────────────────────────
 *
 * `checkPriceTypes()` tiene cuatro casos y la lista del comprador solo pricea en el 3 y en el 4:
 *   - Caso 1 (extension `lista_de_precios_por_rango_de_cantidad_vendida`): el precio sale de los
 *     rangos por categoria, no de la lista del comprador. El CATALOGO si sale de su lista.
 *   - Caso 2 (`use_archivos_de_intercambio`): el precio sale de un archivo por `provider_code`. El
 *     catalogo, otra vez, de la lista del cliente.
 * Es una decision, no un descuido: la restriccion es "que le vendo a quien", y eso no cambia porque
 * el comercio calcule el precio de otra manera. Por eso este helper no mira esas dos extensiones.
 *
 * ── Lo que no cubre (fuera del pedido, queda dicho en el informe) ────────────────────────────
 *
 * Los combos (`combos`, `combo_price_type`) y las promociones de vinoteca no son `Article`: se
 * siguen mostrando a todos, y un combo puede contener articulos no habilitados para la lista.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * 🔴 EL ESQUEMA LO MIGRA `empresa-api`, Y LA TIENDA PUEDE LLEGAR ANTES
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Las columnas llegan a la base de un cliente con el release del ERP; la tienda la despliega Lucas
 * aparte. Va a haber tiendas nuevas contra bases sin las columnas, y ahi un `whereExists` sobre
 * `visible_en_tienda` no seria "un filtro que no filtra": seria "Unknown column" en el camino de
 * TODOS los listados, o sea la tienda entera en 500. Ver `hay_columnas()` y el orden de las
 * preguntas en `lista_restringida()`.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * EL COSTO: EL 100% DE LOS CLIENTES DE HOY NO TIENE NINGUNA LISTA RESTRINGIDA
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Y son ellos los que pagarian cada query de mas, en todos los listados, todo el dia. Por eso:
 *   - la eleccion de lista se memoiza por request y `checkPriceTypes()` la REUSA: la query de las
 *     listas del comercio, que antes corria una vez por cada llamada a `checkPriceTypes()` (hasta
 *     cinco en la home), ahora corre una sola vez por request;
 *   - la guarda de esquema (information_schema) se consulta SOLO si la lista ya dijo que es
 *     restringida, o sea nunca para quien no usa la funcionalidad;
 *   - sin lista restringida `restringir()` no toca la consulta: el SQL es byte a byte el de antes.
 * Lo fija `tests/Feature/CatalogoPorLista/CostoDelCatalogoPorListaTest`.
 */
class CatalogoPorListaHelper
{
    /** La tabla y la columna del interruptor de la lista. */
    const TABLA_LISTAS = 'price_types';
    const COLUMNA_LISTA = 'catalogo_restringido_en_tienda';

    /** La tabla y la columna de la habilitacion por articulo y por lista. */
    const TABLA_PIVOTE = 'article_price_type';
    const COLUMNA_PIVOTE = 'visible_en_tienda';

    /** Clave bajo la que vive la memoria en los atributos del request. Ver `memo()`. */
    const CLAVE_MEMO = 'catalogo_por_lista';

    /** @var bool|null Memo de `hay_columnas()`. */
    private static $hay_columnas = null;

    /**
     * True si la base de este cliente ya tiene LAS DOS columnas del contrato.
     *
     * Exige las dos y no se conforma con una: un esquema a medio aplicar (el interruptor de la lista
     * puesto, la columna del pivote no) haria que `restringir()` nombre una columna que no existe.
     * Mismo criterio que `ComboEsquemaHelper::disponible()`.
     *
     * Molde: `ArticlePriceRangeHelper::hay_tabla()`. En consola NO se memoiza: bajo PHP-FPM la
     * estatica muere con el request (que es lo que se quiere), pero en phpunit el proceso sigue vivo
     * y un test que esconde las columnas en caliente tiene que ver el cambio.
     *
     * ⚠️ No se llama en el camino comun: `lista_restringida()` la pregunta solo cuando la lista YA
     * dijo que es restringida. Ver el docblock de la clase, "El costo".
     *
     * @return bool
     */
    public static function hay_columnas()
    {
        if (is_null(self::$hay_columnas) || app()->runningInConsole()) {
            self::$hay_columnas = Schema::hasColumn(self::TABLA_LISTAS, self::COLUMNA_LISTA)
                && Schema::hasColumn(self::TABLA_PIVOTE, self::COLUMNA_PIVOTE);
        }

        return self::$hay_columnas;
    }

    /**
     * La eleccion de la lista del comprador de esta sesion, con lo que `checkPriceTypes()` necesita
     * para su caso 4. Ver el docblock de la clase para las reglas.
     *
     * Devuelve:
     *   - `lista`: el `PriceType` elegido, o null si no hay lista efectiva.
     *   - `origen`: 'cliente' (la lista del Client del ERP), 'posicion' (la de position mas alta) o
     *     'ninguna'.
     *   - `el_comercio_tiene_listas`: si el comercio tiene alguna lista con `position`, ANTES de
     *     sacar las ocultas al publico. Es lo que le permite a `checkPriceTypes()` distinguir "el
     *     comercio no tiene listas" (vale la columna `final_price`, como siempre) de "tiene listas
     *     pero todas ocultas" (el visitante se queda sin precios). Solo tiene sentido con origen
     *     'posicion' o 'ninguna'; con origen 'cliente' es null (no se consultan las listas).
     *
     * 🔴 La consulta de las listas es LITERALMENTE la que `checkPriceTypes()` hacia en su caso 4
     * (mismo where, mismo orderBy, sin desempate por id): extraerla no puede cambiar que lista gana.
     * `ComboPrecioHelper::lista_del_comprador()` si desempata por id DESC; ante dos listas con la
     * misma `position` los dos lados podrian elegir distinto, igual que antes de esta mision (queda
     * como hallazgo en el informe, no se toca acá).
     *
     * @param  int|string|null  $commerce_id
     * @return array{lista: \App\PriceType|null, origen: string, el_comercio_tiene_listas: bool|null}
     */
    public static function eleccion_de_lista($commerce_id)
    {
        $buyer = auth('buyer')->user();

        $clave = 'eleccion|'.self::clave($commerce_id, $buyer);

        $memo = self::memo();

        if ($memo->has($clave)) {
            return $memo->get($clave);
        }

        /* La lista del cliente del ERP. Son las MISMAS relaciones que lee `checkPriceTypes()` sobre
           la misma instancia del comprador de la sesion, asi que si ya las cargo no cuestan nada. */
        if (
            !is_null($buyer)
            && !is_null($buyer->comercio_city_client)
            && !is_null($buyer->comercio_city_client->price_type)
        ) {
            $eleccion = [
                'lista'                    => $buyer->comercio_city_client->price_type,
                'origen'                   => 'cliente',
                'el_comercio_tiene_listas' => null,
            ];

            $memo->set($clave, $eleccion);

            return $eleccion;
        }

        /* Sin comercio no hay listas que buscar: `where('user_id', null)` compilaria a
           `is null` y `price_types.user_id` es NOT NULL, asi que la query solo costaria. */
        if (is_null($commerce_id) || $commerce_id === '') {
            $eleccion = [
                'lista'                    => null,
                'origen'                   => 'ninguna',
                'el_comercio_tiene_listas' => false,
            ];

            $memo->set($clave, $eleccion);

            return $eleccion;
        }

        $price_types = PriceType::where('user_id', $commerce_id)
                                ->whereNotNull('position')
                                ->orderBy('position', 'DESC')
                                ->get();

        $el_comercio_tiene_listas = count($price_types) >= 1;

        if (is_null($buyer)) {
            /* Las ocultas al publico no juegan para el visitante. Loose a proposito, igual que en
               `checkPriceTypes()`: NULL y 0 son "visible". */
            $price_types = $price_types->filter(function ($price_type) {
                return $price_type->ocultar_al_publico != 1;
            })->values();
        }

        /* La primera es la de posicion mas alta (el precio publico, el mas caro). */
        $lista = $price_types->first();

        $eleccion = [
            'lista'                    => $lista,
            'origen'                   => is_null($lista) ? 'ninguna' : 'posicion',
            'el_comercio_tiene_listas' => $el_comercio_tiene_listas,
        ];

        $memo->set($clave, $eleccion);

        return $eleccion;
    }

    /**
     * La lista efectiva del comprador de esta sesion en este comercio, o null.
     *
     * @param  int|string|null  $commerce_id
     * @return \App\PriceType|null
     */
    public static function lista_del_comprador($commerce_id)
    {
        return self::eleccion_de_lista($commerce_id)['lista'];
    }

    /**
     * La lista efectiva del comprador SOLO si restringe el catalogo; si no, null.
     *
     * 🔴 El ORDEN de las preguntas es la mitad del diseño y no se invierte "para que la guarda vaya
     * primero":
     *
     *   1. La lista (memoizada, y la misma consulta que `checkPriceTypes()` ya necesitaba).
     *   2. Su atributo `catalogo_restringido_en_tienda`, que viene en el `select *` de la lista: leer
     *      un atributo que la tabla no tiene da null (Eloquent no tira) y null es "no restringida".
     *      O sea que sin la columna en `price_types` esto ya corta solo, sin una query mas.
     *   3. Recien si la lista dijo que SI: `hay_columnas()`, que es information_schema y que lo que
     *      de verdad protege es el caso del esquema a medias (la columna de la lista puesta y la del
     *      pivote no), donde `restringir()` nombraria una columna inexistente.
     *
     * Con la guarda primero, cada request de cada tienda pagaria information_schema para descubrir
     * que no hay nada que hacer — y ese es el 100% de los clientes de hoy.
     *
     * @param  int|string|null  $commerce_id
     * @return \App\PriceType|null
     */
    public static function lista_restringida($commerce_id)
    {
        $buyer = auth('buyer')->user();

        $clave = 'restringida|'.self::clave($commerce_id, $buyer);

        $memo = self::memo();

        if ($memo->has($clave)) {
            return $memo->get($clave);
        }

        $lista = self::lista_del_comprador($commerce_id);

        $restringida = null;

        if (
            !is_null($lista)
            && (int) $lista->getAttribute(self::COLUMNA_LISTA) === 1
            && self::hay_columnas()
        ) {
            $restringida = $lista;
        }

        $memo->set($clave, $restringida);

        return $restringida;
    }

    /**
     * Agrega a una consulta de `Article` la restriccion de la lista del comprador: solo los articulos
     * con una fila de pivote `(articulo, esa lista)` habilitada.
     *
     * Sin lista restringida NO toca la consulta: el SQL queda byte a byte el de antes, que es lo que
     * garantiza que el 100% de los clientes de hoy no note nada.
     *
     * ── Por que `EXISTS` y no un join ni `whereHas('price_types')` ───────────────────────────
     *
     * El pivote NO tiene indice unico por `(article_id, price_type_id)`: puede haber filas
     * duplicadas por par, y entonces un join duplicaria articulos en el listado y en el paginador.
     * La pregunta correcta es "¿existe una fila habilitada?", no "¿la fila es...?": con dos filas,
     * una habilitada y otra no, el articulo se ve. Y `whereHas('price_types', ...)` sumaria el join
     * con `price_types`, que aca no aporta nada. El indice por `article_id` del pivote (migracion del
     * 28/9 de `empresa-api`) lo hace barato.
     *
     * La columna del articulo se califica con `qualifyColumn()` y no a mano: este scope tambien corre
     * adentro de `whereHas('articles', ...)` (marcas, categorias, SEO) y de `withCount()`, donde la
     * consulta es la de la relacion.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  Consulta de `App\Article`.
     * @param  int|string|null  $commerce_id  Comercio de la pagina. Sin el, el del request.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function restringir($query, $commerce_id = null)
    {
        if (is_null($commerce_id)) {
            $commerce_id = request()->commerce_id;
        }

        $lista = self::lista_restringida($commerce_id);

        if (is_null($lista)) {
            return $query;
        }

        $columna_del_articulo = $query->qualifyColumn('id');

        $query->whereExists(function ($sub_query) use ($lista, $columna_del_articulo) {
            $sub_query->select(DB::raw(1))
                        ->from(self::TABLA_PIVOTE)
                        ->whereColumn(self::TABLA_PIVOTE.'.article_id', $columna_del_articulo)
                        ->where(self::TABLA_PIVOTE.'.price_type_id', $lista->id)
                        ->where(self::TABLA_PIVOTE.'.'.self::COLUMNA_PIVOTE, 1);
        });

        return $query;
    }

    /**
     * De estos articulos, los que el comprador de esta sesion NO puede ver, con su nombre. Para el
     * carrito y el pedido. Una sola query, y ninguna sin lista restringida.
     *
     * Los ids que no existen (o estan borrados) no vuelven: no son "no visibles", son otra cosa, y
     * el carrito los sigue tratando como hoy. El nombre sale de la BASE y no del payload: es lo que
     * el SPA le muestra al comprador en el aviso.
     *
     * @param  array  $ids
     * @param  int|string|null  $commerce_id  Comercio del CARRITO (lo escribio el servidor), nunca
     *                                        el del payload.
     * @return array<int, array{id: int, name: string}>  En el orden de los ids, sin repetidos.
     */
    public static function no_visibles(array $ids, $commerce_id)
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, function ($id) {
            return is_numeric($id);
        }))));

        if (count($ids) == 0) {
            return [];
        }

        $lista = self::lista_restringida($commerce_id);

        if (is_null($lista)) {
            return [];
        }

        $no_visibles = Article::whereIn('id', $ids)
                            ->whereNotExists(function ($sub_query) use ($lista) {
                                $sub_query->select(DB::raw(1))
                                            ->from(self::TABLA_PIVOTE)
                                            ->whereColumn(self::TABLA_PIVOTE.'.article_id', 'articles.id')
                                            ->where(self::TABLA_PIVOTE.'.price_type_id', $lista->id)
                                            ->where(self::TABLA_PIVOTE.'.'.self::COLUMNA_PIVOTE, 1);
                            })
                            ->get(['id', 'name'])
                            ->keyBy('id');

        $resultado = [];

        foreach ($ids as $id) {
            if ($no_visibles->has($id)) {
                $resultado[] = [
                    'id'   => (int) $id,
                    'name' => (string) $no_visibles->get($id)->name,
                ];
            }
        }

        return $resultado;
    }

    /**
     * Solo los ids de `no_visibles()`.
     *
     * @param  array  $ids
     * @param  int|string|null  $commerce_id
     * @return array<int, int>
     */
    public static function ids_no_visibles(array $ids, $commerce_id)
    {
        return array_column(self::no_visibles($ids, $commerce_id), 'id');
    }

    /**
     * Las lineas de ARTICULO de un carrito guardado que el comprador de esta sesion no puede ver.
     * Es la red de seguridad de `OrderController@store`: un carrito armado antes de que cambiara la
     * lista, el de un visitante que despues se logueo como mayorista, o un POST armado a mano.
     *
     * Sin lista restringida devuelve vacio SIN leer las lineas: la pregunta por la lista va primero.
     *
     * @param  \App\Cart  $cart
     * @return array<int, array{id: int, name: string}>
     */
    public static function no_visibles_del_carrito($cart)
    {
        if (is_null(self::lista_restringida($cart->user_id))) {
            return [];
        }

        $ids = DB::table('article_cart')
                    ->where('cart_id', $cart->id)
                    ->pluck('article_id')
                    ->all();

        return self::no_visibles($ids, $cart->user_id);
    }

    /**
     * Descarta las memorias. La del request muere sola con el request; esto es para los tests que
     * llaman al helper directo, sin pasar por HTTP, y cambian datos en el medio.
     *
     * @return void
     */
    public static function olvidar()
    {
        self::$hay_columnas = null;

        request()->attributes->remove(self::CLAVE_MEMO);
    }

    /**
     * La memoria de este request: un `ParameterBag` colgado de los atributos del propio request.
     *
     * ── Por que en el request y no en una estatica apagada bajo consola ──────────────────────
     *
     * El molde del repo para "memo por request" es una estatica que se relee siempre en consola
     * (`ArticleHelper::$visibilidad_del_anonimo`), y lo es para que no sobreviva entre tests. Aca no
     * sirve: apagada en phpunit, el test de costo mediria un camino que en produccion no existe (la
     * lista consultada en cada `checkOnline()` y en cada `checkPriceTypes()`), y no podria fijar lo
     * unico que importa, que la tienda no sume queries. Colgada del request, la memoria vive
     * exactamente un request en los dos mundos: bajo PHP-FPM muere con el, y en phpunit cada
     * `$this->get()` arma un `Request` nuevo, asi que tampoco cruza de un request a otro ni de un
     * test a otro.
     *
     * ⚠️ Y por eso mismo NO es una estatica con la clave por `spl_object_id(request())`: PHP reusa
     * los ids de los objetos que se liberan, y un request nuevo podia heredar la memoria de uno viejo.
     *
     * @return \Symfony\Component\HttpFoundation\ParameterBag
     */
    private static function memo()
    {
        $atributos = request()->attributes;

        if (!$atributos->has(self::CLAVE_MEMO)) {
            $atributos->set(self::CLAVE_MEMO, new \Symfony\Component\HttpFoundation\ParameterBag());
        }

        return $atributos->get(self::CLAVE_MEMO);
    }

    /**
     * La clave de la memoria: comercio + comprador. El comprador entra porque la sesion puede cambiar
     * en el medio de un request (el login responde con datos del comprador recien logueado), y una
     * eleccion hecha como visitante no le puede servir al logueado.
     *
     * @param  int|string|null  $commerce_id
     * @param  \App\Buyer|null  $buyer
     * @return string
     */
    private static function clave($commerce_id, $buyer)
    {
        return (string) $commerce_id.'|'.(is_null($buyer) ? 'visitante' : 'comprador:'.$buyer->getAuthIdentifier());
    }
}
