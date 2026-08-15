<?php

namespace App\Http\Controllers\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ingesta de los eventos de comportamiento que manda el SPA de la tienda
 * (mision tracking-buyers-tienda).
 *
 * El contrato con `empresa-api` NO es un endpoint: es la base compartida. Alla se crean
 * `buyer_tracking_events` y `buyer_tracking_daily`, y aca se escriben. Nadie las lee
 * todavia — la lectura desde el ERP es una mision aparte.
 *
 * Toda la logica vive en este helper y el controller queda fino, que es la convencion del
 * repo (21 helpers estaticos en esta carpeta).
 *
 * --------------------------------------------------------------------------------------
 * Las cuatro guardas, y por que estan en ESE orden
 * --------------------------------------------------------------------------------------
 * Es el molde de DemoEventoEmitter::emitir() de `empresa-api`, y el orden es el punto:
 * primero lo que no toca la base, la base al final.
 *
 *   1. Forma del lote. Sin una sola query: tope de eventos, lista blanca de tipos, uuids
 *      con forma de uuid. Un lote que no trae nada valido se muere aca.
 *   2. Existencia de la tabla. Es la compatibilidad hacia atras del contrato (ver abajo).
 *   3. Extension `tracking_buyers` del comercio. Es el interruptor.
 *   4. Insert masivo por query builder.
 *
 * --------------------------------------------------------------------------------------
 * Silencioso para el comprador, NO invisible para el operador
 * --------------------------------------------------------------------------------------
 * Todo el cuerpo va dentro de try/catch: un evento de tracking no puede romper —ni
 * demorar— la navegacion de nadie. Lo que NO se hace es tragarse el error: un catch mudo
 * convertiria "hace tres semanas que no se captura nada" en algo que nadie puede notar
 * (clase "medicion que falla y devuelve un valor tranquilizador", APRENDER_NO_PARCHEAR.md).
 * La respuesta HTTP es siempre 204, pero la falla queda en el log con contexto.
 *
 * --------------------------------------------------------------------------------------
 * Sin datos sensibles. Es requisito, no preferencia
 * --------------------------------------------------------------------------------------
 * No se guarda ni se recibe IP, user-agent, email, telefono, nombre, direccion ni nada de
 * pago. La defensa no es una lista negra: la fila se arma con una lista BLANCA de columnas
 * (ver armarFila), asi que cualquier clave que llegue en el payload y no este ahi
 * simplemente no se lee. Nunca $request->all().
 *
 * `buyer_id` tampoco sale del payload por el mismo motivo, y ademas por uno de seguridad:
 * lo resuelve el controller desde la sesion. Si viniera del cliente, cualquiera podria
 * atribuirle su navegacion a otro comprador.
 */
class BuyerTrackingHelper
{
    /** Tabla de eventos crudos. La crea `empresa-api`. */
    const TABLA = 'buyer_tracking_events';

    /** Interruptor de la funcionalidad, por comercio. */
    const SLUG_EXTENCION = 'tracking_buyers';

    /**
     * Tope de eventos por lote. El SPA agrupa de a 20; 50 deja aire para el flush de
     * `visibilitychange` sin que un cliente hostil pueda mandar un lote gigante.
     */
    const MAX_EVENTOS_POR_LOTE = 50;

    /**
     * Lista blanca de tipos de evento. Es EL contrato con `empresa-api`: espejo exacto de
     * `BuyerTrackingEvent::TIPOS` (app/Models/BuyerTrackingEvent.php).
     *
     * 🔴 Si esta lista y aquella divergen, el mismo invariante queda decidido con dos
     * criterios distintos en los dos lados. Se cambian juntas o no se cambian.
     */
    const TIPOS = [
        'product_view',      // vista de producto (trae dwell_ms)
        'search',            // busqueda (trae search_term + results_count)
        'cart_add',          // agregado al carrito
        'cart_remove',       // quitado del carrito
        'checkout_start',    // entro a confirmar compra
        'checkout_complete', // pedido creado (trae order_id + amount)
    ];

    /** Ancho de la columna `search_term`. */
    const LARGO_SEARCH_TERM = 120;

    /**
     * Tope de tiempo en pantalla: 30 minutos. Una pestaña abierta toda la noche no es
     * tiempo de lectura. Por encima se descarta el VALOR, no el evento.
     */
    const MAX_DWELL_MS = 1800000;

