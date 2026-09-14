<?php

namespace App\Http\Controllers;

use App\Cart;
use App\Http\Controllers\Helpers\CartOwnershipHelper;
use App\Services\Zipnova\SinArticulosException;
use App\Services\Zipnova\SinZipnovaException;
use App\Services\Zipnova\UbicacionException;
use App\Services\Zipnova\ZipnovaCotizadorService;
use App\Services\Zipnova\ZipnovaException;
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
 *   422 `ubicacion`     Zipnova no reconoció el CP: `needs_location: true`, pedir localidad y provincia
 *   403 `carrito`       el carrito no es de esta sesión
 *   502 `zipnova`       Zipnova falló o no respondió
 */
class EnvioController extends Controller
{
    /**
     * Cotiza el envío para un código postal.
     *
     * Body: `{commerce_id, zipcode, city?, state?, cart_id?, articles?: [{id, amount}]}`. Con
     * `cart_id` se cotiza el carrito guardado (líneas y subtotal del servidor); sin él, la lista
     * `articles` (ficha del artículo). Si vienen los dos, manda el carrito.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse `{zipcode, city, state, envio_gratis, opciones: [...]}`
     */
    function cotizar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'commerce_id'       => 'required|integer',
            'zipcode'           => 'required|string|min:4|max:8',
            'city'              => 'nullable|string|max:120',
            'state'             => 'nullable|string|max:120',
            'cart_id'           => 'nullable|integer',
            'articles'          => 'nullable|array|max:' . ZipnovaCotizadorService::MAX_LINEAS,
            'articles.*.id'     => 'required|integer',
            'articles.*.amount' => 'nullable|numeric|min:1',
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

        $cart_id = $request->input('cart_id');

        if (!empty($cart_id)) {
            $cart = Cart::find((int) $cart_id);

            // Misma regla que CartController: sin pertenencia no se lee nada del carrito, ni
            // siquiera sus cantidades. 403 también para un id que no existe: no se distingue
            // "no existe" de "es de otro" para no permitir enumerar carritos.
            if (!CartOwnershipHelper::puede($cart)) {
                return response()->json([
                    'codigo'  => 'carrito',
                    'message' => 'No encontramos tu carrito. Volvé a cargarlo e intentá de nuevo.',
                ], 403);
            }

            // El comercio sale del carrito, no del body: el precio y la cuenta de Zipnova son
            // los del dueño de esos artículos.
            $commerce_id = (int) $cart->user_id;
            $armado = ZipnovaCotizadorService::lineas_desde_carrito($cart);
        } else {
            $articles = $request->input('articles');
            $armado = ZipnovaCotizadorService::lineas_desde_articulos($commerce_id, is_array($articles) ? $articles : []);
        }

        try {
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

        return response()->json([
            'zipcode'      => $cotizacion['zipcode'],
            'city'         => $cotizacion['city'],
            'state'        => $cotizacion['state'],
            'envio_gratis' => $cotizacion['envio_gratis'],
            'opciones'     => $cotizacion['opciones'],
        ], 200);
    }
}
