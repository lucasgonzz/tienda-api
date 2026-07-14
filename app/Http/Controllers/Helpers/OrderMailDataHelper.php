<?php

namespace App\Http\Controllers\Helpers;

use App\OnlineConfiguration;
use Carbon\Carbon;

/**
 * Arma el payload que consumen las dos plantillas de mail de pedido (prompt 386).
 *
 * Un solo lugar que resuelve logo, color de acento, datos del comercio, datos del comprador, forma de
 * entrega y desglose de totales, para que las vistas solo impriman.
 */
class OrderMailDataHelper
{
    /**
     * @param \App\Order $order Pedido ya cargado con withAll() + promociones_vinoteca.
     * @return array
     */
    static function build($order) {
        $user = $order->user;
        $configuration = OnlineConfiguration::where('user_id', $order->user_id)->first();

        return [
            'order'         => $order,
            'user'          => $user,
            'company_name'  => is_null($user) ? 'La tienda' : $user->company_name,
            'logo_url'      => Self::logoUrl($user, $configuration),
            'accent_color'  => Self::accentColor($configuration),
            'store_url'     => (!is_null($user) && !empty($user->online)) ? $user->online : null,
            'erp_orders_url'=> (!is_null($user) && !empty($user->default_version)) ? $user->default_version.'/online/pedidos' : null,
            'buyer_name'    => Self::buyerName($order),
            'buyer_email'   => (!is_null($order->buyer) && !empty($order->buyer->email)) ? $order->buyer->email : null,
            'buyer_phone'   => (!is_null($order->buyer) && !empty($order->buyer->phone)) ? $order->buyer->phone : null,
            'entrega'       => Self::entrega($order),
            'payment_method'=> is_null($order->payment_method) ? null : $order->payment_method->name,
            'fecha'         => $order->created_at->format('d/m/Y H:i'),
            'fecha_entrega' => Self::formatFechaEntrega($order->fecha_entrega),
            'totals'        => OrderTotalsHelper::breakdown($order),
        ];
    }

    /**
     * Logo a mostrar arriba del mail: primero el logo de la tienda online, si no el del sistema.
     *
     * @param \App\User|null $user
     * @param \App\OnlineConfiguration|null $configuration
     * @return string|null
     */
    static function logoUrl($user, $configuration) {
        if (!is_null($configuration) && !empty($configuration->logo_url)) {
            return $configuration->logo_url;
        }

        if (!is_null($user) && !empty($user->image_url)) {
            return $user->image_url;
        }

        return null;
    }

    /**
     * Color de acento: el color primario de la tienda del cliente. Si no esta seteado, un gris muy
     * oscuro neutro (nunca un color de ComercioCity: el mail representa al comercio, no a nosotros).
     *
     * @param \App\OnlineConfiguration|null $configuration
     * @return string
     */
    static function accentColor($configuration) {
        if (!is_null($configuration) && !empty($configuration->primary_color)) {
            return $configuration->primary_color;
        }

        return '#111827';
    }

    /**
     * @param \App\Order $order
     * @return string
     */
    static function buyerName($order) {
        if (is_null($order->buyer)) {
            return 'Cliente';
        }

        $name = trim($order->buyer->name.' '.(isset($order->buyer->surname) ? $order->buyer->surname : ''));

        return $name === '' ? 'Cliente' : $name;
    }

    /**
     * Forma de entrega en texto listo para imprimir.
     *
     * @param \App\Order $order
     * @return array ['tipo' => 'Envio a domicilio'|'Retiro por el local', 'detalle' => string|null]
     */
    static function entrega($order) {
        if ($order->deliver) {
            return [
                'tipo'    => 'Envio a domicilio',
                'detalle' => empty($order->address) ? null : $order->address,
            ];
        }

        return [
            'tipo'    => 'Retiro por el local',
            'detalle' => null,
        ];
    }

    /**
     * Formatea la fecha de entrega elegida por el comprador.
     *
     * IMPORTANTE (hallazgo al implementar el prompt 386): `orders.fecha_entrega` NO es un Carbon:
     * el modelo `Order` no la declara en $casts, y se guarda tal cual llega del checkout (un string
     * ISO tipo "2025-05-01", ver `DeliveryDayController::get_dias_habilitados` en tienda-api y su
     * copia directa en `CartController`/`OrderController`). Llamar `->format()` sobre ese valor
     * como si fuera un objeto Carbon rompe con un fatal error apenas el pedido tiene fecha de
     * entrega. Se parsea acá, de forma defensiva, en vez de asumir el tipo.
     *
     * @param string|null $fecha_entrega Valor crudo de la columna (string ISO 'Y-m-d' o null/vacio).
     * @return string|null Fecha formateada 'd/m/Y', o null si no hay fecha o no se pudo interpretar.
     */
    static function formatFechaEntrega($fecha_entrega) {
        if (empty($fecha_entrega)) {
            return null;
        }

        try {
            return Carbon::parse($fecha_entrega)->format('d/m/Y');
        } catch (\Exception $e) {
            // Formato inesperado: no rompemos el mail por esto, simplemente no mostramos la fecha.
            return null;
        }
    }
}
