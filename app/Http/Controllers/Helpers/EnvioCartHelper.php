<?php

namespace App\Http\Controllers\Helpers;

use App\Cart;
use App\Envio;
use App\Services\Zipnova\EnvioDestinoHelper;
use App\Services\Zipnova\SinArticulosException;
use App\Services\Zipnova\SinZipnovaException;
use App\Services\Zipnova\UbicacionException;
use App\Services\Zipnova\ZipnovaCotizadorService;
use App\Services\Zipnova\ZipnovaException;
use App\Services\Zipnova\ZipnovaQuoteNormalizer;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

/**
 * El envío por correo dentro del carrito y del pedido (misión zipnova-envios, 14/9/2026).
 *
 * ── La regla que cuida ────────────────────────────────────────────────────────────────────────
 *
 * El comprador nunca manda un precio de envío. Manda la `key` de la opción que eligió (más el
 * código postal y, al final, la dirección), y el SERVIDOR re-cotiza contra Zipnova y guarda en
 * `carts.envio_precio` lo que va a cobrar. Ese número es el que copia el pedido, el que suma el
 * mail y el que va a la preferencia de Mercado Pago: una sola fuente para la plata del envío.
 *
 * No se re-cotiza en cada guardado del carrito (el SPA lo guarda seguido): solo cuando cambió la
 * opción, el código postal o las líneas (hash de artículo + variante + cantidad). Con lo mismo, se
 * conserva el snapshot y solo se actualizan la dirección y la sucursal elegida.
 *
 * ── Dónde corre ───────────────────────────────────────────────────────────────────────────────
 *
 * Desde `CartController::sync_checkout_fields()`, en `store` y en `update`, ANTES del `save()` y
 * antes de que el controller vuelva a adjuntar los artículos. Por eso las líneas salen del
 * payload (`articles[].id` + `pivot.amount`) y no de `$cart->articles`, que a esa altura está
 * vacío (store) o viejo (update); los artículos se cargan de la base por el comercio del carrito
 * y el precio lo resuelve el servidor (ver `ZipnovaCotizadorService::lineas_desde_articulos`). Y
 * por eso un 422 de acá no deja nada escrito: se lanza antes del `save()`.
 *
 * ── Compatibilidad ────────────────────────────────────────────────────────────────────────────
 *
 * 🔴 Todo lo que escribe `envio_*` pasa por `ZipnovaEsquemaHelper::disponible()`. En una base sin
 * las columnas (tienda nueva + ERP viejo) esto no toca el carrito y el checkout sigue como en
 * master; un SPA viejo nunca manda `envio`, así que para él tampoco cambia nada.
 *
 * Los 422 llevan `codigo` para que el SPA sepa qué mostrar:
 *   `opcion_envio`  la key ya no está en la cotización nueva (trae `opciones` frescas)
 *   `destino`       faltan datos de la dirección (`errors: {campo: [mensaje]}`)
 *   `sin_zipnova` / `sin_articulos` / `ubicacion` / `zipnova` (502): mismos que el cotizador
 */
class EnvioCartHelper
{
    /** Proveedor que se escribe en `orders.envio_proveedor`. */
    const PROVEEDOR = Envio::PROVEEDOR_ZIPNOVA;

    /** Etiquetas de los campos del destino, para los mensajes del 422. */
    const ETIQUETAS_DESTINO = [
        'nombre'        => 'el nombre',
        'apellido'      => 'el apellido',
        'documento'     => 'el DNI (7 a 11 números)',
        'email'         => 'un email válido',
        'telefono'      => 'un teléfono (al menos 8 números)',
        'calle'         => 'la calle',
        'numero'        => 'el número',
        'localidad'     => 'la localidad',
        'provincia'     => 'la provincia',
        'codigo_postal' => 'el código postal',
        'point_id'      => 'la sucursal donde retirar',
    ];

