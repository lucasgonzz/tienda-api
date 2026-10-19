<?php

namespace App\Broadcasting;

use Illuminate\Support\Facades\DB;

/**
 * El "espacio de nombres" de los canales en vivo de ESTE sistema (misión
 * pusher-canales-por-sistema, 19/10/2026).
 *
 * 🔴 POR QUÉ EXISTE. Toda la flota (demos incluidas) usa UNA sola app de Pusher, y hasta esta
 * misión los canales llevaban solo un id (`order.created.{owner}`, `tienda-mensajes.{owner}`,
 * `message.from_commerce.{buyer}`…). Dos sistemas con el mismo id de dueño se escuchaban entre sí:
 * el 19/10 el aviso del escaneo de una factura de demo3 (USER_ID=2600) le apareció a Ferretotal,
 * cuyo dueño también es el 2600. Los canales privados no protegían nada: cada sistema autoriza a
 * SU usuario con SU API, y si el id coincide, escucha lo del otro.
 *
 * La salida es agregarle a cada canal un sufijo propio del sistema:
 *
 *     canal lógico:  order.created.2600
 *     canal físico:  order.created.2600@26d5013a6846
 *
 * con
 *
 *     espacio = substr(hash_hmac('sha256', 'comerciocity-canales|' . APP_ENV . '|' . BASE, CLAVE), 0, 12)
 *
 *  - BASE    = DB::connection()->getDatabaseName(): la base por defecto de la API. Dentro de una
 *              base los ids son clave primaria, así que (base, id) es único aunque 51 comercios
 *              compartan `u767360347_empresa`. empresa-api y tienda-api del mismo cliente leen la
 *              MISMA base, así que dan el mismo espacio sin hablarse (es lo que permite que el ERP
 *              escuche lo que emite la tienda y al revés).
 *  - APP_ENV = config('app.env'): separa la copia local de una base real (el MySQL local tiene
 *              `ferretotal`…) de la de producción aunque se llamen igual; los slots emiten a la
 *              misma app de Pusher que la flota.
 *  - CLAVE   = el secret de Pusher (o la constante de respaldo si está vacío). Con el secret, el
 *              nombre del canal no se adivina desde afuera sabiendo el nombre de la base. Dos
 *              procesos que comparten canales están en la misma app de Pusher, y una app tiene un
 *              solo secret: el resultado es consistente donde importa.
 *
 * 🔴 ESTA CLASE TIENE UNA GEMELA en `empresa-api` (app/Broadcasting/EspacioDeCanales.php), que
 * corre en PHP 7.4. Las dos tienen que dar EL MISMO string para la misma entrada: si difieren, el
 * ERP y la tienda del mismo cliente dejan de oírse en modo estricto, sin ningún error en ningún
 * lado. Los dos repos afirman los mismos tres vectores literales en sus tests
 * (tests/Feature/Broadcast/CanalesPorSistemaTest.php). Cualquier cambio del algoritmo se hace en
 * los dos repos a la vez.
 *
 * Lo que tienda-api NO tiene, a diferencia de la gemela: la parte de autorización de canales
 * privados. tienda-api no autoriza ningún canal (routes/channels.php está todo comentado y
 * tienda-spa no se suscribe a privados): solo emite.
 */
class EspacioDeCanales
{
    /**
     * Separador entre el canal lógico y el espacio. `@` es válido en un nombre de canal de Pusher
     * (pusher-php-server valida con /\A#?[-a-zA-Z0-9_=@,.;]+\z/) y no aparece en ninguno de los
     * canales lógicos de hoy, así que el sufijo nunca se confunde con parte del nombre.
     *
     * @var string
     */
    const SEPARADOR = '@';

    /**
     * Prefijo fijo del mensaje que se firma. Es parte del contrato con empresa-api: cambiarlo
     * cambia el espacio de toda la flota.
     *
     * @var string
     */
    const PREFIJO_DEL_MENSAJE = 'comerciocity-canales|';

    /**
     * Clave de la firma cuando el secret de Pusher está vacío (los tests, un .env sin Pusher). No
     * protege nada contra alguien que la conozca, pero mantiene el algoritmo determinista e igual
     * en los dos repos.
     *
     * @var string
     */
    const CLAVE_DE_RESPALDO = 'comerciocity-canales';

