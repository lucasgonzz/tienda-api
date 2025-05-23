<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ArticlePriceTypeGroup extends Model
{
    function articles() {
        return $this->belongsToMany(Article::class);
    }
}
