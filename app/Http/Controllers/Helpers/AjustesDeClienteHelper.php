<?php

namespace App\Http\Controllers\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Descuentos y recargos de VENTA que el comerciante le vinculo a un cliente del ERP (mision
 * descuentos-recargos-por-cliente, 23/9/2026).
 *
 * 🔴 ESTE ES EL UNICO PUNTO DEL REPO QUE TOCA `client_discount`, `client_surchage`,
 * `discount_order` y `order_surchage`. Si aparece un segundo lugar leyendo o escribiendo esas
 * tablas, el contrato con `empresa-api` queda decidido en dos lados con dos criterios. Molde de
 * punta a punta: `ClientOfferHelper`.
 *
 * --------------------------------------------------------------------------------------
 * El contrato con `empresa-api` es la base compartida, no un endpoint
 * --------------------------------------------------------------------------------------
 * Las cuatro tablas las crea `empresa-api` por migracion (este repo no tiene migraciones):
 *
 *   client_discount (id, client_id, discount_id, timestamps)   -- lo escribe la ficha del cliente
 *   client_surchage (id, client_id, surchage_id, timestamps)   -- idem
 *   discount_order  (id, order_id, discount_id, percentage double, timestamps)       -- lo escribe la tienda
 *   order_surchage  (id, order_id, surchage_id, percentage decimal(12,2), timestamps) -- idem
 *
 * `percentage` en los pivots del pedido es la FOTO del porcentaje con el que se pricearon los
 * renglones, igual que `discount_sale.percentage`: si despues el comerciante cambia o borra el
 * descuento, el pedido y la venta que sale de el conservan el numero.
 *
 * --------------------------------------------------------------------------------------
 * La formula (una sola, la misma que Vender en el ERP)
 * --------------------------------------------------------------------------------------
 *
 *   precio_ajustado = round(precio × Π(1 − d/100) × Π(1 + r/100), 2)
 *
 * Se aplica UNA SOLA VEZ sobre el precio ya resuelto (lista del comprador, y encima de la oferta
 * personalizada si la hay: decision 1 de Lucas). Porcentaje usable: descuento en (0, 100], recargo
 * mayor a 0; uno inservible se ignora entero (ni precio ni badge).
 *
 * --------------------------------------------------------------------------------------
 * 🔴 Solo para el comprador que entro con SU CUENTA
 * --------------------------------------------------------------------------------------
 * Los ajustes son una condicion comercial de un cliente del ERP, asi que valen para quien se
 * autentico como ese cliente: una cuenta con contraseña o con login social. NO para la ficha sin
 * credencial que deja un checkout de invitado: `BuyerController::login()` le abre sesion en el
 * guard a esa ficha cuando alguien compra con su email, y si la ficha estuviera vinculada, un
 * invitado que sabe el email se llevaria el descuento del cliente (o pagaria un recargo que no
 * vio). Mismo criterio de "cuenta" que `BuyerController::esFichaDeInvitado()`. Ver esCuenta().
 *
 * --------------------------------------------------------------------------------------
 * 🔴 SI VENIS A SIMPLIFICAR, LEE ESTO: el chequeo del esquema
 * --------------------------------------------------------------------------------------
 * La tienda se despliega cuando Lucas quiere y el esquema llega con el release de empresa, asi
 * que durante dias o semanas la tienda nueva corre contra bases SIN estas tablas: es el estado
 * normal, no el borde. Sin la guarda, cada pagina con un comprador vinculado tiraria una excepcion
 * (y el try/catch "andaria", pero llenando el log de ruido hasta tapar las fallas de verdad).
 *
 * El chequeo NO es `Schema::hasTable`: en este Laravel eso trae la lista entera de
 * `information_schema.tables` de la base y filtra en PHP, y harian falta cuatro. Es UNA consulta
 * con las cuatro tablas en el `IN` (y, en la misma pasada, la columna `clients.deleted_at`), y el
 * resultado se guarda en `Cache` MINUTOS_DE_CACHE_DEL_ESQUEMA minutos: bajo php-fpm las estaticas
 * mueren con cada request, asi que sin el Cache se pagaria en todos. El costo, dicho de frente:
 * cuando el release de empresa crea las tablas, la tienda tarda hasta esos minutos en enterarse.
 * En consola se mide siempre y no se cachea: la suite esconde y crea tablas en caliente dentro
 * del mismo proceso. Y NUNCA adentro de un loop de articulos.
 *
 * El aviso de "falta el esquema" va al log UNA vez por dia y por base (Cache::add), no una vez por
 * request: con la estatica sola se repetiria en cada pagina de cada visitante.
 *
 * Y todo va en try/catch que registra y devuelve "sin ajustes": un descuento que falla no puede
 * tumbar la navegacion ni el checkout, pero tampoco se lo traga en silencio (warning al log).
 */
