<?php

namespace App\Http\Controllers;

use App\Article;
use App\Events\ArticleViewedEvent;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\HomeHelper;
use App\Http\Controllers\Helpers\TagHelper;
use App\Http\Controllers\LastSearchController;
use App\Question;
use App\Tag;
use App\User;
use App\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ArticleController extends Controller {
    
    function show($slug, $commerce_id) {
    	$article = Article::where('slug', $slug)
                            ->where('user_id', $commerce_id)
                            ->withAll()
    						->with(['questions' => function($query) {
                                $query->whereHas('answer')->with('answer');
                            }])
    						->first();
        // $article = ArticleHelper::setFavorites([$article])[0];
        $article = ArticleHelper::checkPriceTypes([$article])[0];
        // $article = ArticleHelper::setArticlesKey([$article])[0];
        // $article = ArticleHelper::checkVariantsStock([$article])[0];
        // event(new ArticleViewedEvent($article, $this->buyerId()));
    	return response()->json(['article' => $article], 200);
    }

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
                                ->paginate(6);
            $articles = ArticleHelper::checkPriceTypes($articles);
            return response()->json(['models' => $articles], 200);
        }
        return response()->json(['models' => ['data' => []]], 200);
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

    function favorites() {
        $articles = Article::whereLikedBy($this->buyerId())
                            ->withAll()
                            ->with(['questions' => function($query) {
                                $query->whereHas('answer')->with('answer');
                            }])
                            ->paginate(6);
        // $articles = ArticleHelper::setFavorites($articles);
        $articles = ArticleHelper::checkPriceTypes($articles);
        return response()->json(['articles' => $articles], 200);
    }

    function favorite($id) {
        $article = Article::where('id', $id)
                            ->with('images')
                            ->with(['questions' => function($query) {
                                $query->whereHas('answer')->with('answer');
                            }])
                            ->first();
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
        $articles = Article::where('user_id', $commerce_id)
                            ->where(function($q) use ($query, $commerce_id) {
                                $q->whereHas('tags', function($e) use ($query) {
                                    $e->where('name', 'LIKE', "%$query%");
                                })
                                ->orWhere('name', 'LIKE', "%$query%");
                            })
                            ->checkOnline()
                            ->checkStock()
                            ->withAll()
                            ->paginate(6);
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
