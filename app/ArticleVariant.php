<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ArticleVariant extends Model
{
    public function getStockAttribute($stock)
    {
        return \App\Http\Controllers\Helpers\ReservaStockWebHelper::stock_articulo($this->id, null, $stock, true, $this->article_id);
    }

    function article_property_values() {
        return $this->belongsToMany(ArticlePropertyValue::class);
    }
}
