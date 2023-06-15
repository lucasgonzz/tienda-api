<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ArticleProperty extends Model
{

    function article_property_type() {
        return $this->belongsTo(ArticlePropertyType::class);
    }

    function article_property_values() {
        return $this->belongsToMany(ArticlePropertyValue::class);
    }
}