class AjustesDeClienteHelper
{
    /** Descuentos vinculados a cada cliente. La crea `empresa-api`. */
    const TABLA_DESCUENTOS = 'client_discount';

    /** Recargos vinculados a cada cliente. La crea `empresa-api`. */
    const TABLA_RECARGOS = 'client_surchage';

    /** Descuentos con los que se priceo un pedido. La escribe la tienda. */
    const TABLA_DESCUENTOS_DEL_PEDIDO = 'discount_order';

    /** Recargos con los que se priceo un pedido. La escribe la tienda. */
    const TABLA_RECARGOS_DEL_PEDIDO = 'order_surchage';

    /** Tipo de cada entrada de la lista plana que viaja en los articulos (badges del SPA). */
    const TIPO_DESCUENTO = 'descuento';
    const TIPO_RECARGO = 'recargo';

    /** Cuanto vive en Cache la medicion del esquema. Ver el docblock de la clase. */
    const MINUTOS_DE_CACHE_DEL_ESQUEMA = 5;

    /**
     * La medicion del esquema de este request: null = sin resolver, o
     * {tablas: string[], clients_deleted_at: bool}. Ver esquema().
     *
     * @var array|null
     */
    private static $esquema = null;

    /**
     * Ajustes ya leidos, indexados por "user_id:client_id". Es lo que hace que un listado o un
     * carrito de N lineas cueste una query por tipo y no N.
     *
     * @var array
     */
    private static $ajustes_por_cliente = [];

    /**
     * Avisos de configuracion ya dados en este proceso. Ver avisarUnaVez().
     *
     * @var array
     */
    private static $avisos = [];

    /**
     * La forma vacia de los ajustes. Es lo que se devuelve ante cualquier guarda o falla.
     *
     * @return array{descuentos: array, recargos: array}
     */
    public static function vacio()
    {
        return ['descuentos' => [], 'recargos' => []];
    }

    /**
     * ¿Hay en este request un comprador logueado CON SU CUENTA, con cliente del ERP, y estan las
     * tablas de lectura? Es la guarda barata para quien tiene que decidir antes de cargar nada:
     * 0 queries sin sesion o sin cliente (la mayoria de los compradores) y, en el resto, la
     * medicion del esquema de esquema() (cacheada entre requests).
     *
     * @return bool
     */
    public static function hayContrato()
    {
        try {
            if (is_null(self::clienteDeLaSesion())) {
                return false;
            }

            return self::hayTablas();
        } catch (\Throwable $e) {
            self::registrarFalla($e);

            return false;
        }
    }

    /**
     * Los ajustes del comprador logueado para el comercio `$user_id`.
     *
     * El cliente sale de la SESION (el atributo `comercio_city_client_id`, sin disparar la
     * relacion), nunca del payload ni de la URL: si viniera de afuera, cualquiera podria pedir
     * los descuentos de otra persona.
     *
     * @param int|null $user_id Comercio dueño de lo que se esta priceando.
     * @return array{descuentos: array, recargos: array}
     */
    public static function del_comprador($user_id)
    {
        try {
            return self::deCliente(self::clienteDeLaSesion(), $user_id);
        } catch (\Throwable $e) {
            self::registrarFalla($e);

            return self::vacio();
        }
    }

    /**
     * Los ajustes de un comprador dado (para colgarlos del JSON del buyer en /api/user y en el
     * login). El comercio es el del propio comprador (`buyers.user_id`).
     *
     * 🔴 Se cuelga por aca y NO como relacion eager del modelo: si la tabla no existe, un
     * `with()` revienta el login entero.
     *
     * @param \App\Buyer|null $buyer
     * @return array{descuentos: array, recargos: array}
     */
    public static function para_el_comprador($buyer)
    {
        try {
            /* La ficha de un checkout de invitado no es la cuenta del cliente: ver esCuenta(). */
            if (is_null($buyer) || !self::esCuenta($buyer)) {
                return self::vacio();
            }

            return self::deCliente(
                self::idPositivo(isset($buyer->comercio_city_client_id) ? $buyer->comercio_city_client_id : null),
                isset($buyer->user_id) ? $buyer->user_id : null
            );
        } catch (\Throwable $e) {
            self::registrarFalla($e);

            return self::vacio();
        }
    }

