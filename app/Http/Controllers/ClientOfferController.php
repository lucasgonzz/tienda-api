<?php

namespace App\Http\Controllers;

use App\Article;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ClientOfferHelper;

/**
 * Las ofertas personalizadas del comprador logueado (mision promocion-personalizada-tienda).
 *
 * 🔴 Esta ruta vive en el grupo `auth:buyer` y no puede salir de ahi: la oferta es de UN
 * cliente del ERP y se resuelve por `buyers.comercio_city_client_id` de la SESION, nunca por un
 * id de la URL ni del payload.
 *
 * 🔴 Y EL COMERCIO TAMBIEN SALE DE LA SESION, no de la URL. Antes salia de la URL, y eso
 * alcanzaba para que un comprador de A pidiera `/api/client-offers/{id_de_B}` y se llevara los
 * articulos, los precios y las ofertas de B —acotado a ofertas hechas a esa misma persona, pero
 * el filtro de aislamiento entre comercios no lo puede elegir quien pregunta—. Es exactamente
 * lo que este repo ya prohibe en BuyerController@search, cuyo test fija que el controller
 * IGNORE el id de la URL y use el de la sesion. Aca se hace lo mismo.
 *
 * Toda la logica de lectura vive en ClientOfferHelper, que es la convencion del repo (21
 * helpers estaticos en esa carpeta) y ademas el unico punto que toca las tablas del contrato.
 */
class ClientOfferController extends Controller
{
    /**
     * Los articulos con oferta personalizada vigente del comprador logueado.
     *
     * Devuelve ARTICULOS, no ofertas: es la misma forma que cualquier listado de la tienda
     * (`articles`), asi que el SPA los muestra y navega a ellos con lo que ya tiene, y la
     * oferta viaja colgada en `oferta_personalizada` como en cualquier otra pantalla. Una
     * sola forma de dato en todo el sistema.
     *
     * SIN PAGINAR, a proposito: el contrato garantiza una sola oferta activa por (comercio,
     * cliente, articulo) y el motor del ERP genera un puñado por cliente. Si algun dia crece,
     * se pagina; hoy paginar seria complejidad sin problema.
     *
     * @param  int  $commerce_id  se ignora cuando el comprador declara su comercio, ver arriba
     * @return \Illuminate\Http\JsonResponse  { articles: [...] }
     */
    function index($commerce_id)
    {
        /* El comercio sale del comprador de la SESION. El id de la URL queda solo como respaldo
           para los `buyers` viejos que tengan `user_id` en null (la columna es nullable): esos
           no pueden declarar su comercio, y sin respaldo la funcionalidad no existiria para
           ellos. El aislamiento no se apoya en esto de todas formas: la query filtra ADEMAS por
           el `client_id` de la sesion. */
        $buyer = auth('buyer')->user();

        $commerce_id = (!is_null($buyer) && !is_null($buyer->user_id))
            ? (int) $buyer->user_id
            : (int) $commerce_id;

        /* Los scopes checkOnline() y checkStock() de App\Article resuelven el comercio con
           `User::find(request()->commerce_id)`. Como aca el comercio viene por la URL y no por
           query string, sin este merge los dos revientan sobre null. */
        request()->merge(['commerce_id' => $commerce_id]);

        $ids = ClientOfferHelper::idsDeArticulosConOferta($commerce_id);

        /* 200 y lista vacia, NO 404 ni 500. Tambien es el camino cuando las tablas del
           contrato todavia no llegaron a esta base, que es el estado normal de hoy: para el
           SPA "todavia no hay esquema" y "este comprador no tiene ofertas" son lo mismo. */
        if (empty($ids)) {
            return response()->json(['articles' => []], 200);
        }

        /* El MISMO filtro que cualquier listado de la tienda: un articulo dado de baja, sin
           imagenes (si el comercio lo configuro asi) o sin stock no se muestra, porque el
           mensaje llevaria a una ficha que no existe. */
        $articles = Article::whereIn('id', $ids)
                            ->where('user_id', $commerce_id)
                            ->withAll()
                            ->checkOnline()
                            ->checkStock()
                            ->get();

        /* El embudo unico de precios, que ya llama a ClientOfferHelper::aplicar() adentro. No
           se duplica la aplicacion de la oferta aca. */
        $articles = ArticleHelper::checkPriceTypes($articles);

        return response()->json(['articles' => $articles], 200);
    }
}
