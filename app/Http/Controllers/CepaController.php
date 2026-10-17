<?php

namespace App\Http\Controllers;

use App\Cepa;
use App\Http\Controllers\Helpers\CatalogoPorListaHelper;
use Illuminate\Http\Request;

class CepaController extends Controller
{
    /**
     * Las cepas del comercio con cuantos articulos tiene cada una PARA MOSTRAR.
     *
     * El numero es el del listado (mision contadores-bodegas-cepas-marcas-subcategorias, 17/10/2026):
     * cuenta lo que pasa `checkOnline()` + `checkStock()`, igual que `articlesFromCategory()`. Las que
     * quedan en 0 no se devuelven (decision de Lucas, 17/10/2026).
     */
    function index($commerce_id) {
        CatalogoPorListaHelper::fijar_el_comercio($commerce_id);

        $models = Cepa::where('user_id', $commerce_id)
                        ->orderBy('name', 'ASC')
                        ->withCount(['articles' => function ($query) {
                            $query->checkOnline()->checkStock();
                        }])
                        ->get()
                        ->filter(function ($model) {
                            return $model->articles_count > 0;
                        })
                        ->values();

        return response()->json(['models' => $models], 200);
    }
}
