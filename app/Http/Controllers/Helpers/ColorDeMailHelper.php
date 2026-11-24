<?php

namespace App\Http\Controllers\Helpers;

class ColorDeMailHelper
{
    /** Color neutro que se usa cuando ningún candidato sirve (el mail representa al comercio, no a ComercioCity). */
    const NEUTRAL = '#111827';

    /** Contraste mínimo de cualquier fondo de botón contra la tarjeta blanca: descarta los casi blancos. */
    const MIN_CONTRAST = 1.5;

    /** Contraste mínimo para los grises (croma bajo): descarta los grises claros, que parecen desactivados. */
    const MIN_CONTRAST_GRAY = 3.0;

    /** Croma (max-min)/255 por debajo del cual un color se considera gris. */
    const GRAY_CHROMA = 0.10;

    /**
     * Color del botón del mail y de su texto (decisión de Lucas 23/11/2026: sólo blancos y grises claros).
     *
     * El fondo es el primer candidato (color principal y después los de respaldo, en orden) que es un hex
     * válido, contrasta al menos 1,5:1 contra la tarjeta blanca y, si es gris, al menos 3:1. Un color con
     * tono (naranja, verde, celeste) se respeta aunque contraste poco. Si ninguno sirve, el neutro. El
     * texto es blanco o negro, el que más contraste da.
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
            if ($hex !== null && self::acceptable($hex)) {
                $bg = $hex;
                break;
            }
        }
        $l = self::luminance($bg);
        return ['background' => $bg, 'foreground' => (1.05 / ($l + 0.05) >= 4.5 ? '#ffffff' : '#000000')];
    }

    /**
     * Si un #rrggbb sirve de fondo de botón sobre la tarjeta blanca.
     *
     * @param string $hex Color normalizado.
     * @return bool
     */
    protected static function acceptable($hex)
    {
        $ratio = self::contrast($hex, '#ffffff');
        if ($ratio < self::MIN_CONTRAST) return false;
        $channels = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        $is_gray = (max($channels) - min($channels)) / 255 < self::GRAY_CHROMA;
        return !$is_gray || $ratio >= self::MIN_CONTRAST_GRAY;
    }

    /**
     * Normaliza un hex a #rrggbb en minúscula.
     *
     * @param mixed $value Valor crudo de configuración.
     * @return string|null null si no es un hex #RGB o #RRGGBB.
     */
    protected static function normalize($value)
    {
        if (is_string($value)) $value = trim($value);
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
