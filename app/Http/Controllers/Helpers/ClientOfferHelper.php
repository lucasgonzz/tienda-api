<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\CommerceHelper;
use App\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Oferta personalizada del comprador logueado (mision promocion-personalizada-tienda).
 *
 * 🔴 ESTE ES EL UNICO PUNTO DEL REPO QUE TOCA `client_offers` / `client_offer_ranges`.
 * Si aparece un segundo lugar leyendo esas tablas, el contrato con `empresa-api` pasa a estar
 * decidido en dos lados con dos criterios, que es exactamente la clase de error que este
 * helper existe para evitar.
 *
 * --------------------------------------------------------------------------------------
 * El contrato con `empresa-api` NO es un endpoint: es la base compartida
 * --------------------------------------------------------------------------------------
 * `empresa` (el ERP) y `tienda` (el ecommerce) corren sobre la MISMA base del cliente.
 * `empresa-api` crea el esquema —el deploy de la tienda no corre `migrate`, este repo ni
 * siquiera tiene `database/migrations`— y aca solo se LEE.
 *
 * Las dos queries van textuales del docblock de
 * `2026_08_17_100200_create_client_offers_table.php` de `empresa-api`, no reinventadas:
 *
 *   -- 1) Las ofertas vigentes del comprador logueado, para los articulos de la pagina
 *   SELECT co.id, co.article_id, co.tipo_descuento, co.porcentaje, co.desde, co.hasta
 *   FROM client_offers co
 *   WHERE co.user_id  = :user_id                    -- el comercio
 *     AND co.client_id = :comercio_city_client_id   -- buyers.comercio_city_client_id de la SESION
 *     AND co.estado   = 'activa'
 *     AND co.desde   <= CURDATE()
 *     AND co.hasta   >= CURDATE()
 *     AND co.article_id IN (:article_ids)
 *
 *   -- 2) Los tramos, SOLO para las ofertas de tipo 'cantidad'
 *   SELECT r.client_offer_id, r.min, r.max, r.porcentaje
 *   FROM client_offer_ranges r
 *   WHERE r.client_offer_id IN (:offer_ids)
 *   ORDER BY r.min ASC
 *
 * El WHERE de la primera es identico al del contrato (lo unico agregado en el SELECT son
 * `desde` y `hasta`, para poder mostrarle al comprador hasta cuando le vale). Usa el indice
 * a medida `client_offers_user_client_article_estado_index`: igualdad en `user_id` y
 * `client_id`, `IN` en `article_id`, `estado` de cuarta.
 *
 * `CURDATE()` va por whereRaw a proposito: la vigencia la decide el reloj de MySQL, que es
 * el mismo que usa el ERP para generar y vencer las ofertas. Mandar una fecha armada en PHP
 * meteria un segundo reloj en la misma decision.
 *
 * --------------------------------------------------------------------------------------
 * Las reglas del contrato que NO se negocian
 * --------------------------------------------------------------------------------------
 * - El porcentaje es INVARIANTE al IVA y a los `sale_taxes`: se aplica como
 *   `* (1 - porcentaje/100)` sobre el precio YA resuelto por checkPriceTypes(). La tienda no
 *   recalcula costos, margenes ni la cascada de precios del ERP. Ese es el punto del diseño.
 * - La vigencia la dan las FECHAS, no `estado`. Una oferta pasada de fecha con
 *   `estado = 'activa'` NO aplica (el barrido de higiene de `empresa-api` puede no haber
 *   corrido todavia). Por eso la query filtra por las dos cosas.
 * - `porcentaje` es NULL cuando `tipo_descuento = 'cantidad'`. Esta asi a proposito: el
 *   numero vive en los tramos y NADIE lo lee en ese caso.
 * - `max = NULL` en el ultimo tramo significa SIN TECHO. No convertirlo a un numero grande.
 * - Sin comprador logueado, o con un comprador sin `comercio_city_client_id`, no hay
 *   client_id -> no hay oferta. Intencional: el anonimo nunca ve una personalizada.
 *
 * --------------------------------------------------------------------------------------
 * El orden de las guardas, y por que es ESE
 * --------------------------------------------------------------------------------------
 * Molde: BuyerTrackingHelper de este mismo repo. Primero lo que no toca la base, la base al
 * final. `aplicar()` corre en CADA pagina de la tienda que muestre un precio (los 12
 * llamadores de ArticleHelper::checkPriceTypes), asi que cada query de mas se paga en todas.
 *
 *   1. Coleccion vacia                                        0 queries
 *   2. Comprador logueado (ya resuelto en el request)          0 queries nuevas
 *   3. `comercio_city_client_id` — el ATRIBUTO, no la relacion 0 queries
 *   4. `user_id` del primer articulo                           0 queries
 *   5. 🔴 self::hayTablas() — Schema::hasTable MEMOIZADO       1 vez por proceso
 *   6. Juntar los article_ids recorriendo la coleccion         0 queries
 *   7. Query 1 (ofertas), UNA sola, con whereIn                1 query
 *   8. Query 2 (rangos), SOLO si hay alguna 'cantidad'         0 o 1 query
 *   9. Loop de articulos, todo en memoria                      0 queries
 *
 * 🔴 SI VENIS A SIMPLIFICAR ESTE HELPER, LEE ESTO PRIMERO.
 *
 * El `Schema::hasTable` de la guarda 5 va MEMOIZADO en una static, ANTES de cualquier acceso
 * a las tablas, y JAMAS adentro del loop de articulos. Los tres detalles importan:
 *
 *   - ANTES de cualquier acceso, porque el esquema lo crea `empresa-api` y no llega a la base
 *     de un cliente hasta que esa mision se mergee y salga por release, mientras que la tienda
 *     la despliega Lucas a mano cuando quiere. O sea que la tienda va a estar desplegada
 *     DIAS O SEMANAS contra una base sin estas tablas: es el estado NORMAL de hoy, no el borde.
 *     Sin la guarda, cada pagina de la tienda —cada listado, cada ficha, cada carrito— tira una
 *     excepcion. No alcanza con dejarselo al try/catch de afuera: eso "andaria", pero pagando
 *     una excepcion por pagina y llenando el log de ruido durante semanas, hasta tapar las
 *     fallas de verdad.
 *   - MEMOIZADO, porque `Schema::hasTable` es una consulta al information_schema y esto corre
 *     en cada request de cada visitante.
 *   - NUNCA adentro del loop, porque el loop recorre los articulos de la pagina: meterlo ahi
 *     convierte una consulta por proceso en una por articulo, y el costo pasa a crecer con el
 *     tamaño del catalogo mostrado. Hay un test que lo fija midiendo con DB::listen() que con
 *     1 articulo y con 50 se hace la misma cantidad de queries.
 *
 * --------------------------------------------------------------------------------------
 * Silencioso para el comprador, NO invisible para el operador
 * --------------------------------------------------------------------------------------
 * Todo el cuerpo de los tres metodos publicos va en try/catch y devuelve lo que recibio: una
 * oferta que falla no puede romper la navegacion ni el checkout. Lo que NO se hace es
 * tragarse el error: un catch mudo convertiria "hace tres semanas que no se aplica ninguna
 * oferta" en algo que nadie puede notar (clase "medicion que falla y devuelve un valor
 * tranquilizador", APRENDER_NO_PARCHEAR.md). Las fallas van a `warning`; la tabla ausente va
 * a `info` UNA VEZ por proceso, porque es un estado previsto del despliegue y no una falla.
 */
