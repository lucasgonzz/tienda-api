<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromocionVinoteca extends Model
{
    use SoftDeletes;
    use \App\Models\Concerns\TieneMedidasDeEnvio;

    protected $hidden = ['medidas_envio'];
    
    protected $guarded = [];

    public function getStockAttribute($stock)
    {
        return \App\Http\Controllers\Helpers\ReservaStockWebHelper::stock_promocion($this->id, $this->user_id, $stock);
    }

    function scopeWithAll($q) {
        $q->with('images');
    }

    function images() {
        return $this->morphMany('App\Image', 'imageable');
    }
}