    /**
     * Cuelga `ajustes_de_cliente` ({descuentos, recargos}) del JSON del comprador: es lo que lee
     * el desplegable del nombre en el SPA ("Se te estan aplicando estos descuentos...").
     *
     * Va como RELACION en memoria (setRelation) y no como atributo a proposito: un atributo
     * suelto viajaria en el proximo `save()` del modelo (`AuthController::setLastLogin` guarda
     * al comprador en cada /api/user) y reventaria con "Unknown column". Las relaciones no se
     * guardan, y se serializan igual.
     *
     * @param \App\Buyer|null $buyer
     * @return \App\Buyer|null
     */
    public static function colgar_del_comprador($buyer)
    {
        if (is_null($buyer)) {
            return $buyer;
        }

        try {
            $buyer->setRelation('ajustes_de_cliente', collect(self::para_el_comprador($buyer)));
        } catch (\Throwable $e) {
            self::registrarFalla($e);
        }

        return $buyer;
    }

    /**
     * ¿Hay al menos un ajuste usable?
     *
     * @param array $ajustes
     * @return bool
     */
    public static function tiene_ajustes($ajustes)
    {
        return is_array($ajustes)
            && (!empty($ajustes['descuentos']) || !empty($ajustes['recargos']));
    }

    /**
     * El factor combinado: Π(1 − d/100) × Π(1 + r/100). 1 sin ajustes.
     *
     * @param array $ajustes
     * @return float
     */
    public static function factor($ajustes)
    {
        $factor = 1.0;

        if (!is_array($ajustes)) {
            return $factor;
        }

        foreach (isset($ajustes['descuentos']) ? $ajustes['descuentos'] : [] as $descuento) {
            $factor *= (1 - ((float) $descuento['percentage'] / 100));
        }

        foreach (isset($ajustes['recargos']) ? $ajustes['recargos'] : [] as $recargo) {
            $factor *= (1 + ((float) $recargo['percentage'] / 100));
        }

        return $factor;
    }

    /**
     * El precio con los ajustes aplicados, redondeado a 2 decimales.
     *
     * Sin ajustes devuelve el MISMO valor que recibio, sin redondear ni castear: asi un comprador
     * sin descuentos vinculados cobra byte por byte lo mismo que en master. Un precio no numerico
     * (null, texto de precio pausado) tambien pasa tal cual.
     *
     * @param mixed $precio
     * @param array $ajustes
     * @return mixed
     */
    public static function ajustar($precio, $ajustes)
    {
        if (!self::tiene_ajustes($ajustes) || is_null($precio) || !is_numeric($precio)) {
            return $precio;
        }

        return round(((float) $precio) * self::factor($ajustes), 2);
    }

    /**
     * La lista plana que viaja en cada articulo, combo o promo ajustado: una entrada por badge,
     * primero los descuentos y despues los recargos.
     *
     * @param array $ajustes
     * @return array Cada entrada {id, tipo, name, percentage}.
     */
    public static function lista($ajustes)
    {
        $lista = [];

        foreach (isset($ajustes['descuentos']) ? $ajustes['descuentos'] : [] as $descuento) {
            $lista[] = array_merge(['tipo' => self::TIPO_DESCUENTO], $descuento);
        }

        foreach (isset($ajustes['recargos']) ? $ajustes['recargos'] : [] as $recargo) {
            $lista[] = array_merge(['tipo' => self::TIPO_RECARGO], $recargo);
        }

        return $lista;
    }

