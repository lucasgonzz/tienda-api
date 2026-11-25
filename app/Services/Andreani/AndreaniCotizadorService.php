<?php

namespace App\Services\Andreani;

use App\Http\Controllers\Helpers\AndreaniCredentialsHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use App\Services\Zipnova\ZipnovaEnvioGratisHelper;
use App\Services\Zipnova\ZipnovaPaquetesHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cotiza el envío de un conjunto de artículos con el número de cliente y los contratos de Andreani del comercio
 * (misión envios-andreani, 25/11/2026). Es la única puerta de la tienda hacia la API de tarifas y sucursales de
 * Andreani; la usa `CotizadorDeEnviosService` (el agregador que junta a Andreani con Zipnova).
 *
 * ── Qué decide ────────────────────────────────────────────────────────────────────────────────
 *
 *  - Si el comercio ofrece Andreani: esquema de envíos disponible + conector `andreani` conectado + «Ofrecer
 *    Andreani en la tienda» sin destildar (`AndreaniCredentialsHelper::se_ofrece_en_la_tienda()`).
 *  - Los bultos: un bulto por unidad, con `ZipnovaPaquetesHelper` (mismas medidas y bulto por defecto que Zipnova) y
 *    `AndreaniTarifaNormalizer::bultos_desde_items()`.
 *  - Los topes de paquetería (50 kg, 165 cm por lado, 300 cm de suma, 30 bultos): los aplicamos nosotros porque el
 *    cotizador de Andreani no rechaza por tamaño. Un paquete que los pasa NO se consulta.
 *  - Envío gratis: `ZipnovaEnvioGratisHelper` con la config de Andreani (`envio_gratis_desde`).
 *  - Retiro en sucursal: solo con contrato de sucursal Y al menos una sucursal que entregue y atienda en el CP.
 *
 * 🔴 NUNCA LANZA hacia afuera. Andreani caído, lento o con una respuesta rara no puede tumbar la cotización de
 * Zipnova (el pedido de Lucas del 25/11: Andreani es otra opción, no un punto de falla): cualquier problema sale
 * como `motivo` y la lista de opciones queda vacía. Un solo intento y timeout corto (`services.andreani.timeout`).
 *
 * Caché de diez minutos para tarifas y sucursales, como Zipnova: una falla nunca se cachea.
 *
 * Motivos (`motivo`, null si hay al menos una opción):
 *   `no_conectado`      el comercio no ofrece Andreani (no conectado, apagado o sin esquema).
 *   `sin_articulos`     nada de lo que hay en el carrito requiere envío.
 *   `excede_medidas`    algún bulto pasa los topes de Andreani.
 *   `demasiados_bultos` más de 30 bultos.
 *   `sin_tarifa`        Andreani rechazó la consulta por sus datos (400): contrato o CP que no reconoce.
 *   `error`             Andreani no respondió o falló (5xx, timeout) o hubo un error inesperado.
 */
class AndreaniCotizadorService
{
    /** Cuánto vive una tarifa o una lista de sucursales en caché: diez minutos. */
    const SEGUNDOS_DE_CACHE = 600;

    /** Prefijos de las claves de caché. */
    const PREFIJO_CACHE_TARIFA = 'andreani-tarifa:';
    const PREFIJO_CACHE_SUCURSALES = 'andreani-sucursales:';