class ClientOfferHelper
{
    /** Tabla de ofertas activas. La crea `empresa-api`. */
    const TABLA = 'client_offers';

    /** Tramos por cantidad de una oferta. La crea `empresa-api`. */
    const TABLA_RANGOS = 'client_offer_ranges';

    /** Descuento de un solo porcentaje, en `client_offers.porcentaje`. */
    const TIPO_UNIDAD = 'unidad';

    /** Descuento por tramos: el porcentaje vive en `client_offer_ranges`. */
    const TIPO_CANTIDAD = 'cantidad';

    /** Unico estado que la tienda mira. 'vencida' y 'cancelada' no aplican nunca. */
    const ESTADO_ACTIVA = 'activa';

    /**
     * Extension que YA pisa el precio por su cuenta con sus propios rangos.
     *
     * Con esta prendida, ArticleHelper::checkPriceTypes() entra en set_ranges() y ni siquiera
     * setea `final_price`, y el SPA ignora `final_price` y usa `article.ranges[].price`.
     * Componer los dos mecanismos sin haberlo medido es cambiar precios en tiendas ya
     * publicadas. Ver comercioTieneRangosPorCantidad().
     */
    const SLUG_RANGOS_POR_CANTIDAD_VENDIDA = 'lista_de_precios_por_rango_de_cantidad_vendida';

    /**
     * Memoria de "existen las dos tablas", por proceso. Tres estados y los tres importan:
     * null = todavia no se resolvio, true/false = resuelto.
     *
     * @var bool|null
     */
    private static $hay_tablas = null;

    /**
     * Avisos de configuracion ya dados en este proceso, indexados por clave. Ver avisarUnaVez.
     *
     * @var array
     */
    private static $avisos = [];

    /**
     * Memoria de "este comercio tiene la extension de rangos por cantidad vendida", indexada
     * por user_id. Ver comercioTieneRangosPorCantidad().
     *
     * @var array
     */
    private static $extenciones = [];

    /**
     * Memoria de las ofertas vigentes de un comprador, indexada por "user_id:client_id".
     * Es lo que hace que un carrito de N lineas cueste una query y no N. Ver
     * ofertasDelComprador().
     *
     * @var array
     */
    private static $ofertas_por_comprador = [];

