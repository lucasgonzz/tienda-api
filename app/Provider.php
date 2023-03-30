<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    
    protected $fillable = [
    	'user_id',
    	'name',
    ];
}
