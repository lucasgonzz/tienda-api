<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Una dirección de entrega guardada por el comprador de la tienda (misión
 * formas-envio-direcciones-maps, 19/10/2026).
 *
 * La tabla `buyer_addresses` la crea `empresa-api` (2026_10_19_100000) y hay bases de clientes
 * donde todavía no existe: 🔴 nunca se consulta sin preguntarle antes a
 * `DireccionesEsquemaHelper::disponible()`.
 *
 * El comprador la busca con el autocompletado de Google Places en el SPA, que llena calle, altura,
 * localidad, provincia, código postal, la línea de Google (`formatted_address`), las coordenadas y
 * el `place_id`. Si Google no responde (sin facturación, sin red), el SPA cae a la carga a mano y
 * llegan solo los campos de texto: por eso todo es opcional salvo calle (o línea), localidad y
 * provincia.
 *
 * ── Por qué la normalización vive acá ─────────────────────────────────────────────────────────
 *
 * La usan tres caminos con las mismas reglas: el ABM del comprador (`BuyerAddressController`), la
 * dirección del invitado que viaja entera en el carrito (`CartController::sync_direccion_envio`) y la
 * foto que se copia al pedido (`OrderController@store`). Son las reglas de ESTA tabla (sus largos,
 * sus obligatorios), así que se escriben una sola vez, al lado de sus columnas.
 */
class BuyerAddress extends Model
{
    protected $guarded = [];

    protected $casts = [
        'user_id'    => 'integer',
        'buyer_id'   => 'integer',
        'lat'        => 'float',
        'lng'        => 'float',
        'is_default' => 'boolean',
    ];

    /** Tope de direcciones guardadas por comprador. */
    const MAXIMO_POR_COMPRADOR = 20;

    /**
     * Los campos de texto con el largo de su columna (el de la migración de `empresa-api`). Lo que
     * llega más largo se recorta: es un dato de Google o del teclado, y cortar es mejor que trabar la
     * compra con un 422 por un renglón de referencia demasiado largo.
     */
    const LARGOS = [
        'street'            => 160,
        'street_number'     => 20,
        'apartment'         => 60,
        'city'              => 120,
        'province'          => 120,
        'zip_code'          => 20,
        'reference'         => 255,
        'formatted_address' => 255,
        'place_id'          => 255,
    ];

    /** Mensajes de los obligatorios, en la forma de los `errors` de Laravel. */
    const MENSAJES = [
        'street'   => 'Falta la calle.',
        'city'     => 'Falta la localidad.',
        'province' => 'Falta la provincia.',
    ];

    function buyer() {
        return $this->belongsTo('App\Buyer');
    }

    /**
     * Limpia y valida los datos de una dirección, vengan del ABM o del carrito del invitado.
     *
     * - Strings: `trim`, espacios internos colapsados, vacío = null, recortados al largo de la
     *   columna.
     * - `lat`/`lng`: numéricos y en rango (±90 / ±180), o null. Van de a dos: si una no sirve, se
     *   descartan las dos. Una coordenada mala NO es un 422: la dirección en texto alcanza para
     *   entregar, y la compra nunca puede quedar trabada por un dato de Google.
     * - Obligatorios: `street` o `formatted_address`, `city` y `province`.
     * - Si no hay `formatted_address` (carga a mano), se arma con calle, altura, localidad y
     *   provincia: es la línea que muestran las tarjetas del SPA y el modal del mapa del ERP.
     *
     * @param array $datos
     * @return array{datos: array, errores: array<string, string[]>}
     */
    public static function normalizar(array $datos)
    {
        $limpio = [];

        foreach (self::LARGOS as $campo => $largo) {
            $limpio[$campo] = self::texto(isset($datos[$campo]) ? $datos[$campo] : null, $largo);
        }

        $lat = self::coordenada(isset($datos['lat']) ? $datos['lat'] : null, 90);
        $lng = self::coordenada(isset($datos['lng']) ? $datos['lng'] : null, 180);

        if (is_null($lat) || is_null($lng)) {
            $lat = null;
            $lng = null;
        }

        $limpio['lat'] = $lat;
        $limpio['lng'] = $lng;

        $errores = [];

        if (is_null($limpio['street']) && is_null($limpio['formatted_address'])) {
            $errores['street'] = [self::MENSAJES['street']];
        }
        if (is_null($limpio['city'])) {
            $errores['city'] = [self::MENSAJES['city']];
        }
        if (is_null($limpio['province'])) {
            $errores['province'] = [self::MENSAJES['province']];
        }

        if (is_null($limpio['formatted_address'])) {
            $limpio['formatted_address'] = self::texto(self::armar_linea($limpio), self::LARGOS['formatted_address']);
        }

        return ['datos' => $limpio, 'errores' => $errores];
    }

