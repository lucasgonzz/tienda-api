<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CategoryPriceTypeRange extends Model
{
    
    public function category() {
        return $this->belongsTo(Category::class);
    }

    public function sub_category() {
        return $this->belongsTo(SubCategory::class);
    }

    public function price_type() {
        return $this->belongsTo(PriceType::class);
    }
}