    /**
     * Sincroniza las cuatro columnas de envío del carrito con `$data['envio']`.
     *
     * `$data['envio']` = `{zipcode, city?, state?, opcion_key, point_id?, destino?}`.
     *
     * @param Cart $cart Carrito ya cargado (con `user_id`); se modifica en memoria, no se guarda.
     * @param array $data Payload del carrito tal como llega del SPA.
     * @return void
     * @throws HttpResponseException 422/502 con `codigo` y `message`.
     */
    public static function sincronizar(Cart $cart, array $data)
    {
        if (!ZipnovaEsquemaHelper::disponible()) {
            return;
        }

        $envio = isset($data['envio']) && is_array($data['envio']) ? $data['envio'] : [];
        $deliver = isset($data['deliver']) ? (int) $data['deliver'] : 0;
        $opcion_key = isset($envio['opcion_key']) && is_scalar($envio['opcion_key']) ? trim((string) $envio['opcion_key']) : '';

        // Retiro por el local, zona propia, o todavía sin opción: no hay envío por correo.
        if ($deliver !== 1 || $opcion_key === '') {
            self::limpiar($cart);

            return;
        }

        $lineas_request = self::lineas_del_request($data);

        // Un carrito que se está vaciando no tiene nada que cotizar (el controller lo borra).
        if (count($lineas_request) === 0) {
            self::limpiar($cart);

            return;
        }

        // Zipnova y zona propia son excluyentes. Si llegaron las dos, gana la opción explícita
        // de correo: el SPA anula la zona al elegirla, así que un payload con ambas es un estado
        // viejo del store, y el precio de Zipnova es el que el servidor acaba de cotizar.
        $cart->delivery_zone_id = null;

        $zipcode = ZipnovaCotizadorService::zipcode_limpio(isset($envio['zipcode']) ? $envio['zipcode'] : null);

        if ($zipcode === '') {
            self::fallar('opcion_envio', 'Falta el código postal para cotizar el envío. Volvé a cotizar.');
        }

        $items_hash = self::hash_de_lineas($lineas_request);

        $opcion = is_array($cart->envio_opcion) ? $cart->envio_opcion : null;
        $cotizacion = is_array($cart->envio_cotizacion) ? $cart->envio_cotizacion : null;

        $vigente = !is_null($opcion) && !is_null($cotizacion)
            && isset($opcion['key']) && (string) $opcion['key'] === $opcion_key
            && isset($cotizacion['zipcode']) && (string) $cotizacion['zipcode'] === $zipcode
            && isset($cotizacion['items_hash']) && (string) $cotizacion['items_hash'] === $items_hash;

        if (!$vigente) {
            $cotizacion = self::cotizar($cart, $lineas_request, $data, $zipcode, $envio);
            $opcion = ZipnovaQuoteNormalizer::buscar($cotizacion['opciones'], $opcion_key);

            if (is_null($opcion)) {
                self::fallar('opcion_envio', 'Esa forma de envío ya no está disponible, volvé a cotizar.', [
                    'opciones' => $cotizacion['opciones'],
                ]);
            }

            $cotizacion['items_hash'] = $items_hash;
            $cart->envio_cotizacion = $cotizacion;
        }

        // La sucursal elegida solo tiene sentido en una opción de retiro; en las demás se limpia
        // para que no arrastre la de una elección anterior.
        $es_punto_de_retiro = !empty($opcion['es_punto_de_retiro']);
        $point_id = self::entero_positivo(isset($envio['point_id']) ? $envio['point_id'] : null);

        if ($es_punto_de_retiro) {
            if (is_null($point_id) && isset($envio['destino']['point_id'])) {
                $point_id = self::entero_positivo($envio['destino']['point_id']);
            }
            if (is_null($point_id) && isset($opcion['point_id'])) {
                $point_id = self::entero_positivo($opcion['point_id']);
            }
        } else {
            $point_id = null;
        }
        $opcion['point_id'] = $point_id;

        $cart->envio_opcion = $opcion;
        $cart->envio_precio = isset($opcion['precio']) && is_numeric($opcion['precio']) ? round((float) $opcion['precio'], 2) : 0.0;
        $cart->envio_destino = self::destino_validado($envio, $zipcode, $es_punto_de_retiro, $point_id);
    }

