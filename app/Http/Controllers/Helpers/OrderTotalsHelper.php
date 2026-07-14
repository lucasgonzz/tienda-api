<?php

namespace App\Http\Controllers\Helpers;

use App\Cupon;

/**
 * Calcula el desglose de totales de un pedido, replicando exactamente la cadena que ve el comprador
 * en el checkout de tienda-spa (@src/mixins/cart.js):
 *
 *   subtotal -> ± medio de pago -> - cupon -> + envio -> TOTAL
 *
 * IMPORTANTE: no se usa la columna orders.total, que guarda SOLO el subtotal de articulos (viene
 * copiada de carts.total) y no el total final. Usarla mostraria un numero equivocado en todo pedido
 * con envio, cupon o recargo por medio de pago.
 */
class OrderTotalsHelper
{
    /**
     * Precio unitario de una linea del pedido, contemplando articulos cotizados en dolares
     * (pivot.with_dolar guarda la cotizacion usada al momento de la compra).
     *
     * @param object $pivot
     * @return float
     */
    static function unitPrice($pivot) {
        $price = (float) $pivot->price;

        if (isset($pivot->with_dolar) && !is_null($pivot->with_dolar) && (float) $pivot->with_dolar > 0) {
            $price = $price * (float) $pivot->with_dolar;
        }

        return $price;
    }

    /**
     * Lineas del pedido, listas para renderizar en el mail (articulos + promociones de vinoteca).
     * Cada linea: name, variant, notes, amount, unit_price, line_total.
     *
     * @param \App\Order $order
     * @return array
     */
    static function lines($order) {
        $lines = [];

        if (!is_null($order->articles)) {
            foreach ($order->articles as $article) {
                $unit_price = Self::unitPrice($article->pivot);

                $lines[] = [
                    'name'       => $article->name,
                    'code'       => isset($article->code) ? $article->code : null,
                    'variant'    => Self::variantDescription($article),
                    'notes'      => isset($article->pivot->notes) ? $article->pivot->notes : null,
                    'amount'     => (int) $article->pivot->amount,
                    'unit_price' => $unit_price,
                    'line_total' => $unit_price * (float) $article->pivot->amount,
                ];
            }
        }

        // Las promociones de vinoteca son items pagos del pedido igual que los articulos, asi que
        // entran en el listado y en el subtotal.
        if (!is_null($order->promociones_vinoteca)) {
            foreach ($order->promociones_vinoteca as $promo) {
                $unit_price = Self::unitPrice($promo->pivot);

                $lines[] = [
                    'name'       => $promo->name,
                    'code'       => null,
                    'variant'    => null,
                    'notes'      => isset($promo->pivot->notes) ? $promo->pivot->notes : null,
                    'amount'     => (int) $promo->pivot->amount,
                    'unit_price' => $unit_price,
                    'line_total' => $unit_price * (float) $promo->pivot->amount,
                ];
            }
        }

        return $lines;
    }

    /**
     * Descripcion de la variante elegida, si la linea tiene una.
     *
     * @param \App\Article $article
     * @return string|null
     */
    static function variantDescription($article) {
        if (!isset($article->pivot->variant_id) || is_null($article->pivot->variant_id)) {
            return null;
        }

        if (!isset($article->article_variants) || is_null($article->article_variants)) {
            return null;
        }

        foreach ($article->article_variants as $variant) {
            if ($variant->id == $article->pivot->variant_id) {
                $values = [];

                if (isset($variant->article_property_values) && !is_null($variant->article_property_values)) {
                    foreach ($variant->article_property_values as $value) {
                        $values[] = $value->value;
                    }
                }

                if (count($values) > 0) {
                    return implode(' / ', $values);
                }
            }
        }

        return null;
    }