    /**
     * Cuelga la oferta personalizada del comprador logueado sobre los articulos, y aplica el
     * descuento cuando corresponde. Nunca tira: devuelve la misma coleccion que recibio.
     *
     * @param mixed $articles Coleccion, paginador o array de articulos ya pasados por checkPriceTypes.
     * @return mixed Los mismos articulos, con oferta_personalizada y precio_sin_oferta cuando aplica.
     */
    public static function aplicar($articles)
    {
        try {
            /* Guarda 1: sin articulos no hay nada que hacer, y sin una sola query. */
            if (!is_countable($articles) || count($articles) == 0) {
                return $articles;
            }

            /* Guardas 2 y 3: quien es el comprador y de que cliente del ERP es. Cero queries
               nuevas: el guard ya esta resuelto en el request y `comercio_city_client_id` es
               un ATRIBUTO de `buyers`, no la relacion (leer la relacion seria una query por
               pagina para responder algo que ya esta en la fila). */
            list($buyer_id, $client_id) = self::buyerYCliente();

            if (is_null($client_id)) {
                return $articles;
            }

            /* Guarda 4: el comercio dueño de la instancia. Sale del primer articulo, igual que
               en checkPriceTypes(). */
            $user_id = self::userIdDe($articles);

            if (is_null($user_id)) {
                return $articles;
            }

            /**
             * Guarda 5. 🔴 LA MAS IMPORTANTE DE TODO EL HELPER, y protege el escenario NORMAL
             * y no el borde: hoy la base de todo cliente real NO tiene estas tablas. Va aca,
             * antes de cualquier acceso, memoizada, y NUNCA adentro del loop de la guarda 9.
             * El razonamiento largo esta en el docblock de la clase.
             */
            if (!self::hayTablas()) {
                self::avisarUnaVez(
                    'tablas',
                    'ClientOfferHelper: la tabla '.self::TABLA.' no existe todavia en esta base. '
                    .'No hay ofertas personalizadas hasta que llegue el esquema de empresa-api.'
                );

                return $articles;
            }

            /* Guarda 6: los ids de la pagina, en una sola pasada. */
            $article_ids = self::idsDe($articles);

            if (empty($article_ids)) {
                return $articles;
            }

            /* Guarda 7: UNA query para toda la pagina, con whereIn. */
            $ofertas = self::ofertasVigentes($user_id, $client_id, $article_ids);

            if (empty($ofertas)) {
                return $articles;
            }

            /* Guarda 8: los tramos, SOLO si hay alguna oferta de tipo 'cantidad'. Si todas son
               'unidad' esta query no se corre nunca. */
            $rangos = self::rangosDeLasOfertasPorCantidad($ofertas);

            /* Se resuelve una sola vez para toda la pagina y recien aca: si el comercio no
               tiene ninguna oferta vigente para este comprador —el caso mas frecuente— no se
               paga ni esta consulta. */
            $con_extencion_de_rangos = self::comercioTieneRangosPorCantidad($user_id);

            /* Guarda 9: el loop, todo en memoria. Ni una query aca adentro. */
            foreach ($articles as $article) {
                if (!is_object($article) || !isset($article->id)) {
                    continue;
                }

                $article_id = (int) $article->id;

                if (!array_key_exists($article_id, $ofertas)) {
                    continue;
                }

                $oferta = $ofertas[$article_id];

                if ($oferta['tipo_descuento'] === self::TIPO_CANTIDAD) {
                    $oferta['rangos'] = array_key_exists($oferta['id'], $rangos)
                        ? $rangos[$oferta['id']]
                        : [];
                }

                $article->oferta_personalizada = self::colgarEnElArticulo(
                    $article,
                    $oferta,
                    $con_extencion_de_rangos
                );
            }

            return $articles;
        } catch (\Throwable $e) {
            self::registrarFalla($e);

            return $articles;
        }
    }

    /**
     * Los articulos del comercio con una oferta vigente para el comprador logueado.
     * Devuelve [] cuando no hay sesion, no hay client_id, no estan las tablas o no hay ofertas.
     *
     * @param int $user_id Comercio dueño de la instancia.
     * @return array Ids de articulo con oferta vigente.
     */
    public static function idsDeArticulosConOferta($user_id)
    {
        try {
            /* Mismas guardas que aplicar() y en el mismo orden, salteando las dos que aca no
               tienen sentido (no hay coleccion que mirar ni ids que juntar). */
            list($buyer_id, $client_id) = self::buyerYCliente();

            if (is_null($client_id)) {
                return [];
            }

            $user_id = self::idPositivo($user_id);

            if (is_null($user_id)) {
                return [];
            }

            if (!self::hayTablas()) {
                self::avisarUnaVez(
                    'tablas',
                    'ClientOfferHelper: la tabla '.self::TABLA.' no existe todavia en esta base. '
                    .'No hay ofertas personalizadas hasta que llegue el esquema de empresa-api.'
                );

                return [];
            }

            /* Misma query 1 pero SIN el `article_id IN (...)`: sigue entrando por el mismo
               indice por prefijo (user_id, client_id) con `estado` resuelto adentro del
               indice. No se inventa ningun indice nuevo ni se pide uno. */
            $ofertas = self::ofertasVigentes($user_id, $client_id, null);

            return array_keys($ofertas);
        } catch (\Throwable $e) {
            self::registrarFalla($e);

            return [];
        }
    }