    /**
     * Los atributos de envío que `OrderController@store` copia del carrito al pedido. Vacío si
     * el esquema no está, si el pedido no se envía o si no hay opción de correo elegida: así el
     * `Order::create` de siempre no cambia en ninguno de esos casos.
     *
     * @param Cart $cart
     * @return array
     */
    public static function atributos_para_pedido(Cart $cart)
    {
        // Es el único camino que ESCRIBE `orders.envio_*`: sin el esquema no se manda ninguna de
        // esas claves al create (leer el carrito ya habría dado null, pero la guarda va explícita).
        if (!ZipnovaEsquemaHelper::disponible()) {
            return [];
        }

        $opcion = self::opcion_de($cart);

        if (is_null($opcion)) {
            return [];
        }

        return [
            'envio_cotizacion' => is_array($cart->envio_cotizacion) ? $cart->envio_cotizacion : null,
            'envio_opcion'     => $opcion,
            'envio_destino'    => is_array($cart->envio_destino) ? $cart->envio_destino : null,
            'envio_precio'     => is_numeric($cart->envio_precio) ? round((float) $cart->envio_precio, 2) : 0.0,
            'envio_proveedor'  => self::PROVEEDOR,
        ];
    }

    /**
     * Campos que le faltan al destino del carrito para poder crear el pedido. Vacío cuando no
     * aplica (sin opción de correo) o cuando está completo. Es la última guarda antes del
     * `Order::create`: un pedido con opción de correo y sin dirección no se puede despachar
     * desde el ERP.
     *
     * @param Cart $cart
     * @return array Lista de campos (claves de ETIQUETAS_DESTINO).
     */
    public static function faltantes_del_destino(Cart $cart)
    {
        $opcion = self::opcion_de($cart);

        if (is_null($opcion)) {
            return [];
        }

        $destino = is_array($cart->envio_destino) ? EnvioDestinoHelper::normalizar($cart->envio_destino) : [];

        if (count($destino) === 0) {
            return EnvioDestinoHelper::faltantes(EnvioDestinoHelper::normalizar([]), !empty($opcion['es_punto_de_retiro']));
        }

        return EnvioDestinoHelper::faltantes($destino, !empty($opcion['es_punto_de_retiro']));
    }

    /**
     * La dirección del pedido en una línea, para `orders.address` (el ERP viejo la muestra tal
     * cual) y los mails. Null si el carrito no tiene destino de correo.
     *
     * @param Cart $cart
     * @return string|null
     */
    public static function direccion_en_texto(Cart $cart)
    {
        if (is_null(self::opcion_de($cart)) || !is_array($cart->envio_destino)) {
            return null;
        }

        $texto = EnvioDestinoHelper::como_texto($cart->envio_destino);

        return $texto === '' ? null : $texto;
    }

    /**
     * Lo que se cobra de envío por correo en una preferencia de pago: `carts.envio_precio`, del
     * carrito y nunca del body. Null cuando el carrito no va por Zipnova (entonces vale la zona
     * que manda el SPA, como siempre); 0.0 cuando va por Zipnova con envío gratis.
     *
     * @param Cart|null $cart
     * @return float|null
     */
    public static function precio_para_cobrar($cart)
    {
        if (is_null($cart) || is_null(self::opcion_de($cart))) {
            return null;
        }

        return is_numeric($cart->envio_precio) ? round((float) $cart->envio_precio, 2) : 0.0;
    }

    /**
     * La opción de correo elegida en un carrito o un pedido, o null si no va por Zipnova.
     *
     * Sirve para carritos y pedidos porque los dos tienen las mismas columnas. No hace falta la
     * guarda de esquema para LEER: en una fila sin la columna el atributo es null.
     *
     * @param \Illuminate\Database\Eloquent\Model $modelo Cart u Order.
     * @return array|null
     */
    public static function opcion_de($modelo)
    {
        if ((int) $modelo->deliver !== 1) {
            return null;
        }

        $opcion = $modelo->envio_opcion;

        if (!is_array($opcion) || !isset($opcion['key']) || (string) $opcion['key'] === '') {
            return null;
        }

        return $opcion;
    }

    /**
     * Etiqueta corta de la opción para totales y mails: "Andreani (Envio a domicilio)".
     *
     * @param array $opcion
     * @return string
     */
    public static function etiqueta_de_opcion(array $opcion)
    {
        $carrier = isset($opcion['carrier_name']) ? trim((string) $opcion['carrier_name']) : '';
        $servicio = isset($opcion['service_name']) ? trim((string) $opcion['service_name']) : '';

        if ($carrier === '') {
            $carrier = 'correo';
        }

        return $servicio === '' ? $carrier : $carrier . ' (' . $servicio . ')';
    }

