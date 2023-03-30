<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes;
    
	public $timestamps = false;

	protected $fillable = ['name', 'user_id'];	

    function icon() {
        return $this->belongsTo('App\Icon');
    }

    function views() {
        return $this->morphMany('App\View', 'viewable');
    }
    
    public function articles() {
        return $this->hasMany('App\Article');
    }

    function sub_categories() {
    	return $this->hasMany('App\SubCategory');
    }
}
