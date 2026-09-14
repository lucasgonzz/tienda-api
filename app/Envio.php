<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Envío real generado en un proveedor de logística (hoy Zipnova) a partir de un pedido de la
 * tienda (misión zipnova-envios, 14/9/2026). Tabla `envios`.
 *
 * 🔴 ESTE REPO SOLO LEE ESTA TABLA. El envío lo genera, sincroniza y cancela `empresa-api`
 * (`ZipnovaEnvioService`) cuando el comercio confirma el pedido; la tienda solo lo muestra en
 * "Mis pedidos" (estado, correo, link de seguimiento). Espejo de solo lectura de
 * `empresa-api/app/Models/Envio.php`: misma tabla, mismas constantes, sin ningún `save()`.
 *
 * Es distinto de `orders.envio_opcion` a propósito: la opción es lo que el comprador ELIGIÓ al
 * comprar (correo, servicio, precio); el envío es lo que el comercio GENERÓ después, con id en
 * Zipnova, número de guía y un estado que va cambiando hasta "entregado". Un pedido puede tener
 * la opción y ningún envío todavía, y un envío cancelado puede reemplazarse por otro del mismo
 * pedido: por eso `Order::envio()` toma el más nuevo.
 *
 * ⚠️ La tabla la crea una migración de `empresa-api` (`2026_09_14_100300_create_envios_table`) y
 * la tienda se despliega por cliente, independiente del ERP: hay bases sin esta tabla. Todo
 * acceso pasa antes por `ZipnovaEsquemaHelper::tabla_envios()`; el modelo solo describe la forma.
 */
class Envio extends Model
{
    /** Proveedor con el que se generó el envío. Hoy el único. */
    const PROVEEDOR_ZIPNOVA = 'zipnova';

    /**
     * Estado propio de ComercioCity: el envío nunca llegó a existir en Zipnova (el `POST
     * /shipments` falló en el ERP). No es un estado de Zipnova.
     */
    const STATUS_ERROR = 'error';

    /**
     * Estados de Zipnova desde los que el envío ya no se mueve. Misma lista que en `empresa-api`.
     *
     * @var array<int, string>
     */
    const ESTADOS_FINALES = [
        'cancelled',
        'expired',
        'reshipped',
        'delivered',
        'delivered_with_damage',
        'lost_in_carrier',
        'generated_return',
        'returned_to_seller',
        'lost',
    ];

    protected $table = 'envios';

    protected $guarded = [];

    /**
     * Lo que NO viaja al comprador. "Mis pedidos" necesita el estado, el correo, el seguimiento y
     * la fecha estimada; todo lo demás es del comercio o de Zipnova:
     *   - `respuesta`: el payload completo, con el depósito de origen (dirección, teléfono,
     *     documento) y datos internos de la cuenta;
     *   - `account_id`, `external_id`, `delivery_id`: identificadores de la cuenta de Zipnova
     *     y del remito, que solo sirven para operar desde el ERP;
     *   - `bultos`: lo que se declaró a Zipnova (peso y medidas), que es del comercio;
     *   - `error_message`: el detalle técnico de un envío que no se pudo crear, que se le muestra
     *     al comercio en el modal del pedido y no al comprador.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'respuesta',
        'account_id',
        'external_id',
        'delivery_id',
        'bultos',
        'error_message',
    ];

    /**
     * Los tres json y las dos fechas, igual que en el ERP.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'destino'               => 'array',
        'bultos'                => 'array',
        'respuesta'             => 'array',
        'estimated_delivery'    => 'datetime',
        'ultima_sincronizacion' => 'datetime',
    ];

    /**
     * Scope estándar del proyecto. El envío se carga desde el pedido (`Order::envio()`), acá no
     * hace falta nada.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }

    /**
     * Pedido de la tienda del que salió el envío.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * True si Zipnova ya cerró el envío (entregado, cancelado, perdido, devuelto...).
     *
     * @return bool
     */
    public function esta_cerrado(): bool
    {
        return in_array((string) $this->status, self::ESTADOS_FINALES, true);
    }
}
