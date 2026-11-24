<?php

namespace App\Http\Controllers\Helpers;

class ColorDeMailHelper
{
    /** Color neutro que se usa cuando ningún candidato sirve (el mail representa al comercio, no a ComercioCity). */
    const NEUTRAL = '#111827';

    /** Contraste mínimo del botón contra la tarjeta blanca del mail (WCAG 1.4.11). */
    const MIN_CONTRAST = 3.0;

    /**
     * Color del botón del mail y de su texto.
     *
     * Regla: el fondo es el primer candidato (el color principal y después los de respaldo, en orden) que
     * es un hex válido y contrasta al menos 3:1 contra la tarjeta blanca del mail; si ninguno sirve, el
     * neutro. El texto es blanco o negro, el que más contraste da. Un color principal casi blanco ya no
     * deja un botón que parece desactivado.
     *
     * @param mixed $value Color principal configurado (cualquier valor; sólo vale un hex #RGB o #RRGGBB).
     * @param array $fallbacks Colores de respaldo en orden de preferencia (por ejemplo botón de carrito y secundario).
     * @return array ['background' => '#rrggbb', 'foreground' => '#ffffff'|'#000000']
     */
    public static function palette($value, array $fallbacks = [])
    {
        $bg = self::NEUTRAL;
        foreach (array_merge([$value], $fallbacks) as $candidate) {
            $hex = self::normalize($candidate);
            if ($hex !== null && self::contrast($hex, '#ffffff') >= self::MIN_CONTRAST) {
                $bg = $hex;
                break;
            }
        }
        $l = self::luminance($bg);
        return ['background' => $bg, 'foreground' => (1.05 / ($l + 0.05) >= 4.5 ? '#ffffff' : '#000000')];
    }

    /**
     * Normaliza un hex a #rrggbb en minúscula.
     *
     * @param mixed $value Valor crudo de configuración.
     * @return string|null null si no es un hex #RGB o #RRGGBB.
     */
    protected static function normalize($value)
    {
        if (is_string($value) && preg_match('/^#[0-9a-f]{3}$/iD', $value)) {
            $value = '#'.$value[1].$value[1].$value[2].$value[2].$value[3].$value[3];
        }
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/iD', $value) ? strtolower($value) : null;
    }

    /**
     * Relación de contraste WCAG entre dos colores #rrggbb.
     *
     * @param string $a Primer color.
     * @param string $b Segundo color.
     * @return float
     */
    protected static function contrast($a, $b)
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function luminance($hex)
    {
        $sum = 0;
        foreach ([0.2126, 0.7152, 0.0722] as $i => $weight) {
            $v = hexdec(substr($hex, 1 + $i * 2, 2)) / 255;
            $sum += $weight * ($v <= 0.04045 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4));
        }
        return $sum;
    }
}