    /**
     * Precio de UNA LINEA del carrito con la oferta personalizada aplicada, resuelta por el
     * servidor. Devuelve null cuando no hay oferta vigente o cuando el payload no trae la base
     * explicita (ver abajo el por que de null y no un recalculo).
     *
     * 🔴 LO QUE ESTE METODO LE SACA AL CLIENTE ES EL DESCUENTO, NO EL PRECIO BASE.
     *
     * El PORCENTAJE y la VIGENCIA dejan de venir del navegador: se releen de la base. Nunca se
     * confia en el objeto `oferta_personalizada` que el SPA reenvia con el carrito — ahi es
     * donde alguien podria inventarse un 90%, resucitar una oferta vencida o usar la de otro
     * cliente, y eso termina en un pedido confirmado, que en esta base es una venta contra la
     * cuenta corriente de una persona.
     *
     * La BASE del precio la sigue mandando el SPA, igual que hoy manda `final_price`.
     * Reconstruirla en el servidor exigiria duplicar los cuatro casos de
     * ArticleHelper::checkPriceTypes() adentro de un helper de carrito, y una copia que se
     * desincronice cobra distinto segun el camino — peor que el agujero que arregla. "El
     * cliente fija el precio base" es un agujero PREEXISTENTE y su arreglo es otra mision.
     *
     * 🔴 Y CUANDO LA OFERTA YA NO EXISTE, EL PRECIO ES LA BASE — no `final_price`.
     *
     * Este es el punto que la primera version de este metodo tenia mal, y el defecto era de
     * plata: devolvia null al no encontrar oferta vigente, y `CartHelper::get_price()` caia
     * entonces a `$article['final_price']`, que en una oferta 'unidad' es EL PRECIO QUE ESTE
     * MISMO SERVIDOR dejo ya descontado en la respuesta anterior. Resultado: el comerciante
     * cancelaba la promocion (o pasaba la medianoche del `hasta`) y la pestaña que habia
     * quedado abierta la seguia cobrando, sin que nadie manipulara nada y sin ninguna señal.
     *
     * Por eso null significa una sola cosa: "este camino no es mio, segui por donde ibas".
     * Se devuelve null nada mas en tres casos, y en los tres el precio de la linea es
     * exactamente el de master:
     *   - el payload no trae `precio_sin_oferta` (SPA viejo, o articulo que nunca tuvo oferta),
     *   - el comercio tiene la extension de rangos por cantidad vendida (el precio lo resuelve
     *     get_price_range con article.ranges[].price, que es otro mecanismo entero),
     *   - el articulo tiene el precio pausado (no hay importe que descontar).
     * En todo lo demas —sin sesion, sin cliente del ERP, sin las tablas, sin oferta para ese
     * articulo, oferta vencida, cancelada, de otro cliente, de otro comercio, porcentaje
     * inservible o tramo que no encaja— la respuesta es LA BASE.
     *
     * La unica excepcion es el catch: una excepcion no es evidencia de que la oferta no valga,
     * y cobrarle al comprador mas de lo que vio en pantalla por una falla nuestra es peor que
     * seguir confiando en `final_price` como ya hace el resto del carrito.
     *
     * @param array $article Articulo tal cual lo mando el SPA (con 'id', 'amount', 'precio_sin_oferta').
     * @param int $user_id Comercio dueño del CARRITO (no el del payload: ver CartHelper::attachArticles).
     * @return float|null
     */
    public static function precioDeLinea($article, $user_id)
    {
        try {
            if (!is_array($article)) {
                return null;
            }

            /**
             * 🔴 Guarda 1, y la que cierra el riesgo de DOBLE DESCUENTO.
             *
             * Sin la base explicita NO se toca nada. Un SPA que no manda `precio_sin_oferta`
             * manda un `final_price` que YA puede venir descontado (caso 'unidad', donde el
             * servidor piso final_price en checkPriceTypes), y recalcular ahi seria aplicar el
             * descuento dos veces. Devolver null deja exactamente el precio de hoy.
             *
             * Un SPA viejo, un payload viejo guardado en localStorage, o cualquier camino que
             * no pase por la API nueva, caen aca y cobran lo mismo que en master.
             */
            if (!isset($article['precio_sin_oferta']) || !is_numeric($article['precio_sin_oferta'])) {
                return null;
            }

            $base = round((float) $article['precio_sin_oferta'], 2);

            /* Guarda 2: el comercio, que sale del CARRITO y no del payload. */
            $user_id = self::idPositivo($user_id);

            if (is_null($user_id)) {
                return null;
            }

            /* Guarda 3: con la extension de rangos por cantidad vendida prendida NO se toca
               ningun precio, ni aca ni en aplicar(): el precio de esa extension lo resuelve
               get_price_range() con `article.ranges[].price`, que es otro mecanismo entero.
               Devolver null deja que CartHelper::get_price() siga por esa rama, intacta. */
            if (self::comercioTieneRangosPorCantidad($user_id)) {
                return null;
            }

            /* Guarda 4: con el precio pausado no hay importe que descontar. Ese flujo ya esta
               resuelto y probado, y esta mision no lo toca. */
            if (isset($article['precio_pausado']) && $article['precio_pausado']) {
                return null;
            }

            /*
             * 🔴 DE ACA PARA ABAJO DECIDE EL SERVIDOR, Y NO HAY MAS `return null`.
             *
             * El payload declaro una base, o sea que este servidor le aplico una oferta a esta
             * linea en algun momento. Entonces el precio lo vuelve a decidir el: con oferta
             * vigente, la base descontada; sin ella, LA BASE. Nunca `final_price`, que es el
             * numero que quedo en el navegador y que puede ser el descontado de cuando la
             * oferta valia. Ver el docblock de arriba: ahi esta el defecto que esto arregla.
             */

            /* El comprador de la SESION. El client_id jamas sale del payload ni de la URL. */
            list($buyer_id, $client_id) = self::buyerYCliente();

            if (is_null($client_id)) {
                return $base;
            }

            if (!self::hayTablas()) {
                self::avisarUnaVez(
                    'tablas',
                    'ClientOfferHelper: la tabla '.self::TABLA.' no existe todavia en esta base. '
                    .'No hay ofertas personalizadas hasta que llegue el esquema de empresa-api.'
                );

                return $base;
            }

            $article_id = self::idPositivo(isset($article['id']) ? $article['id'] : null);

            if (is_null($article_id)) {
                return $base;
            }

            /* Las ofertas del comprador, releidas DE LA BASE y memoizadas para todo el carrito:
               un carrito de N lineas hace UNA query, no N. Ver ofertasDelComprador(). */
            $ofertas = self::ofertasDelComprador($user_id, $client_id);

            if (!array_key_exists($article_id, $ofertas)) {
                return $base;
            }

            $oferta = $ofertas[$article_id];

            if ($oferta['tipo_descuento'] === self::TIPO_CANTIDAD) {
                $porcentaje = self::porcentajeDelTramo(
                    $oferta['rangos'],
                    self::cantidadDeLinea($article)
                );

                /* Sin tramo que encaje —una cantidad por debajo del primero— no hay descuento:
                   se cobra la base, que es el precio de lista. */
                if (is_null($porcentaje)) {
                    return $base;
                }

                return self::aplicarPorcentaje($base, $porcentaje);
            }

            if ($oferta['tipo_descuento'] !== self::TIPO_UNIDAD) {
                return $base;
            }

            if (!self::porcentajeUsable($oferta['porcentaje'])) {
                return $base;
            }

            return self::aplicarPorcentaje($base, $oferta['porcentaje']);
        } catch (\Throwable $e) {
            self::registrarFalla($e);

            return null;
        }
    }

