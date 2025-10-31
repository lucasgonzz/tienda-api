<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Cepa extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function articles() {
        return $this->hasMany(Article::class);
    }
}