    /**
     * La foto de la dirección que se guarda en `carts/orders.envio_direccion`, con las claves del
     * contrato (todas presentes, las que no aplican en null).
     *
     * @param array $datos Ya normalizados (`normalizar()['datos']`, o los atributos de un modelo).
     * @param int|null $buyer_address_id La dirección guardada de la que sale, o null (invitado).
     * @return array
     */
    public static function snapshot(array $datos, $buyer_address_id = null)
    {
        $foto = ['buyer_address_id' => is_numeric($buyer_address_id) ? (int) $buyer_address_id : null];

        foreach (['formatted_address', 'street', 'street_number', 'apartment', 'city', 'province', 'zip_code', 'reference'] as $campo) {
            $foto[$campo] = isset($datos[$campo]) && $datos[$campo] !== '' ? (string) $datos[$campo] : null;
        }

        $foto['lat'] = isset($datos['lat']) && is_numeric($datos['lat']) ? (float) $datos['lat'] : null;
        $foto['lng'] = isset($datos['lng']) && is_numeric($datos['lng']) ? (float) $datos['lng'] : null;
        $foto['place_id'] = isset($datos['place_id']) && $datos['place_id'] !== '' ? (string) $datos['place_id'] : null;

        return $foto;
    }

    /**
     * La foto de una dirección guardada.
     *
     * @return array
     */
    public function como_snapshot()
    {
        return self::snapshot($this->attributesToArray(), $this->id);
    }

    /**
     * La dirección en una línea para `orders.address` (el ERP viejo y los mails la muestran tal
     * cual): la línea de Google, el piso/depto y la referencia entre paréntesis.
     * "Av. Colón 123, Córdoba, Argentina, 4 B (portón negro)". Null si no hay nada.
     *
     * @param array $snapshot
     * @return string|null
     */
    public static function linea_en_texto(array $snapshot)
    {
        $linea = isset($snapshot['formatted_address']) ? trim((string) $snapshot['formatted_address']) : '';

        if ($linea === '') {
            $linea = self::armar_linea($snapshot);
        }

        if ($linea === '') {
            return null;
        }

        if (!empty($snapshot['apartment'])) {
            $linea .= ', ' . trim((string) $snapshot['apartment']);
        }

        if (!empty($snapshot['reference'])) {
            $linea .= ' (' . trim((string) $snapshot['reference']) . ')';
        }

        return $linea;
    }

    /**
     * "Calle 123, Localidad, Provincia" con lo que haya.
     *
     * @param array $datos
     * @return string
     */
    protected static function armar_linea(array $datos)
    {
        $calle = trim(trim(isset($datos['street']) ? (string) $datos['street'] : '') . ' ' . trim(isset($datos['street_number']) ? (string) $datos['street_number'] : ''));

        $partes = [];

        foreach ([$calle, isset($datos['city']) ? $datos['city'] : null, isset($datos['province']) ? $datos['province'] : null] as $parte) {
            $parte = trim((string) $parte);
            if ($parte !== '') {
                $partes[] = $parte;
            }
        }

        return implode(', ', $partes);
    }

    /**
     * @param mixed $valor
     * @param int $largo
     * @return string|null
     */
    protected static function texto($valor, $largo)
    {
        if (!is_scalar($valor) || is_bool($valor)) {
            return null;
        }

        // Con un UTF-8 roto `preg_replace` con /u devuelve null: se sigue con el texto sin colapsar.
        $colapsado = preg_replace('/\s+/u', ' ', (string) $valor);
        $texto = trim(is_string($colapsado) ? $colapsado : (string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, $largo);
    }

    /**
     * @param mixed $valor
     * @param int $limite 90 para la latitud, 180 para la longitud.
     * @return float|null
     */
    protected static function coordenada($valor, $limite)
    {
        if (!is_numeric($valor)) {
            return null;
        }

        $numero = (float) $valor;

        if (!is_finite($numero) || $numero < -$limite || $numero > $limite) {
            return null;
        }

        return round($numero, 7);
    }
}
