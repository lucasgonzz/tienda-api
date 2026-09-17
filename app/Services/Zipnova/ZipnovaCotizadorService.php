<?php

namespace App\Services\Zipnova;

use App\Article;
use App\Cart;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ZipnovaCredentialsHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use Illuminate\Support\Facades\Cache;
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
 *  - El destino: el código postal, con la localidad y la provincia del comprador si las escribió
 *    y con el centinela de `CENTINELA_UBICACION` si no. Con el centinela, Zipnova resuelve la
 *    localidad por el código postal y la devuelve en `destination`; si no la resuelve, sale
 *    `UbicacionException` y el comprador la escribe, igual que siempre. Ver la constante.
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

    /** Cuánto vive una cotización en caché: diez minutos. */
    const SEGUNDOS_DE_CACHE = 600;

    /** Prefijo de las claves de caché de cotizaciones. */
    const PREFIJO_CACHE = 'zipnova-cotizacion:';

    /**
     * Localidad y provincia que se le mandan a Zipnova cuando el comprador escribió SOLO el código
     * postal. Es el mecanismo con el que se resuelve ese caso, no un relleno decorativo.
     *
     * 🔴 POR QUÉ EXISTE — si esto se "limpia", el cotizador vuelve a pedirle la localidad al
     * comprador en cada compra, que es exactamente el defecto que esta constante arregla.
     *
     * 1. Zipnova NO tiene resolvedor de código postal. No hay endpoint que traduzca un CP a una
     *    localidad: `/v2/cities`, `/v2/zipcodes/{cp}` y `/v2/destinations` no existen, y
     *    `/v2/locations` lista puntos de retiro, exige `city`+`state` e IGNORA el `zipcode`.
     * 2. En `destination`, `city` y `state` son obligatorios y mutuamente obligatorios: mandar
     *    `zipcode`+`state` sin `city` es un 400 de FORMA ("The destination.city field is required
     *    when destination.state is present"), sin mirar siquiera qué provincia se mandó.
     * 3. Pero la resolución es POR CÓDIGO POSTAL, con `city`+`state` como pista opcional: si la
     *    pareja matchea el padrón de Zipnova, gana la pareja; si NO matchea, Zipnova la ignora y
     *    resuelve por el código postal solo, devolviendo la localidad en `destination`.
     *
     * Medido el 17/9/2026 contra la cuenta real sobre diez códigos postales (2000, 1425, 8400,
     * 5000, 3000, 4000, 7600, 5500, 9410 y el CPA X5000ABC): los diez resolvieron a la localidad
     * correcta con este centinela, con el mismo `destination.id` y los mismos precios al centavo
     * que la corrida de control hecha con la localidad y la provincia bien escritas.
     *
     * 🔴 Por eso el valor tiene que ser IMPOSIBLE de matchear contra una localidad o una provincia
     * argentina. Si algún día matcheara algo, Zipnova cotizaría a ESE lugar y el comprador pagaría
     * un envío a otra ciudad sin que nada avise.
     *
     * 🔴 Y por eso tampoco se arma ningún padrón de códigos postales propio: Zipnova no valida que
     * el CP y la provincia sean coherentes. `{zipcode: 2000, city: Rosario, state: Córdoba}`
     * devuelve 200 y resuelve a Villa Del Rosario, Córdoba — otra localidad, a 400 km y $2.182 más
     * cara. Una tabla nuestra con un dato mal puesto cobraría el envío equivocado en silencio; el
     * código postal solo, con el centinela, no tiene esa puerta.
     *
     * La contrapartida obligatoria es la guarda de `zipnova_resolvio_el_destino()`: nunca se da por
     * sentado que resolvió. Y esa guarda corre SIEMPRE, incluso cuando el comprador mandó su
     * localidad: `POST /api/envios/cotizar` es pública y este mismo valor puede llegar por el body.
     */
    const CENTINELA_UBICACION = 'Zzz Inexistente';

    /**
     * Cotiza y devuelve la lista normalizada de opciones.
     *
     * @param int $commerce_id Comercio (owner) dueño de los artículos.
     * @param array $lineas `[['article' => Article, 'amount' => int], ...]` (ver `lineas_desde_*`).
     * @param float $subtotal Subtotal de artículos, para el valor declarado y el envío gratis.
     * @param string $zipcode Código postal de destino.
     * @param string|null $city Localidad, si el comprador la indicó. Sin ella (o sin `$state`) se
     *                          cotiza con el centinela y Zipnova resuelve por el código postal.
     * @param string|null $state Provincia, si el comprador la indicó.
     * @return array `{zipcode, city, state, envio_gratis, declared_value, quoted_at, opciones: [...]}`
     *               `city` y `state` son los que resolvió Zipnova: salen siempre llenos y con un
     *               lugar de verdad —la guarda no deja pasar otra cosa, tampoco un centinela que
     *               haya llegado por el body—, no null como antes de 17/9/2026.
     * @throws SinZipnovaException Sin esquema o sin conector.
     * @throws SinArticulosException Nada que enviar.
     * @throws UbicacionException Zipnova no reconoció el destino, o el destino que quedó no es un
     *                            lugar de verdad (la guarda del centinela, que corre siempre).
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

        $city = is_string($city) ? trim($city) : '';
        $state = is_string($state) ? trim($state) : '';

        // `city` y `state` son mutuamente obligatorios para Zipnova, así que van los dos del
        // comprador o van los dos centinela: con uno solo el request es un 400 de forma. Cuando
        // faltan, el centinela hace que Zipnova resuelva por el código postal (ver la constante).
        $resolver_por_zipcode = $city === '' || $state === '';

        $destination = [
            'zipcode' => (string) $zipcode,
            'city'    => $resolver_por_zipcode ? self::CENTINELA_UBICACION : $city,
            'state'   => $resolver_por_zipcode ? self::CENTINELA_UBICACION : $state,
        ];

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

        $respuesta = self::respuesta_de_zipnova($client, $payload, self::clave_de_cache((int) $commerce_id, $payload, $envio_gratis));

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
        // devolverlo normalizado distinto ("X5000ABC" -> "5000"). Y no es una precaución teórica:
        // Zipnova ecoa el `zipcode` tal cual se lo mandan, sin normalizarlo ni verificarlo contra
        // la localidad que resolvió (medido el 17/9/2026).
        $cotizacion['zipcode'] = (string) $zipcode;

        // Localidad y provincia son las que resolvió Zipnova; con la localidad del comprador, si
        // Zipnova no la ecoa, quedan las que él escribió.
        if (!$resolver_por_zipcode) {
            if (!self::es_una_ubicacion_real($cotizacion['city'])) {
                $cotizacion['city'] = $city;
            }
            if (!self::es_una_ubicacion_real($cotizacion['state'])) {
                $cotizacion['state'] = $state;
            }
        }

        // 🔴 La guarda del centinela, y es la parte más importante de este método: NO se da por
        // sentado que el destino que se está por devolver sea un lugar de verdad. Si `city` o
        // `state` ecoan el centinela (o vienen vacías), lo que hay en la mano es un precio a
        // ninguna parte. Ahí el comportamiento correcto es el de siempre —`needs_location`, que el
        // comprador escriba su localidad—, y no mostrar un envío que nadie sabe a dónde va. Esto es
        // lo que vuelve verificable un comportamiento que Zipnova no documenta en ningún lado.
        //
        // 🔴 Corre SIEMPRE, y no solo cuando se resolvió por código postal. `POST /api/envios/cotizar`
        // es PÚBLICA y `city` viaja en el body: mandando `city` = `state` = el centinela se salteaba
        // la guarda entera y el 200 devolvía "Zzz Inexistente" como localidad, que el SPA guarda en
        // `buyers.envio_city` y después precarga en `carts.envio_destino`. O sea: la última puerta
        // por la que el centinela podía terminar escrito como la localidad de una persona. Va
        // DESPUÉS de completar con lo que escribió el comprador, para mirar el valor que realmente
        // va a salir en la respuesta y no un paso intermedio.
        if (!self::zipnova_resolvio_el_destino($cotizacion)) {
            throw new UbicacionException('No se resolvió la localidad del código postal ' . $zipcode . '.');
        }

        $cotizacion['declared_value'] = $declared_value;
        $cotizacion['quoted_at'] = now()->toIso8601String();

        return $cotizacion;
    }

    /**
     * La respuesta cruda de `POST /shipments/quote`, con caché de `SEGUNDOS_DE_CACHE`.
     *
     * Por qué se cachea: el rate limit de Zipnova (500 cotizaciones por minuto) es por IP DEL
     * SERVIDOR, y en el shared hosting esa IP la comparten todas las tiendas. Cada comprador que
     * escribe su CP en la ficha, después en el carrito y después elige una opción pide la misma
     * cotización tres veces; con la caché se la pide una. Solo se guarda una respuesta con
     * resultados (una vacía o rara no se repite diez minutos) y una falla nunca se cachea. Si la
     * caché misma falla (permisos del storage), se cotiza igual y queda el rastro en el log.
     *
     * @param ZipnovaClient $client
     * @param array $payload
     * @param string $clave
     * @return array
     * @throws UbicacionException|ZipnovaException
     */
    protected static function respuesta_de_zipnova(ZipnovaClient $client, array $payload, $clave)
    {
        $cacheada = null;
        try {
            $cacheada = Cache::get($clave);
        } catch (\Throwable $e) {
            Log::warning('ZipnovaCotizadorService: no se pudo leer la caché de cotizaciones: ' . $e->getMessage());
        }

        if (is_array($cacheada) && self::es_una_cotizacion($cacheada)) {
            return $cacheada;
        }

        try {
            $respuesta = $client->quote($payload);
        } catch (ZipnovaException $e) {
            if ($e->esDeUbicacion()) {
                throw new UbicacionException($e->getMessage(), $e->getStatus(), $e->getBody(), $e);
            }

            throw $e;
        }

        if (self::es_una_cotizacion($respuesta)) {
            try {
                Cache::put($clave, $respuesta, self::SEGUNDOS_DE_CACHE);
            } catch (\Throwable $e) {
                Log::warning('ZipnovaCotizadorService: no se pudo escribir la caché de cotizaciones: ' . $e->getMessage());
            }
        }

        return $respuesta;
    }

    /**
     * Clave de caché de una cotización: comercio + depósito + destino + ítems (peso, medidas y
     * descripción de cada uno, o sea lo que Zipnova realmente cotiza) + valor declarado + envío
     * gratis. La cola va hasheada para que la clave sirva en cualquier driver (memcached no
     * acepta espacios ni claves largas, y la localidad viene escrita por el comprador).
     *
     * El centinela de `CENTINELA_UBICACION` entra acá como una localidad más, y eso es lo que
     * corresponde: es una constante, así que dos cotizaciones sin localidad del mismo destino
     * comparten la clave (que es el punto de la caché) y no colisionan con una del mismo CP pero
     * con la localidad escrita a mano, que es OTRO request a Zipnova. Lo que separa a la corrida
     * base de la del conjunto sigue siendo el hash de los ítems, que el centinela no toca.
     *
     * @param int $commerce_id
     * @param array $payload El body que se le manda a Zipnova.
     * @param bool $envio_gratis
     * @return string
     */
    public static function clave_de_cache($commerce_id, array $payload, $envio_gratis)
    {
        $destination = isset($payload['destination']) && is_array($payload['destination']) ? $payload['destination'] : [];
        $zipcode = isset($destination['zipcode']) ? (string) $destination['zipcode'] : '';

        $partes = [
            isset($payload['origin_id']) ? (string) $payload['origin_id'] : 'auto',
            $zipcode,
            isset($destination['city']) ? mb_strtolower(trim((string) $destination['city'])) : '',
            isset($destination['state']) ? mb_strtolower(trim((string) $destination['state'])) : '',
            md5(json_encode(isset($payload['items']) ? $payload['items'] : [])),
            isset($payload['declared_value']) ? (string) $payload['declared_value'] : '0',
            $envio_gratis ? '1' : '0',
        ];

        return self::PREFIJO_CACHE . (int) $commerce_id . ':' . $zipcode . ':' . md5(implode('|', $partes));
    }

    /**
     * True si el destino que se está por devolver es un lugar de verdad.
     *
     * Es la contrapartida de `CENTINELA_UBICACION` y se pregunta SIEMPRE, no solo cuando se cotizó
     * con él: la cotización trae el destino que Zipnova resolvió (`destination.city` /
     * `destination.state`), ya completado con lo que haya escrito el comprador, y si eso vuelve
     * vacío o ecoando el centinela, entonces no hay destino. El endpoint es público y `city` viaja
     * en el body, así que el centinela también puede entrar por ahí.
     *
     * @param array $cotizacion Cotización ya normalizada.
     * @return bool
     */
    protected static function zipnova_resolvio_el_destino(array $cotizacion)
    {
        $city = isset($cotizacion['city']) ? $cotizacion['city'] : null;
        $state = isset($cotizacion['state']) ? $cotizacion['state'] : null;

        return self::es_una_ubicacion_real($city) && self::es_una_ubicacion_real($state);
    }

    /**
     * True si el valor es una localidad o provincia de verdad y no el centinela ni un vacío.
     *
     * La comparación es laxa a propósito (sin distinguir mayúsculas ni espacios de más): lo que se
     * está descartando es un eco del valor que se mandó, y no hay ningún caso legítimo en el que
     * Zipnova devuelva una localidad parecida al centinela.
     *
     * @param mixed $valor
     * @return bool
     */
    protected static function es_una_ubicacion_real($valor)
    {
        if (!is_string($valor)) {
            return false;
        }

        $limpio = trim($valor);

        if ($limpio === '') {
            return false;
        }

        return mb_strtolower($limpio) !== mb_strtolower(self::CENTINELA_UBICACION);
    }

    /**
     * True si la respuesta tiene la forma de una cotización (con resultados, aunque sea vacíos).
     *
     * @param mixed $respuesta
     * @return bool
     */
    protected static function es_una_cotizacion($respuesta)
    {
        if (!is_array($respuesta)) {
            return false;
        }

        return (isset($respuesta['all_results']) && is_array($respuesta['all_results']))
            || (isset($respuesta['results']) && is_array($respuesta['results']));
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
     * Las líneas de la base MÁS las que el comprador está por agregar (modo `articles_extra` del
     * endpoint público: la ficha del artículo con un carrito ya empezado).
     *
     * Un id que ya está en la base no agrega una línea nueva: le SUMA la cantidad. El envío se
     * cotiza por unidad (`ZipnovaPaquetesHelper` repite el ítem `amount` veces), así que dos
     * líneas del mismo artículo o una con la suma dan los mismos ítems — pero una sola línea es lo
     * que el carrito va a tener de verdad cuando el comprador apriete "Agregar", y es lo que hace
     * que la clave de caché de esta corrida coincida con la del carrito ya armado.
     *
     * El subtotal se suma: el de la base sale de `carts.total` (precios que resolvió el servidor) y
     * el del extra del precio público del artículo, que es el mismo embudo con el que el carrito lo
     * va a cargar. Solo se usa para el valor declarado y para la regla de envío gratis.
     *
     * Un extra que no existe, que es de otro comercio o que no viaja no agrega nada: la cotización
     * del conjunto queda igual a la de la base y la diferencia da 0, que es la verdad.
     *
     * @param array $armado_base `{lineas, subtotal}` de `lineas_desde_carrito()` o `lineas_desde_articulos()`.
     * @param int $commerce_id Comercio dueño de los artículos (el del carrito, si hay carrito).
     * @param array $items_extra `[['id' => int, 'amount' => int], ...]`
     * @return array{lineas: array, subtotal: float}
     */
    public static function lineas_con_extra(array $armado_base, $commerce_id, array $items_extra)
    {
        $armado_extra = self::lineas_desde_articulos($commerce_id, $items_extra);

        if (count($armado_extra['lineas']) === 0) {
            return $armado_base;
        }

        $lineas = $armado_base['lineas'];

        foreach ($armado_extra['lineas'] as $extra) {
            $id = (int) $extra['article']->id;
            $ya_estaba = false;

            foreach ($lineas as $i => $linea) {
                if (!isset($linea['article']) || !is_object($linea['article'])) {
                    continue;
                }
                if ((int) $linea['article']->id !== $id) {
                    continue;
                }
                $lineas[$i]['amount'] = (int) $linea['amount'] + (int) $extra['amount'];
                $ya_estaba = true;
                break;
            }

            if (!$ya_estaba) {
                $lineas[] = $extra;
            }
        }

        return [
            'lineas'   => $lineas,
            'subtotal' => round((float) $armado_base['subtotal'] + (float) $armado_extra['subtotal'], 2),
        ];
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