    /**
     * ¿Tiene sentido siquiera preguntar por ofertas en este request?
     *
     * Es la guarda barata para quien necesita decidir ANTES de cargar nada: hay comprador
     * logueado con cliente del ERP, y las dos tablas del contrato existen. Cuesta 0 queries sin
     * sesion o sin cliente (que es la mayoria de los compradores de la tienda) y la del
     * information_schema memoizada en el resto.
     *
     * La usa CartHelper::resincronizar_precios_de_oferta() para no cargar los articulos del
     * carrito en las bases donde este esquema todavia no llego — o sea, hoy, en todas.
     *
     * @return bool
     */
    public static function hayContrato()
    {
        try {
            list($buyer_id, $client_id) = self::buyerYCliente();

            if (is_null($client_id)) {
                return false;
            }

            return self::hayTablas();
        } catch (\Throwable $e) {
            self::registrarFalla($e);

            return false;
        }
    }

    /**
     * Descarta la memoria del proceso. La usan los tests, que crean y esconden las tablas.
     *
     * @return void
     */
    public static function olvidarMemoria()
    {
        self::$hay_tablas = null;
        self::$extenciones = [];
        self::$ofertas_por_comprador = [];

        /* Tambien los avisos: si no, el segundo caso del proceso no podria medir que se avisa. */
        self::$avisos = [];
    }

    /**
     * ¿Existen las dos tablas del contrato? Memoizado por proceso.
     *
     * 🔴 En consola NO se memoiza, y ese es justamente el punto de la memoria.
     *
     * "Por request" se cumple bajo PHP-FPM porque las estaticas se resetean al terminar el
     * script, aunque el worker siga vivo y atienda el request siguiente. Un proceso de consola
     * no termina entre casos: la suite corre todos en el mismo proceso, y ahi el
     * estatico se convierte en un cache persistente que sirve una respuesta vieja — arruinando
     * justo el test que verifica el camino de "las tablas no estan", que las esconde en
     * caliente con RENAME TABLE. Mismo criterio que BuyerTrackingHelper::hayTabla().
     *
     * Se piden las DOS: una base con `client_offers` y sin `client_offer_ranges` es un esquema
     * a medio llegar, y en ese estado la query 2 explotaria para toda oferta 'cantidad'.
     *
     * @return bool
     */
    private static function hayTablas()
    {
        if (is_null(self::$hay_tablas) || app()->runningInConsole()) {
            self::$hay_tablas = Schema::hasTable(self::TABLA) && Schema::hasTable(self::TABLA_RANGOS);
        }

        return self::$hay_tablas;
    }

    /**
     * El comprador logueado y su cliente del ERP, los dos de la SESION.
     *
     * 🔴 `comercio_city_client_id` se lee como ATRIBUTO y no por la relacion
     * `comercio_city_client`: la relacion dispara una query, y esto corre en cada pagina de la
     * tienda para responder algo que ya viene en la fila de `buyers`.
     *
     * Y sale de la sesion, NUNCA de la URL ni del payload: la oferta es de UN cliente del ERP,
     * asi que si el id viniera de afuera cualquiera podria pedir la oferta de otra persona.
     *
     * @return array{0:int|null,1:int|null} [buyer_id, client_id]
     */
    private static function buyerYCliente()
    {
        $buyer = Auth::guard('buyer')->user();

        if (is_null($buyer)) {
            /* Visitante anonimo: nunca ve una oferta personalizada. Intencional, y cortado
               antes de tocar la base. */
            return [null, null];
        }

        return [
            self::idPositivo(isset($buyer->id) ? $buyer->id : null),
            self::idPositivo(isset($buyer->comercio_city_client_id) ? $buyer->comercio_city_client_id : null),
        ];
    }

    /**
     * Query 1 del contrato: las ofertas vigentes, indexadas por article_id.
     *
     * Query builder y no Eloquent, a proposito: sin hidratar modelos y sin disparar eventos,
     * porque esto corre en cada pagina de la tienda de cada comprador con cliente del ERP.
     *
     * El indexado por article_id colapsa duplicados quedandose con uno solo. Es correcto: el
     * contrato garantiza EN CODIGO una sola oferta 'activa' por (comercio, cliente, articulo)
     * —el unique no existe en la base a proposito, para que el historial pueda convivir—, asi
     * que un duplicado seria un dato roto del ERP y no un caso a resolver aca.
     *
     * @param int $user_id
     * @param int $client_id
     * @param array|null $article_ids null = todas las del comprador (el endpoint del listado).
     * @return array Indexado por article_id.
     */
    private static function ofertasVigentes($user_id, $client_id, $article_ids = null)
    {
        $query = DB::table(self::TABLA)
                    ->where('user_id', $user_id)
                    ->where('client_id', $client_id)
                    ->where('estado', self::ESTADO_ACTIVA)
                    /* La vigencia la decide el reloj de MySQL, el mismo con el que el ERP
                       genera y vence las ofertas. Y filtra por fechas ADEMAS de por `estado`
                       porque el barrido de higiene de empresa-api puede no haber corrido. */
                    ->whereRaw('desde <= CURDATE()')
                    ->whereRaw('hasta >= CURDATE()');

        if (!is_null($article_ids)) {
            $query = $query->whereIn('article_id', $article_ids);
        }

        $filas = $query->get(['id', 'article_id', 'tipo_descuento', 'porcentaje', 'desde', 'hasta']);

        $ofertas = [];

        foreach ($filas as $fila) {
            $ofertas[(int) $fila->article_id] = [
                'id'              => (int) $fila->id,
                'tipo_descuento'  => $fila->tipo_descuento,
                /* PDO devuelve los decimal como string. Se castea aca y no en el SPA para que
                   el JSON del contrato lleve numeros y no textos. NULL cuando es 'cantidad':
                   se preserva el null a proposito, para que nadie lo lea por accidente. */
                'porcentaje'      => is_null($fila->porcentaje) ? null : (float) $fila->porcentaje,
                'desde'           => $fila->desde,
                'hasta'           => $fila->hasta,
                /* Arranca en false y lo sube colgarEnElArticulo() cuando la oferta SI se puede
                   aplicar a este articulo. Ojo con el matiz: en 'unidad' eso significa que
                   `final_price` quedo descontado; en 'cantidad' el precio no se toca —el
                   servidor no conoce la cantidad en un listado— y el true significa que la
                   base quedo en `precio_sin_oferta` y los tramos viajan para que el SPA
                   resuelva el precio. false = la oferta se muestra pero no toca ningun precio. */
                'precio_aplicado' => false,
                'rangos'          => [],
            ];
        }

        return $ofertas;
    }

