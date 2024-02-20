<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    function article() {
        return $this->belongsTo(Article::class);
    }
}
