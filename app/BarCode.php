<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BarCode extends Model
{
   protected $fillable = ['name', 'amount', 'user_id'];

    public function article() {
        return $this->belongsTo('App\Article');
    }
}