    /**
     * Query 2 del contrato: los tramos, indexados por client_offer_id y ordenados por `min`.
     *
     * @param array $offer_ids
     * @return array Indexado por client_offer_id.
     */
    private static function rangosDe($offer_ids)
    {
        if (empty($offer_ids)) {
            return [];
        }

        $filas = DB::table(self::TABLA_RANGOS)
                    ->whereIn('client_offer_id', $offer_ids)
                    ->orderBy('min', 'ASC')
                    ->get(['client_offer_id', 'min', 'max', 'porcentaje']);

        $rangos = [];

        foreach ($filas as $fila) {
            $rangos[(int) $fila->client_offer_id][] = [
                'min'        => (int) $fila->min,
                /* 🔴 `max` NULL = SIN TECHO. No convertirlo a un numero grande: es la misma
                   convencion de category_price_type_ranges, que la tienda ya sabe leer. */
                'max'        => is_null($fila->max) ? null : (int) $fila->max,
                'porcentaje' => (float) $fila->porcentaje,
            ];
        }

        return $rangos;
    }

    /**
     * TODAS las ofertas vigentes del comprador en ese comercio, con sus tramos ya colgados,
     * memoizadas por proceso.
     *
     * Existe por el carrito. `precioDeLinea()` corre UNA VEZ POR LINEA, asi que sin memoria un
     * carrito de 8 articulos hacia 8 queries de ofertas mas 8 de tramos — justo lo contrario de
     * la disciplina que esta clase declara para el listado ("una query para toda la pagina").
     * Con esto, el carrito entero cuesta 1 query (y 1 mas si alguna oferta es 'cantidad').
     *
     * Se traen TODAS y no solo las de los articulos del carrito porque un comprador tiene un
     * puñado de ofertas —el motor de empresa-api genera pocas por cliente— y porque asi la
     * memoria sirve para cualquier linea que venga despues, sin volver a la base.
     *
     * En consola no se memoiza, por el mismo motivo que hayTablas(): la suite crea y borra
     * ofertas entre casos dentro del mismo proceso.
     *
     * @param int $user_id
     * @param int $client_id
     * @return array Indexado por article_id, con 'rangos' ya resuelto.
     */
    private static function ofertasDelComprador($user_id, $client_id)
    {
        $clave = $user_id.':'.$client_id;

        if (!array_key_exists($clave, self::$ofertas_por_comprador) || app()->runningInConsole()) {
            $ofertas = self::ofertasVigentes($user_id, $client_id, null);

            $rangos = self::rangosDeLasOfertasPorCantidad($ofertas);

            foreach ($ofertas as $article_id => $oferta) {
                if ($oferta['tipo_descuento'] === self::TIPO_CANTIDAD) {
                    $ofertas[$article_id]['rangos'] = array_key_exists($oferta['id'], $rangos)
                        ? $rangos[$oferta['id']]
                        : [];
                }
            }

            self::$ofertas_por_comprador[$clave] = $ofertas;
        }

        return self::$ofertas_por_comprador[$clave];
    }

    /**
     * Los tramos de las ofertas de tipo 'cantidad' que haya en el lote, o [] si no hay ninguna.
     *
     * Existe para que la guarda 8 sea explicita: si todas las ofertas de la pagina son
     * 'unidad', la segunda query NO SE CORRE NUNCA.
     *
     * @param array $ofertas Indexado por article_id.
     * @return array Indexado por client_offer_id.
     */
    private static function rangosDeLasOfertasPorCantidad($ofertas)
    {
        $offer_ids = [];

        foreach ($ofertas as $oferta) {
            if ($oferta['tipo_descuento'] === self::TIPO_CANTIDAD) {
                $offer_ids[] = $oferta['id'];
            }
        }

        return self::rangosDe($offer_ids);
    }

