<?php

namespace App\Services\Zipnova;

use App\Article;
use App\Cart;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ZipnovaCredentialsHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use Illuminate\Support\Facades\Log;

/**
 * Cotiza el envío de un conjunto de artículos con la cuenta de Zipnova del comercio
 * (misión zipnova-envios, 14/9/2026). Es la única puerta de la tienda hacia `POST
 * /shipments/quote`: la usan el endpoint público `POST /api/envios/cotizar` (la ficha del
 * artículo y el carrito) y `EnvioCartHelper` (el checkout, cuando el comprador elige una opción).
 *
 * ── Qué decide ────────────────────────────────────────────────────────────────────────────────
 *
 *  - Si el comercio puede cotizar: esquema disponible + conector de Zipnova conectado. Si no,
 *    `SinZipnovaException` (422 `sin_zipnova`, sin log: es el estado normal de la mayoría).
 *  - Los ítems: `ZipnovaPaquetesHelper` con el bulto por defecto del conector. Sin ítems,
 *    `SinArticulosException`.
 *  - El valor declarado: el subtotal si el comercio marcó "asegurar por el valor de la compra",
 *    si no 0. Zipnova lo usa para el seguro y lo exige en el body aunque sea 0.
 *  - Envío gratis: `ZipnovaEnvioGratisHelper` con el subtotal y `envio_gratis_desde`. Cuando
 *    aplica, el normalizador deja `precio = 0` en todas las opciones y `precio_original` con
 *    lo que Zipnova le cobra al comercio igual.
 *  - El destino: solo el código postal (más localidad y provincia si el SPA ya las pidió
 *    después de un `needs_location`).
 *
 * ── El precio del artículo ────────────────────────────────────────────────────────────────────
 *
 * Para el modo `articles` (la ficha, sin carrito) el subtotal se arma con el MISMO precio que la
 * tienda muestra: `Article::withAll()` + `ArticleHelper::checkPriceTypes()`, que es el embudo de
 * precios de todo el repo (lista del comprador logueado, lista pública por `position`, rangos,
 * oferta personalizada, y null para el anónimo que no puede ver precios). Es lo que
 * `CartHelper::getFullModel()` devuelve como `final_price` y lo que el SPA manda de vuelta como
 * `pivot.price` al agregar al carrito. El subtotal solo sirve para `declared_value` y para la
 * regla de envío gratis: no se cobra nada con él.
 */
class ZipnovaCotizadorService
{
    /** Tope de líneas que se aceptan en una cotización (Zipnova acepta hasta 1000 ítems; 50 líneas sobran). */
    const MAX_LINEAS = 50;

