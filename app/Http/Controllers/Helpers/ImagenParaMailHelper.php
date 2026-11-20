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
            if (!self::publicUrl($origin)) return null;
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
        if (!is_string($source) || strlen($source) > 4096) return null;
        $url = self::publicUrl($source, $origin);
        if (!$url || !function_exists('imagecreatefromstring')) return null;
        $host = strtolower(parse_url($url, PHP_URL_HOST));
        if (!in_array($host, array_map('strtolower', $allowedHosts), true)) return null;
        try {
            $local = false;
            $bytes = $this->localBytes($url, $local);
            if ($local) return $this->fromBytes($bytes);
            if (!function_exists('curl_init')) return null;
            $ips = $this->addresses($host);
            if (!$ips) return null;
            foreach ($ips as $ip) if (!self::publicIp($ip)) return null;
            return $this->fromBytes($this->download($url, $host, $ips[0]));
        } catch (\Throwable $e) {
            return null; // No registrar URL, datos ni errores de transporte.
        }
    }

    /** Lee únicamente el namespace público configurado; no transforma fallos locales en HTTP. */
    protected function localBytes($url, &$local)
    {
        $disk = config('filesystems.disks.public');
        if (!is_array($disk)) return null;
        $base = $disk['url'] ?? null;
        if (!is_string($base) || strlen($base) > 4096 || !self::publicUrl($base)) return null;
        $b = parse_url($base);
        $u = parse_url($url);
        if (strtolower($b['scheme']) !== strtolower($u['scheme'])
            || strtolower($b['host']) !== strtolower($u['host'])) return null;
        $basePath = $b['path'] ?? '';
        $prefix = rtrim($basePath, '/');
        $path = $u['path'] ?? '';
        if (substr($path, 0, strlen($prefix) + 1) !== $prefix.'/') return null;
        $local = true;
        if (($disk['driver'] ?? null) !== 'local' || strpos($basePath, '//') !== false || isset($b['query']) || isset($b['fragment'])
            || isset($u['query']) || isset($u['fragment'])) return null;
        // La URL base tampoco puede esconder segmentos ambiguos.
        if ($prefix !== '' && $this->relativePath(substr($prefix, 1)) === null) return null;
        $relative = $this->relativePath(substr($path, strlen($prefix) + 1));
        $root = $disk['root'] ?? null;
        if ($relative === null || !is_string($root) || $root === '' || $root[0] !== '/'
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $root)) return null;
        $root = realpath($root);
        if (!$root || $root === DIRECTORY_SEPARATOR || !is_dir($root)) return null;
        $file = $this->inspectLocal($root, $relative);
        if ($file === null) return null;
        $before = @lstat($file);
        if (!$before || $before['size'] > self::MAX_BYTES) return null;
        $handle = @fopen($file, 'rb');
        if (!$handle) return null;
        try {
            $stat = fstat($handle);
            if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > self::MAX_BYTES
                || $stat['dev'] !== $before['dev'] || $stat['ino'] !== $before['ino']
                || $this->inspectLocal($root, $relative) !== $file) return null;
            $bytes = '';
            while (!feof($handle) && strlen($bytes) <= self::MAX_BYTES) {
                $chunk = fread($handle, min(8192, self::MAX_BYTES + 1 - strlen($bytes)));
                if ($chunk === false || ($chunk === '' && !feof($handle))) return null;
                $bytes .= $chunk;
            }
            $after = fstat($handle);
            clearstatcache(true, $file);
            $current = @lstat($file);
            if (strlen($bytes) > self::MAX_BYTES || !$after || !$current
                || $current['ino'] !== $stat['ino'] || $current['dev'] !== $stat['dev'] || $after['size'] !== $stat['size']
                || $after['mtime'] !== $stat['mtime'] || $this->inspectLocal($root, $relative) !== $file) return null;
            return $bytes;
        } finally { fclose($handle); }
    }

    protected function relativePath($raw)
    {
        if ($raw === '' || strlen($raw) > 4096 || preg_match('/%(?![0-9a-f]{2})|%2f|%5c/i', $raw)) return null;
        $decoded = rawurldecode($raw);
        if (preg_match('/[%\x00-\x1f\x7f\\\\:]/', $decoded)) return null;
        $parts = explode('/', $decoded);
        if (count($parts) > 64) return null;
        foreach ($parts as $part) if ($part === '' || $part === '.' || $part === '..') return null;
        return $decoded;
    }

    /** No sigue enlaces descendientes, ni siquiera si apuntan al interior del disco. */
    protected function inspectLocal($root, $relative)
    {
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= DIRECTORY_SEPARATOR.$part;
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (!$stat || ($stat['mode'] & 0170000) === 0120000) return null;
        }
        $real = realpath($path);
        return $real && substr($real, 0, strlen($root) + 1) === $root.DIRECTORY_SEPARATOR
            && is_file($real) && is_readable($real) ? $real : null;
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
        if (!is_string($bytes) || strlen($bytes) > self::MAX_BYTES || !function_exists('imagecreatefromstring')
            || !function_exists('getimagesizefromstring') || !function_exists('imagesavealpha')
            || !function_exists('imagepng') || !function_exists('imagedestroy')) return null;
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096
            || $info[0] * $info[1] > self::MAX_PIXELS) return null;
        if (!function_exists(['image/png'=>'imagecreatefrompng', 'image/jpeg'=>'imagecreatefromjpeg',
            'image/webp'=>'imagecreatefromwebp'][$info['mime']])) return null;
        $image = @imagecreatefromstring($bytes);
        if (!$image) return null;
        try {
            imagesavealpha($image, true);
            ob_start();
            try { $ok = imagepng($image); $png = ob_get_contents(); } finally { ob_end_clean(); }
            return $ok && is_string($png) && $png !== '' && strlen($png) <= self::MAX_BYTES
                ? ['bytes' => $png, 'mime' => 'image/png', 'name' => 'logo.png'] : null;
        } finally {
            imagedestroy($image);
        }
    }
}
