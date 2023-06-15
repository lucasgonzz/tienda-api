<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ArticleVariant extends Model
{
    function article_property_values() {
        return $this->belongsToMany(ArticlePropertyValue::class);
    }
}