    /**
     * Aplica al articulo lo que corresponda y devuelve la oferta lista para colgarle.
     *
     * Las cuatro reglas de precio, todas medidas contra lo que ya hace el sistema:
     *
     * 1. Comercio CON la extension `lista_de_precios_por_rango_de_cantidad_vendida`:
     *    NO SE TOCA NINGUN PRECIO. Es el caso mas delicado de la mision. Esa extension ya pisa
     *    el precio por dos lados —en el servidor checkPriceTypes() entra en set_ranges() y ni
     *    siquiera setea `final_price`; en el SPA articlePriceEfectivo() ignora `final_price` y
     *    usa `article.ranges[].price`—, asi que componer los dos mecanismos sin haberlo medido
     *    es cambiar precios en tiendas ya publicadas. La oferta se MUESTRA (el comerciante la
     *    creo a proposito) con `precio_aplicado = false` y sin `precio_sin_oferta`. Limitacion
     *    conocida y deliberada: cero precios cambiados donde no se midio.
     * 2. `precio_pausado`: no hay importe que descontar. El SPA muestra el texto de
     *    configuracion en lugar del precio y bloquea el carrito; ese flujo ya esta resuelto y
     *    esta mision no lo toca.
     * 3. 'unidad': `precio_sin_oferta` = el precio de antes, `final_price` = el descontado.
     * 4. 'cantidad': `final_price` NO SE TOCA —el servidor no conoce la cantidad en un
     *    listado— y `precio_sin_oferta` queda con la misma cifra, para que el SPA tenga la
     *    base en un solo nombre. El descuento lo resuelve el tramo: en la ficha lo calcula el
     *    SPA y en el carrito lo calcula el servidor (precioDeLinea).
     *
     * @param object $article Se muta.
     * @param array $oferta
     * @param bool $con_extencion_de_rangos
     * @return array La oferta, con precio_aplicado ya resuelto.
     */
    private static function colgarEnElArticulo($article, $oferta, $con_extencion_de_rangos)
    {
        if ($con_extencion_de_rangos) {
            return $oferta;
        }

        if (isset($article->precio_pausado) && $article->precio_pausado) {
            return $oferta;
        }

        /* Sin un precio numerico no hay nada que descontar. Pasa con la extension de rangos
           (que no setea final_price) y con cualquier articulo sin precio resuelto. */
        if (!isset($article->final_price) || !is_numeric($article->final_price)) {
            return $oferta;
        }

        $base = round((float) $article->final_price, 2);

        if ($oferta['tipo_descuento'] === self::TIPO_CANTIDAD) {
            /* Una oferta 'cantidad' sin tramos no tiene descuento por ningun lado: se muestra
               pero no se anuncia como aplicada. */
            if (empty($oferta['rangos'])) {
                return $oferta;
            }

            $article->precio_sin_oferta = $base;
            $oferta['precio_aplicado'] = true;

            return $oferta;
        }

        if ($oferta['tipo_descuento'] !== self::TIPO_UNIDAD) {
            return $oferta;
        }

        if (!self::porcentajeUsable($oferta['porcentaje'])) {
            return $oferta;
        }

        $article->precio_sin_oferta = $base;
        $article->final_price = self::aplicarPorcentaje($base, $oferta['porcentaje']);
        $oferta['precio_aplicado'] = true;

        return $oferta;
    }

    /**
     * El porcentaje del tramo que le toca a esa cantidad, o null.
     *
     * Misma forma que CartHelper::get_price_range(), que es el mecanismo que la tienda ya usa
     * para lo mismo: se recorren los tramos ordenados por `min` y se queda el ULTIMO que
     * cumple. `max` null es sin techo.
     *
     * @param array $rangos Ordenados por min ASC.
     * @param float $cantidad
     * @return float|null
     */
    private static function porcentajeDelTramo($rangos, $cantidad)
    {
        $porcentaje = null;

        foreach ($rangos as $rango) {
            if (
                (
                    is_null($rango['min'])
                    || $cantidad >= $rango['min']
                )
                &&
                (
                    is_null($rango['max'])
                    || $cantidad <= $rango['max']
                )
            ) {
                $porcentaje = $rango['porcentaje'];
            }
        }

        return self::porcentajeUsable($porcentaje) ? (float) $porcentaje : null;
    }

    /**
     * El precio con el porcentaje aplicado, redondeado a 2 decimales.
     *
     * Es un `* (1 - porcentaje/100)` sobre el precio YA resuelto, y eso alcanza porque el
     * porcentaje es invariante al IVA y a los sale_taxes: los dos son factores
     * multiplicativos, asi que el mismo numero vale sobre el neto y sobre el bruto. La tienda
     * no tiene que conocer costos ni margenes. Ver el docblock de la clase.
     *
     * @param float $precio
     * @param float $porcentaje
     * @return float
     */
    private static function aplicarPorcentaje($precio, $porcentaje)
    {
        return round(((float) $precio) * (1 - ((float) $porcentaje / 100)), 2);
    }

    /**
     * ¿El porcentaje sirve para descontar?
     *
     * La columna es decimal(6,2), o sea que admite hasta 9999.99: un valor fuera de (0, 100]
     * daria un precio negativo o un descuento nulo, y esto esta en el camino de la plata. Un
     * dato asi es basura del ERP, no una oferta: se muestra la oferta pero no se toca ningun
     * precio.
     *
     * @param mixed $porcentaje
     * @return bool
     */
    private static function porcentajeUsable($porcentaje)
    {
        if (!is_numeric($porcentaje)) {
            return false;
        }

        $porcentaje = (float) $porcentaje;

        return $porcentaje > 0 && $porcentaje <= 100;
    }

