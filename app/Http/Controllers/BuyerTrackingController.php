<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\BuyerTrackingHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Ingesta de los eventos de comportamiento que manda el SPA de la tienda
 * (mision tracking-buyers-tienda).
 *
 * Un solo endpoint por LOTES y no uno por evento: una vista de producto no puede costarle
 * un request al comprador.
 *
 * Toda la logica esta en BuyerTrackingHelper, que es la convencion del repo. Aca solo se
 * lee el lote del cuerpo, se resuelve quien es el comprador y se delega.
 */
class BuyerTrackingController extends Controller
{
    /**
     * Registra un lote de eventos de tracking.
     *
     * ⚠️ QUE DEVUELVE, DE VERDAD. Este metodo devuelve 204 en TODOS sus caminos: sin la
     * extension, sin la tabla, con el lote entero invalido, con el cuerpo ilegible o con la
     * base caida. Es el molde de DemoEventoController@store de `empresa-api`, y el motivo es
     * el mismo: el que llama es el navegador de un comprador que esta mirando productos. Un
     * 403 o un 500 le llenarian la consola de errores a gente que no tiene nada que ver con
     * esto, y el requisito de esta mision es no degradar la navegacion.
     *
     * 🔴 Lo que NO es cierto —y este docblock lo afirmaba— es que el SPA vea 204 "pase lo que
     * pase". Hay middlewares que corren ANTES que este metodo y responden por su cuenta:
     *
     *   - `throttle:60,1` (routes/api.php) responde 429 cuando se pasa del limite;
     *   - VerifyCsrfToken respondia 419 en todo cliente real, porque el pipeline stateful de
     *     Sanctum corre siempre y sendBeacon no puede mandar el X-XSRF-TOKEN. Medido con curl
     *     el 15/8/2026 y arreglado agregando 'api/buyer-tracking/*' al $except de
     *     App\Http\Middleware\VerifyCsrfToken (ver el comentario largo de ese archivo).
     *
     * Ese es justamente el modo de falla mas caro de esta funcionalidad: cuando corta un
     * middleware, el try/catch del helper nunca llega a ejecutarse y no queda ni una señal en
     * el log. Que sea mudo para el comprador no lo puede volver mudo para el operador.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    function store(Request $request) {
        /**
         * 🔴 El try envuelve TODO, incluido $this->buyerId().
         *
         * No es defensivo de mas: buyerId() consulta el guard `buyer`, o sea la sesion, y
         * antes se evaluaba como ARGUMENTO de registrar() — o sea afuera del try/catch del
         * helper. Un driver de sesion caido subia como 500 al navegador del comprador por un
         * evento de tracking, que es exactamente lo que esta mision promete que no pasa.
         *
         * Lo mismo vale para leer el cuerpo: json_decode sobre lo que manda un navegador.
         */
        try {
            $lote = $this->loteDelRequest($request);

            /**
             * 🔴 `buyer_id` sale de la SESION y nunca del payload. Si viniera del cliente,
             * cualquiera podria atribuirle su navegacion a otro comprador.
             *
             * Que devuelva null es el caso NORMAL y no la excepcion: el grupo `auth:sanctum`
             * de routes/api.php esta comentado y la mayor parte del trafico de una tienda no
             * esta logueado. null = visitante anonimo, y la columna lo admite.
             */
            BuyerTrackingHelper::registrar(
                $lote['events'],
                $lote['commerce_id'],
                $this->buyerId()
            );
        } catch (\Throwable $e) {
            $this->registrarFalla($e);
        }

        return response(null, 204);
    }

    /**
     * Saca el lote del request, venga como venga el cuerpo.
     *
     * -----------------------------------------------------------------------------------
     * Por que hay que soportar un content-type que NO es application/json
     * -----------------------------------------------------------------------------------
     * Esto es lo contraintuitivo del endpoint, asi que va escrito: el camino principal manda
     * el cuerpo como `text/plain;charset=UTF-8` aunque el contenido sea JSON.
     *
     * El SPA envia el lote con navigator.sendBeacon, que es lo unico que sobrevive al cierre
     * de la pestaña —justo cuando se pierde el ultimo product_view de cada sesion, que es el
     * caso por el que se eligio sendBeacon—. Si el Blob va con type 'application/json', el
     * request deja de ser "simple" para CORS y el navegador le antepone un OPTIONS de
     * preflight. Y es cross-origin real (cliente.com.ar -> api.cliente.com.ar). Durante
     * pagehide/beforeunload el preflight puede no llegar a completarse y el navegador
     * DESCARTA el beacon: se pierde exactamente el evento que se queria salvar.
     *
     * `text/plain;charset=UTF-8` esta en la lista segura de CORS, asi que no hay preflight.
     * El costo es este metodo: con ese content-type Laravel NO parsea el cuerpo, o sea que
     * $request->input('events') viene vacio y hay que leer el crudo a mano.
     *
     * Se soportan los DOS formatos porque los dos existen en produccion: el fallback por
     * axios (cuando no hay sendBeacon) sigue mandando application/json.
     *
     * Un cuerpo que no sea JSON valido se descarta sin explotar: devuelve el lote vacio y el
     * helper corta en su primera guarda.
     *
     * @param Request $request
     * @return array ['events' => mixed, 'commerce_id' => mixed]
     */
    private function loteDelRequest(Request $request)
    {
        /* Camino 1: application/json (el fallback por axios). Laravel ya lo parseo. */
        $events = $request->input('events');

        if (!is_null($events)) {
            return [
                'events'      => $events,
                'commerce_id' => $request->input('commerce_id'),
            ];
        }

        /* Camino 2: text/plain con JSON adentro (el beacon). Hay que parsearlo a mano. */
        $crudo = json_decode($request->getContent(), true);

        if (!is_array($crudo)) {
            return ['events' => null, 'commerce_id' => null];
        }

        return [
            'events'      => array_key_exists('events', $crudo) ? $crudo['events'] : null,
            'commerce_id' => array_key_exists('commerce_id', $crudo)
                ? $crudo['commerce_id']
                : $request->input('commerce_id'),
        ];
    }

    /**
     * Deja constancia de una falla del controller (leer el cuerpo o resolver la sesion), con
     * contexto suficiente para diagnosticarla y sin un solo dato del comprador.
     *
     * El try anidado es el mismo criterio que usa BuyerTrackingHelper::registrarFalla: este
     * metodo es lo ultimo que corre antes de dar la excepcion por atendida, asi que un logger
     * roto no puede ser la via por la que termine escapando igual.
     *
     * @param \Throwable $e
     * @return void
     */
    private function registrarFalla($e)
    {
        try {
            Log::warning('BuyerTrackingController: fallo antes de delegar en el helper.', [
                'excepcion' => get_class($e),
                'mensaje'   => $e->getMessage(),
                'archivo'   => $e->getFile().':'.$e->getLine(),
            ]);
        } catch (\Throwable $ignorada) {
            /* Si hasta el logger esta roto, no queda nada mas que hacer: lo que no puede
               pasar es que la navegacion del comprador se rompa por un evento de tracking. */
        }
    }
}
