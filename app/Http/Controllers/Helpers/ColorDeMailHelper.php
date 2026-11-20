<?php

namespace App\Http\Controllers\Helpers;

class ColorDeMailHelper
{
    public static function palette($value)
    {
        if (is_string($value) && preg_match('/^#[0-9a-f]{3}$/iD', $value)) {
            $value = '#'.$value[1].$value[1].$value[2].$value[2].$value[3].$value[3];
        }
        $bg = is_string($value) && preg_match('/^#[0-9a-f]{6}$/iD', $value) ? strtolower($value) : '#111827';
        $l = self::luminance($bg);
        return ['background' => $bg, 'foreground' => (1.05 / ($l + 0.05) >= 4.5 ? '#ffffff' : '#000000')];
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
