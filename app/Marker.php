<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Marker extends Model
{
	public $timestamps = false;
    protected $fillable = [
        'user_id',
    	'article_id',
    	'marker_group_id',
    ];
    
    public function markerGroup() {
        return $this->belongsTo('App\MarkerGroup');
    }

    // Uno a uno
    public function article() {
    	return $this->belongsTo('App\Article');
    }
    
}