    /**
     * Aplica los ajustes del comprador logueado sobre articulos YA pasados por los cuatro casos de
     * checkPriceTypes() y por la oferta personalizada. Nunca tira: devuelve lo que recibio.
     *
     * Por articulo con `final_price` numerico y sin `precio_pausado`:
     *   - `precio_sin_ajustes_de_cliente` = el `final_price` de antes (la base que el carrito
     *     necesita para no aplicar el factor dos veces, ver CartHelper::get_price()),
     *   - `final_price` = round(final_price × factor, 2),
     *   - `ajustes_de_cliente` = la lista de badges.
     * Con la extension de rangos por cantidad vendida, el precio que muestra el SPA es el de
     * `article.ranges[].price`: cada rango se ajusta igual y guarda su propia base.
     *
     * ⚠️ Los tramos por articulo (`article_price_ranges`) NO se tocan aca: el servidor los relee de
     * la base en el carrito (ArticlePriceRangeHelper) y les aplica el factor ahi. Ajustarlos en
     * memoria los dejaria ajustados para la resincronizacion del carrito, que les volveria a
     * aplicar el factor. El SPA multiplica el precio del tramo para mostrarlo.
     *
     * @param mixed $articles Coleccion, paginador o array de articulos.
     * @return mixed
     */
    public static function aplicar($articles)
    {
        try {
            if (!is_countable($articles) || count($articles) == 0) {
                return $articles;
            }

            $client_id = self::clienteDeLaSesion();

            if (is_null($client_id)) {
                return $articles;
            }

            $ajustes = self::deCliente($client_id, self::userIdDe($articles));

            if (!self::tiene_ajustes($ajustes)) {
                return $articles;
            }

            $lista = self::lista($ajustes);

            foreach ($articles as $article) {
                self::ajustarUno($article, $ajustes, $lista, true);
            }

            return $articles;
        } catch (\Throwable $e) {
            self::registrarFalla($e);

            return $articles;
        }
    }

    /**
     * Lo mismo que aplicar() para lo comprable con precio FIJO: combos (`final_price` = `price`) y
     * promociones de vinoteca (`final_price` propio). Decision 2 de Lucas: los ajustes van sobre
     * todo el carrito, asi que tambien se muestran ajustados.
     *
     * @param mixed $items
     * @param int $user_id Comercio dueño (el de la pagina o el del carrito, nunca el del payload).
     * @return mixed
     */
    public static function aplicar_a_precios_fijos($items, $user_id)
    {
        try {
            if (!is_countable($items) || count($items) == 0) {
                return $items;
            }

            $ajustes = self::del_comprador($user_id);

            if (!self::tiene_ajustes($ajustes)) {
                return $items;
            }

            $lista = self::lista($ajustes);

            foreach ($items as $item) {
                self::ajustarUno($item, $ajustes, $lista, false);
            }

            return $items;
        } catch (\Throwable $e) {
            self::registrarFalla($e);

            return $items;
        }
    }

    /**
     * Una linea del payload del carrito con los precios devueltos a su base SIN ajustes.
     *
     * 🔴 Es la pieza que impide el doble factor. El SPA reenvia los articulos tal como se los
     * dio la API, o sea con `final_price` YA ajustado. Si el carrito multiplicara eso por el
     * factor, cobraria 1000 × 0,9 × 1,05 dos veces (893,03 en vez de 945). Por eso el carrito
     * primero vuelve a la base (`precio_sin_ajustes_de_cliente`), resuelve el precio por la
     * cadena de siempre y recien al final aplica el factor, UNA vez, con los porcentajes leidos
     * de la base y no del payload.
     *
     * Sin la clave (SPA viejo, articulo que nunca se ajusto) `final_price` se toma como base: es
     * el precio sin ajustes, porque este servidor nunca lo ajusto.
     *
     * @param array $linea
     * @return array
     */
    public static function desajustar_linea($linea)
    {
        if (!is_array($linea)) {
            return $linea;
        }

        if (isset($linea['precio_sin_ajustes_de_cliente']) && is_numeric($linea['precio_sin_ajustes_de_cliente'])) {
            $linea['final_price'] = (float) $linea['precio_sin_ajustes_de_cliente'];
        }

        if (isset($linea['ranges']) && is_array($linea['ranges'])) {
            foreach ($linea['ranges'] as $indice => $rango) {
                if (
                    is_array($rango)
                    && isset($rango['precio_sin_ajustes_de_cliente'])
                    && is_numeric($rango['precio_sin_ajustes_de_cliente'])
                ) {
                    $linea['ranges'][$indice]['price'] = (float) $rango['precio_sin_ajustes_de_cliente'];
                }
            }
        }

        return $linea;
    }

