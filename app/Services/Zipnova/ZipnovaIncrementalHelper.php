<?php

namespace App\Services\Zipnova;

/**
 * Compara dos cotizaciones —la del carrito tal como está (la BASE) y la del carrito CON el
 * artículo que el comprador está mirando— y arma el bloque `incremental` de
 * `POST /api/envios/cotizar` (misión tienda-boton-compra-y-envio, 17/9/2026).
 *
 * ── Por qué existe ────────────────────────────────────────────────────────────────────────────
 *
 * El envío es UNO solo. Con un producto de $3.000 en el carrito el envío sale $10.000; al sumarle
 * un segundo producto de $2.000 el envío pasa a $11.000, no a $20.000. Hasta esta misión la ficha
 * del segundo producto mostraba $10.000 —el envío como si fuera nuevo e independiente—, así que el
 * comprador creía que iba a pagar el doble de envío. Lo que tiene que ver es **la diferencia**:
 * $1.000.
 *
 * ── Las tres cosas que deciden si el número sirve o miente ────────────────────────────────────
 *
 * 1. **Se compara la MISMA opción en las dos corridas.** Restar la más barata de la base menos la
 *    más barata del conjunto mezcla dos servicios (Correo Argentino contra OCA) y da un número que
 *    no le pasa a nadie. Se toma la opción elegida en la base (la más barata, que es la que la
 *    ficha y el carrito muestran) y se busca en el conjunto la de la MISMA `key`. Si esa opción
 *    desapareció —con más peso quedan menos transportistas— se cae a la más barata de cada corrida
 *    y se marca `misma_opcion: false`, para que el SPA no afirme de más.
 *
 * 2. **La diferencia puede dar NEGATIVA y es un caso bueno.** Si sumar el artículo hace que la
 *    compra supere `envio_gratis_desde`, la base cuesta $10.000 y el conjunto $0. Por eso viajan
 *    `envio_gratis_base` y `envio_gratis_total` además del número: `queda_gratis` es el caso que el
 *    SPA cuenta como "agregando esto el envío te queda gratis", nunca como "-$10.000".
 *
 * 3. **Sin base no hay incremental.** Carrito vacío, carrito que no se pudo leer, o base sin
 *    ninguna línea que viaje: `hay_base: false` y `diferencia: null`. El costo que se muestra es el
 *    completo, como siempre. Devolver una diferencia igual al total sería indistinguible de una
 *    diferencia real.
 *
 * Una base que cotizó pero volvió SIN opciones (ningún correo llega a ese CP con lo que ya hay)
 * cuenta también como "sin base": no hay contra qué restar.
 */
class ZipnovaIncrementalHelper
{
    /**
     * El bloque `incremental` de la respuesta.
     *
     * @param array|null $cotizacion_base Cotización del carrito solo, o null si no hubo base.
     * @param array $cotizacion_con_extra Cotización del carrito CON el artículo de la ficha.
     * @return array {hay_base, misma_opcion, key, key_base, precio_base, precio_total, diferencia,
     *               envio_gratis_base, envio_gratis_total, queda_gratis}
     */
    public static function comparar($cotizacion_base, array $cotizacion_con_extra)
    {
        $opciones_total = self::opciones_de($cotizacion_con_extra);
        $envio_gratis_total = is_array($cotizacion_con_extra) && !empty($cotizacion_con_extra['envio_gratis']);

        $opciones_base = self::opciones_de($cotizacion_base);
        $envio_gratis_base = is_array($cotizacion_base) && !empty($cotizacion_base['envio_gratis']);

        $opcion_base = self::mas_barata($opciones_base);

        // Sin base: el costo a mostrar es el completo. Se informa igual la opción del conjunto para
        // que el SPA sepa de cuál es el precio que está viendo.
        if (is_null($opcion_base)) {
            $opcion_total = self::mas_barata($opciones_total);

            return [
                'hay_base'           => false,
                'misma_opcion'       => false,
                'key'                => is_null($opcion_total) ? null : (string) $opcion_total['key'],
                'key_base'           => null,
                'precio_base'        => null,
                'precio_total'       => is_null($opcion_total) ? null : round((float) $opcion_total['precio'], 2),
                'diferencia'         => null,
                'envio_gratis_base'  => false,
                'envio_gratis_total' => $envio_gratis_total,
                'queda_gratis'       => false,
            ];
        }

        // La misma opción en las dos corridas (punto 1). Si no está en el conjunto, la más barata
        // del conjunto y `misma_opcion: false`.
        $opcion_total = ZipnovaQuoteNormalizer::buscar($opciones_total, $opcion_base['key']);
        $misma_opcion = !is_null($opcion_total);

        if (!$misma_opcion) {
            $opcion_total = self::mas_barata($opciones_total);
        }

        $precio_base = round((float) $opcion_base['precio'], 2);
        $precio_total = is_null($opcion_total) ? null : round((float) $opcion_total['precio'], 2);

        return [
            'hay_base'           => true,
            'misma_opcion'       => $misma_opcion,
            'key'                => is_null($opcion_total) ? null : (string) $opcion_total['key'],
            'key_base'           => (string) $opcion_base['key'],
            'precio_base'        => $precio_base,
            'precio_total'       => $precio_total,
            'diferencia'         => is_null($precio_total) ? null : round($precio_total - $precio_base, 2),
            'envio_gratis_base'  => $envio_gratis_base,
            'envio_gratis_total' => $envio_gratis_total,
            'queda_gratis'       => !$envio_gratis_base && $envio_gratis_total,
        ];
    }

    /**
     * Las opciones de una cotización, o vacío si no hay cotización.
     *
     * @param mixed $cotizacion
     * @return array
     */
    protected static function opciones_de($cotizacion)
    {
        if (!is_array($cotizacion) || !isset($cotizacion['opciones']) || !is_array($cotizacion['opciones'])) {
            return [];
        }

        return $cotizacion['opciones'];
    }

    /**
     * La opción más barata de una lista ya normalizada.
     *
     * Se busca el mínimo explícito en vez de tomar `[0]`: el normalizador hoy las devuelve
     * ordenadas por precio, pero de esta resta sale el número que ve el comprador y no tiene por
     * qué depender de que ese orden se mantenga. Es el mismo criterio que `opcion_mas_barata` del
     * cotizador del SPA.
     *
     * @param array $opciones
     * @return array|null
     */
    public static function mas_barata(array $opciones)
    {
        $mas_barata = null;

        foreach ($opciones as $opcion) {
            if (!is_array($opcion) || !isset($opcion['key']) || !isset($opcion['precio'])) {
                continue;
            }
            if (is_null($mas_barata) || (float) $opcion['precio'] < (float) $mas_barata['precio']) {
                $mas_barata = $opcion;
            }
        }

        return $mas_barata;
    }
}
