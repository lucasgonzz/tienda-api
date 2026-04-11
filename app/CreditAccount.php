<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CreditAccount extends Model
{
    protected $guarded = [];

    function current_acounts() {
        return $this->hasMany(CurrentAcount::class);
    }

    function moneda() {
        return $this->belongsTo(Moneda::class);
    }
}
