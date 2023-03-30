<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    public $guarded = [];

    function imageable() {
        return $this->morphTo();
    }
}