    /**
     * True si el comercio ofrece Andreani en la tienda. Nunca lanza.
     *
     * @param int $commerce_id Comercio (owner).
     * @return bool
     */
    public static function ofrece($commerce_id)
    {
        try {
            return ZipnovaEsquemaHelper::disponible() && AndreaniCredentialsHelper::se_ofrece_en_la_tienda((int) $commerce_id);
        } catch (\Throwable $e) {
            Log::warning('AndreaniCotizadorService::ofrece: no se pudo resolver la integración del comercio ' . $commerce_id . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Cotiza y devuelve las opciones de Andreani (domicilio y, si corresponde, sucursal).
     *
     * @param int $commerce_id Comercio (owner) dueño de los artículos.
     * @param array $lineas `[['article' => Article, 'amount' => int], ...]` (ver `ZipnovaCotizadorService::lineas_desde_*`).
     * @param float $subtotal Subtotal de artículos, para el valor declarado y el envío gratis.
     * @param string $zipcode Código postal de destino, ya limpio.
     * @return array `{opciones: [...], motivo: null|string}` (ver los motivos en el docblock de la clase).
     */
    public static function cotizar($commerce_id, array $lineas, $subtotal, $zipcode)
    {
        try {
            return self::armar_cotizacion((int) $commerce_id, $lineas, $subtotal, (string) $zipcode);
        } catch (\Throwable $e) {
            // Última red: ni un error de programación en Andreani puede tirar la cotización de Zipnova.
            Log::warning('AndreaniCotizadorService::cotizar: error inesperado para el comercio ' . $commerce_id . ': ' . $e->getMessage());

            return ['opciones' => [], 'motivo' => 'error'];
        }
    }

    /**
     * Lo que `GET /api/commerce/{id}` publica de Andreani: si el comprador puede cotizar con él y desde qué monto
     * el envío es gratis (como incentivo). Nunca el número de cliente ni los contratos. Nunca lanza.
     *
     * @param int $commerce_id
     * @return array `{envios_andreani: bool, envio_gratis_desde: float|null}`
     */
    public static function disponibilidad_publica($commerce_id)
    {
        $publico = ['envios_andreani' => false, 'envio_gratis_desde' => null];

        try {
            if (!ZipnovaEsquemaHelper::disponible()) {
                return $publico;
            }

            $credentials = AndreaniCredentialsHelper::credentials((int) $commerce_id);

            if (is_null($credentials['cliente']) || !$credentials['config']['ofrecer_en_tienda']) {
                return $publico;
            }

            $publico['envios_andreani'] = true;
            $publico['envio_gratis_desde'] = $credentials['config']['envio_gratis_desde'];
        } catch (\Throwable $e) {
            Log::warning('AndreaniCotizadorService::disponibilidad_publica: no se pudo resolver la integración del comercio ' . $commerce_id . ', se publica como no disponible: ' . $e->getMessage());
        }

        return $publico;
    }

    /**
     * El cálculo de `cotizar()`, sin la red de seguridad.
     *
     * @param int $commerce_id
     * @param array $lineas
     * @param float $subtotal
     * @param string $zipcode
     * @return array
     */
    protected static function armar_cotizacion($commerce_id, array $lineas, $subtotal, $zipcode)
    {
        $vacio = ['opciones' => [], 'motivo' => null];

        if (!ZipnovaEsquemaHelper::disponible()) {
            $vacio['motivo'] = 'no_conectado';

            return $vacio;
        }

        $credentials = AndreaniCredentialsHelper::credentials($commerce_id);

        if (is_null($credentials['cliente']) || !$credentials['config']['ofrecer_en_tienda']) {
            $vacio['motivo'] = 'no_conectado';

            return $vacio;
        }

        $config = $credentials['config'];

        $items = ZipnovaPaquetesHelper::items_desde_lineas($lineas, $config['bulto_default']);

        if (count($items) === 0) {
            $vacio['motivo'] = 'sin_articulos';

            return $vacio;
        }

        $subtotal = is_numeric($subtotal) ? round((float) $subtotal, 2) : 0.0;
        $envio_gratis = ZipnovaEnvioGratisHelper::aplica($lineas, $subtotal, $config);
        $valor_declarado = $config['declarar_valor'] ? $subtotal : 0.0;

        $armado = AndreaniTarifaNormalizer::bultos_desde_items($items, $valor_declarado);

        if (!is_null($armado['motivo'])) {
            $vacio['motivo'] = $armado['motivo'];

            return $vacio;
        }

        $bultos = $armado['bultos'];

        // 🔴 Los topes los aplicamos nosotros: Andreani cotiza cualquier tamaño y después no puede hacer el envío.
        foreach ($bultos as $bulto) {
            if (AndreaniTarifaNormalizer::excede_limites($bulto)) {
                $vacio['motivo'] = 'excede_medidas';

                return $vacio;
            }
        }

        $consulta = AndreaniTarifaNormalizer::bultos_para_consulta($bultos, $config['declarar_valor']);
        $client = new AndreaniClient();

        $opciones = [];
        $motivo_de_falla = null;

        // Domicilio: es la opción base (el contrato de domicilio es obligatorio para estar conectado).
        try {
            $tarifa = self::tarifa($client, $commerce_id, $credentials['cliente'], $config['contrato_domicilio'], $zipcode, $consulta, $config['sucursal_origen']);

            $opcion = AndreaniTarifaNormalizer::opcion(AndreaniTarifaNormalizer::TIPO_DOMICILIO, $tarifa, $envio_gratis, [], $bultos, $valor_declarado);
            if (!is_null($opcion)) {
                $opciones[] = $opcion;
            }
        } catch (AndreaniException $e) {
            $motivo_de_falla = self::motivo_de($e);
        }

        // Retiro en sucursal: solo con contrato de sucursal y sucursales que sirvan en el CP.
        if (!is_null($config['contrato_sucursal'])) {
            $opcion_sucursal = self::opcion_de_sucursal($client, $commerce_id, $credentials['cliente'], $config, $zipcode, $consulta, $bultos, $valor_declarado, $envio_gratis);
            if (!is_null($opcion_sucursal)) {
                $opciones[] = $opcion_sucursal;
            }
        }

        if (count($opciones) === 0) {
            return ['opciones' => [], 'motivo' => is_null($motivo_de_falla) ? 'sin_tarifa' : $motivo_de_falla];
        }

        return ['opciones' => $opciones, 'motivo' => null];
    }

    /**
     * La opción de retiro en sucursal, o null si no hay sucursales que sirvan o Andreani no dio tarifa para el
     * contrato de sucursal. Opcional: una falla acá nunca borra la opción de domicilio.
     *
     * @param AndreaniClient $client
     * @param int $commerce_id
     * @param string $cliente
     * @param array $config
     * @param string $zipcode
     * @param array $consulta Bultos con las claves de la API de tarifas.
     * @param array $bultos Bultos del normalizador.
     * @param float $valor_declarado
     * @param bool $envio_gratis
     * @return array|null
     */
    protected static function opcion_de_sucursal(AndreaniClient $client, $commerce_id, $cliente, array $config, $zipcode, array $consulta, array $bultos, $valor_declarado, $envio_gratis)
    {
        try {
            $puntos = AndreaniTarifaNormalizer::puntos_de_retiro(self::sucursales($client, $zipcode));

            if (count($puntos) === 0) {
                return null;
            }

            $tarifa = self::tarifa($client, $commerce_id, $cliente, $config['contrato_sucursal'], $zipcode, $consulta, $config['sucursal_origen']);
        } catch (AndreaniException $e) {
            return null;
        }

        return AndreaniTarifaNormalizer::opcion(AndreaniTarifaNormalizer::TIPO_SUCURSAL, $tarifa, $envio_gratis, $puntos, $bultos, $valor_declarado);
    }

    /**
     * La tarifa cruda de Andreani, con caché. Solo se guarda una respuesta buena: una falla nunca se cachea.
     *
     * @param AndreaniClient $client
     * @param int $commerce_id
     * @param string $cliente
     * @param string $contrato
     * @param string $zipcode
     * @param array $consulta
     * @param int|null $sucursal_origen
     * @return array
     * @throws AndreaniException
     */
    protected static function tarifa(AndreaniClient $client, $commerce_id, $cliente, $contrato, $zipcode, array $consulta, $sucursal_origen)
    {
        $clave = self::PREFIJO_CACHE_TARIFA . (int) $commerce_id . ':' . md5(implode('|', [
            $cliente, $contrato, $zipcode, (string) $sucursal_origen, json_encode($consulta),
        ]));

        $cacheada = self::leer_cache($clave);

        if (is_array($cacheada)) {
            return $cacheada;
        }

        $tarifa = $client->tarifa($cliente, $contrato, $zipcode, $consulta, $sucursal_origen);

        self::escribir_cache($clave, $tarifa);

        return $tarifa;
    }

    /**
     * La lista cruda de sucursales de un código postal, con caché (la misma para todos los comercios: no depende de
     * ningún contrato).
     *
     * @param AndreaniClient $client
     * @param string $zipcode
     * @return array
     * @throws AndreaniException
     */
    protected static function sucursales(AndreaniClient $client, $zipcode)
    {
        $clave = self::PREFIJO_CACHE_SUCURSALES . md5($zipcode);

        $cacheada = self::leer_cache($clave);

        if (is_array($cacheada)) {
            return $cacheada;
        }

        $sucursales = $client->sucursales($zipcode);

        self::escribir_cache($clave, $sucursales);

        return $sucursales;
    }

    /**
     * Traduce una falla de Andreani al motivo con el que se informa.
     *
     * @param AndreaniException $e
     * @return string `sin_tarifa` si Andreani rechazó los datos; `error` si no respondió.
     */
    protected static function motivo_de(AndreaniException $e)
    {
        if ($e->esDeValidacion()) {
            return 'sin_tarifa';
        }

        Log::warning('AndreaniCotizadorService: Andreani no pudo cotizar (estado ' . $e->getStatus() . '): ' . $e->getMessage());

        return 'error';
    }

    /**
     * Lee la caché sin que un problema del storage rompa la cotización.
     *
     * @param string $clave
     * @return mixed null si no hay o no se pudo leer.
     */
    protected static function leer_cache($clave)
    {
        try {
            return Cache::get($clave);
        } catch (\Throwable $e) {
            Log::warning('AndreaniCotizadorService: no se pudo leer la caché: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Escribe la caché sin que un problema del storage rompa la cotización.
     *
     * @param string $clave
     * @param array $valor
     * @return void
     */
    protected static function escribir_cache($clave, array $valor)
    {
        try {
            Cache::put($clave, $valor, self::SEGUNDOS_DE_CACHE);
        } catch (\Throwable $e) {
            Log::warning('AndreaniCotizadorService: no se pudo escribir la caché: ' . $e->getMessage());
        }
    }
}
