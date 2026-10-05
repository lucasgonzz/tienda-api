<?php

namespace App\Http\Controllers;

use App\Article;
use App\Brand;
use App\Category;
use App\Events\SubCategoryViewed;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CatalogoPorListaHelper;
use App\Http\Controllers\Helpers\HomeHelper;
use App\SubCategory;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HomeController extends Controller
{

    function featuredLastUploads(Request $request) {
        $last_uploads = Article::where('user_id', $request->commerce_id)
                            ->checkOnline()
                            ->checkStock()
                            ->withAll()
                            ->orderBy('created_at', 'DESC')
                            ->where('status', 'active');
        if (str_contains(env('APP_URL'), 'truvari')) {
            $last_uploads = $last_uploads->paginate(10);
        } else {
            $last_uploads = $last_uploads->paginate(12);
        }

        $last_uploads = ArticleHelper::checkPriceTypes($last_uploads);

        if ($request->get('page') == 1) {
            $featured = HomeHelper::getFeatured($request->commerce_id);
            $in_offer = HomeHelper::getInOffer($request->commerce_id);
            $promociones_vinoteca = HomeHelper::get_promociones_vinoteca($request->commerce_id);

            // Novedades: los ultimos 20 articulos con un INGRESO de mercaderia (no ventas ni ajustes)
            $novedades = HomeHelper::getNovedades($request->commerce_id);

            /*
             * Las dos colecciones nuevas de la mision combos-y-rangos-de-precio (16/9/2026).
             * Son ADITIVAS y nada de lo que ya viajaba cambio de nombre ni de forma.
             *
             * Las dos pueden venir vacias sin que eso sea un error, y por dos motivos distintos:
             *   - `articulos_con_rangos`: el comercio no cargo ningun tramo de precio.
             *   - `combos`: no hay combos con "Mostrar en la tienda" prendido, O esta base
             *     todavia no tiene el esquema de combos porque el release de empresa no llego
             *     (ver `ComboEsquemaHelper`). Los dos casos se ven igual desde afuera, a
             *     proposito: el SPA esconde la seccion y listo.
             */
            $articulos_con_rangos = HomeHelper::get_articulos_con_rangos($request->commerce_id);
            $combos = HomeHelper::get_combos($request->commerce_id);

            $featured = ArticleHelper::checkPriceTypes($featured);
            $in_offer = ArticleHelper::checkPriceTypes($in_offer);
            $novedades = ArticleHelper::checkPriceTypes($novedades);
            $articulos_con_rangos = ArticleHelper::checkPriceTypes($articulos_con_rangos);
            return response()->json([
                                        'articles' => $last_uploads,
                                        'featured'  => $featured,
                                        'promociones_vinoteca'  => $promociones_vinoteca,
                                        'in_offer'  => $in_offer,
                                        'novedades' => $novedades,
                                        'articulos_con_rangos' => $articulos_con_rangos,
                                        'combos'    => $combos,
                                    ], 200);
        } 
        return response()->json(['articles' => $last_uploads], 200);
    }

    function articlesFromCategory($category_id, $sub_category_id, $bodega_id, $cepa_id, $order_by) {
        $articles = Article::withAll()
                            ->checkOnline()
                            ->checkStock();
        if ($category_id != 0) {
            $articles = $articles->where('category_id', $category_id);
        } else if ($sub_category_id != 0) {
            $articles = $articles->where('sub_category_id', $sub_category_id);
        } else if ($bodega_id != 0) {
            $articles = $articles->where('bodega_id', $bodega_id);
        } else if ($cepa_id != 0) {
            $articles = $articles->where('cepa_id', $cepa_id);
        }
        if ($order_by == 'fecha-mayor-menor') {
            $articles = $articles->orderBy('created_at', 'DESC');
        } else if ($order_by == 'fecha-menor-mayor') {
            $articles = $articles->orderBy('created_at', 'ASC');
        } else if ($order_by == 'precio-mayor-menor') {
            Log::info('precio mayor a menor');
            $articles = $articles->orderBy('final_price', 'DESC');
        } else if ($order_by == 'precio-menor-mayor') {
            Log::info('precio menor a mayor');
            $articles = $articles->orderBy('final_price', 'ASC');
        } else if ($order_by == 'a-z') {
            $articles = $articles->orderBy('name', 'ASC');
        } else if ($order_by == 'z-a') {
            $articles = $articles->orderBy('name', 'DESC');
        }
        $articles = $articles->simplePaginate(12);
        $articles = ArticleHelper::checkPriceTypes($articles);
        return response()->json(['articles' => $articles, 'reverse' => true], 200);
    }

    /**
     * Listado de marcas del comercio que tienen al menos un artículo online con stock según reglas de la tienda.
     *
     * @param  int|string  $commerce_id
     * @return \Illuminate\Http\JsonResponse
     */
    function brands($commerce_id)
    {
        request()->merge(['commerce_id' => $commerce_id]);

        /* Catalogo por lista (mision catalogo-por-lista-tienda, 5/10/2026). El `whereHas` ya
           respeta la lista del comprador porque pasa por checkOnline(), asi que una marca sin
           articulos habilitados no se devuelve. El conteo, en cambio, no pasaba por ahi: con una
           lista restringida cuenta solo los habilitados, por el mismo motivo que categories() (el
           numero le diria al mayorista cuantos articulos existen que no le muestran). Sin lista
           restringida la consulta queda identica a la de antes. */
        $lista = CatalogoPorListaHelper::lista_restringida($commerce_id);

        $brands = Brand::where('user_id', $commerce_id)
            ->whereHas('articles', function ($query) use ($commerce_id) {
                $query->where('user_id', $commerce_id)
                    ->checkOnline()
                    ->checkStock();
            });

        if (is_null($lista)) {
            $brands->withCount('articles');
        } else {
            $brands->withCount(['articles' => function ($query) use ($commerce_id) {
                CatalogoPorListaHelper::restringir($query, $commerce_id);
            }]);
        }

        $brands = $brands->orderBy('name', 'ASC')
            ->get();

        return response()->json(['brands' => $brands], 200);
    }

    /**
     * Artículos filtrados por marca (mismo criterio de orden que from-category).
     *
     * @param  int|string  $brand_id
     * @param  string  $order_by
     * @param  int|string  $commerce_id
     * @return \Illuminate\Http\JsonResponse
     */
    function articlesFromBrand($brand_id, $order_by, $commerce_id)
    {
        request()->merge(['commerce_id' => $commerce_id]);
        $articles = Article::withAll()
            ->checkOnline()
            ->checkStock()
            ->where('user_id', $commerce_id)
            ->where('brand_id', $brand_id);
        if ($order_by == 'fecha-mayor-menor') {
            $articles = $articles->orderBy('created_at', 'DESC');
        } else if ($order_by == 'fecha-menor-mayor') {
            $articles = $articles->orderBy('created_at', 'ASC');
        } else if ($order_by == 'precio-mayor-menor') {
            Log::info('precio mayor a menor');
            $articles = $articles->orderBy('final_price', 'DESC');
        } else if ($order_by == 'precio-menor-mayor') {
            Log::info('precio menor a mayor');
            $articles = $articles->orderBy('final_price', 'ASC');
        } else if ($order_by == 'a-z') {
            $articles = $articles->orderBy('name', 'ASC');
        } else if ($order_by == 'z-a') {
            $articles = $articles->orderBy('name', 'DESC');
        }
        $articles = $articles->simplePaginate(12);
        $articles = ArticleHelper::checkPriceTypes($articles);

        return response()->json(['articles' => $articles, 'reverse' => true], 200);
    }

    /**
     * Las subcategorias de una categoria que tienen articulos.
     *
     * Catalogo por lista (mision catalogo-por-lista-tienda, 5/10/2026): SOLO si el comprador tiene
     * una lista restringida, "tiene articulos" pasa a ser "tiene articulos habilitados para su
     * lista" — si no, el mayorista veria subcategorias que al abrirlas estan vacias. Sin lista
     * restringida la consulta de subcategorias queda identica a la de antes.
     *
     * La ruta no trae `commerce_id`, y averiguarlo antes costaria una lectura de la categoria en
     * cada request. Por eso la consulta de siempre corre PRIMERO, tal cual, y el comercio sale de lo
     * que devolvio (`sub_categories.user_id`, que lo escribio el ERP): si no hay subcategorias no hay
     * nada que esconder, y si las hay y la lista no es restringida se devuelven esas mismas. Solo el
     * comprador de una lista restringida paga la segunda consulta, la filtrada.
     */
    function subCategories($category_id) {
        $sub_categories = SubCategory::where('category_id', $category_id)
                                    ->whereHas('articles')
                                    ->get();

        if (count($sub_categories) >= 1) {
            $commerce_id = $sub_categories->first()->user_id;

            if (!is_null(CatalogoPorListaHelper::lista_restringida($commerce_id))) {
                $sub_categories = SubCategory::where('category_id', $category_id)
                                            ->whereHas('articles', function ($query) use ($commerce_id) {
                                                CatalogoPorListaHelper::restringir($query, $commerce_id);
                                            })
                                            ->get();
            }
        }

        return response()->json(['sub_categories' => $sub_categories], 200);
    }

    /**
     * Las categorias del comercio, con cuantos articulos tiene cada una (y sus subcategorias).
     *
     * Catalogo por lista (mision catalogo-por-lista-tienda, 5/10/2026): SOLO si el comprador tiene
     * una lista restringida, los conteos cuentan los articulos habilitados para su lista y las
     * categorias sin ninguno no se devuelven. Es por dos motivos: una categoria que al abrirla esta
     * vacia es un defecto visible, y un conteo de todo el catalogo le diria al mayorista cuantos
     * articulos existen que no le muestran.
     *
     * 🔴 Sin lista restringida (el 100% de los clientes de hoy) la consulta queda IDENTICA a la de
     * antes, incluido que las categorias sin articulos SI se devuelven: el SPA de hoy convive con
     * eso y esta mision no lo cambia. Por eso son dos ramas y no un closure que "a veces no hace
     * nada": la rama de siempre no se toca ni en el SQL.
     *
     * Lo que se cuenta con lista restringida es lo mismo que se contaba antes (todos los articulos de
     * la categoria, sin mirar `online` ni el stock) menos los no habilitados: se agrega SOLO la
     * restriccion de la lista.
     */
    function categories($commerce_id) {
        $lista = CatalogoPorListaHelper::lista_restringida($commerce_id);

        if (is_null($lista)) {
            $categories = Category::where('user_id', $commerce_id)
                                    ->where('name', '!=', 'La de siempre')
                                    ->withCount('articles')
                                    ->with(['sub_categories' => function($query) {
                                        $query->whereHas('articles')
                                                ->withCount('articles')
                                                ->orderBy('name', 'ASC');
                                    }])
                                    ->orderBy('name', 'ASC')
                                    ->get();
        } else {
            $solo_habilitados = function ($query) use ($commerce_id) {
                CatalogoPorListaHelper::restringir($query, $commerce_id);
            };

            $categories = Category::where('user_id', $commerce_id)
                                    ->where('name', '!=', 'La de siempre')
                                    ->whereHas('articles', $solo_habilitados)
                                    ->withCount(['articles' => $solo_habilitados])
                                    ->with(['sub_categories' => function($query) use ($solo_habilitados) {
                                        $query->whereHas('articles', $solo_habilitados)
                                                ->withCount(['articles' => $solo_habilitados])
                                                ->orderBy('name', 'ASC');
                                    }])
                                    ->orderBy('name', 'ASC')
                                    ->get();
        }
        // $categories = HomeHelper::addIndexCategory($categories, $commerce_id);
        // $categories = HomeHelper::removeCategoriesWithoutArticles($categories, $commerce_id);
        return response()->json(['categories' => $categories], 200);
    }

}
