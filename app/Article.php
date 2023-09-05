<?php

namespace App;

use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use SoftDeletes;
    use \Conner\Likeable\Likeable;
    
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('discounts', 'images', 'descriptions', 'condition', 'sizes', 'colors', 'brand', 'iva', 'article_properties.article_property_values', 'article_properties.article_property_type', 'article_variants.article_property_values.article_property_type');
    }

    function scopeCheckOnline($query) {
        $commerce = User::find(request()->commerce_id);
        $query->where('status', 'active')
                ->where('online', 1);
        if (!$commerce->online_configuration->show_articles_without_images) {
            $query = $query->whereHas('images');
        }
        $query = $query->with(['questions' => function($sub_query) {
                            $sub_query->whereHas('answer')
                                    ->with('answer');
                        }]);
    }

    /**
        * @param Eloquent Builder $query
        * 
        * @param Searchable Columns
    */
    function scopeCheckStock($query) {
        $commerce = User::find(request()->commerce_id);
        if (!$commerce->online_configuration->show_articles_without_stock) {
            $query->where(function($sub_query) use ($commerce) {
            $sub_query->where('stock', '>', 0);
                if ($commerce->online_configuration->stock_null_equal_0) {
                    $sub_query->orWhereNotNull('stock');
                    Log::info('chequeando que el stock no sea null');
                } else {
                    $sub_query->orWhereNull('stock');
                    Log::info('chequeando que el stock sea null');
                }
            });
        }
        return $query;
    }

    function article_properties() {
        return $this->hasMany(ArticleProperty::class);
    }

    function article_variants() {
        return $this->hasMany(ArticleVariant::class);
    }

    function views() {
        return $this->morphMany('App\View', 'viewable');
    }

    function discounts() {
        return $this->hasMany('App\ArticleDiscount');
    }

    function descriptions() {
        return $this->hasMany('App\Description');
    }

    function brand() {
        return $this->belongsTo('App\Brand');
    }

    function sizes() {
        return $this->belongsToMany('App\Size');
    }

    function tags() {
        return $this->belongsToMany('App\Tag');
    }

    function colors() {
        return $this->belongsToMany('App\Color');
    }

    function condition() {
        return $this->belongsTo('App\Condition');
    }

    public function user() {
        return $this->belongsTo('App\User');
    }

    public function category() {
        return $this->belongsTo('App\Category');
    }

    public function marker() {
        return $this->hasOne('App\Marker');
    }

    function images() {
        return $this->morphMany('App\Image', 'imageable');
    }

    public function sub_user() {
        return $this->belongsTo('App\User', 'sub_user_id');
    }

    public function sub_category() {
        return $this->belongsTo('App\SubCategory');
    }

    public function sales() {
        return $this->belongsToMany('App\Sale')->latest();
    }

    public function specialPrices() {
        return $this->belongsToMany('App\SpecialPrice')->withPivot('price');
    }
    
    public function providers(){
        return $this->belongsToMany('App\Provider')
                                                    ->withPivot('amount', 'cost', 'price')
                                                    ->withTimestamps()
                                                    ->orderBy('id', 'DESC');
    }

    public function questions() {
        return $this->hasMany('App\Question');
    }

    function iva() {
        return $this->belongsTo('App\Iva');
    }
}
