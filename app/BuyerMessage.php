<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BuyerMessage extends Model
{
    protected $guarded = [];

    function buyer_message_default_responses() {
        return $this->hasMany(BuyerMessageDefaultResponse::class);
    }
}
