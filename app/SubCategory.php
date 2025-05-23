<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubCategory extends Model
{

    use SoftDeletes;

    function articles() {
    	return $this->hasMany('App\Article');
    }

    function category_price_type_ranges() {
        return $this->hasMany(CategoryPriceTypeRange::class)->orderBy('min', 'asc');
    }
}