    /** Ventana hacia atras que se le acepta al reloj del cliente. Ver normalizarOcurrencia. */
    const MAX_ANTIGUEDAD_HORAS = 24;

    /** Techo de `unsignedInteger` en MySQL. Ver enteroAcotado. */
    const MAX_UNSIGNED_INT = 4294967295;

    /**
     * Techo de `amount`, y a proposito MUY por debajo del de la columna. Ver monto().
     *
     * 🔴 SI VENIS A "CORREGIRLO" AL MAXIMO DE decimal(22,2), LEE ESTO PRIMERO.
     *
     * Aca decia 99999999999999999999.99 (el tope exacto de la columna) y era un bug medido:
     * ese literal NO ENTRA EN UN DOUBLE, asi que PHP lo parsea como exactamente 1.0E+20, que
     * es MAYOR que el tope real de decimal(22,2). O sea que la guarda dejaba pasar
     * justamente el valor que MySQL despues rechaza (`ERROR 1264 Out of range`, verificado con
     * modo estricto). Y como el insert es UNA SOLA sentencia multi-fila, un evento con
     * amount: 1e20 se llevaba puesto el LOTE ENTERO de hasta 50 — que es exactamente lo que
     * el comentario de enteroAcotado() dice estar previniendo.
     *
     * De ahi que el techo sea "chico" y no el maximo de la columna:
     *   - 999999999999.99 es un billon de pesos en un pedido de ecommerce. Sobra por varios
     *     ordenes de magnitud, y cualquier valor por encima es basura, no un dato;
     *   - es exactamente representable en un double (999999999999.99 * 100 entra en los 53
     *     bits de mantisa), asi que la comparacion mide lo que dice medir;
     *   - al quedar ordenes de magnitud debajo del tope de la columna, ademas le saca el
     *     combustible al desborde del SUM(amount) del rollup diario de `empresa-api`.
     */
    const MAX_AMOUNT = 999999999999.99;

    /**
     * Memoria de "existe la tabla", por request. Tres estados y los tres importan:
     * null = todavia no se resolvio, true/false = resuelto.
     *
     * @var bool|null
     */
    private static $hay_tabla = null;

    /**
     * Memoria de "este comercio tiene la extension", indexada por user_id.
     *
     * @var array
     */
    private static $extenciones = [];

    /**
     * Avisos de configuracion ya dados en este proceso, indexados por clave. Ver avisarUnaVez.
     *
     * @var array
     */
    private static $avisos = [];

