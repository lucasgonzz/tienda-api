<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ArticlePropertyValue extends Model
{
    function article_property_type() {
        return $this->belongsTo(ArticlePropertyType::class);
    }
}
