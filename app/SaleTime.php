<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SaleTime extends Model
{
    public $timestamps = false;
    
    protected $fillable = [
    	'user_id',
    	'name',
    	'from',
    	'to',
    ];
}