    /**
     * El precio SIN ajustes de un articulo ya pasado por checkPriceTypes(): la base que el
     * servidor usa para resincronizar el carrito.
     *
     * @param object $article
     * @return mixed
     */
    public static function precio_sin_ajustes($article)
    {
        if (isset($article->precio_sin_ajustes_de_cliente) && is_numeric($article->precio_sin_ajustes_de_cliente)) {
            return $article->precio_sin_ajustes_de_cliente;
        }

        return isset($article->final_price) ? $article->final_price : null;
    }

    /**
     * Guarda en el pedido los ajustes con los que se pricearon sus renglones, con la FOTO del
     * porcentaje. Si las tablas del pedido no estan (empresa todavia sin el release), no hace
     * nada: el pedido nace sin pivots, que para el ERP es exactamente "pedido de hoy".
     *
     * Nunca tira: el pedido ya esta creado cuando esto corre, y una falla aca no puede dejar al
     * comprador sin saber si su compra entro.
     *
     * @param int $order_id
     * @param array $ajustes
     * @return void
     */
    public static function guardar_en_el_pedido($order_id, $ajustes)
    {
        try {
            if (!self::tiene_ajustes($ajustes)) {
                return;
            }

            if (!self::hayTablasDelPedido()) {
                self::avisarUnaVez(
                    'tablas_del_pedido',
                    'AjustesDeClienteHelper: faltan '.self::TABLA_DESCUENTOS_DEL_PEDIDO.' / '
                    .self::TABLA_RECARGOS_DEL_PEDIDO.'. El pedido se crea sin sus ajustes hasta que llegue el esquema de empresa-api.'
                );

                return;
            }

            $ahora = Carbon::now();

            foreach ($ajustes['descuentos'] as $descuento) {
                DB::table(self::TABLA_DESCUENTOS_DEL_PEDIDO)->insert([
                    'order_id'    => $order_id,
                    'discount_id' => $descuento['id'],
                    'percentage'  => $descuento['percentage'],
                    'created_at'  => $ahora,
                    'updated_at'  => $ahora,
                ]);
            }

            foreach ($ajustes['recargos'] as $recargo) {
                DB::table(self::TABLA_RECARGOS_DEL_PEDIDO)->insert([
                    'order_id'    => $order_id,
                    'surchage_id' => $recargo['id'],
                    'percentage'  => $recargo['percentage'],
                    'created_at'  => $ahora,
                    'updated_at'  => $ahora,
                ]);
            }
        } catch (\Throwable $e) {
            self::registrarFalla($e);
        }
    }

    /**
     * Descarta la memoria del proceso. La usan los tests.
     *
     * @return void
     */
    public static function olvidarMemoria()
    {
        self::$esquema = null;
        self::$ajustes_por_cliente = [];
        self::$avisos = [];
    }

    /**
     * Aplica los ajustes sobre un objeto (articulo, combo o promo). Muta el objeto.
     *
     * @param mixed $item
     * @param array $ajustes
     * @param array $lista
     * @param bool $con_rangos Si se ajustan tambien los `ranges` de la extension por categoria.
     * @return void
     */
    private static function ajustarUno($item, $ajustes, $lista, $con_rangos)
    {
        if (!is_object($item)) {
            return;
        }

        /* Con el precio pausado no hay importe que ajustar: el SPA muestra el texto de
           configuracion y bloquea el carrito. Tampoco se cuelgan badges. */
        if (isset($item->precio_pausado) && $item->precio_pausado) {
            return;
        }

        $ajustado = false;

        if (isset($item->final_price) && is_numeric($item->final_price)) {
            $base = round((float) $item->final_price, 2);

            $item->precio_sin_ajustes_de_cliente = $base;
            $item->final_price = self::ajustar($base, $ajustes);
            $ajustado = true;
        }

        if ($con_rangos && isset($item->ranges) && is_array($item->ranges) && count($item->ranges) > 0) {
            /*
             * 🔴 Cada rango se CLONA antes de ajustarlo. set_ranges() arma `ranges` con los mismos
             * objetos de `category_price_type_ranges`, y el eager load de Eloquent comparte la
             * instancia de la categoria entre todos los articulos que la tienen: ajustar en el
             * lugar le aplicaria el factor al mismo rango una vez por articulo de la pagina.
             */
            $rangos = [];

            foreach ($item->ranges as $rango) {
                if (!is_object($rango)) {
                    $rangos[] = $rango;
                    continue;
                }

                $copia = clone $rango;

                if (isset($copia->price) && is_numeric($copia->price)) {
                    $base_del_rango = round((float) $copia->price, 2);

                    $copia->precio_sin_ajustes_de_cliente = $base_del_rango;
                    $copia->price = self::ajustar($base_del_rango, $ajustes);
                    $ajustado = true;
                }

                $rangos[] = $copia;
            }

            $item->ranges = $rangos;
        }

        if ($ajustado) {
            $item->ajustes_de_cliente = $lista;
        }
    }

