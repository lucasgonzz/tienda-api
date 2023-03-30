<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Recommendation extends Model
{
    protected $fillable = [
    	'name', 'address', 'commerce_id', 'admin_id'
    ];

    function commerce() {
    	return $this->belongsTo('App\User', 'commerce_id');
    }
}
