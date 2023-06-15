<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class OnlineConfiguration extends Model
{
    function online_price_type() {
        return $this->belongsTo(OnlinePriceType::class);
    }
}
