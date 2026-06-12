<?php

namespace App;

use App\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use HasFactory;
    use SoftDeletes;
    use \Conner\Likeable\Likeable;
    
    protected $guarded = [];

    /**
     * Eager load de relaciones habituales para listados y detalle de artículos en tienda.
     * Incluye price_types para que checkPriceTypes pueda leer pivot->final_price.
     */
    function scopeWithAll($query) {
        $query->with('discounts', 'images', 'descriptions', 'condition', 'sizes', 'colors', 'brand', 'iva', 'article_properties.article_property_values', 'article_properties.article_property_type', 'article_variants.article_property_values.article_property_type', 'bodega', 'cepa', 'price_types');
    }
    
    protected $casts = [
        'stock' => 'integer',
    ];

    
    function addresses() {
        return $this->belongsToMany(Address::class)->withPivot('amount');
    }
    
    public function getStockAttribute()
    {

        if (env('APP_URL') == 'https://api.golonorte.com.ar') {

            $address_id = 1;
            $article_address = $this->addresses()
                ->where('address_id', $address_id)
                ->first();

            if ($article_address) {
                return (float)$article_address->pivot->amount;
            }

        } 

        return $this->attributes['stock'];
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
        
        $show_without_stock = $commerce->online_configuration->show_articles_without_stock;
        $stock_null_equal_0 = $commerce->online_configuration->stock_null_equal_0;

        if (!$show_without_stock) {
            if ($stock_null_equal_0) {
                // Mostrar solo artículos con stock > 0
                $query->where('stock', '>', 0);
            } else {
                // Mostrar artículos con stock > 0 o stock null
                $query->where(function($sub_query) {
                    $sub_query->where('stock', '>', 0)
                              ->orWhereNull('stock');
                });
            }
        }
        return $query;
    }
    
    function bodega() {
        return $this->belongsTo(Bodega::class);
    }
    
    function cepa() {
        return $this->belongsTo(Cepa::class);
    }

    function price_types() {
        return $this->belongsToMany(PriceType::class)->withPivot('percentage', 'price', 'final_price');
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
