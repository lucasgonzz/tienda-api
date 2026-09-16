<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Las dos secciones de recomendacion de la ficha del articulo
 * (mision tienda-ficha-estilo-ml):
 *
 *   - "Quienes compraron este producto tambien compraron" -> co-compra, sale de los pedidos
 *     (`article_order` + `orders`).
 *   - "Quienes vieron este producto tambien compraron" -> sale del comportamiento guardado
 *     en `buyer_tracking_events`, la tabla que escribe BuyerTrackingHelper.
 *
 * 🔴 ESTA CLASE SOLO LEE. No escribe un evento, no toca la ingesta, no crea ni modifica
 * ninguna tabla. Es el primer lector de `buyer_tracking_events` desde la tienda.
 *
 * Toda la logica vive aca y el controller queda fino, que es la convencion del repo (21
 * helpers estaticos en esta carpeta). El molde —guardas en orden, hasTable memoizado,
 * try/catch que loguea y no rompe la navegacion— es BuyerTrackingHelper, el otro lado de
 * esta misma funcionalidad.
 *
 * --------------------------------------------------------------------------------------
 * 🔴 EL SCOPE POR COMERCIO NO ES OPCIONAL
 * --------------------------------------------------------------------------------------
 * Toda consulta de esta clase filtra por el comercio (`orders.user_id`,
 * `buyer_tracking_events.user_id`, `articles.user_id`), y no es defensa de mas: hay bases
 * compartidas con DECENAS de comercios adentro —`u767360347_empresa` tiene 51—, donde los
 * pedidos y los articulos de todos viven en las mismas tablas. Sin ese filtro, la ficha de
 * un comercio le mostraria a sus compradores lo que se vende en el negocio de al lado, y
 * con los precios y el stock del otro. Es una fuga de datos, no un resultado feo.
 *
 * ⚠️ El comercio LLEGA POR PARAMETRO, no se adivina. Viene del `{commerce_id}` de la ruta,
 * que es el mismo que ya leen `Article::scopeCheckOnline()` y `scopeCheckStock()` via
 * `request()->commerce_id`, y el mismo camino por el que BuyerTrackingHelper::registrar()
 * recibe su `user_id`. NO sale de `config('app.USER_ID')`: esa clave no existe en
 * `config/app.php` de este repo (el `USER_ID` del `.env` no lo lee nadie), asi que leerla
 * daria null y las dos secciones quedarian vacias para siempre, en silencio.
 *
 * --------------------------------------------------------------------------------------
 * Silencioso para el comprador, NO invisible para el operador
 * --------------------------------------------------------------------------------------
 * Todo el cuerpo va dentro de try/catch: una recomendacion no puede romper —ni demorar— la
 * ficha de un producto. Lo que NO se hace es tragarse el error: un catch mudo convertiria
 * "hace tres semanas que no recomienda nada" en algo que nadie puede notar. La respuesta
 * HTTP es siempre 200 con la lista vacia; la falla queda en el log con contexto.
 */
class RecomendacionesHelper
{
    /** Tabla de eventos crudos. La crea `empresa-api`, la escribe BuyerTrackingHelper. */
    const TABLA_EVENTOS = 'buyer_tracking_events';

    /** Cuantos articulos devuelve cada seccion. Es el ancho de un carrusel, no un catalogo. */
    const MAX_RECOMENDADOS = 12;

    /**
     * Techo del paso 1 de vieronTambienCompraron(): visitantes distintos que miraron el
     * articulo. Ver el docblock de ese metodo — este numero es el que evita que un producto
     * popular se lleve puesta la ficha.
     */
    const MAX_VISITANTES = 500;

    /** Techo del paso 2: pedidos distintos de esos visitantes. */
    const MAX_PEDIDOS = 500;

    /**
     * Ventana hacia atras de las vistas. Es la misma que la retencion de los eventos crudos
     * de `tracking_buyers`: mas atras de eso no hay nada que leer, asi que pedirlo solo
     * agrandaria el rango del indice sin agregar un solo dato.
     */
    const DIAS_DE_VENTANA = 90;

