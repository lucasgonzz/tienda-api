<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SpecialPrice extends Model
{
    protected $fillable = ['user_id', 'name'];

    public function articles() {
        return $this->belongsToMany('App\Article')->withPivot('price');
    }
}