    /**
     * Los ajustes usables de un cliente para un comercio, memoizados por request.
     *
     * Dos queries (una por tipo) con join, sin hidratar modelos. Solo descuentos/recargos NO
     * borrados y del MISMO `user_id` que el comercio: la base es compartida entre comercios, y
     * un vinculo que apunte a un descuento de otro comercio es un dato roto que no se cobra.
     *
     * @param int|null $client_id
     * @param mixed $user_id
     * @return array{descuentos: array, recargos: array}
     */
    private static function deCliente($client_id, $user_id)
    {
        $client_id = self::idPositivo($client_id);
        $user_id = self::idPositivo($user_id);

        if (is_null($client_id) || is_null($user_id)) {
            return self::vacio();
        }

        /* Una sola medicion del esquema para todo lo de aca abajo (en consola esquema() mide en
           cada llamada, asi que no se le pregunta dos veces). */
        $esquema = self::esquema();

        if (
            !in_array(self::TABLA_DESCUENTOS, $esquema['tablas'], true)
            || !in_array(self::TABLA_RECARGOS, $esquema['tablas'], true)
        ) {
            self::avisarUnaVez(
                'tablas',
                'AjustesDeClienteHelper: faltan '.self::TABLA_DESCUENTOS.' / '.self::TABLA_RECARGOS
                .'. No hay descuentos ni recargos por cliente hasta que llegue el esquema de empresa-api.'
            );

            return self::vacio();
        }

        $clave = $user_id.':'.$client_id;

        if (!array_key_exists($clave, self::$ajustes_por_cliente) || app()->runningInConsole()) {
            $sin_borrados = $esquema['clients_deleted_at'];

            self::$ajustes_por_cliente[$clave] = [
                'descuentos' => self::leer(self::TABLA_DESCUENTOS, 'discounts', 'discount_id', $client_id, $user_id, true, $sin_borrados),
                'recargos'   => self::leer(self::TABLA_RECARGOS, 'surchages', 'surchage_id', $client_id, $user_id, false, $sin_borrados),
            ];
        }

        return self::$ajustes_por_cliente[$clave];
    }

    /**
     * Una de las dos queries: los vinculos del cliente contra su tabla de ajustes.
     *
     * @param string $tabla_vinculo
     * @param string $tabla_ajuste
     * @param string $columna
     * @param int $client_id
     * @param int $user_id
     * @param bool $es_descuento
     * @param bool $clients_con_deleted_at Si `clients` tiene la columna (sale de esquema()).
     * @return array Cada entrada {id, name, percentage}, sin repetidos, en el orden del vinculo.
     */
    private static function leer($tabla_vinculo, $tabla_ajuste, $columna, $client_id, $user_id, $es_descuento, $clients_con_deleted_at)
    {
        $query = DB::table($tabla_vinculo.' as v')
                    ->join($tabla_ajuste.' as a', 'a.id', '=', 'v.'.$columna)
                    /* El cliente tiene que existir: un vinculo colgado de un cliente que el ERP
                       borro no se cobra. */
                    ->join('clients as c', 'c.id', '=', 'v.client_id')
                    ->where('v.client_id', $client_id)
                    ->where('a.user_id', $user_id)
                    ->whereNull('a.deleted_at');

        /* Y si lo borro con soft delete, tampoco. Solo si la columna existe: en una base vieja
           sin ella, el whereNull seria "Unknown column". */
        if ($clients_con_deleted_at) {
            $query->whereNull('c.deleted_at');
        }

        $filas = $query->orderBy('v.id', 'ASC')
                        ->get(['a.id', 'a.name', 'a.percentage']);

        $ajustes = [];

        foreach ($filas as $fila) {
            $id = (int) $fila->id;

            /* Un mismo descuento vinculado dos veces se aplica una: el ERP lo mostraria como
               un solo descuento en Vender. */
            if (array_key_exists($id, $ajustes)) {
                continue;
            }

            if (!self::porcentajeUsable($fila->percentage, $es_descuento)) {
                continue;
            }

            $ajustes[$id] = [
                'id'         => $id,
                'name'       => $fila->name,
                /* PDO devuelve los decimal como string: el JSON del contrato lleva numeros. */
                'percentage' => (float) $fila->percentage,
            ];
        }

        return array_values($ajustes);
    }

