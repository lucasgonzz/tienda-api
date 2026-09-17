<?php

namespace App\Http\Controllers;

use App\Cart;
use App\Http\Controllers\Helpers\CartOwnershipHelper;
use App\Services\Zipnova\SinArticulosException;
use App\Services\Zipnova\SinZipnovaException;
use App\Services\Zipnova\UbicacionException;
use App\Services\Zipnova\ZipnovaCotizadorService;
use App\Services\Zipnova\ZipnovaException;
use App\Services\Zipnova\ZipnovaIncrementalHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Cotización pública del envío por correo (misión zipnova-envios, 14/9/2026).
 *
 * `POST /api/envios/cotizar` es PÚBLICA a propósito: el comprador escribe su código postal en la
 * ficha del artículo o en el carrito antes de identificarse, y la mayoría compra sin registrarse.
 * Lo que la acota es el `throttle:30,1` de la ruta (cada llamada le cuesta una consulta a Zipnova
 * al comercio, con un rate limit propio de 500/min por IP) y que en modo `cart_id` el carrito
 * tiene que ser de quien pide (`CartOwnershipHelper`, misma regla que `CartController`).
 *
 * El precio que devuelve es informativo: el que se cobra es el que `EnvioCartHelper` guarda en
 * `carts.envio_precio` al elegir la opción, re-cotizando del lado del servidor.
 *
 * Códigos de error, todos con `message` en castellano para el comprador y el detalle en el log:
 *   422 `validacion`    faltan o están mal los datos del pedido de cotización (`errors`)
 *   422 `sin_zipnova`   el negocio no tiene envíos por correo (sin conector o sin esquema)
 *   422 `sin_articulos` nada que enviar (artículos ajenos, digitales, o lista vacía)
 *   422 `ubicacion`     Zipnova no reconoció el CP ni resolvió su localidad: `needs_location: true`,
 *                       pedir localidad y provincia (es el camino de excepción desde el 17/9/2026:
 *                       un CP válido se resuelve solo, ver `ZipnovaCotizadorService`)
 *   403 `carrito`       el carrito no es de esta sesión
 *   502 `zipnova`       Zipnova falló o no respondió
 */
