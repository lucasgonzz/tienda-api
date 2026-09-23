<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Recargo de VENTA del ERP (`surchages`, con la ortografia de la tabla original).
 *
 * Mismo criterio que `App\Discount`: la tabla es de `empresa-api`, la tienda solo la lee para los
 * recargos vinculados a un cliente (`client_surchage`) via `AjustesDeClienteHelper`.
 */
class Surchage extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function scopeWithAll($query) {}
}