    /**
     * ¿El porcentaje sirve? Descuento en (0, 100]; recargo mayor a 0. Fuera de eso daria un
     * precio negativo o un ajuste nulo, y esto esta en el camino de la plata.
     *
     * @param mixed $porcentaje
     * @param bool $es_descuento
     * @return bool
     */
    private static function porcentajeUsable($porcentaje, $es_descuento)
    {
        if (!is_numeric($porcentaje)) {
            return false;
        }

        $porcentaje = (float) $porcentaje;

        if ($es_descuento) {
            return $porcentaje > 0 && $porcentaje <= 100;
        }

        return $porcentaje > 0;
    }

    /**
     * ¿Existen las dos tablas de lectura? Sale de esquema(): ver el docblock de la clase.
     *
     * @return bool
     */
    private static function hayTablas()
    {
        $tablas = self::esquema()['tablas'];

        return in_array(self::TABLA_DESCUENTOS, $tablas, true) && in_array(self::TABLA_RECARGOS, $tablas, true);
    }

    /**
     * ¿Existen las dos tablas del pedido? Se piden las dos: un pedido con los descuentos
     * guardados y los recargos perdidos le mentiria al ERP.
     *
     * @return bool
     */
    private static function hayTablasDelPedido()
    {
        $tablas = self::esquema()['tablas'];

        return in_array(self::TABLA_DESCUENTOS_DEL_PEDIDO, $tablas, true)
            && in_array(self::TABLA_RECARGOS_DEL_PEDIDO, $tablas, true);
    }

    /**
     * Que parte del esquema del contrato existe en esta base.
     *
     * Estatica dentro del request, Cache por MINUTOS_DE_CACHE_DEL_ESQUEMA entre requests, y en
     * consola medida SIEMPRE y sin cachear (la suite esconde tablas en caliente). Solo se cachea
     * el resultado de la medicion; si el Cache falla, se mide derecho.
     *
     * @return array{tablas: string[], clients_deleted_at: bool}
     */
    private static function esquema()
    {
        if (app()->runningInConsole()) {
            self::$esquema = self::medirEsquema();

            return self::$esquema;
        }

        if (!is_null(self::$esquema)) {
            return self::$esquema;
        }

        try {
            self::$esquema = Cache::remember(
                self::claveDeCache('esquema'),
                Carbon::now()->addMinutes(self::MINUTOS_DE_CACHE_DEL_ESQUEMA),
                function () {
                    return self::medirEsquema();
                }
            );
        } catch (\Throwable $e) {
            self::$esquema = self::medirEsquema();
        }

        return self::$esquema;
    }

    /**
     * UNA consulta al information_schema: las cuatro tablas del contrato en el `IN` y, en la
     * misma pasada, si `clients` tiene `deleted_at`. `table_schema = DATABASE()` la acota a la
     * base de este cliente (el servidor tiene muchas).
     *
     * @return array{tablas: string[], clients_deleted_at: bool}
     */
    private static function medirEsquema()
    {
        $filas = DB::select(
            "SELECT table_name AS nombre FROM information_schema.tables "
            ."WHERE table_schema = DATABASE() AND table_name IN (?, ?, ?, ?) "
            ."UNION ALL "
            ."SELECT CONCAT(table_name, '.', column_name) AS nombre FROM information_schema.columns "
            ."WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [
                self::TABLA_DESCUENTOS,
                self::TABLA_RECARGOS,
                self::TABLA_DESCUENTOS_DEL_PEDIDO,
                self::TABLA_RECARGOS_DEL_PEDIDO,
                'clients',
                'deleted_at',
            ]
        );

        $esquema = ['tablas' => [], 'clients_deleted_at' => false];

        foreach ($filas as $fila) {
            $nombre = strtolower((string) $fila->nombre);

            if ($nombre === 'clients.deleted_at') {
                $esquema['clients_deleted_at'] = true;
            } else {
                $esquema['tablas'][] = $nombre;
            }
        }