    /**
     * Deja el carrito sin envío por correo. Solo se llama con el esquema disponible.
     *
     * @param Cart $cart
     * @return void
     */
    protected static function limpiar(Cart $cart)
    {
        $cart->envio_cotizacion = null;
        $cart->envio_opcion = null;
        $cart->envio_destino = null;
        $cart->envio_precio = null;
    }

    /**
     * Re-cotiza con las líneas del payload y traduce las fallas a respuestas.
     *
     * @param Cart $cart
     * @param array $lineas_request
     * @param array $data
     * @param string $zipcode
     * @param array $envio
     * @return array Cotización normalizada.
     */
    protected static function cotizar(Cart $cart, array $lineas_request, array $data, $zipcode, array $envio)
    {
        $armado = ZipnovaCotizadorService::lineas_desde_articulos((int) $cart->user_id, $lineas_request);
        $subtotal = $armado['subtotal'] + self::subtotal_de_promociones($data);

        try {
            return ZipnovaCotizadorService::cotizar(
                (int) $cart->user_id,
                $armado['lineas'],
                $subtotal,
                $zipcode,
                isset($envio['city']) && is_string($envio['city']) ? $envio['city'] : null,
                isset($envio['state']) && is_string($envio['state']) ? $envio['state'] : null
            );
        } catch (SinZipnovaException $e) {
            self::fallar('sin_zipnova', 'Este negocio no tiene envíos por correo configurados.');
        } catch (SinArticulosException $e) {
            self::fallar('sin_articulos', 'No hay nada para enviar: los artículos del carrito no requieren envío.');
        } catch (UbicacionException $e) {
            self::fallar('ubicacion', 'No reconocimos ese código postal. Decinos la localidad y la provincia.', [
                'needs_location' => true,
            ]);
        } catch (ZipnovaException $e) {
            Log::warning('EnvioCartHelper: Zipnova no pudo re-cotizar el carrito', [
                'cart_id'     => $cart->id,
                'commerce_id' => $cart->user_id,
                'zipcode'     => $zipcode,
                'status'      => $e->getStatus(),
                'detalle'     => $e->getMessage(),
            ]);

            self::fallar('zipnova', 'No pudimos cotizar el envío en este momento. Probá de nuevo en un rato.', [], 502);
        }
    }

    /**
     * El destino normalizado y validado, o null si el comprador todavía no lo cargó.
     *
     * Un destino totalmente vacío no es un error: el SPA guarda el carrito varias veces antes de
     * llegar al formulario de dirección. Uno a medias sí lo es (422 `destino`), y el código postal
     * tiene que ser el cotizado: el envío se pagó para ese CP.
     *
     * @param array $envio
     * @param string $zipcode
     * @param bool $es_punto_de_retiro
     * @param int|null $point_id
     * @return array|null
     */
    protected static function destino_validado(array $envio, $zipcode, $es_punto_de_retiro, $point_id)
    {
        if (!isset($envio['destino']) || !is_array($envio['destino'])) {
            return null;
        }

        $destino = EnvioDestinoHelper::normalizar($envio['destino']);
        $destino['point_id'] = $es_punto_de_retiro ? $point_id : null;

        if (self::destino_vacio($destino)) {
            return null;
        }

        if (is_null($destino['codigo_postal'])) {
            $destino['codigo_postal'] = $zipcode;
        }

        $faltantes = EnvioDestinoHelper::faltantes($destino, $es_punto_de_retiro);
        $errores = self::errores_de_destino($faltantes);

        if (strtoupper((string) $destino['codigo_postal']) !== $zipcode) {
            $errores['codigo_postal'] = ['El código postal de la dirección no es el que cotizaste. Volvé a cotizar con el nuevo.'];
        }

        if (count($errores) > 0) {
            self::fallar('destino', 'Faltan datos de la dirección de entrega.', ['errors' => $errores]);
        }

        return $destino;
    }

    /**
     * True si el destino no trae ningún dato de la persona ni de la dirección.
     *
     * @param array $destino Ya normalizado.
     * @return bool
     */
    protected static function destino_vacio(array $destino)
    {
        foreach (['nombre', 'apellido', 'documento', 'email', 'telefono', 'calle', 'numero', 'localidad', 'provincia'] as $clave) {
            if (isset($destino[$clave]) && !is_null($destino[$clave])) {
                return false;
            }
        }

        return true;
    }

