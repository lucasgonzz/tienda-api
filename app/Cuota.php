<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Las cuotas que el comerciante carga en el ABM «Cuotas» del ERP (tabla `cuotas`, mision
 * `tienda-cuotas-mercado-pago`, 15/10/2026).
 *
 * 🔴 La tienda solo LEE esta tabla (la gobierna el ERP; la base es compartida). Lo que este modelo
 * bloquea, exactamente:
 *  - `save()` y `delete()` del modelo tiran `LogicException` (con eso quedan fuera `create()`,
 *    `forceCreate()`, `update()` y `delete()` sobre una instancia);
 *  - la asignacion masiva esta cerrada (`$guarded = ['*']`).
 * Lo que NO bloquea: las escrituras que no pasan por una instancia del modelo
 * (`Cuota::query()->update()/delete()/insert()`, `DB::table('cuotas')`). El resguardo contra esas es
 * convencion de esta mision, no codigo: nada de la tienda escribe en `cuotas`.
 *
 * Columnas que importan:
 *  - `cantidad_cuotas`: 3, 6, 12...
 *  - `recargo` y `descuento`: porcentajes, ambos NULL-ables. El neto de una fila es
 *    `(recargo ?? 0) - (descuento ?? 0)` y lo resuelve `CuotasMercadoPagoHelper`, que es la unica
 *    fuente del calculo.
 *  - `payment_method_id`: el metodo de pago de CUENTA CORRIENTE (`current_acount_payment_methods`)
 *    al que aplica la fila; NULL = regla generica, que la tienda no ofrece.
 *  - `user_id`: el comercio.
 */
class Cuota extends Model
{
    protected $table = 'cuotas';

    /** Nada se asigna en masa: el modelo es de lectura. */
    protected $guarded = ['*'];

    /**
     * @param array $options
     * @return bool
     *
     * @throws \LogicException Siempre: la tienda no escribe en `cuotas`.
     */
    public function save(array $options = [])
    {
        throw new LogicException('Cuota es de solo lectura en la tienda: las cuotas se cargan desde el ERP.');
    }

    /**
     * @return bool|null
     *
     * @throws \LogicException Siempre: la tienda no escribe en `cuotas`.
     */
    public function delete()
    {
        throw new LogicException('Cuota es de solo lectura en la tienda: las cuotas se cargan desde el ERP.');
    }
}
