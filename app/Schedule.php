<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    function workdays() {
        return $this->belongsToMany('App\Workday');
    }
}
