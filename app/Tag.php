<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class tag extends Model
{
    function articles() {
        return $this->belongsToMany('App\Article');
    }
}