    /**
     * Largo del espacio calculado: los primeros 12 caracteres hexadecimales del HMAC (48 bits).
     *
     * @var int
     */
    const LARGO_DEL_ESPACIO = 12;

    /**
     * Largo máximo del override `broadcasting.espacio` después de sanearlo.
     *
     * @var int
     */
    const LARGO_MAXIMO_DEL_OVERRIDE = 32;

    /**
     * Espacios ya calculados en este proceso, por entrada (base, entorno, clave, override).
     *
     * Se indexa por las cuatro entradas y no se guarda "el" espacio a secas a propósito: si un
     * test (o un worker largo) cambia la config o la base en caliente, el valor nuevo se calcula
     * solo, sin que nadie tenga que acordarse de vaciar nada.
     *
     * @var array<string, string>
     */
    private static $espacios_calculados = [];

    /**
     * El algoritmo puro: el espacio para una base, un entorno y una clave dados.
     *
     * Es estático y sin dependencias a propósito: es lo que los tests de los dos repos comparan
     * contra los vectores literales del contrato.
     *
     * @param  string|null  $base     Nombre de la base de datos por defecto de la API.
     * @param  string|null  $entorno  APP_ENV (`production`, `local`, `testing`…).
     * @param  string|null  $clave    Secret de Pusher; vacío o null usa CLAVE_DE_RESPALDO.
     * @return string                 12 caracteres hexadecimales en minúscula.
     */
    public static function calcular($base, $entorno, $clave)
    {
        // El mensaje firmado: prefijo fijo, entorno y base, separados por `|`.
        $mensaje = self::PREFIJO_DEL_MENSAJE . (string) $entorno . '|' . (string) $base;

        // hash_hmac devuelve el hexadecimal en minúscula; se toman los primeros 12.
        $firma = hash_hmac('sha256', $mensaje, self::clave_efectiva($clave));

        return substr($firma, 0, self::LARGO_DEL_ESPACIO);
    }

    /**
     * El espacio de ESTE sistema: el override saneado si hay uno, y si no el calculado con la base
     * por defecto, el entorno y el secret de Pusher del proceso.
     *
     * @return string
     */
    public static function espacio()
    {
        // Las cuatro entradas que determinan el resultado.
        $override = self::override_saneado();
        $base = self::base_actual();
        $entorno = (string) config('app.env');
        $clave = (string) config('broadcasting.connections.pusher.secret');

        // Llave del cache: las cuatro entradas separadas por un caracter que no aparece en ellas.
        $llave = implode("\0", [$base, $entorno, $clave, $override]);

        if (isset(self::$espacios_calculados[$llave])) {
            return self::$espacios_calculados[$llave];
        }

        // Un override no vacío (después de sanearlo) gana sobre el cálculo.
        $espacio = $override !== '' ? $override : self::calcular($base, $entorno, $clave);

        self::$espacios_calculados[$llave] = $espacio;

        return $espacio;
    }

    /**
     * El nombre físico de un canal: el lógico con el sufijo `@espacio`.
     *
     * Idempotente: un nombre que ya termina con el sufijo propio vuelve igual, así nunca queda
     * `canal@espacio@espacio` si algo lo traduce dos veces.
     *
     * El `private-` / `presence-` de Pusher queda adelante sin tocarlo: el sufijo va al final.
     *
     * @param  string  $canal  Nombre del canal tal como lo arma el evento (`private-x.1`, `x.1`).
     * @return string
     */
    public static function con_espacio($canal)
    {
        $canal = (string) $canal;

        // El sufijo propio de este sistema.
        $sufijo = self::SEPARADOR . self::espacio();

        if (self::termina_con($canal, $sufijo)) {
            return $canal;
        }

        return $canal . $sufijo;
    }

