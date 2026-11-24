<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/** Esquema gobernado por empresa-api (U9). Nunca contiene correos ni payloads. */
class OrderCommunicationDelivery extends Model
{
    protected $guarded = ['id'];
    protected $casts = [
        'attempt_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'accepted_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
