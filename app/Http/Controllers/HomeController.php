<?php

namespace App\Http\Controllers;

use App\Article;
use App\Category;
use App\Events\SubCategoryViewed;
use App\Http\Controllers\Helpers\ArticleHelper;
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
                            ->paginate(12);

        // $last_uploads = ArticleHelper::setFavorites($last_uploads);
        $last_uploads = ArticleHelper::checkPriceTypes($last_uploads);

        if ($request->get('page') == 1) {
            $featured = HomeHelper::getFeatured($request->commerce_id);
            $featured = ArticleHelper::checkPriceTypes($featured);
            return response()->json(['articles' => $last_uploads, 'featured' => $featured], 200);
        } 
        return response()->json(['articles' => $last_uploads], 200);
    }

    function articlesFromCategory($category_id, $sub_category_id) {
        $articles = Article::withAll()
                            ->checkOnline()
                            ->checkStock()
                            ->orderBy('created_at', 'DESC');
        if ($category_id != 0) {
            $articles = $articles->where('category_id', $category_id);
        } else {
            $articles = $articles->where('sub_category_id', $sub_category_id);
        }
        $articles = $articles->paginate(12);
        $articles = ArticleHelper::checkPriceTypes($articles);
        return response()->json(['articles' => $articles], 200);
    }

    function subCategories($category_id) {
        $sub_categories = SubCategory::where('category_id', $category_id)
                                    ->whereHas('articles')
                                    ->get();
        return response()->json(['sub_categories' => $sub_categories], 200);
    }

    function categories($commerce_id) {
        $categories = Category::where('user_id', $commerce_id)
                                ->where('name', '!=', 'La de siempre')
                                ->with(['sub_categories' => function($query) {
                                    $query->orderBy('name', 'ASC');
                                }])
                                ->orderBy('name', 'ASC')
                                ->get();
        // $categories = HomeHelper::addIndexCategory($categories, $commerce_id);
        // $categories = HomeHelper::removeCategoriesWithoutArticles($categories, $commerce_id);
        return response()->json(['categories' => $categories], 200);
    }

}
