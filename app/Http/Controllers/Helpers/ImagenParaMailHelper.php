<?php

namespace App\Http\Controllers\Helpers;

/** Imagen CID acotada. Nunca descarga un host inferido del dato del comercio. */
class ImagenParaMailHelper
{
    const MAX_BYTES = 1048576;
    const MAX_PIXELS = 4000000;

    public static function publicUrl($value, $origin = null)
    {
        if (!is_string($value) || $value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) return null;
        if (substr($value, 0, 1) === '/' && substr($value, 0, 2) !== '//' && $origin) {
            $base = parse_url($origin);
            if (!isset($base['scheme'], $base['host'])) return null;
            $value = $base['scheme'].'://'.$base['host'].$value;
        }
        $p = parse_url($value);
        if (!$p || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), ['https', 'http'], true)
            || isset($p['user']) || isset($p['pass']) || isset($p['port'])
            || !filter_var($value, FILTER_VALIDATE_URL)) return null;
        $host = strtolower($p['host']);
        // Sólo nombres DNS completos o IP públicas: no formas alternativas de loopback.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!self::publicIp($host)) return null;
        } elseif (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)
            || preg_match('/\.(localhost|local|internal|invalid|test)$/D', $host)) return null;
        return $value;
    }

    protected static function publicIp($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        $number = ip2long($ip);
        foreach (['0.0.0.0/8', '100.64.0.0/10', '169.254.0.0/16', '192.0.0.0/24',
            '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24',
            '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $range) {
            [$network, $bits] = explode('/', $range);
            $mask = -1 << (32 - (int) $bits);
            if (($number & $mask) === (ip2long($network) & $mask)) return false;
        }
        return true;
    }

    protected function addresses($host)
    {
        // IPs públicas explícitas: evita DNS sin timeout y rebinding. Sin mapa, texto.
        $map = (array) config('mail.image_public_addresses', []);
        return (array) ($map[$host] ?? []);
    }

    public function prepare($source, $origin = null, array $allowedHosts = [])
    {
        $url = self::publicUrl($source, $origin);
        if (!$url || !function_exists('curl_init') || !function_exists('imagecreatefromstring')) return null;
        $host = strtolower(parse_url($url, PHP_URL_HOST));
        if (!in_array($host, array_map('strtolower', $allowedHosts), true)) return null;
        try {
            $ips = $this->addresses($host);
            if (!$ips) return null;
            foreach ($ips as $ip) if (!self::publicIp($ip)) return null;
            return $this->fromBytes($this->download($url, $host, $ips[0]));
        } catch (\Throwable $e) {
            return null; // No registrar URL, datos ni errores de transporte.
        }
    }

    protected function download($url, $host, $ip)
    {
        $bytes = '';
        if (app()->environment('testing')) return ''; // En tests sólo downloader fake explícito.
        $ch = curl_init($url);
        $port = parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80;
        curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT_MS => 1000, CURLOPT_TIMEOUT_MS => 3000,
            CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host.':'.$port.':'.$ip], CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$bytes) {
                if (strlen($bytes) + strlen($chunk) > self::MAX_BYTES) return 0;
                $bytes .= $chunk;
                return strlen($chunk);
            }]);
        try {
            $ok = curl_exec($ch);
            return $ok && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 ? $bytes : '';
        } finally {
            curl_close($ch);
        }
    }

    /** MIME real y recodificación PNG: alpha conservado, nada de SVG/HTML ni extensión inferida. */
    public function fromBytes($bytes)
    {
        if (!is_string($bytes) || strlen($bytes) > self::MAX_BYTES || !function_exists('imagecreatefromstring')) return null;
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096
            || $info[0] * $info[1] > self::MAX_PIXELS) return null;
        $image = @imagecreatefromstring($bytes);
        if (!$image) return null;
        try {
            imagesavealpha($image, true);
            ob_start();
            try { $ok = imagepng($image); $png = ob_get_contents(); } finally { ob_end_clean(); }
            return $ok && strlen($png) <= self::MAX_BYTES
                ? ['bytes' => $png, 'mime' => 'image/png', 'name' => 'logo.png'] : null;
        } finally {
            imagedestroy($image);
        }
    }
}