    /**
     * Cotiza y devuelve la lista normalizada de opciones.
     *
     * @param int $commerce_id Comercio (owner) dueño de los artículos.
     * @param array $lineas `[['article' => Article, 'amount' => int], ...]` (ver `lineas_desde_*`).
     * @param float $subtotal Subtotal de artículos, para el valor declarado y el envío gratis.
     * @param string $zipcode Código postal de destino.
     * @param string|null $city Localidad, si el comprador ya la indicó.
     * @param string|null $state Provincia, si el comprador ya la indicó.
     * @return array `{zipcode, city, state, envio_gratis, declared_value, quoted_at, opciones: [...]}`
     * @throws SinZipnovaException Sin esquema o sin conector.
     * @throws SinArticulosException Nada que enviar.
     * @throws UbicacionException Zipnova no reconoció el destino.
     * @throws ZipnovaException Cualquier otra falla de Zipnova (la atrapa el llamador y responde 502).
     */
    public static function cotizar($commerce_id, array $lineas, $subtotal, $zipcode, $city = null, $state = null)
    {
        if (!ZipnovaEsquemaHelper::disponible()) {
            throw new SinZipnovaException('La base de este negocio todavía no tiene el esquema de envíos.');
        }

        $credentials = ZipnovaCredentialsHelper::credentials((int) $commerce_id);

        if (is_null($credentials['basic'])) {
            throw new SinZipnovaException('El negocio no tiene Zipnova conectado.');
        }

        $config = $credentials['config'];

        $items = ZipnovaPaquetesHelper::items_desde_lineas($lineas, $config['bulto_default']);

        if (count($items) === 0) {
            throw new SinArticulosException('No hay artículos que requieran envío.');
        }

        $subtotal = is_numeric($subtotal) ? round((float) $subtotal, 2) : 0.0;
        $envio_gratis = ZipnovaEnvioGratisHelper::aplica($lineas, $subtotal, $config);
        $declared_value = $config['declarar_valor'] ? $subtotal : 0.0;

        $destination = ['zipcode' => (string) $zipcode];
        if (is_string($city) && trim($city) !== '') {
            $destination['city'] = trim($city);
        }
        if (is_string($state) && trim($state) !== '') {
            $destination['state'] = trim($state);
        }

        $payload = [
            'declared_value' => $declared_value,
            'destination'    => $destination,
            'items'          => $items,
            'sort_by'        => 'price',
        ];

        if (!is_null($config['origin_id']) && is_numeric($config['origin_id'])) {
            $payload['origin_id'] = (int) $config['origin_id'];
        }

        $client = new ZipnovaClient($credentials['basic'], $credentials['account_id']);

        try {
            $respuesta = $client->quote($payload);
        } catch (ZipnovaException $e) {
            if ($e->esDeUbicacion()) {
                throw new UbicacionException($e->getMessage(), $e->getStatus(), $e->getBody(), $e);
            }

            throw $e;
        }

        $cotizacion = ZipnovaQuoteNormalizer::normalizar($respuesta, $envio_gratis);

        // Una opción de retiro sin sucursales nunca se podría completar (el envío en Zipnova
        // exige el point_id): no se le ofrece al comprador.
        $cotizacion['opciones'] = array_values(array_filter($cotizacion['opciones'], function ($opcion) {
            if (empty($opcion['es_punto_de_retiro'])) {
                return true;
            }

            return isset($opcion['puntos_de_retiro']) && is_array($opcion['puntos_de_retiro']) && count($opcion['puntos_de_retiro']) > 0;
        }));

        // El CP es SIEMPRE el que mandó el comprador, ya limpio, y no el eco de Zipnova: es lo
        // que el carrito compara en cada guardado y contra la dirección, y Zipnova puede
        // devolverlo normalizado distinto ("X5000ABC" -> "5000"). Localidad y provincia sí vienen
        // resueltas por Zipnova; si no las mandó, quedan las del comprador.
        $cotizacion['zipcode'] = (string) $zipcode;
        if (is_null($cotizacion['city']) && isset($destination['city'])) {
            $cotizacion['city'] = $destination['city'];
        }
        if (is_null($cotizacion['state']) && isset($destination['state'])) {
            $cotizacion['state'] = $destination['state'];
        }

        $cotizacion['declared_value'] = $declared_value;
        $cotizacion['quoted_at'] = now()->toIso8601String();

        return $cotizacion;
    }

    /**
     * Líneas y subtotal a partir de ids y cantidades (modo `articles` del endpoint público).
     *
     * Los artículos se buscan SIEMPRE por el comercio: un id de otro comercio se ignora. El
     * precio es el que la tienda muestra (ver el docblock de la clase); si para este visitante es
     * null (anónimo sin permiso de ver precios) cuenta como 0 en el subtotal.
     *
     * @param int $commerce_id
     * @param array $items `[['id' => int, 'amount' => int], ...]`
     * @return array{lineas: array, subtotal: float}
     */
    public static function lineas_desde_articulos($commerce_id, array $items)
    {
        $cantidades = [];
        foreach (array_slice($items, 0, self::MAX_LINEAS) as $item) {
            if (!is_array($item) || !isset($item['id']) || !is_numeric($item['id'])) {
                continue;
            }
            $id = (int) $item['id'];
            $amount = isset($item['amount']) && is_numeric($item['amount']) ? (int) ceil((float) $item['amount']) : 1;
            if ($amount < 1) {
                $amount = 1;
            }
            $cantidades[$id] = (isset($cantidades[$id]) ? $cantidades[$id] : 0) + $amount;
        }

        if (count($cantidades) === 0) {
            return ['lineas' => [], 'subtotal' => 0.0];
        }

        // Solo `price_types`: es lo que `checkPriceTypes()` necesita para resolver el precio en
        // sus cuatro casos (la lista del comprador, la pública por `position`, y el pivot para
        // los rangos). `withAll()` traería 13 relaciones (imágenes, descripciones, variantes...)
        // que una cotización no mira. `sub_category`/`category` las carga `set_ranges()` a
        // demanda, solo en los comercios con rangos por cantidad. Las columnas de dimensiones
        // (peso, alto, ancho, profundidad, requires_shipping, free_shipping) son de la fila.
        $articulos = Article::where('user_id', (int) $commerce_id)
            ->whereIn('id', array_keys($cantidades))
            ->with('price_types')
            ->get();

        if (count($articulos) === 0) {
            return ['lineas' => [], 'subtotal' => 0.0];
        }

        $articulos = ArticleHelper::checkPriceTypes($articulos);

        $lineas = [];
        $subtotal = 0.0;
        foreach ($articulos as $articulo) {
            $amount = $cantidades[(int) $articulo->id];
            $lineas[] = ['article' => $articulo, 'amount' => $amount];
            if (is_numeric($articulo->final_price)) {
                $subtotal += (float) $articulo->final_price * $amount;
            }
        }

        return ['lineas' => $lineas, 'subtotal' => round($subtotal, 2)];
    }