    /**
     * Cuanto vive la lista de ids en la cache, en minutos.
     *
     * Diez y no cero porque esta consulta corre en CADA VISTA DE FICHA: sin cache, un
     * producto popular la paga una vez por visitante. Y diez y no un dia porque una
     * recomendacion tampoco necesita ser de este segundo — lo que se cachea es "que se
     * compro junto con esto", que cambia de a un pedido por vez.
     *
     * Se cachea la LISTA DE IDS y no los articulos ya hidratados, a proposito: el precio,
     * el stock y la visibilidad los resuelve ArticleHelper::checkPriceTypes() contra el
     * comprador logueado, y guardar eso diez minutos serviria el precio de un comprador a
     * otro. Los ids no dependen de quien mira.
     */
    const MINUTOS_DE_CACHE = 10;

    /**
     * Memoria de "existe la tabla de eventos", por request. Tres estados y los tres
     * importan: null = todavia no se resolvio, true/false = resuelto.
     *
     * @var bool|null
     */
    private static $hay_tabla = null;

    /**
     * "Quienes compraron este producto tambien compraron".
     *
     * UNA sola consulta, y puede serlo porque los dos extremos estan acotados por el propio
     * dato: `article_order` tiene una fila por linea de pedido, no una por visita. El peso
     * es la cantidad de PEDIDOS distintos en los que los dos articulos viajaron juntos.
     *
     *   SELECT ao2.article_id, COUNT(DISTINCT ao2.order_id) AS peso
     *   FROM article_order ao1
     *   JOIN orders o          ON o.id = ao1.order_id AND o.user_id = :user_id
     *   JOIN article_order ao2 ON ao2.order_id = ao1.order_id AND ao2.article_id <> :article_id
     *   WHERE ao1.article_id = :article_id
     *   GROUP BY ao2.article_id
     *   ORDER BY peso DESC, ao2.article_id DESC
     *   LIMIT 12
     *
     * 🔴 `o.user_id = :user_id` es la unica razon por la que existe el join a `orders`: sin
     * el, en una base compartida esta consulta devuelve la co-compra de los otros cincuenta
     * comercios. Ver el docblock de la clase.
     *
     * El desempate por `ao2.article_id DESC` no es decorativo: sin un segundo criterio, dos
     * articulos con el mismo peso salen en el orden que se le antoje a MySQL y la seccion
     * se reordena sola entre dos vistas de la misma ficha.
     *
     * Esta consulta no toca `buyer_tracking_events`, asi que no necesita la guarda de tabla:
     * `article_order` y `orders` son el esquema base de cualquier tienda.
     *
     * @param mixed $article_id Articulo de la ficha.
     * @param mixed $user_id Comercio dueño de la tienda (el `{commerce_id}` de la ruta).
     * @return array|\Illuminate\Support\Collection Articulos hidratados, o [] si no hay nada.
     */
    public static function compraronTambienCompraron($article_id, $user_id)
    {
        $article_id = self::idPositivo($article_id);
        $user_id = self::idPositivo($user_id);

        if (is_null($article_id) || is_null($user_id)) {
            return [];
        }

        $ids = self::idsCacheados('compras', $article_id, $user_id, function () use ($article_id, $user_id) {
            return DB::table('article_order as ao1')
                ->join('orders as o', function ($join) use ($user_id) {
                    $join->on('o.id', '=', 'ao1.order_id')
                        ->where('o.user_id', '=', $user_id);
                })
                /*
                 * 🔴 Un pedido CANCELADO no es una compra, y el titulo de la seccion afirma
                 * que alguien compro. Sin esto, la co-compra se armaba con ventas que nunca
                 * se concretaron -y en un comercio que cancela seguido (falta de stock, pago
                 * rechazado) eso no es el borde, es la norma-.
                 *
                 * Se excluye por las DOS vias porque el esquema arrastra las dos: la columna
                 * vieja `orders.status` (enum, hoy no la escribe el checkout) y el
                 * `order_status_id` que si escribe OrderController. Los nombres de
                 * `order_statuses` son texto libre POR COMERCIO, asi que el match es por
                 * 'cancel' y cubre Cancelado/Cancelada/Canceled. Es una heuristica: un
                 * comercio que le ponga "Anulado" a su estado de cancelacion se cuela. Mejor
                 * eso que no filtrar nada.
                 *
                 * 'Sin confirmar' NO se excluye a proposito: es el estado con el que nace
                 * TODO pedido del checkout (OrderController), asi que sacarlo dejaria afuera
                 * las compras mas recientes, que son las que mas valen para recomendar.
                 */
                ->leftJoin('order_statuses as os', 'os.id', '=', 'o.order_status_id')
                ->where(function ($q) {
                    $q->whereNull('os.name')
                        ->orWhere('os.name', 'not like', '%cancel%');
                })
                ->where(function ($q) {
                    $q->whereNull('o.status')
                        ->orWhere('o.status', '<>', 'canceled');
                })
                ->join('article_order as ao2', function ($join) use ($article_id) {
                    $join->on('ao2.order_id', '=', 'ao1.order_id')
                        ->where('ao2.article_id', '<>', $article_id);
                })
                ->where('ao1.article_id', $article_id)
                ->groupBy('ao2.article_id')
                ->select('ao2.article_id')
                ->selectRaw('COUNT(DISTINCT ao2.order_id) as peso')
                ->orderByDesc('peso')
                ->orderByDesc('ao2.article_id')
                ->limit(self::MAX_RECOMENDADOS)
                ->pluck('article_id')
                ->all();
        });

        return self::hidratar($ids, 'compras', $article_id, $user_id);
    }

