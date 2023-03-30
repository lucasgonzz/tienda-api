<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $guarded = [];

    function article() {
        return $this->belongsTo('App\Article');
    }
}