    /**
     * Registra un lote de eventos. Nunca tira: devuelve cuantas filas escribio.
     *
     * @param mixed $eventos Lote crudo tal cual llego del SPA.
     * @param mixed $user_id Comercio dueño de la instancia (el `commerce_id` del request).
     * @param mixed $buyer_id Comprador logueado, o null si es un visitante anonimo.
     * @return int Cantidad de filas escritas. 0 tambien es un resultado normal.
     */
    public static function registrar($eventos, $user_id, $buyer_id = null)
    {
        try {
            /**
             * Guarda 1, y la unica que corre siempre: NO toca la base.
             *
             * Sin comercio no hay a quien atribuirle los eventos, y `user_id` es NOT NULL
             * en la tabla. Se corta antes de mirar el lote.
             */
            $user_id = self::idPositivo($user_id);

            if (is_null($user_id)) {
                /*
                 * Descarte de CONFIGURACION, no de un evento: si el SPA manda eventos pero no
                 * manda el commerce_id, no se va a capturar nada NUNCA, y sin esta linea eso
                 * es indistinguible de "no hubo trafico". Ver avisarUnaVez.
                 *
                 * Se avisa solo si venian eventos: un cuerpo vacio no dice nada de la
                 * configuracion (puede ser un healthcheck, un bot o un preflight raro).
                 */
                if (is_array($eventos) && !empty($eventos)) {
                    self::avisarUnaVez(
                        'commerce_id',
                        'BuyerTrackingHelper: llegan eventos sin un commerce_id valido. No se captura nada.'
                    );
                }

                return 0;
            }

            if (!is_array($eventos) || empty($eventos)) {
                return 0;
            }

            $filas = self::normalizarLote($eventos, $user_id, self::idPositivo($buyer_id));

            if (empty($filas)) {
                return 0;
            }

            /**
             * Guarda 2. 🔴 ESTA es la compatibilidad hacia atras del contrato, y protege el
             * escenario NORMAL, no el borde.
             *
             * El esquema lo crea `empresa-api` sobre la rama `refractor`, y no llega a la
             * base de un cliente hasta que eso se mergee a `develop` y salga por release.
             * La tienda, en cambio, la despliega Lucas a mano cuando quiere. O sea que la
             * tienda va a estar desplegada durante DIAS O SEMANAS contra una base que no
             * tiene estas tablas — es lo esperado, no un accidente.
             *
             * Sin esta guarda, cada vista de producto de cada comprador tira una excepcion.
             * Con ella, el SPA ni se entera: 204 y a otra cosa.
             *
             * Por eso tampoco alcanza con dejarselo al try/catch de afuera: eso "andaria",
             * pero pagando una excepcion por lote y llenando el log de ruido durante
             * semanas, hasta tapar las fallas de verdad.
             *
             * Si alguien viene a simplificar esto, es esto lo que tiene que leer primero.
             */
            if (!self::hayTabla()) {
                /*
                 * Estado ESPERADO durante la ventana de despliegue, pero que igual tiene que
                 * poder verse: es la diferencia entre "todavia no llego el esquema" y "hace
                 * tres semanas que no se captura nada y nadie sabe por que". Una vez por
                 * proceso y en nivel info, no warning: ver avisarUnaVez.
                 */
                self::avisarUnaVez(
                    'tabla',
                    'BuyerTrackingHelper: la tabla '.self::TABLA.' no existe todavia en esta base. '
                    .'No se captura nada hasta que llegue el esquema de empresa-api.'
                );

                return 0;
            }

            /** Guarda 3: el interruptor del comercio. Una query, memoizada por request. */
            if (!self::comercioTieneLaExtencion($user_id)) {
                /*
                 * Idem: el comercio no tiene prendida la extension. Es una decision valida y
                 * frecuente, pero si el SPA igual esta mandando eventos hay algo desalineado
                 * entre el interruptor del front y el del back, y conviene poder verlo.
                 */
                self::avisarUnaVez(
                    'extencion:'.$user_id,
                    'BuyerTrackingHelper: el comercio '.$user_id.' no tiene la extension '
                    .self::SLUG_EXTENCION.'. Llegan eventos y se descartan todos.'
                );

                return 0;
            }

            /**
             * Guarda 4: query builder y no Eloquent, a proposito. Sin hidratar objetos y
             * sin disparar eventos de modelo: esto corre en cada vista de producto de cada
             * visitante, y el lote entero entra en una sola sentencia.
             */
            DB::table(self::TABLA)->insert($filas);

            return count($filas);
        } catch (\Throwable $e) {
            self::registrarFalla($e, $eventos);

            return 0;
        }
    }

    /**
     * Deja constancia de una falla de la ingesta, con contexto suficiente para
     * diagnosticarla y sin un solo dato del comprador.
     *
     * El try anidado no es decorativo: este metodo es lo ultimo que corre antes de que la
     * excepcion se de por atendida, asi que un logger roto no puede ser la via por la que
     * termine escapando igual.
     *
     * @param \Throwable $e
     * @param mixed $eventos Lote crudo. Solo se usa para contar, nunca se loguea su contenido.
     * @return void
     */
    private static function registrarFalla($e, $eventos)
    {
        try {
            Log::warning('BuyerTrackingHelper: fallo la ingesta de eventos de tracking.', [
                'excepcion' => get_class($e),
                'mensaje'   => $e->getMessage(),
                'archivo'   => $e->getFile() . ':' . $e->getLine(),
                'eventos'   => is_array($eventos) ? count($eventos) : 0,
            ]);
        } catch (\Throwable $ignorada) {
            /* Si hasta el logger esta roto, no queda nada mas que hacer: lo que no puede
               pasar es que la navegacion del comprador se rompa por un evento de tracking. */
        }
    }

