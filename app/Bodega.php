<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Bodega extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function articles() {
        return $this->hasMany(Article::class);
    }
}
