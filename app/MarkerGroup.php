<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class MarkerGroup extends Model
{
    
	public $timestamps = false;
    protected $fillable = [
        'user_id',
    	'name',
    ];

    public function markers() {
        return $this->hasMany('App\Marker');
    }

}