        return $esquema;
    }

    /**
     * Clave de Cache de este helper, por base: varias tiendas pueden compartir el mismo store.
     *
     * @param string $que
     * @return string
     */
    private static function claveDeCache($que)
    {
        return 'ajustes_de_cliente.'.$que.'.'.DB::connection()->getDatabaseName();
    }

    /**
     * El cliente del ERP del comprador logueado CON SU CUENTA, leido como ATRIBUTO (la relacion
     * seria una query por pagina para algo que ya esta en la fila de `buyers`). null sin sesion,
     * sin cliente, o si quien esta en el guard es la ficha de un checkout de invitado.
     *
     * @return int|null
     */
    private static function clienteDeLaSesion()
    {
        $buyer = Auth::guard('buyer')->user();

        if (is_null($buyer) || !self::esCuenta($buyer)) {
            return null;
        }

        return self::idPositivo(isset($buyer->comercio_city_client_id) ? $buyer->comercio_city_client_id : null);
    }

    /**
     * ¿El comprador es una CUENTA (contraseña o login social) y no la ficha sin credencial de un
     * checkout de invitado?
     *
     * 🔴 Es `BuyerController::esFichaDeInvitado()` al reves, y por el mismo motivo: ese
     * controller le abre sesion en el guard a la ficha sin credencial cuando un invitado compra
     * con su email, asi que "hay un buyer en el guard" NO significa "entro el cliente". Sin esta
     * guarda, cualquiera que supiera el email de una ficha vinculada compraria con los descuentos
     * del cliente, o pagaria un recargo que nunca vio. Los atributos se leen crudos: `$hidden`
     * solo afecta el JSON.
     *
     * @param \App\Buyer $buyer
     * @return bool
     */
    private static function esCuenta($buyer)
    {
        $con_password = isset($buyer->password) && $buyer->password !== '';
        $con_provider = isset($buyer->provider_id) && $buyer->provider_id !== '';

        return $con_password || $con_provider;
    }

    /**
     * El comercio dueño de la coleccion: el `user_id` del primer elemento, igual que
     * checkPriceTypes() y ClientOfferHelper.
     *
     * @param mixed $articles
     * @return int|null
     */
    private static function userIdDe($articles)
    {
        foreach ($articles as $article) {
            return (is_object($article) && isset($article->user_id))
                ? self::idPositivo($article->user_id)
                : null;
        }

        return null;
    }

    /**
     * Id valido (entero > 0) o null.
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
     * Deja constancia de un estado de CONFIGURACION (esquema sin llegar), una vez por dia y por base
     * en nivel info: es el estado previsto del despliegue, no una falla.
     *
     * @param string $clave
     * @param string $mensaje
     * @return void
     */
    private static function avisarUnaVez($clave, $mensaje)
    {
        if (array_key_exists($clave, self::$avisos)) {
            return;
        }

        self::$avisos[$clave] = true;

        /* Fuera de consola, una vez por DIA y por base: la estatica muere con cada request bajo
           php-fpm, asi que sola avisaria en cada pagina. Cache::add solo escribe (y devuelve
           true) si la clave no estaba. En consola alcanza la estatica, que los tests limpian. */
        if (!app()->runningInConsole()) {
            try {
                if (!Cache::add(self::claveDeCache('aviso.'.$clave), true, Carbon::now()->addDay())) {
                    return;
                }
            } catch (\Throwable $ignorada) {
                /* Sin Cache se avisa igual: peor es no avisar nunca. */
            }
        }

        try {
            Log::info($mensaje);
        } catch (\Throwable $ignorada) {
            /* Un logger roto no puede romper la navegacion del comprador. */
        }
    }

    /**
     * Deja constancia de una falla, sin datos del comprador. El try anidado evita que un logger
     * roto sea la via por la que la excepcion termine escapando.
     *
     * @param \Throwable $e
     * @return void
     */
    private static function registrarFalla($e)
    {
        try {
            Log::warning('AjustesDeClienteHelper: fallaron los ajustes del cliente.', [
                'excepcion' => get_class($e),
                'mensaje'   => $e->getMessage(),
                'archivo'   => $e->getFile().':'.$e->getLine(),
            ]);
        } catch (\Throwable $ignorada) {
            /* Nada mas que hacer: lo que no puede pasar es que la tienda se rompa por esto. */
        }
    }
}