    /**
     * "Quienes vieron este producto tambien compraron".
     *
     * --------------------------------------------------------------------------------
     * 🔴 SON TRES PASOS EN PHP Y NO UNA CONSULTA CON DOS JOINS. LEE ESTO ANTES DE "SIMPLIFICARLO".
     * --------------------------------------------------------------------------------
     * La version de una sola consulta —`buyer_tracking_events` unida consigo misma por
     * `visitor_id` y despues a `article_order`— es mas corta y es exactamente la que no se
     * puede escribir aca: `buyer_tracking_events` es una tabla de TRAFICO, con una fila por
     * vista de producto de cada visitante. Un articulo popular de una tienda con movimiento
     * junta cientos de miles de filas en los 90 dias de retencion, y un self-join sin techo
     * sobre eso no tiene cota: MySQL materializa el producto de las dos patas antes de
     * poder agrupar nada. Y esto corre en la ficha de un producto, o sea en la pantalla mas
     * visitada de la tienda, justo cuando el articulo es popular.
     *
     * Partido en tres, cada paso tiene SU techo y el peor caso queda acotado de antemano:
     *
     *   1. Hasta MAX_VISITANTES visitantes DISTINTOS que vieron este articulo en los
     *      ultimos DIAS_DE_VENTANA dias, los mas recientes primero. Usa
     *      `bte_articulo_ocurrido_index` (article_id, occurred_at).
     *   2. Hasta MAX_PEDIDOS pedidos distintos que esos visitantes terminaron de comprar.
     *      Usa `bte_visitante_index` (visitor_id).
     *   3. Agregado sobre `article_order` de esos pedidos, excluyendo el articulo de la
     *      ficha. Como maximo 500 pedidos, o sea un rango chico y conocido.
     *
     * "Los mas recientes primero" del paso 1 tampoco es un detalle: si hay que quedarse con
     * 500 de 200.000 visitantes, los de esta semana dicen mucho mas sobre que se vende hoy
     * que los de hace tres meses.
     *
     * Si cualquier paso vuelve vacio se corta ahi y se devuelve []: no tiene sentido pagar
     * el paso siguiente con una lista vacia.
     *
     * @param mixed $article_id Articulo de la ficha.
     * @param mixed $user_id Comercio dueño de la tienda (el `{commerce_id}` de la ruta).
     * @return array|\Illuminate\Support\Collection Articulos hidratados, o [] si no hay nada.
     */
    public static function vieronTambienCompraron($article_id, $user_id)
    {
        $article_id = self::idPositivo($article_id);
        $user_id = self::idPositivo($user_id);

        if (is_null($article_id) || is_null($user_id)) {
            return [];
        }

        /**
         * 🔴 Guarda 1, y va ANTES de cualquier acceso a la base. Es la compatibilidad hacia
         * atras del contrato con `empresa-api`, y protege el escenario NORMAL, no el borde.
         *
         * El esquema de `buyer_tracking_events` lo crea `empresa-api` y llega a la base de
         * un cliente por release; la tienda, en cambio, la despliega Lucas a mano cuando
         * quiere. O sea que la tienda va a estar desplegada durante DIAS O SEMANAS contra
         * una base que todavia no tiene esa tabla — es lo esperado, no un accidente. Sin
         * esta guarda, cada ficha de producto de cada comprador tira una excepcion.
         *
         * Tampoco alcanza con dejarselo al try/catch de abajo: eso "andaria", pero pagando
         * una excepcion por ficha y llenando el log de ruido durante semanas, hasta tapar
         * las fallas de verdad. Mismo razonamiento que BuyerTrackingHelper::hayTabla().
         */
        if (!self::hayTablaDeEventos()) {
            return [];
        }

        $ids = self::idsCacheados('vistas', $article_id, $user_id, function () use ($article_id, $user_id) {
            return self::idsPorVistas($article_id, $user_id);
        });

        return self::hidratar($ids, 'vistas', $article_id, $user_id);
    }

