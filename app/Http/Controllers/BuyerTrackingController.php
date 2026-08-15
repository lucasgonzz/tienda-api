<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\BuyerTrackingHelper;
use Illuminate\Http\Request;

/**
 * Ingesta de los eventos de comportamiento que manda el SPA de la tienda
 * (mision tracking-buyers-tienda).
 *
 * Un solo endpoint por LOTES y no uno por evento: una vista de producto no puede costarle
 * un request al comprador.
 *
 * Toda la logica esta en BuyerTrackingHelper, que es la convencion del repo. Aca solo se
 * resuelve quien es el comprador y se delega.
 */
class BuyerTrackingController extends Controller
{
    /**
     * Registra un lote de eventos de tracking.
     *
     * 🔴 Devuelve 204 SIEMPRE, pase lo que pase: sin la extension, sin la tabla, con el
     * lote entero invalido o con la base caida. Es el molde de DemoEventoController@store
     * de `empresa-api`, y el motivo es el mismo: el que llama es el navegador de un
     * comprador que esta mirando productos. Un 403 o un 500 le llenarian la consola de
     * errores a gente que no tiene nada que ver con esto, y el requisito de esta mision es
     * no degradar la navegacion.
     *
     * Que sea mudo para el comprador no lo hace mudo para el operador: el helper deja la
     * falla en el log con contexto.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    function store(Request $request) {
        /**
         * 🔴 `buyer_id` sale de la SESION y nunca del payload. Si viniera del cliente,
         * cualquiera podria atribuirle su navegacion a otro comprador.
         *
         * Que devuelva null es el caso NORMAL y no la excepcion: el grupo `auth:sanctum`
         * de routes/api.php esta comentado y la mayor parte del trafico de una tienda no
         * esta logueado. null = visitante anonimo, y la columna lo admite.
         */
        BuyerTrackingHelper::registrar(
            $request->input('events'),
            $request->input('commerce_id'),
            $this->buyerId()
        );

        return response(null, 204);
    }
}