    /**
     * Deja constancia de un descarte de CONFIGURACION, una sola vez por proceso.
     *
     * -----------------------------------------------------------------------------------
     * Por que existe este metodo
     * -----------------------------------------------------------------------------------
     * Las guardas que cortan por configuracion (falta la tabla, falta la extension, no viene
     * el commerce_id) devolvian 0 sin decir una palabra. Eso es justo lo que el docblock de
     * esta clase dice estar evitando: convierten "hace tres semanas que no se captura nada"
     * en algo que nadie puede notar. La respuesta HTTP tiene que ser muda para el comprador;
     * el log no.
     *
     * Dos decisiones, y las dos importan:
     *
     * 1. UNA VEZ POR PROCESO. Estos estados no cambian dentro de un request y duran dias o
     *    semanas. Loguearlos por lote serian miles de lineas por dia en un comercio con
     *    trafico, y ese ruido termina tapando las fallas de verdad — el mismo argumento por
     *    el que existe la guarda Schema::hasTable en vez de dejarselo al try/catch.
     *
     * 2. NIVEL info, NO warning. warning y error quedan reservados para las fallas de
     *    verdad (el catch de registrar()). Que la tabla no este todavia, o que un comercio
     *    no tenga prendida la extension, son estados PREVISTOS del despliegue: reportarlos
     *    como fallas seria mentir en el otro sentido, y ademas volveria inutil la unica
     *    asercion que distingue "la guarda anda" de "el try/catch la tapa"
     *    (ContratoConEmpresaApiTest, que fija que ese camino no genera warnings).
     *
     * NO se avisa por los descartes evento por evento (tipo invalido, uuid mal formado, un
     * amount fuera de rango): esos si serian ruido, son normales y no significan que la
     * captura este rota.
     *
     * Los descartes per-lote sin misterio (lote vacio, todas las filas descartadas) tampoco
     * se avisan: no dicen nada que el operador pueda accionar.
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
     * ¿Existe la tabla de eventos? Memoizado por request.
     *
     * 🔴 En consola NO se memoiza, y ese es justamente el punto de la memoria.
     *
     * "Por request" se cumple en PHP-FPM porque el proceso se recicla entre requests. Un
     * proceso de consola no: la suite de tests corre todos los casos en el mismo proceso, y
     * ahi el estatico se convierte en un cache persistente que sirve una respuesta vieja —
     * arruinando justo el test que verifica el camino de "la tabla no esta", que renombra
     * la tabla en caliente. Es el mismo razonamiento (y el mismo remedio) que
     * DemoTrackingConfigHelper::actual() en `empresa-api`.
     *
     * @return bool
     */
    private static function hayTabla()
    {
        if (is_null(self::$hay_tabla) || app()->runningInConsole()) {
            self::$hay_tabla = Schema::hasTable(self::TABLA);
        }

        return self::$hay_tabla;
    }

    /**
     * ¿El comercio tiene prendida la extension `tracking_buyers`? Memoizado por request.
     *
     * Se resuelve con un join por la base y no con el modelo: `CommerceHelper::hasExtencion`
     * hace `User::find()` y despues recorre la coleccion entera de extensiones del comercio,
     * o sea dos queries y dos hidrataciones para responder un booleano. Este camino corre en
     * cada lote de cada visitante.
     *
     * Lo que NO cambia es el criterio: la misma tabla pivote y el mismo slug que mira el
     * helper viejo, y el mismo que mira el SPA con commerce_has_extencion(). El front decide
     * si manda o no, pero el que decide de verdad es este.
     *
     * @param int $user_id
     * @return bool
     */
    private static function comercioTieneLaExtencion($user_id)
    {
        if (!array_key_exists($user_id, self::$extenciones) || app()->runningInConsole()) {
            self::$extenciones[$user_id] = DB::table('extencion_empresa_user')
                ->join(
                    'extencion_empresas',
                    'extencion_empresas.id',
                    '=',
                    'extencion_empresa_user.extencion_empresa_id'
                )
                ->where('extencion_empresa_user.user_id', $user_id)
                ->where('extencion_empresas.slug', self::SLUG_EXTENCION)
                ->exists();
        }

        return self::$extenciones[$user_id];
    }

    /**
     * Descarta la memoria de este proceso. La usan los tests, que prenden y apagan la
     * extension entre casos dentro del mismo proceso.
     *
     * @return void
     */
    public static function olvidarMemoria()
    {
        self::$hay_tabla = null;
        self::$extenciones = [];

        /* Tambien los avisos: si no, el segundo caso del proceso no podria medir que se avisa. */
        self::$avisos = [];
    }

