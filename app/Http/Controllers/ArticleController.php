<?php

namespace App\Http\Controllers;

use App\Article;
use App\Events\ArticleViewedEvent;
use App\Http\Controllers\Helpers\AjustesDeClienteHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CatalogoPorListaHelper;
use App\Http\Controllers\Helpers\HomeHelper;
use App\Http\Controllers\Helpers\RecomendacionesHelper;
use App\Http\Controllers\Helpers\TagHelper;
use App\Http\Controllers\LastSearchController;
use App\PromocionVinoteca;
use App\Question;
use App\Tag;
use App\User;
use App\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ArticleController extends Controller {
    
    /**
     * La ficha de un articulo (o de una promocion de vinoteca) por slug.
     *
     * Catalogo por lista (mision catalogo-por-lista-tienda, 5/10/2026): un articulo que el
     * comprador no puede ver por su lista de precios responde IGUAL que un slug inexistente —
     * `{"article": null}` con 200, que es lo que esta ruta ya devolvia— y no con un 403 ni un 404
     * distinto: un codigo propio le confirmaria a quien prueba links que ese articulo existe.
     *
     * ⚠️ Esta ruta NO pasa por `checkOnline()`, y hoy un articulo offline o inactivo se puede abrir
     * por su slug. Eso es un hallazgo aparte (queda en el informe) y no se arregla acá: el scope
     * que se suma agrega SOLO la restriccion de la lista.
     */
    function show($slug, $commerce_id) {
    	$article = Article::where('slug', $slug)
                            ->where('user_id', $commerce_id)
                            ->visibleParaLaLista($commerce_id)
                            ->withAll()
    						->with(['questions' => function($query) {
                                $query->whereHas('answer')->with('answer');
                            }])
    						->first();

        if ($article) {
            $article = ArticleHelper::checkPriceTypes([$article])[0];
            return response()->json(['article' => $article], 200);
        } else {
            $promo = PromocionVinoteca::where('slug', $slug)
                            ->where('user_id', $commerce_id)
                            ->where('online', 1)
                            ->withAll()
                            ->first();
            /* La ficha de una promo muestra el precio con los ajustes del cliente del comprador,
               igual que la tarjeta de la home (mision descuentos-recargos-por-cliente). */
            if (!is_null($promo)) {
                AjustesDeClienteHelper::aplicar_a_precios_fijos([$promo], $commerce_id);
            }
        	return response()->json(['article' => $promo], 200);
        }

    }

    /**
     * Una seleccion de articulos por id ("A-B-C"), en el orden pedido. Un id que no existe deja un
     * `null` en su lugar, desde siempre.
     *
     * Catalogo por lista (mision catalogo-por-lista-tienda): un articulo que el comprador no puede
     * ver por su lista de precios queda IGUAL que uno inexistente, `null` en su posicion. La ruta no
     * trae `commerce_id`, asi que el comercio sale de cada articulo (`user_id`), que lo escribio el
     * ERP y no el navegador. Sin lista restringida `ids_no_visibles()` no hace ninguna query.
     */
    function seleccionEspecial($articles_id) {
        $articles = [];
        foreach (explode('-', $articles_id) as $article_id) {
            $article = Article::where('id', $article_id)
                                ->withAll()
                                ->first();

            if (!is_null($article) && count(CatalogoPorListaHelper::ids_no_visibles([$article->id], $article->user_id)) > 0) {
                $article = null;
            }

            $articles[] = $article;
        }
        return response()->json(['models' => $articles], 200);
    }

    /**
     * Cuantos similares por pagina, con 6 de default.
     *
     * 🔴 El default es 6 y NO se cambia: es lo que devolvia este endpoint desde siempre, y un
     * SPA viejo que no manda `per_page` tiene que seguir viendo exactamente lo mismo. El
     * parametro existe porque los "productos relacionados" de la ficha nueva dibujan hasta 3
     * filas de 3 y con 6 articulos nunca pasaban de 2 (mision tienda-ficha-estilo-ml).
     *
     * El techo de 24 no es decorativo: sin el, cualquiera puede pedir `per_page=100000` a un
     * endpoint publico y sin auth que hace `withAll()` — que trae imagenes, descuentos y
     * precios de cada articulo.
     */
    const SIMILARES_POR_PAGINA = 6;
    const SIMILARES_POR_PAGINA_MAX = 24;

    function similars($article_id) {
        $article = Article::find($article_id);
        if (!is_null($article->sub_category)) {
            $category_id = $article->sub_category->category_id;
            $articles = Article::where('id', '!=', $article_id)
                                ->whereHas('sub_category', function ($q) use ($category_id) {
                                    $q->where('category_id', $category_id);
                                })
                                ->withAll()
                                ->checkOnline()
                                ->checkStock()
                                ->paginate($this->similaresPorPagina());
            $articles = ArticleHelper::checkPriceTypes($articles);
            return response()->json(['models' => $articles], 200);
        }
        return response()->json(['models' => ['data' => []]], 200);
    }

    /**
     * Lee `per_page` del request y lo deja adentro de [1, SIMILARES_POR_PAGINA_MAX].
     *
     * Cualquier cosa que no sea un numero util —vacio, texto, 0, negativo— cae al default sin
     * hacer ruido: es un parametro opcional de un endpoint publico, no una entrada a validar
     * con un 422.
     *
     * @return int
     */
    private function similaresPorPagina() {
        $pedido = request()->per_page;
        if (!is_numeric($pedido)) {
            return self::SIMILARES_POR_PAGINA;
        }
        $pedido = (int) $pedido;
        if ($pedido < 1) {
            return self::SIMILARES_POR_PAGINA;
        }
        if ($pedido > self::SIMILARES_POR_PAGINA_MAX) {
            return self::SIMILARES_POR_PAGINA_MAX;
        }
        return $pedido;
    }

    /**
     * "Quienes vieron este producto tambien compraron" (mision tienda-ficha-estilo-ml).
     *
     * 🔴 `models` es un ARRAY PLANO, no un paginador: estas secciones son un carrusel de
     * hasta 12 articulos, sin scroll infinito. Es la diferencia con similars(), que si
     * pagina y por eso devuelve `['data' => []]` cuando no tiene nada.
     *
     * 🔴 Sin datos devuelve `{'models': []}` con 200, nunca un 404 ni un error: "este
     * producto todavia no tiene recomendaciones" es un resultado normal —y el mas frecuente
     * de todos— y no una falla. El SPA oculta la seccion cuando el array viene vacio.
     *
     * El `$commerce_id` de la ruta no es decorativo: scopea la consulta al comercio, que en
     * una base compartida es lo unico que evita mostrar lo que se vende en el negocio de al
     * lado. Ver el docblock de RecomendacionesHelper.
     *
     * @param mixed $article_id
     * @param mixed $commerce_id
     * @return \Illuminate\Http\JsonResponse
     */
    function tambienCompraronVistas($article_id, $commerce_id) {
        $articles = RecomendacionesHelper::vieronTambienCompraron($article_id, $commerce_id);
        return response()->json(['models' => $articles], 200);
    }

    /**
     * "Quienes compraron este producto tambien compraron" (mision tienda-ficha-estilo-ml).
     *
     * Mismo contrato que tambienCompraronVistas(): array plano en `models`, y `[]` con 200
     * cuando no hay nada. Ver el docblock de arriba.
     *
     * @param mixed $article_id
     * @param mixed $commerce_id
     * @return \Illuminate\Http\JsonResponse
     */
    function tambienCompraronCompras($article_id, $commerce_id) {
        $articles = RecomendacionesHelper::compraronTambienCompraron($article_id, $commerce_id);
        return response()->json(['models' => $articles], 200);
    }

    function setViewed($article_id) {
        $article = Article::find($article_id);
        // event(new ArticleViewedEvent($article, $this->buyerId()));
        return response(null, 200);
    }

    function questions($id) {
        $questions = Question::where('article_id', $id)
                            ->whereHas('answer')
                            ->with('answer')
                            ->get();
        return response()->json(['questions' => $questions], 200);
    }

    /**
     * Los favoritos del comprador logueado (la ruta va en `auth:buyer`).
     *
     * Catalogo por lista (mision catalogo-por-lista-tienda): un favorito que el comprador ya no
     * puede ver por su lista de precios no se lista. La ruta no trae `commerce_id`: el comercio es
     * el del comprador de la SESION (`buyers.user_id`), nunca uno de la URL (ver
     * `CatalogoPorListaHelper::comercio_del_comprador()`). Esta ruta no pasa por `checkOnline()` y no
     * se le suma: se agrega SOLO la restriccion de la lista.
     */
    function favorites() {
        $articles = Article::whereLikedBy($this->buyerId())
                            ->visibleParaLaLista(CatalogoPorListaHelper::comercio_del_comprador())
                            ->withAll()
                            ->with(['questions' => function($query) {
                                $query->whereHas('answer')->with('answer');
                            }])
                            ->paginate(6);
        // $articles = ArticleHelper::setFavorites($articles);
        $articles = ArticleHelper::checkPriceTypes($articles);
        return response()->json(['articles' => $articles], 200);
    }

    /**
     * Marca o desmarca un favorito del comprador logueado (la ruta va en `auth:buyer`).
     *
     * Catalogo por lista (mision catalogo-por-lista-tienda, revision independiente B1): devolvia
     * CUALQUIER articulo por id, asi que un mayorista restringido, enumerando ids, recibia nombre,
     * slug, precios, imagenes y preguntas de articulos que su lista no habilita —y podia marcarlos—.
     * Ahora, como `show()` y `favorites()`, un articulo que su lista no le deja ver responde IGUAL que
     * uno que no existe: `{"article": null}` con 200 y sin tocar nada. El comercio es el del comprador
     * de la SESION, no uno de la URL (ver `CatalogoPorListaHelper::comercio_del_comprador()`).
     *
     * ⚠️ Un id que no existe tambien responde `{"article": null}`: antes era un 500 sobre null.
     *
     * 🔴 HALLAZGO APARTE, y no se toca aca: hoy este metodo NO lo alcanza ningun request. La ruta
     * `GET /articles/favorite/{article_id}` esta registrada DESPUES de `GET /articles/{slug}/{commerce_id}`
     * (routes/api.php), y Laravel atiende por orden de registro: `/articles/favorite/12` lo contesta
     * `show('favorite', 12)` con `{"article": null}`, y el corazon de la ficha (NameHeart.vue) no marca
     * nada en el servidor. Mover la ruta arregla los favoritos y cambia el comportamiento de todas las
     * tiendas, asi que es decision de Lucas; el filtro de arriba ya esta puesto para el dia que se haga.
     */
    function favorite($id) {
        $article = Article::where('id', $id)
                            ->visibleParaLaLista(CatalogoPorListaHelper::comercio_del_comprador())
                            ->with('images')
                            ->with(['questions' => function($query) {
                                $query->whereHas('answer')->with('answer');
                            }])
                            ->first();

        if (is_null($article)) {
            return response()->json(['article' => null], 200);
        }

        $buyer_id = $this->buyerId();
        if (!$article->liked($buyer_id)) {
            $article->like($buyer_id);
            $article->is_favorite = true;
        } else {
            $article->unlike($buyer_id);
            $article->is_favorite = false;
        }
        // dd($article);
        return response()->json(['article' => $article], 200);
    }

    function names($commerce_id) {
        $commerce = User::find($commerce_id);
        $names = Article::where('user_id', $commerce_id)
                            ->checkOnline()
                            ->checkStock()
                            ->select('id', 'name', 'slug')
                            ->get();
        $tags = TagHelper::addTagsAndSetId($commerce_id);
        return response()->json(['articles_names' => $names, 'tags' => $tags], 200);
    }

    function search($query, $commerce_id, $save_last_search = true) {
        $query = str_replace('%20', ' ', $query);
        Log::info('Buscando '.$query);
        $articles = Article::where('user_id', $commerce_id);

        $keywords = explode(' ', $query);

        if (count($keywords) > 1) {

            foreach ($keywords as $keyword) {
                $query = 'name LIKE ?';
                $articles->whereRaw($query, ["%$keyword%"]);
            }
        } else {
            $articles->where(function($q) use ($query) {
                    $q->where('name', 'LIKE', "%$query%")
                        ->orWhere('bar_code', 'LIKE', "%$query%");
                });
        }

        $articles = $articles->checkOnline()
                            ->checkStock()
                            ->withAll()
                            ->paginate(12);


        // $articles = ArticleHelper::setFavorites($articles);
        $articles = ArticleHelper::checkPriceTypes($articles);
        if ($save_last_search) {
            $last_search = $this->saveLastSearch($query);
        } else {
            return $articles;            
        }
        return response()->json(['articles' => $articles, 'last_search' => $last_search], 200);
    }

    function saveLastSearch($query) {
        $last_search = new LastSearchController();
        return $last_search->store($query);
    }
}
