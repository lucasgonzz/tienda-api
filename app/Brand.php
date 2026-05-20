<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    /**
     * Artículos asociados a la marca (mismo comercio vía user_id del artículo).
     */
    public function articles()
    {
        return $this->hasMany(Article::class);
    }
}