    /**
     * Líneas y subtotal a partir de un carrito ya guardado (modo `cart_id`).
     *
     * El subtotal es `carts.total`, que `CartHelper::set_total()` acaba de calcular con los
     * precios que resolvió el servidor; si por algún camino viejo está en null, se suma del pivot.
     *
     * @param Cart $cart
     * @return array{lineas: array, subtotal: float}
     */
    public static function lineas_desde_carrito(Cart $cart)
    {
        $lineas = [];
        $suma_pivot = 0.0;

        foreach ($cart->articles as $articulo) {
            $amount = isset($articulo->pivot->amount) ? (int) ceil((float) $articulo->pivot->amount) : 1;
            if ($amount < 1) {
                continue;
            }
            $lineas[] = ['article' => $articulo, 'amount' => $amount];
            if (isset($articulo->pivot->price) && is_numeric($articulo->pivot->price)) {
                $suma_pivot += (float) $articulo->pivot->price * $amount;
            }
        }

        $subtotal = is_numeric($cart->total) && (float) $cart->total > 0 ? (float) $cart->total : $suma_pivot;

        return ['lineas' => $lineas, 'subtotal' => round($subtotal, 2)];
    }

    /**
     * Código postal sin espacios ni signos, en mayúsculas: Zipnova acepta el numérico ("5000") y
     * el CPA ("X5000ABC"); todo lo demás es ruido del teclado del teléfono.
     *
     * @param mixed $zipcode
     * @return string Vacío si después de limpiar no quedan entre 4 y 8 caracteres.
     */
    public static function zipcode_limpio($zipcode)
    {
        if (!is_scalar($zipcode)) {
            return '';
        }

        $limpio = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $zipcode));
        $largo = strlen($limpio);

        if ($largo < 4 || $largo > 8) {
            return '';
        }

        return $limpio;
    }

    /**
     * Lo que `GET /api/commerce/{id}` publica sobre la integración: si el comprador puede cotizar
     * por correo en esta tienda, y la única configuración que el SPA necesita (el monto de envío
     * gratis, para mostrarlo como incentivo). Nunca el token, nunca la cuenta.
     *
     * Nunca lanza: un cliente sin las tablas, o con un conector que no se puede descifrar, ve
     * `envios_zipnova: false` y la tienda sigue igual que siempre.
     *
     * @param int $commerce_id
     * @return array{envios_zipnova: bool, envios_zipnova_config: array{envio_gratis_desde: float|null}}
     */
    public static function disponibilidad_publica($commerce_id)
    {
        $publico = [
            'envios_zipnova'        => false,
            'envios_zipnova_config' => ['envio_gratis_desde' => null],
        ];

        try {
            if (!ZipnovaEsquemaHelper::disponible()) {
                return $publico;
            }

            $credentials = ZipnovaCredentialsHelper::credentials((int) $commerce_id);

            if (is_null($credentials['basic'])) {
                return $publico;
            }

            $publico['envios_zipnova'] = true;
            $publico['envios_zipnova_config']['envio_gratis_desde'] = $credentials['config']['envio_gratis_desde'];
        } catch (\Throwable $e) {
            Log::warning('ZipnovaCotizadorService::disponibilidad_publica: no se pudo resolver la integración del comercio ' . $commerce_id . ', se publica como no disponible: ' . $e->getMessage());
        }

        return $publico;
    }
}