    /**
     * ¿El comercio tiene prendida la extension que ya pisa el precio con sus propios rangos?
     * Memoizado por proceso, indexado por user_id.
     *
     * Se resuelve con CommerceHelper y no con una query propia a proposito: es EXACTAMENTE el
     * mismo criterio con el que ArticleHelper::checkPriceTypes() y CartHelper::attachArticles()
     * deciden entrar en set_ranges() / get_price_range(). Si aca se usara otro, la oferta
     * podria tocar el precio justo en el camino donde el otro mecanismo tambien lo toca.
     *
     * En consola no se memoiza, por el mismo motivo que hayTablas(): la suite prende y apaga
     * extensiones entre casos dentro del mismo proceso.
     *
     * El comercio se busca aca y se le pasa YA RESUELTO a hasExtencion(): ese helper recibe el
     * id como tercer argumento y hace el find adentro, pero sin chequear el null, asi que con
     * un user_id que no exista revienta sobre la relacion. Aca no puede pasar —el user_id sale
     * de un articulo—, pero esto esta en el camino de la plata y no se apoya en eso.
     *
     * @param int $user_id
     * @return bool
     */
    private static function comercioTieneRangosPorCantidad($user_id)
    {
        if (!array_key_exists($user_id, self::$extenciones) || app()->runningInConsole()) {
            $commerce = User::find($user_id);

            self::$extenciones[$user_id] = !is_null($commerce)
                && CommerceHelper::hasExtencion(self::SLUG_RANGOS_POR_CANTIDAD_VENDIDA, $commerce);
        }

        return self::$extenciones[$user_id];
    }

    /**
     * El comercio dueño de la coleccion: el `user_id` del primer articulo, igual que
     * checkPriceTypes().
     *
     * Se resuelve recorriendo y no con `$articles[0]` porque la coleccion puede venir de un
     * paginador o de un filtro con las claves salteadas, y ahi ese indice devuelve null con un
     * warning. Es la misma guarda del plan, sin el borde.
     *
     * @param mixed $articles
     * @return int|null
     */
    private static function userIdDe($articles)
    {
        /* El PRIMER elemento manda y no se sigue buscando, igual que checkPriceTypes(): si ese
           no tiene user_id, no hay comercio que resolver y no se aplica nada. */
        foreach ($articles as $article) {
            return (is_object($article) && isset($article->user_id))
                ? self::idPositivo($article->user_id)
                : null;
        }

        return null;
    }

    /**
     * Los ids de articulo de la coleccion, en una sola pasada y sin repetidos.
     *
     * Sin repetidos porque un carrito puede traer el mismo articulo en dos lineas (variantes),
     * y mandar el id dos veces en el `IN` no agrega nada.
     *
     * @param mixed $articles
     * @return array
     */
    private static function idsDe($articles)
    {
        $ids = [];

        foreach ($articles as $article) {
            if (!is_object($article) || !isset($article->id)) {
                continue;
            }

            $id = self::idPositivo($article->id);

            if (!is_null($id)) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * La cantidad de una linea del carrito.
     *
     * `amount` es donde la deja el SPA y donde ya la lee
     * CartHelper::check_article_price_type_group(). El pivot es el respaldo: attachArticles()
     * guarda desde `pivot.amount`, asi que hay payloads que traen las dos.
     *
     * @param array $article
     * @return float
     */
    private static function cantidadDeLinea($article)
    {
        if (isset($article['amount']) && is_numeric($article['amount'])) {
            return (float) $article['amount'];
        }

        if (
            isset($article['pivot']['amount'])
            && is_numeric($article['pivot']['amount'])
        ) {
            return (float) $article['pivot']['amount'];
        }

        return 0;
    }

    /**
     * Id valido (entero > 0) o null. El 0 y los negativos no son ids.
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

    /**
     * Deja constancia de un descarte de CONFIGURACION, una sola vez por proceso.
     *
     * Dos decisiones, y las dos importan:
     *
     * 1. UNA VEZ POR PROCESO. Que las tablas no esten todavia es un estado que dura dias o
     *    semanas y no cambia dentro de un request. Loguearlo por pagina serian miles de lineas
     *    por dia en un comercio con trafico, y ese ruido termina tapando las fallas de verdad
     *    — el mismo argumento por el que existe la guarda Schema::hasTable.
     * 2. NIVEL info, NO warning. `warning` queda reservado para las fallas de verdad (el catch
     *    de los metodos publicos). Que el esquema no haya llegado es el estado PREVISTO del
     *    despliegue: reportarlo como falla seria mentir en el otro sentido, y ademas volveria
     *    inutil la unica asercion que distingue "la guarda anda" de "el try/catch la tapa".
     *
     * @param string $clave Identifica el aviso. Se avisa una vez por clave y por proceso.
     * @param string $mensaje
     * @return void
     */
    private static function avisarUnaVez($clave, $mensaje)
    {
        if (array_key_exists($clave, self::$avisos)) {
            return;
        }

        self::$avisos[$clave] = true;

        try {
            Log::info($mensaje);
        } catch (\Throwable $ignorada) {
            /* Un logger roto no puede romper la navegacion del comprador. Mismo criterio que
               registrarFalla(). */
        }
    }

    /**
     * Deja constancia de una falla de la oferta personalizada, con contexto suficiente para
     * diagnosticarla y sin un solo dato del comprador.
     *
     * El try anidado no es decorativo: este metodo es lo ultimo que corre antes de que la
     * excepcion se de por atendida, asi que un logger roto no puede ser la via por la que
     * termine escapando igual y rompiendo la navegacion o el checkout.
     *
     * @param \Throwable $e
     * @return void
     */
    private static function registrarFalla($e)
    {
        try {
            Log::warning('ClientOfferHelper: fallo la oferta personalizada.', [
                'excepcion' => get_class($e),
                'mensaje'   => $e->getMessage(),
                'archivo'   => $e->getFile().':'.$e->getLine(),
            ]);
        } catch (\Throwable $ignorada) {
            /* Si hasta el logger esta roto, no queda nada mas que hacer: lo que no puede pasar
               es que la navegacion o el checkout se rompan por una oferta. */
        }
    }
}