    /**
     * `{campo: [mensaje]}` a partir de la lista de faltantes, con la forma de los `errors` de
     * Laravel para que el SPA los muestre igual que cualquier validación.
     *
     * @param array $faltantes
     * @return array
     */
    protected static function errores_de_destino(array $faltantes)
    {
        $errores = [];

        foreach ($faltantes as $campo) {
            $etiqueta = isset(self::ETIQUETAS_DESTINO[$campo]) ? self::ETIQUETAS_DESTINO[$campo] : $campo;
            $errores[$campo] = ['Falta ' . $etiqueta . '.'];
        }

        return $errores;
    }

    /**
     * Las líneas del payload: id, variante y cantidad de cada artículo (las promociones de
     * vinoteca no viajan como ítems, no tienen peso ni medidas).
     *
     * @param array $data
     * @return array `[['id' => int, 'variant_id' => int|null, 'amount' => int], ...]`
     */
    protected static function lineas_del_request(array $data)
    {
        $lineas = [];

        if (!isset($data['articles']) || !is_array($data['articles'])) {
            return $lineas;
        }

        foreach ($data['articles'] as $article) {
            if (!is_array($article) || isset($article['is_promocion_vinoteca']) || !isset($article['id']) || !is_numeric($article['id'])) {
                continue;
            }
            $pivot = isset($article['pivot']) && is_array($article['pivot']) ? $article['pivot'] : [];
            $amount = isset($pivot['amount']) && is_numeric($pivot['amount']) ? (int) ceil((float) $pivot['amount']) : 1;
            if ($amount < 1) {
                continue;
            }
            $lineas[] = [
                'id'         => (int) $article['id'],
                'variant_id' => self::entero_positivo(isset($pivot['variant_id']) ? $pivot['variant_id'] : null),
                'amount'     => $amount,
            ];
        }

        return $lineas;
    }

    /**
     * Hash de las líneas (`artículo|variante|cantidad`, ordenado) para saber si hace falta
     * re-cotizar. El precio no entra: un cambio de precio no cambia el paquete.
     *
     * @param array $lineas Ver `lineas_del_request`.
     * @return string md5
     */
    public static function hash_de_lineas(array $lineas)
    {
        $partes = [];

        foreach ($lineas as $linea) {
            $partes[] = $linea['id'] . '|' . (is_null($linea['variant_id']) ? '' : $linea['variant_id']) . '|' . $linea['amount'];
        }
        sort($partes);

        return md5(implode(',', $partes));
    }

    /**
     * Lo que suman las promociones de vinoteca del payload: son ítems pagos del carrito y
     * cuentan para el valor declarado y el envío gratis, aunque no viajen como bultos. El precio
     * sale del payload porque es exactamente lo que `CartHelper::attach_promociones_vinoteca()`
     * guarda.
     *
     * @param array $data
     * @return float
     */
    protected static function subtotal_de_promociones(array $data)
    {
        $suma = 0.0;

        if (!isset($data['promociones_vinoteca']) || !is_array($data['promociones_vinoteca'])) {
            return $suma;
        }

        foreach ($data['promociones_vinoteca'] as $promo) {
            if (!is_array($promo) || !isset($promo['final_price']) || !is_numeric($promo['final_price'])) {
                continue;
            }
            $amount = isset($promo['pivot']['amount']) && is_numeric($promo['pivot']['amount']) ? (float) $promo['pivot']['amount'] : 1;
            $suma += (float) $promo['final_price'] * $amount;
        }

        return round($suma, 2);
    }

    /**
     * @param mixed $valor
     * @return int|null
     */
    protected static function entero_positivo($valor)
    {
        return is_numeric($valor) && (int) $valor > 0 ? (int) $valor : null;
    }

    /**
     * Corta el request con una respuesta JSON. `HttpResponseException` la devuelve tal cual,
     * sin pasar por el handler de errores.
     *
     * @param string $codigo
     * @param string $message
     * @param array $extra Claves adicionales del body (`errors`, `opciones`, `needs_location`).
     * @param int $status
     * @return void
     */
    protected static function fallar($codigo, $message, array $extra = [], $status = 422)
    {
        throw new HttpResponseException(response()->json(array_merge([
            'codigo'  => $codigo,
            'message' => $message,
        ], $extra), $status));
    }
}
