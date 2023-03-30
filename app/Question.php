<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    
	protected $guarded = [];

    public function article() {
    	return $this->belongsTo('App\Article');
    }

    public function buyer() {
    	return $this->belongsTo('App\Buyer');
    }

    public function answer() {
    	return $this->hasOne('App\Answer');
    }
}
