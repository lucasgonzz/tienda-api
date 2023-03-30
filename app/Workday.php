<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Workday extends Model
{
    protected $guarded = [];

    function schedules() {
        return $this->belongsToMany('App\Schedule');
    }
}
