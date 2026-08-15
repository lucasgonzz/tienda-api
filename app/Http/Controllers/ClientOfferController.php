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
 * id de la URL ni del payload. El `commerce_id` de la URL solo acota el comercio; la identidad
 * la pone la sesion.
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
     * @param  int  $commerce_id
     * @return \Illuminate\Http\JsonResponse  { articles: [...] }
     */
    function index($commerce_id)
    {
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