    /**
     * Normaliza el lote entero y devuelve las filas listas para insertar.
     *
     * El recorte a MAX_EVENTOS_POR_LOTE va ANTES de normalizar: un lote de 5000 eventos no
     * puede costar 5000 normalizaciones para despues tirar 4950.
     *
     * Un evento invalido se descarta SOLO A EL. Tirar el lote entero por un evento mal
     * formado perderia los 49 buenos que venian al lado.
     *
     * @param array $eventos
     * @param int $user_id
     * @param int|null $buyer_id
     * @return array
     */
    private static function normalizarLote(array $eventos, $user_id, $buyer_id)
    {
        $ahora = Carbon::now();
        $filas = [];

        foreach (array_slice(array_values($eventos), 0, self::MAX_EVENTOS_POR_LOTE) as $evento) {
            $fila = self::armarFila($evento, $user_id, $buyer_id, $ahora);

            if (!is_null($fila)) {
                $filas[] = $fila;
            }
        }

        return $filas;
    }

    /**
     * Arma una fila a partir de un evento crudo, o devuelve null si el evento no sirve.
     *
     * 🔴 El array que devuelve es LA LISTA BLANCA de columnas: lo que no esta aca no se lee
     * del payload, asi que un `email` o un `ip` que venga en el evento no tiene por donde
     * llegar a la base. No agregues claves sin mirar la migracion de `empresa-api`.
     *
     * @param mixed $evento
     * @param int $user_id
     * @param int|null $buyer_id
     * @param \Carbon\Carbon $ahora
     * @return array|null
     */
    private static function armarFila($evento, $user_id, $buyer_id, $ahora)
    {
        if (!is_array($evento)) {
            return null;
        }

        $event_type = self::texto($evento, 'event_type');

        /* Defensa en profundidad: el SPA ya manda solo estos tipos, pero el helper no le cree. */
        if (!in_array($event_type, self::TIPOS, true)) {
            return null;
        }

        $visitor_id = self::texto($evento, 'visitor_id');
        $session_id = self::texto($evento, 'session_id');

        /*
         * Las dos columnas son char(36) NOT NULL. Sin identidad del visitante el evento no
         * se puede atribuir a ningun recorrido, asi que no vale la pena guardarlo.
         */
        if (!Str::isUuid($visitor_id) || !Str::isUuid($session_id)) {
            return null;
        }

        return [
            'user_id'         => $user_id,
            'buyer_id'        => $buyer_id,
            'visitor_id'      => $visitor_id,
            'session_id'      => $session_id,
            'event_type'      => $event_type,
            'article_id'      => self::idPositivo(self::valor($evento, 'article_id')),
            'category_id'     => self::idPositivo(self::valor($evento, 'category_id')),
            'sub_category_id' => self::idPositivo(self::valor($evento, 'sub_category_id')),
            'search_term'     => self::normalizarTermino(self::valor($evento, 'search_term')),
            'results_count'   => self::enteroAcotado(self::valor($evento, 'results_count')),
            'quantity'        => self::enteroAcotado(self::valor($evento, 'quantity')),
            'amount'          => self::monto(self::valor($evento, 'amount')),
            'dwell_ms'        => self::normalizarDwell(self::valor($evento, 'dwell_ms')),
            'order_id'        => self::idPositivo(self::valor($evento, 'order_id')),
            'occurred_at'     => self::normalizarOcurrencia(self::valor($evento, 'occurred_at'), $ahora),
            'created_at'      => $ahora->format('Y-m-d H:i:s'),
            'updated_at'      => $ahora->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Lee una clave del evento sin asumir que existe.
     *
     * @param array $evento
     * @param string $clave
     * @return mixed
     */
    private static function valor(array $evento, $clave)
    {
        return array_key_exists($clave, $evento) ? $evento[$clave] : null;
    }

    /**
     * Lee una clave del evento como texto recortado.
     *
     * `is_scalar` y no un cast directo: el cuerpo lo arma el navegador, y un
     * `{"event_type": ["product_view"]}` convertiria esto en un "Array to string conversion"
     * — que sube como ErrorException justo por donde este helper promete no romper nada.
     *
     * @param array $evento
     * @param string $clave
     * @return string
     */
    private static function texto(array $evento, $clave)
    {
        $valor = self::valor($evento, $clave);

        return is_scalar($valor) ? trim((string) $valor) : '';
    }

    /**
     * Id valido (entero > 0) o null.
     *
     * Sirve para todas las FK logicas y tambien para el user_id. El 0 y los negativos van a
     * null a proposito: no son ids, son un cliente mandando basura.
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
     * Entero que entra en una columna `unsignedInteger`, o null.
     *
     * 🔴 El 0 se preserva y NO se confunde con null: en `results_count`, "busco y no
     * encontro nada" es el dato mas valioso de toda la tabla para el motor de ofertas.
     * Por eso el chequeo es contra is_numeric y no contra empty().
     *
     * Fuera de rango (negativo o por encima del techo de unsigned int) va a null en vez de
     * recortarse: con `strict` prendido MySQL rechaza el insert y se perderia el LOTE
     * entero por un valor de un evento.
     *
     * @param mixed $valor
     * @return int|null
     */
    private static function enteroAcotado($valor)
    {
        if (!is_numeric($valor)) {
            return null;
        }

        $entero = (int) $valor;

        if ($entero < 0 || $entero > self::MAX_UNSIGNED_INT) {
            return null;
        }

        return $entero;
    }

    /**
     * Monto que entra en un `decimal(22,2)`, o null.
     *
     * Los negativos van a null: un monto de compra negativo es basura, no un dato.
     *
     * @param mixed $valor
     * @return float|null
     */
    private static function monto($valor)
    {
        if (!is_numeric($valor)) {
            return null;
        }

        $monto = (float) $valor;

        if ($monto < 0 || $monto > self::MAX_AMOUNT) {
            return null;
        }

        return round($monto, 2);
    }

    /**
     * Termino buscado, en minusculas y recortado al ancho de la columna.
     *
     * Es el mismo tratamiento que `last_searches.body` ya le da hoy al mismo dato
     * (LastSearchController@store), asi que los dos historicos quedan comparables.
     *
     * mb_strtolower y mb_substr y no sus versiones sin mb: un termino con acentos o con
     * emojis recortado a byte deja un caracter partido al medio, y MySQL rechaza el insert.
     *
     * @param mixed $valor
     * @return string|null
     */
    private static function normalizarTermino($valor)
    {
        if (!is_scalar($valor)) {
            return null;
        }

        $termino = trim(mb_strtolower((string) $valor));

        if ($termino === '') {
            return null;
        }

        return mb_substr($termino, 0, self::LARGO_SEARCH_TERM);
    }

    /**
     * Tiempo en pantalla acotado a MAX_DWELL_MS.
     *
     * Por encima del tope se descarta el VALOR y no el evento: que alguien haya dejado la
     * pestaña abierta toda la noche no invalida que haya visto el producto.
     *
     * @param mixed $valor
     * @return int|null
     */
    private static function normalizarDwell($valor)
    {
        $dwell = self::enteroAcotado($valor);

        if (is_null($dwell) || $dwell > self::MAX_DWELL_MS) {
            return null;
        }

        return $dwell;
    }

    /**
     * Momento del evento, acotado a la ventana [ahora - 24h, ahora].
     *
     * Se le acepta la marca al cliente porque el evento pudo haberse encolado en el
     * navegador y salir varios segundos despues, y esa diferencia es informacion real. Lo
     * que no se le acepta es un reloj mal seteado: fuera de la ventana se usa el instante
     * del servidor. Sin esto, una maquina con la fecha en 2019 —o en 2031— ensucia el
     * historico para siempre y cualquier consulta por rango de fechas queda mintiendo.
     *
     * @param mixed $valor
     * @param \Carbon\Carbon $ahora
     * @return string
     */
    private static function normalizarOcurrencia($valor, $ahora)
    {
        $servidor = $ahora->format('Y-m-d H:i:s');

        if (!is_scalar($valor) || trim((string) $valor) === '') {
            return $servidor;
        }

        /* Carbon::parse tira con cualquier texto que no sepa leer, y el texto lo manda el navegador. */
        try {
            $fecha = Carbon::parse((string) $valor);
        } catch (\Throwable $e) {
            return $servidor;
        }

        $minimo = $ahora->copy()->subHours(self::MAX_ANTIGUEDAD_HORAS);

        if ($fecha->lt($minimo) || $fecha->gt($ahora)) {
            return $servidor;
        }

        return $fecha->format('Y-m-d H:i:s');
    }
}
