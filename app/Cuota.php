<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Las cuotas que el comerciante carga en el ABM «Cuotas» del ERP (tabla `cuotas`, mision
 * `tienda-cuotas-mercado-pago`, 15/10/2026).
 *
 * 🔴 SOLO LECTURA. La tabla la gobierna `empresa-api` (la base es compartida): la tienda lee las
 * cuotas que el comerciante ofrece para su metodo vinculado y nunca escribe una. Por eso el modelo
 * no acepta asignacion masiva y `save()`/`delete()` cortan con una excepcion, en vez de dejar que
 * un descuido de una mision futura le cambie el recargo a un comercio desde la tienda.
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
