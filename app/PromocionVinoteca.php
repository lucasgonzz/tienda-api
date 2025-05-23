<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromocionVinoteca extends Model
{
    use SoftDeletes;
    
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('images');
    }

    function images() {
        return $this->morphMany('App\Image', 'imageable');
    }
}
