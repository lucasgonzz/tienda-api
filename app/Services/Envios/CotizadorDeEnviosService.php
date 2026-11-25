<?php

namespace App\Services\Envios;

use App\Services\Andreani\AndreaniCotizadorService;
use App\Services\Zipnova\SinArticulosException;
use App\Services\Zipnova\SinZipnovaException;
use App\Services\Zipnova\UbicacionException;
use App\Services\Zipnova\ZipnovaCotizadorService;
use App\Services\Zipnova\ZipnovaException;
use Illuminate\Support\Facades\Log;

/**
 * El cotizador de envíos por correo de la tienda: junta a Zipnova y a Andreani directo en UNA lista (misión
 * envios-andreani, 25/11/2026). Es la única puerta nueva: `POST /api/envios/cotizar` y `EnvioCartHelper` (el
 * checkout) llaman acá en vez de a `ZipnovaCotizadorService`, que queda intacto.
 *
 * ── La regla ──────────────────────────────────────────────────────────────────────────────────
 *
 * 1. Sin Andreani (no conectado, apagado o sin esquema): devuelve EXACTAMENTE lo de
 *    `ZipnovaCotizadorService::cotizar()` y deja pasar sus excepciones. La respuesta es la de siempre, clave por
 *    clave: ni `proveedores` ni `proveedor` en las opciones.
 * 2. Con Andreani: se le pregunta a Zipnova (sus fallas se atrapan) y a Andreani (nunca lanza).
 *    - Si Andreani dio precio, se muestran sus opciones junto a las de Zipnova y se OCULTA el «Andreani» que ofrece
 *      Zipnova (decisión de Lucas, 25/11: P2 = sí). Solo cuando el directo dio precio para ese carrito: si el
 *      directo falla, el de Zipnova se queda.
 *    - Si Zipnova no puede cotizar el paquete (200 sin opciones seleccionables, 4xx de medidas, caída, destino que
 *      no reconoce, sin conector) y Andreani sí, la respuesta es 200 con Andreani solo: el cambio automático del
 *      pedido de Lucas, que hoy terminaba en «no se puede cotizar».
 *    - Si ninguno da precio, se relanza la excepción de Zipnova (el mismo 422/502 de hoy) o, si Zipnova respondió 200
 *      vacío, se devuelve 200 vacío con `sin_opciones_motivo` si el paquete excede los topes de Andreani.
 * 3. Orden de la lista: precio, después `dias_min` (null al final), después `key` (el criterio del normalizador de
 *    Zipnova).
 *
 * Solo cuando Andreani está en juego se suman dos claves aditivas: `proveedores` (`{zipnova, andreani}`, ver
 * `estado_de_zipnova()` y los motivos de `AndreaniCotizadorService`) y `sin_opciones_motivo` (`'medidas'` o null).
 */