class EnvioController extends Controller
{
    /**
     * Cotiza el envío para un código postal.
     *
     * ── Request ───────────────────────────────────────────────────────────────────────────────
     *
     * `{commerce_id, zipcode, city?, state?, cart_id?, articles?: [{id, amount}],
     *   articles_extra?: [{id, amount}]}`
     *
     * La BASE es lo que el comprador ya tiene: con `cart_id`, el carrito guardado (líneas y
     * subtotal del servidor); sin él, la lista `articles`. Si vienen los dos, manda el carrito.
     *
     * `articles_extra` es lo que está por agregar (el artículo de la ficha, con su cantidad). Es
     * OPCIONAL y sin él la respuesta es exactamente la de siempre, clave por clave: un SPA viejo no
     * se entera de nada.
     *
     * ── Respuesta 200 ─────────────────────────────────────────────────────────────────────────
     *
     * `{zipcode: string, city: string|null, state: string|null, envio_gratis: bool, opciones: [...]}`
     *
     * `city` y `state` son la localidad y la provincia que Zipnova resolvió para ese código
     * postal, y desde el 17/9/2026 vienen llenas TAMBIÉN cuando el comprador mandó solo el CP:
     * el servidor las resuelve con el centinela de `ZipnovaCotizadorService::CENTINELA_UBICACION`.
     * No hay ninguna clave nueva —son las mismas cinco de siempre—, lo que cambió es que dejaron
     * de venir en null. El SPA las muestra ("Envíos a Rosario, Santa Fe") y las guarda en el perfil
     * del comprador; si Zipnova no resolvió nada, sale el 422 `ubicacion` de siempre.
     *
     * Con `articles_extra` se cotiza DOS veces —primero la base sola, después la base más el
     * extra— y `envio_gratis` y `opciones` son los del conjunto CON el extra: es lo que el
     * comprador va a pagar si lo agrega. Se suma un bloque:
     *
     * ```
     * incremental: {
     *   hay_base:           bool          había base con la que comparar (carrito con algo que viaje)
     *   misma_opcion:       bool          se compararon dos precios de la MISMA opción de envío
     *   key:                string|null   opción del conjunto de la que sale `precio_total`
     *   key_base:           string|null   opción de la base de la que sale `precio_base`
     *   precio_base:        float|null    lo que el envío cuesta HOY (sin el extra)
     *   precio_total:       float|null    lo que costaría CON el extra
     *   diferencia:         float|null    precio_total - precio_base; puede ser 0 o negativa
     *   envio_gratis_base:  bool          la base ya caía en envío gratis
     *   envio_gratis_total: bool          el conjunto cae en envío gratis
     *   queda_gratis:       bool          !envio_gratis_base && envio_gratis_total
     * }
     * ```
     *
     * Con `hay_base: false` (carrito vacío, carrito que no se pudo leer, o base sin nada que
     * viaje) `diferencia` y `precio_base` son null y lo que se muestra es el costo completo, como
     * siempre. Ver `ZipnovaIncrementalHelper` para los tres casos que definen si el número sirve.
     *
     * ── Un `cart_id` que no se puede leer ─────────────────────────────────────────────────────
     *
     * Sin `articles_extra` sigue siendo 403 `carrito` (el comprador pidió cotizar ESE carrito y no
     * es suyo). Con `articles_extra` el carrito es solo la base: se ignora, se cotiza el extra solo
     * y sale `hay_base: false`. La ficha del artículo tiene que seguir mostrando un precio aunque
     * el carrito se haya vencido del otro lado.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse `{zipcode, city, state, envio_gratis, opciones: [...], incremental?}`
     */
    function cotizar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'commerce_id'             => 'required|integer',
            // El largo real (4 a 8) se exige DESPUÉS de limpiar: "x5000-abc" o "5000 " son
            // entradas legítimas del teclado del teléfono; acá solo se frena el abuso.
            'zipcode'                 => 'required|string|max:20',
            'city'                    => 'nullable|string|max:120',
            'state'                   => 'nullable|string|max:120',
            'cart_id'                 => 'nullable|integer',
            'articles'                => 'nullable|array|max:' . ZipnovaCotizadorService::MAX_LINEAS,
            'articles.*.id'           => 'required|integer',
            'articles.*.amount'       => 'nullable|numeric|min:1',
            // Mismas reglas que `articles`: es la misma lista de líneas, del otro lado de la resta.
            'articles_extra'          => 'nullable|array|max:' . ZipnovaCotizadorService::MAX_LINEAS,
            'articles_extra.*.id'     => 'required|integer',
            'articles_extra.*.amount' => 'nullable|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'codigo'  => 'validacion',
                'message' => 'Revisá el código postal e intentá de nuevo.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $commerce_id = (int) $request->input('commerce_id');
        $zipcode = ZipnovaCotizadorService::zipcode_limpio($request->input('zipcode'));

        if ($zipcode === '') {
            return response()->json([
                'codigo'  => 'validacion',
                'message' => 'Revisá el código postal e intentá de nuevo.',
                'errors'  => ['zipcode' => ['El código postal tiene que tener entre 4 y 8 letras o números.']],
            ], 422);
        }

        $articles_extra = $request->input('articles_extra');
        $articles_extra = is_array($articles_extra) ? $articles_extra : [];
        $hay_extra = count($articles_extra) > 0;

        $cart_id = $request->input('cart_id');
        $cart = null;

