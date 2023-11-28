<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class PriceType extends Model
{
    function sub_categories() {
        return $this->belongsToMany(SubCategory::class)->withPivot('percentage');
    }
}