class CotizadorDeEnviosService
{
    /**
     * Cotiza y devuelve la lista normalizada de opciones de todos los proveedores del comercio.
     *
     * @param int $commerce_id Comercio (owner) dueño de los artículos.
     * @param array $lineas `[['article' => Article, 'amount' => int], ...]`.
     * @param float $subtotal Subtotal de artículos.
     * @param string $zipcode Código postal de destino, ya limpio.
     * @param string|null $city Localidad, si el comprador la indicó.
     * @param string|null $state Provincia, si el comprador la indicó.
     * @return array La cotización de Zipnova (`zipcode, city, state, envio_gratis, declared_value, quoted_at, opciones`) y, con Andreani,
     *               además `proveedores` y `sin_opciones_motivo`.
     * @throws SinZipnovaException|SinArticulosException|UbicacionException|ZipnovaException Las de Zipnova, cuando no hay otra opción.
     */
    public static function cotizar($commerce_id, array $lineas, $subtotal, $zipcode, $city = null, $state = null)
    {
        // Sin Andreani no cambia nada de nada: es la cotización de Zipnova, con sus excepciones.
        if (!AndreaniCotizadorService::ofrece($commerce_id)) {
            return ZipnovaCotizadorService::cotizar($commerce_id, $lineas, $subtotal, $zipcode, $city, $state);
        }

        $zipnova = null;
        $zipnova_falla = null;

        try {
            $zipnova = ZipnovaCotizadorService::cotizar($commerce_id, $lineas, $subtotal, $zipcode, $city, $state);
        } catch (SinArticulosException $e) {
            // Nada que enviar: igual que hoy, con o sin Andreani.
            throw $e;
        } catch (ZipnovaException $e) {
            // Sin conector, destino que Zipnova no reconoce o Zipnova caído: se ve si Andreani puede.
            $zipnova_falla = $e;

            if (!($e instanceof SinZipnovaException) && !($e instanceof UbicacionException)) {
                Log::warning('CotizadorDeEnviosService: Zipnova no pudo cotizar, se prueba con Andreani', [
                    'commerce_id' => $commerce_id,
                    'zipcode'     => $zipcode,
                    'status'      => $e->getStatus(),
                    'detalle'     => $e->getMessage(),
                ]);
            }
        }

        $andreani = AndreaniCotizadorService::cotizar($commerce_id, $lineas, $subtotal, $zipcode);

        if ($andreani['motivo'] === 'sin_articulos') {
            // Solo Andreani en juego y nada que enviar: el mismo 422 `sin_articulos` de siempre.
            throw new SinArticulosException('No hay artículos que requieran envío.');
        }

        $zipnova_opciones = is_null($zipnova) ? [] : $zipnova['opciones'];
        $hay_andreani = count($andreani['opciones']) > 0;

        // Ninguno dio precio.
        if (!$hay_andreani && count($zipnova_opciones) === 0) {
            // Zipnova falló: se relanza SU error (el mismo 422/502 de hoy). Si además no estaba conectado (Andreani
            // es el único proveedor y está caído) se avisa con un 502, no con un «no tenés envíos configurados».
            if (!is_null($zipnova_falla)) {
                if ($zipnova_falla instanceof SinZipnovaException && $andreani['motivo'] === 'error') {
                    throw new ZipnovaException('Andreani no pudo cotizar en este momento.', 0);
                }

                if ($zipnova_falla instanceof SinZipnovaException && in_array($andreani['motivo'], ['sin_tarifa', 'excede_medidas', 'demasiados_bultos'], true)) {
                    return self::respuesta($commerce_id, $zipnova, $zipnova_falla, $andreani, [], $zipcode, $city, $state);
                }

                throw $zipnova_falla;
            }

            // Zipnova respondió 200 sin opciones y Andreani tampoco: 200 vacío, explicando si es por el tamaño.
            return self::respuesta($commerce_id, $zipnova, null, $andreani, [], $zipcode, $city, $state);
        }

        // Con precio de Andreani directo se oculta el Andreani de Zipnova (que sería el mismo correo, más caro).
        if ($hay_andreani) {
            $zipnova_opciones = array_values(array_filter($zipnova_opciones, function ($opcion) {
                return !self::es_andreani_de_zipnova($opcion);
            }));
        }

        $opciones = array_merge($andreani['opciones'], $zipnova_opciones);

        self::ordenar($opciones);

        return self::respuesta($commerce_id, $zipnova, $zipnova_falla, $andreani, $opciones, $zipcode, $city, $state);
    }

    /**
     * Lo que `GET /api/commerce/{id}` publica del envío por correo: lo de Zipnova tal cual (`envios_zipnova` y
     * `envios_zipnova_config` no cambian de significado) más tres claves aditivas: `envios_andreani`,
     * `envios_correo` (Zipnova o Andreani) y `envios_correo_config` (el menor `envio_gratis_desde` no nulo de los dos).
     * Nunca lanza.
     *
     * @param int $commerce_id
     * @return array
     */
    public static function disponibilidad_publica($commerce_id)
    {
        $zipnova = ZipnovaCotizadorService::disponibilidad_publica($commerce_id);
        $andreani = AndreaniCotizadorService::disponibilidad_publica($commerce_id);

        $umbrales = [];

        if (!empty($zipnova['envios_zipnova']) && !is_null($zipnova['envios_zipnova_config']['envio_gratis_desde'])) {
            $umbrales[] = (float) $zipnova['envios_zipnova_config']['envio_gratis_desde'];
        }

        if ($andreani['envios_andreani'] && !is_null($andreani['envio_gratis_desde'])) {
            $umbrales[] = (float) $andreani['envio_gratis_desde'];
        }

        return array_merge($zipnova, [
            'envios_andreani'      => $andreani['envios_andreani'],
            'envios_correo'        => !empty($zipnova['envios_zipnova']) || $andreani['envios_andreani'],
            'envios_correo_config' => ['envio_gratis_desde' => count($umbrales) > 0 ? min($umbrales) : null],
        ]);
    }