    /**
     * Desglose completo de totales. Todas las claves vienen siempre (las que no aplican, en null),
     * asi el blade solo pregunta por is_null y no calcula nada.
     *
     * @param \App\Order $order
     * @return array
     */
    static function breakdown($order) {
        $lines = Self::lines($order);

        $subtotal = 0;
        foreach ($lines as $line) {
            $subtotal += $line['line_total'];
        }

        $total = $subtotal;

        // 1. Medio de pago: descuento O recargo, nunca los dos (asi lo resuelve tienda-spa).
        $payment_method_label = null;
        $payment_method_amount = null;

        if (!is_null($order->payment_method_discount) && (float) $order->payment_method_discount > 0) {
            $payment_method_amount = -1 * ($subtotal * (float) $order->payment_method_discount / 100);
            $payment_method_label = 'Descuento por medio de pago ('.Self::formatPercentage($order->payment_method_discount).'%)';
            $total = $total + $payment_method_amount;
        } elseif (!is_null($order->payment_method_surchage) && (float) $order->payment_method_surchage > 0) {
            $payment_method_amount = $subtotal * (float) $order->payment_method_surchage / 100;
            $payment_method_label = 'Recargo por medio de pago ('.Self::formatPercentage($order->payment_method_surchage).'%)';
            $total = $total + $payment_method_amount;
        }

        // 2. Cupon: monto fijo o porcentaje sobre el total ya ajustado por medio de pago.
        $cupon_label = null;
        $cupon_amount = null;
        $cupon = Self::resolveCupon($order);

        if (!is_null($cupon)) {
            if (!is_null($cupon->amount) && (float) $cupon->amount > 0) {
                $cupon_amount = -1 * (float) $cupon->amount;
                $cupon_label = 'Cupon '.$cupon->code;
            } elseif (!is_null($cupon->percentage) && (float) $cupon->percentage > 0) {
                $cupon_amount = -1 * ($total * (float) $cupon->percentage / 100);
                $cupon_label = 'Cupon '.$cupon->code.' ('.Self::formatPercentage($cupon->percentage).'%)';
            }

            if (!is_null($cupon_amount)) {
                $total = $total + $cupon_amount;
            }
        }

        // 3. Envio.
        $delivery_label = null;
        $delivery_amount = null;

        if (!is_null($order->delivery_zone)) {
            $delivery_amount = (float) $order->delivery_zone->price;
            $delivery_label = 'Envio a '.$order->delivery_zone->name;
            $total = $total + $delivery_amount;
        }

        return [
            'lines'                 => $lines,
            'subtotal'              => $subtotal,
            'payment_method_label'  => $payment_method_label,
            'payment_method_amount' => $payment_method_amount,
            'cupon_label'           => $cupon_label,
            'cupon_amount'          => $cupon_amount,
            'delivery_label'        => $delivery_label,
            'delivery_amount'       => $delivery_amount,
            'total'                 => $total,
        ];
    }

    /**
     * El cupon del pedido puede venir por la columna cupon_id o por la relacion muchos-a-muchos
     * cupons (las dos existen en el esquema). Se prioriza cupon_id, que es lo que copia el carrito.
     *
     * @param \App\Order $order
     * @return \App\Cupon|null
     */
    static function resolveCupon($order) {
        if (!is_null($order->cupon_id)) {
            return Cupon::find($order->cupon_id);
        }

        if (!is_null($order->cupons) && count($order->cupons) > 0) {
            return $order->cupons[0];
        }

        return null;
    }

    /**
     * Formatea un importe en pesos para mostrar en el mail: $ 12.345,67
     *
     * @param float $amount
     * @return string
     */
    static function money($amount) {
        return '$ '.number_format((float) $amount, 2, ',', '.');
    }

    /**
     * Porcentaje sin decimales inutiles: 10.00 -> 10, 10.50 -> 10,5
     *
     * @param float $percentage
     * @return string
     */
    static function formatPercentage($percentage) {
        $value = (float) $percentage;

        if ($value == (int) $value) {
            return (string) (int) $value;
        }

        return str_replace('.', ',', (string) round($value, 2));
    }
}