    /**
     * El modo de los canales de tienda: true = transición (default), false = estricto.
     *
     * En transición todo lo que emite tienda-api sale con Y sin espacio (ver nombres_para_emitir),
     * para que una punta nueva siga andando con la otra vieja (regla de compatibilidad de Lucas del
     * 14/8: el ERP sale por release a cada cliente y la tienda se actualiza aparte). El estricto se
     * prende en las dos mitades del mismo sistema a la vez (empresa-api y tienda-api).
     *
     * @return bool
     */
    public static function canales_viejos_de_tienda()
    {
        return filter_var(config('broadcasting.canales_viejos_de_tienda', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Los nombres por los que hay que emitir un canal.
     *
     * TODO lo que emite tienda-api es "de tienda": lo escucha el ERP (`order.created.{owner}`,
     * `private-tienda-mensajes.{owner}`, `added_model.{owner}`, `message.from_buyer.{owner}`) o el
     * comprador (`message.from_commerce.{buyer}`). Por eso acá no hay lista de prefijos como en la
     * gemela de empresa-api: el modo vale para todos.
     *
     *  - Transición: [lógico, físico]. Un solo trigger con los dos nombres, sin costo extra. El que
     *    escucha viejo oye el lógico; el que escucha nuevo en transición también escucha el lógico
     *    (no recibe dos veces); el nuevo en estricto escucha el físico.
     *  - Estricto: [físico].
     *
     * @param  string  $canal
     * @return string[]
     */
    public static function nombres_para_emitir($canal)
    {
        $canal = (string) $canal;
        $fisico = self::con_espacio($canal);

        // Estricto, o un canal que ya venía con el sufijo propio (no hay lógico que emitir).
        if (!self::canales_viejos_de_tienda() || $fisico === $canal) {
            return [$fisico];
        }

        return [$canal, $fisico];
    }

    /**
     * Lo que el SPA necesita saber para armar los nombres físicos: el atributo `canales_en_vivo`
     * de App\Buyer (contrato API → SPA, idéntico al de App\Models\User en empresa-api).
     *
     * @return array{espacio: string, canales_viejos_de_tienda: bool}
     */
    public static function para_el_spa()
    {
        return [
            'espacio'                  => self::espacio(),
            'canales_viejos_de_tienda' => self::canales_viejos_de_tienda(),
        ];
    }

    /**
     * El override `broadcasting.espacio` (env BROADCAST_ESPACIO) saneado: en minúscula, solo
     * `[a-z0-9]` y hasta 32 caracteres. Vacío si no hay override o si no sobrevive nada al saneo
     * (en ese caso se usa el calculado).
     *
     * Es para tests y para alinear a mano las dos mitades de un mismo sistema si algún día no
     * coinciden. No se carga en ningún .env en la misión que lo creó.
     *
     * @return string
     */
    private static function override_saneado()
    {
        $override = config('broadcasting.espacio');

        if ($override === null || $override === false) {
            return '';
        }

        // Se pasa a minúscula antes de filtrar: `ABC` es `abc` y no se pierde.
        $saneado = preg_replace('/[^a-z0-9]/', '', strtolower((string) $override));

        return substr((string) $saneado, 0, self::LARGO_MAXIMO_DEL_OVERRIDE);
    }

    /**
     * Nombre de la base por defecto de la API.
     *
     * `DB::connection()` no abre la conexión (el PDO de Laravel es perezoso): solo arma el objeto
     * y lee el nombre de su config. Si ni eso se puede (una config de base rota), se cae al nombre
     * que dice la config, para que el espacio siga siendo determinista.
     *
     * @return string
     */
    private static function base_actual()
    {
        try {
            return (string) DB::connection()->getDatabaseName();
        } catch (\Throwable $excepcion) {
            $conexion = config('database.default');

            return (string) config('database.connections.' . $conexion . '.database', '');
        }
    }

    /**
     * La clave de la firma: el secret, o la constante de respaldo si está vacío.
     *
     * @param  string|null  $clave
     * @return string
     */
    private static function clave_efectiva($clave)
    {
        if ($clave === null || (string) $clave === '') {
            return self::CLAVE_DE_RESPALDO;
        }

        return (string) $clave;
    }

    /**
     * Si `$texto` termina con `$final`. Sin str_ends_with para que el código sea el mismo que el de
     * la gemela de empresa-api (PHP 7.4).
     *
     * @param  string  $texto
     * @param  string  $final
     * @return bool
     */
    private static function termina_con($texto, $final)
    {
        $largo = strlen($final);

        return $largo > 0 && strlen($texto) >= $largo && substr($texto, -$largo) === $final;
    }
}