    /**
     * El proveedor de una opción de envío: el que ella declara (`proveedor`) o Zipnova, que es el de todas las
     * opciones anteriores a Andreani (sin esa clave).
     *
     * @param array $opcion Opción normalizada.
     * @return string `zipnova` o `andreani`.
     */
    public static function proveedor_de(array $opcion)
    {
        return isset($opcion['proveedor']) && is_string($opcion['proveedor']) && $opcion['proveedor'] !== ''
            ? $opcion['proveedor']
            : 'zipnova';
    }

    /**
     * True si la opción es el «Andreani» que ofrece Zipnova como uno de sus correos (se reconoce por el nombre,
     * sin acentos ni mayúsculas, y no por el `carrier_id` de Zipnova, que es un número que no es nuestro).
     *
     * @param array $opcion
     * @return bool
     */
    protected static function es_andreani_de_zipnova(array $opcion)
    {
        if (self::proveedor_de($opcion) !== 'zipnova') {
            return false;
        }

        $nombre = isset($opcion['carrier_name']) ? mb_strtolower(trim((string) $opcion['carrier_name'])) : '';
        $nombre = strtr($nombre, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);

        return strpos($nombre, 'andreani') !== false;
    }

    /**
     * Ordena la lista por precio, después por plazo mínimo (null al final) y después por key.
     *
     * @param array $opciones Se ordena en el lugar.
     * @return void
     */
    protected static function ordenar(array &$opciones)
    {
        usort($opciones, function ($a, $b) {
            if ($a['precio'] != $b['precio']) {
                return $a['precio'] < $b['precio'] ? -1 : 1;
            }
            $dias_a = is_null($a['dias_min']) ? PHP_INT_MAX : $a['dias_min'];
            $dias_b = is_null($b['dias_min']) ? PHP_INT_MAX : $b['dias_min'];
            if ($dias_a != $dias_b) {
                return $dias_a < $dias_b ? -1 : 1;
            }

            return strcmp($a['key'], $b['key']);
        });
    }

    /**
     * Arma la respuesta final con las claves de la cotización de Zipnova (cuando la hay) y las de Andreani.
     *
     * @param int $commerce_id
     * @param array|null $zipnova Cotización de Zipnova, o null si falló.
     * @param ZipnovaException|null $zipnova_falla Por qué falló Zipnova.
     * @param array $andreani `{opciones, motivo}` de Andreani.
     * @param array $opciones Lista final.
     * @param string $zipcode
     * @param string|null $city
     * @param string|null $state
     * @return array
     */
    protected static function respuesta($commerce_id, $zipnova, $zipnova_falla, array $andreani, array $opciones, $zipcode, $city, $state)
    {
        $base = is_null($zipnova) ? [
            'zipcode'        => (string) $zipcode,
            'city'           => is_string($city) && trim($city) !== '' ? trim($city) : null,
            'state'          => is_string($state) && trim($state) !== '' ? trim($state) : null,
            'envio_gratis'   => false,
            'declared_value' => null,
            'quoted_at'      => now()->toIso8601String(),
        ] : $zipnova;

        // Si Zipnova no respondió, el envío gratis es el de Andreani (la opción que el comprador ve).
        if (is_null($zipnova)) {
            foreach ($andreani['opciones'] as $opcion) {
                if (!empty($opcion['envio_gratis'])) {
                    $base['envio_gratis'] = true;
                }
            }
        }

        $base['opciones'] = $opciones;
        $base['proveedores'] = [
            'zipnova'  => self::estado_de_zipnova($zipnova, $zipnova_falla),
            'andreani' => count($andreani['opciones']) > 0 ? 'ok' : (string) $andreani['motivo'],
        ];

        $medidas = in_array($andreani['motivo'], ['excede_medidas', 'demasiados_bultos'], true);
        $base['sin_opciones_motivo'] = count($opciones) === 0 && $medidas ? 'medidas' : null;

        return $base;
    }

    /**
     * El estado de Zipnova en `proveedores`: `ok`, `sin_opciones` (200 sin ninguna opción seleccionable),
     * `ubicacion` (no reconoció el destino), `no_conectado` o `error` (falló o no respondió).
     *
     * @param array|null $zipnova
     * @param ZipnovaException|null $falla
     * @return string
     */
    protected static function estado_de_zipnova($zipnova, $falla)
    {
        if (!is_null($falla)) {
            if ($falla instanceof SinZipnovaException) {
                return 'no_conectado';
            }

            return $falla instanceof UbicacionException ? 'ubicacion' : 'error';
        }

        return count($zipnova['opciones']) > 0 ? 'ok' : 'sin_opciones';
    }
}
