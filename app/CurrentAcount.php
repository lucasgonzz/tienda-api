<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CurrentAcount extends Model
{
    protected $guarded = [];

    function credit_account() {
        return $this->belongsTo(CreditAccount::class);
    }

    function sale() {
        return $this->belongsTo(Sale::class);
    }

    function current_acount_payment_methods() {
        return $this->belongsToMany(CurrentAcountPaymentMethod::class)
                    ->withPivot('amount');
    }
}