    /**
     * Los tres pasos de vieronTambienCompraron(), ya sin las guardas.
     *
     * @param int $article_id
     * @param int $user_id
     * @return array Ids de articulo ordenados por peso, a lo sumo MAX_RECOMENDADOS.
     */
    private static function idsPorVistas($article_id, $user_id)
    {
        $desde = Carbon::now()->subDays(self::DIAS_DE_VENTANA)->format('Y-m-d H:i:s');

        /**
         * Paso 1. `GROUP BY visitor_id` + `MAX(occurred_at)` y no `DISTINCT` + `ORDER BY
         * occurred_at`: esa segunda forma la rechaza MySQL 8 (no se puede ordenar por una
         * columna que el DISTINCT no selecciona), y ademas hace falta UN valor por visitante
         * para poder ordenarlos — la ultima vez que lo miro.
         */
        $visitantes = DB::table(self::TABLA_EVENTOS)
            ->where('article_id', $article_id)
            ->where('occurred_at', '>=', $desde)
            ->where('user_id', $user_id)
            ->where('event_type', 'product_view')
            ->groupBy('visitor_id')
            ->select('visitor_id')
            ->selectRaw('MAX(occurred_at) as ultima_vista')
            ->orderByDesc('ultima_vista')
            ->limit(self::MAX_VISITANTES)
            ->pluck('visitor_id')
            ->all();

        if (empty($visitantes)) {
            return [];
        }

        /**
         * Paso 2. `order_id` NO NULO: `checkout_complete` lo trae, pero la columna admite
         * null y un evento viejo o mal formado sin pedido no identifica ninguna compra.
         * Mismo criterio que el paso 1 para quedarse con los distintos y los mas recientes.
         */
        $pedidos = DB::table(self::TABLA_EVENTOS)
            ->whereIn('visitor_id', $visitantes)
            ->where('user_id', $user_id)
            ->where('event_type', 'checkout_complete')
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->select('order_id')
            ->selectRaw('MAX(occurred_at) as ultima_compra')
            ->orderByDesc('ultima_compra')
            ->limit(self::MAX_PEDIDOS)
            ->pluck('order_id')
            ->all();

        if (empty($pedidos)) {
            return [];
        }

        /**
         * Paso 3. El desempate por `article_id DESC` es el mismo de la co-compra y por el
         * mismo motivo: sin un segundo criterio, dos articulos con el mismo peso se
         * reordenan solos entre dos vistas de la misma ficha.
         */
        return DB::table('article_order')
            ->whereIn('order_id', $pedidos)
            ->where('article_id', '<>', $article_id)
            ->groupBy('article_id')
            ->select('article_id')
            ->selectRaw('COUNT(DISTINCT order_id) as peso')
            ->orderByDesc('peso')
            ->orderByDesc('article_id')
            ->limit(self::MAX_RECOMENDADOS)
            ->pluck('article_id')
            ->all();
    }

