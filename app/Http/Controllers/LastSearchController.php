<?php

namespace App\Http\Controllers;

use App\Http\Controllers\ArticleController;
use App\LastSearch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LastSearchController extends Controller
{
    /**
     * Ultimas busquedas del comprador autenticado.
     *
     * 🔴 El guard no es defensivo, tapa una fuga medida. `last_searches.buyer_id` es NULLABLE y
     * Eloquent convierte where('buyer_id', null) en whereNull('buyer_id'), asi que sin sesion
     * esta consulta devolvia las busquedas de TODOS los visitantes anonimos — y la tabla ni
     * siquiera tiene user_id, o sea que cruzaba comercios. Medido el 15/8/2026 con curl sin
     * sesion: 200 con las busquedas de otro.
     *
     * Mismo criterio que OrderController@index: se corta antes de armar la query.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    function index() {
        $buyer_id = $this->buyerId();

        if (is_null($buyer_id)) {
            return response()->json(['last_searchs' => []], 200);
        }

        $last_searchs = LastSearch::where('buyer_id', $buyer_id)
                                    ->orderBy('created_at', 'DESC')
                                    ->get();
        return response()->json(['last_searchs' => $last_searchs], 200);
    }

    function forSearchPage($commerce_id) {
        $buyer_id = $this->buyerId();
        $last_searchs = LastSearch::take(12)
                                    ->orderBy('created_at', 'DESC');
        if ($buyer_id) {
            $last_searchs = $last_searchs->where('buyer_id', '!=', $buyer_id)
                                        ->orWhereNull('buyer_id')
                                        ->get();
        } else {
            $last_searchs = $last_searchs->get();
        }
        $ct = new ArticleController();
        $articles = [];
        foreach ($last_searchs as $last_search) {
            $article = $ct->search($last_search->body, $commerce_id, false)[0];
            if ($article && !$this->isInLastSearchs($articles, $article)) {
                $articles[] = $article;
            } 
        }
        return response()->json(['articles' => $articles], 200);
    }

    function store($body) {
        // $this->checkLast10();
    	$this->deleteIfRepeated($body);
    	$last_search = LastSearch::create([
    		'body' 		=> strtolower($body),
    		'buyer_id'  => $this->isLogin() ? $this->buyerId() : null,
    	]);
        return $last_search;
    }

    function checkLast10() {
        if ($this->isLogin()) {
        	$last_searchs = LastSearch::where('buyer_id', $this->buyerId())
        								->get();
        	if (count($last_searchs) >= 10) {
        		for ($i=9; $i < count($last_searchs); $i++) { 
        			$last_searchs[$i]->delete();
        		}
        	}
        }
    }

    function isInLastSearchs($articles, $article) {
        $is_in_array = false;
        foreach ($articles as $art) {
            if ($art->id == $article->id) {
                $is_in_array = true;
            }
        }
        return $is_in_array;
    }

    function deleteIfRepeated($body) {
        if ($this->isLogin()) {
            $last_search = LastSearch::where('buyer_id', $this->buyerId())
                                        ->where('body', $body)
                                        ->first();
        } else {
            $last_search = LastSearch::whereNull('buyer_id')
                                        ->where('body', $body)
                                        ->first();
        }
        if ($last_search) {
            $last_search->delete();
        }
    }
}