        if (!empty($cart_id)) {
            $cart = Cart::find((int) $cart_id);

            // Misma regla que CartController: sin pertenencia no se lee nada del carrito, ni
            // siquiera sus cantidades. 403 también para un id que no existe: no se distingue
            // "no existe" de "es de otro" para no permitir enumerar carritos.
            if (!CartOwnershipHelper::puede($cart)) {
                if (!$hay_extra) {
                    return response()->json([
                        'codigo'  => 'carrito',
                        'message' => 'No encontramos tu carrito. Volvé a cargarlo e intentá de nuevo.',
                    ], 403);
                }

                // Con `articles_extra` el carrito es solo la base de la resta: si no se puede
                // leer, se pierde la base (no el precio). Ver el docblock del método.
                $cart = null;
            }
        }

        if (!is_null($cart)) {
            // El comercio sale del carrito, no del body: el precio y la cuenta de Zipnova son
            // los del dueño de esos artículos.
            $commerce_id = (int) $cart->user_id;
            $armado = ZipnovaCotizadorService::lineas_desde_carrito($cart);
        } else {
            $articles = $request->input('articles');
            $armado = ZipnovaCotizadorService::lineas_desde_articulos($commerce_id, is_array($articles) ? $articles : []);
        }

        $cotizacion_base = null;

        try {
            if ($hay_extra) {
                // 🔴 La base va PRIMERO, y ese orden importa por la caché: el comprador mira una
                // ficha atrás de otra con el mismo carrito, así que esta corrida se repite y la
                // caché de diez minutos se la come. La del conjunto cambia con cada artículo.
                //
                // Una base sin nada que enviar (carrito vacío, todo digital) no es un error acá:
                // es `hay_base: false` y el costo que se muestra es el completo. El resto de las
                // fallas —sin conector, destino que no se reconoce, Zipnova caído— salen por el
                // mismo lugar de siempre: al conjunto le pasaría exactamente lo mismo.
                try {
                    $cotizacion_base = ZipnovaCotizadorService::cotizar(
                        $commerce_id,
                        $armado['lineas'],
                        $armado['subtotal'],
                        $zipcode,
                        $request->input('city'),
                        $request->input('state')
                    );
                } catch (SinArticulosException $e) {
                    $cotizacion_base = null;
                }

                $armado = ZipnovaCotizadorService::lineas_con_extra($armado, $commerce_id, $articles_extra);
            }

            $cotizacion = ZipnovaCotizadorService::cotizar(
                $commerce_id,
                $armado['lineas'],
                $armado['subtotal'],
                $zipcode,
                $request->input('city'),
                $request->input('state')
            );
        } catch (SinZipnovaException $e) {
            return response()->json([
                'codigo'  => 'sin_zipnova',
                'message' => 'Este negocio no tiene envíos por correo configurados.',
            ], 422);
        } catch (SinArticulosException $e) {
            return response()->json([
                'codigo'  => 'sin_articulos',
                'message' => 'No hay nada para enviar: los artículos elegidos no requieren envío.',
            ], 422);
        } catch (UbicacionException $e) {
            return response()->json([
                'codigo'         => 'ubicacion',
                'needs_location' => true,
                'message'        => 'No reconocimos ese código postal. Decinos la localidad y la provincia.',
            ], 422);
        } catch (ZipnovaException $e) {
            Log::warning('EnvioController@cotizar: Zipnova no pudo cotizar', [
                'commerce_id' => $commerce_id,
                'zipcode'     => $zipcode,
                'status'      => $e->getStatus(),
                'detalle'     => $e->getMessage(),
            ]);

            return response()->json([
                'codigo'  => 'zipnova',
                'message' => 'No pudimos cotizar el envío en este momento. Probá de nuevo en un rato.',
            ], 502);
        }

        $respuesta = [
            'zipcode'      => $cotizacion['zipcode'],
            'city'         => $cotizacion['city'],
            'state'        => $cotizacion['state'],
            'envio_gratis' => $cotizacion['envio_gratis'],
            'opciones'     => $cotizacion['opciones'],
        ];

        // Sin `articles_extra` la respuesta queda igual a la de siempre, clave por clave.
        if ($hay_extra) {
            $respuesta['incremental'] = ZipnovaIncrementalHelper::comparar($cotizacion_base, $cotizacion);
        }

        return response()->json($respuesta, 200);
    }
}