    /**
     * Devuelve la lista de ids, de la cache si esta, y si no corriendo la consulta.
     *
     * Envuelve TODO el camino en el try/catch de la clase: la consulta, la cache y lo que
     * venga. Una recomendacion que falla devuelve [] —la seccion no se dibuja— pero deja
     * rastro en el log.
     *
     * 🔴 La lista VACIA tambien se cachea, y es a proposito: "este articulo no tiene
     * co-compra" es el caso mas frecuente de todos (una tienda recien instalada, un articulo
     * nuevo, un comercio sin la extension `tracking_buyers`). Si el vacio no se cacheara,
     * justamente el caso comun pagaria la consulta entera en cada vista de ficha.
     *
     * La cache se lee y se escribe en su propio try/catch, como ya hace
     * ZipnovaCotizadorService: si el store esta roto, la recomendacion se calcula igual y
     * queda el aviso. Una cache caida tiene que degradar a "mas lento", nunca a "vacio".
     *
     * @param string $tipo 'compras' o 'vistas'. Va en la clave: son dos listas distintas.
     * @param int $article_id
     * @param int $user_id
     * @param \Closure $calcular Devuelve el array de ids.
     * @return array
     */
    private static function idsCacheados($tipo, $article_id, $user_id, $calcular)
    {
        /* El comercio va en la clave por lo mismo que va en las consultas: el resultado esta
           scopeado por comercio, y dos tiendas del mismo hosting pueden terminar compartiendo
           el store de cache (el mismo directorio de `file`, el mismo prefijo de redis). Una
           clave que nombre solo el articulo le serviria a un comercio la lista del otro. */
        $clave = 'recomendaciones:'.$tipo.':'.$user_id.':'.$article_id;

        try {
            $cacheados = Cache::get($clave);
        } catch (\Throwable $e) {
            $cacheados = null;
            self::registrarFalla($e, $tipo, $article_id, $user_id, 'no se pudo leer la cache');
        }

        if (is_array($cacheados)) {
            return $cacheados;
        }

        try {
            $ids = $calcular();
        } catch (\Throwable $e) {
            self::registrarFalla($e, $tipo, $article_id, $user_id, 'fallo la consulta');

            return [];
        }

        try {
            Cache::put($clave, $ids, self::MINUTOS_DE_CACHE * 60);
        } catch (\Throwable $e) {
            self::registrarFalla($e, $tipo, $article_id, $user_id, 'no se pudo escribir la cache');
        }

        return $ids;
    }

    /**
     * Trae los articulos de esos ids, con el MISMO tratamiento que
     * ArticleController@similars.
     *
     * 🔴 `withAll()` + `checkOnline()` + `checkStock()` + `ArticleHelper::checkPriceTypes()`
     * no son una copia por comodidad: son las reglas con las que el resto de la tienda
     * decide que precio se muestra, que descuento aplica y que articulo se puede ver. Si
     * esta seccion las salteara, mostraria articulos apagados, sin stock o con un precio
     * que no es el que ese comprador paga — en la misma pantalla en la que el de al lado
     * los muestra bien.
     *
     * `where('user_id', $user_id)` es el ultimo cierre del scope por comercio, y es el que
     * de verdad cierra la puerta: ni checkOnline ni checkStock filtran por comercio, asi que
     * sin esta linea un id que se hubiera colado desde otro negocio saldria publicado igual.
     *
     * El orden por peso lo pone PHP porque la consulta no lo conserva: un `WHERE id IN (...)`
     * devuelve las filas en el orden que quiera el motor, no en el del IN. Y el orden ES el
     * dato: el primero del carrusel es el que mas veces se compro junto con este.
     *
     * @param array $ids Ids de articulo, ya ordenados por peso.
     * @param string $tipo 'compras' o 'vistas'. Solo para el log.
     * @param int $article_id Articulo de la ficha. Solo para el log.
     * @param int $user_id
     * @return array|\Illuminate\Support\Collection
     */
    private static function hidratar($ids, $tipo, $article_id, $user_id)
    {
        if (!is_array($ids) || empty($ids)) {
            return [];
        }

        try {
            $articles = Article::whereIn('id', $ids)
                ->where('user_id', $user_id)
                ->withAll()
                ->checkOnline()
                ->checkStock()
                ->get();

            $posiciones = array_flip(array_values($ids));

            $articles = $articles->sortBy(function ($article) use ($posiciones) {
                return array_key_exists($article->id, $posiciones)
                    ? $posiciones[$article->id]
                    : PHP_INT_MAX;
            })->values();

            if ($articles->isEmpty()) {
                return [];
            }

            return ArticleHelper::checkPriceTypes($articles);
        } catch (\Throwable $e) {
            self::registrarFalla($e, $tipo, $article_id, $user_id, 'fallo al traer los articulos');

            return [];
        }
    }

    /**
     * ¿Existe la tabla de eventos? Memoizado por request.
     *
     * 🔴 En consola NO se memoiza, y ese es justamente el punto de la memoria. "Por request"
     * se cumple en PHP-FPM porque el proceso se recicla entre requests; un proceso de consola
     * no, y ahi el estatico se convierte en un cache persistente que sirve una respuesta
     * vieja. Mismo criterio (y mismo remedio) que BuyerTrackingHelper::hayTabla().
     *
     * @return bool
     */
    private static function hayTablaDeEventos()
    {
        if (is_null(self::$hay_tabla) || app()->runningInConsole()) {
            try {
                self::$hay_tabla = Schema::hasTable(self::TABLA_EVENTOS);
            } catch (\Throwable $e) {
                /* Si no se puede ni preguntar por el esquema, la base esta en un estado en
                   el que esta seccion no tiene nada que hacer. Se anota y se sigue sin ella. */
                self::registrarFalla($e, 'vistas', 0, 0, 'no se pudo consultar el esquema de '.self::TABLA_EVENTOS);

                self::$hay_tabla = false;
            }
        }

        return self::$hay_tabla;
    }

    /**
     * Deja constancia de una falla, con contexto suficiente para diagnosticarla y sin un
     * solo dato del comprador.
     *
     * El try anidado no es decorativo: este metodo es lo ultimo que corre antes de que la
     * excepcion se de por atendida, asi que un logger roto no puede ser la via por la que
     * termine escapando igual. Mismo criterio que BuyerTrackingHelper::registrarFalla().
     *
     * @param \Throwable $e
     * @param string $tipo
     * @param int $article_id
     * @param int $user_id
     * @param string $que Que se estaba haciendo cuando fallo.
     * @return void
     */
    private static function registrarFalla($e, $tipo, $article_id, $user_id, $que)
    {
        try {
            Log::warning('RecomendacionesHelper: '.$que.'.', [
                'tipo'       => $tipo,
                'article_id' => $article_id,
                'user_id'    => $user_id,
                'excepcion'  => get_class($e),
                'mensaje'    => $e->getMessage(),
                'archivo'    => $e->getFile().':'.$e->getLine(),
            ]);
        } catch (\Throwable $ignorada) {
            /* Si hasta el logger esta roto, no queda nada mas que hacer: lo que no puede
               pasar es que la ficha del producto se rompa por una recomendacion. */
        }
    }

    /**
     * Id valido (entero > 0) o null.
     *
     * El 0 y los negativos van a null a proposito: no son ids, son alguien mandando basura
     * por la URL. Mismo criterio que BuyerTrackingHelper::idPositivo().
     *
     * @param mixed $valor
     * @return int|null
     */
    private static function idPositivo($valor)
    {
        if (!is_numeric($valor)) {
            return null;
        }

        $entero = (int) $valor;

        return $entero > 0 ? $entero : null;
    }
}
