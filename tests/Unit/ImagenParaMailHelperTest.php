<?php

namespace Tests\Unit;

use App\Http\Controllers\Helpers\ImagenParaMailHelper as Imagen;
use App\Http\Controllers\Helpers\ColorDeMailHelper as Color;
use Tests\TestCase;

class ImagenParaMailHelperTest extends TestCase
{
    const JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2NjIpLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6KKKAP/9k=';
    const WEBP = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoCAAIAAUAmJaQAA3AA/v02aAA=';
    // Fixtures mínimas embebidas, no archivos ni red.
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function test_png_alpha_se_conserva_y_corruptos_svg_y_limites_se_rechazan()
    {
        $h = new Imagen;
        $valid = $h->fromBytes(base64_decode(self::PNG));
        $this->assertSame('image/png', $valid['mime']);
        $this->assertSame('logo.png', $valid['name']);
        $this->assertSame('image/png', getimagesizefromstring($valid['bytes'])['mime']);
        $this->assertNull($h->fromBytes('<svg xmlns="http://www.w3.org/2000/svg"/>'));
        $this->assertNull($h->fromBytes('corrupto.jpg'));
        $this->assertNull($h->fromBytes(str_repeat('x', Imagen::MAX_BYTES + 1)));
        // Imagen de un píxel completamente transparente, independiente de fixture opaca.
        $im = imagecreatetruecolor(1, 1);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagesetpixel($im, 0, 0, imagecolorallocatealpha($im, 10, 30, 50, 127));
        ob_start(); imagepng($im); $bytes = ob_get_clean(); imagedestroy($im);
        $decoded = imagecreatefromstring($h->fromBytes($bytes)['bytes']);
        $this->assertSame(127, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha']);
        imagedestroy($decoded);
        // Cabecera PNG con dimensiones enormes, descartada antes de decodificar.
        $huge = substr_replace(base64_decode(self::PNG), pack('NN', 100000, 100000), 16, 8);
        $this->assertNull($h->fromBytes($huge));
    }

    public function test_jpeg_y_webp_embebidos_se_normalizan_por_mime_no_por_extension()
    {
        foreach ([self::JPEG] as $base64) {
            $result = (new Imagen)->fromBytes(base64_decode($base64));
            $this->assertNotNull($result);
            $this->assertSame('image/png', $result['mime']);
        }
    }

    public function test_webp_mime_real_y_fallback_sin_decodificador()
    {
        $bytes = base64_decode(self::WEBP);
        $this->assertSame('image/webp', getimagesizefromstring($bytes)['mime']);
        $result = (new Imagen)->fromBytes($bytes);
        if (function_exists('imagecreatefromwebp')) {
            $this->assertSame('image/png', $result['mime']);
        } else {
            $this->assertNull($result);
        }
    }

    public function test_urls_y_origen_publico_sin_credenciales_controles_o_destinos_internos()
    {
        foreach (['javascript:alert(1)', '//example.com/logo', 'http://127.0.0.1/a', 'http://[::1]/a',
            'http://2130706433/a', 'http://localhost/a', 'https://a.local/a', 'https://a.internal/a',
            'https://u:p@example.com/a', "https://example.com/\n", 'https://example.com:444/a',
            'https://example.com\\@evil.com/a', 'data:image/png;base64,AAA'] as $url) {
            $this->assertNull(Imagen::publicUrl($url), $url);
        }
        $this->assertSame('https://example.com/logo.png', Imagen::publicUrl('/logo.png', 'https://example.com/base'));
        $this->assertNull(Imagen::publicUrl('/logo.png'));
    }

    public function test_ssrf_hosts_y_direcciones_todos_publicos_antes_del_downloader_fake()
    {
        $h = new class extends Imagen {
            public $ips = ['93.184.216.34'];
            public $calls = 0;
            protected function addresses($host) { return $this->ips; }
            protected function download($url, $host, $ip) { $this->calls++; return base64_decode(ImagenParaMailHelperTest::PNG); }
        };
        $this->assertNull($h->prepare('https://evil.com/a', null, ['example.com']));
        foreach ([['127.0.0.1'], ['100.64.1.1'], ['224.0.0.1'], ['198.18.0.1'], ['169.254.169.254'], ['10.0.0.1'], ['::ffff:127.0.0.1'], ['93.184.216.34', '192.168.0.1'], []] as $ips) {
            $h->ips = $ips;
            $this->assertNull($h->prepare('https://example.com/a.jpg', null, ['example.com']));
        }
        $this->assertSame(0, $h->calls);
        $h->ips = ['93.184.216.34'];
        $this->assertSame('image/png', $h->prepare('/a.jpg', 'https://example.com', ['example.com'])['mime']);
        $this->assertSame(1, $h->calls);
        config(['mail.image_public_addresses' => []]);
        $this->assertNull((new Imagen)->prepare('https://example.com/a', null, ['example.com']));
    }

    public function test_falla_descarga_o_contenido_no_imagen_degrada_sin_excepcion()
    {
        $h = new class extends Imagen {
            public $mode = 'corrupto';
            protected function addresses($host) { return ['93.184.216.34']; }
            protected function download($url, $host, $ip) {
                if ($this->mode === 'timeout') throw new \RuntimeException('timeout sintético');
                return '<html>redirección o error, no imagen</html>';
            }
        };
        $this->assertNull($h->prepare('https://example.com/logo.png', null, ['example.com']));
        $h->mode = 'timeout';
        $this->assertNull($h->prepare('https://example.com/logo.png', null, ['example.com']));
    }

    public function test_colores_cta_siempre_contraste_al_menos_4_5_y_css_invalido_no_se_imprime()
    {
        foreach (['#fff', '#000', '#ffffff', '#000000', '#ffff00', '#777777', '#ff0000', '#123456', 'red;position:fixed', null] as $value) {
            $p = Color::palette($value);
            $a = Color::luminance($p['background']); $b = Color::luminance($p['foreground']);
            $this->assertGreaterThanOrEqual(4.5, (max($a, $b) + .05) / (min($a, $b) + .05));
        }
        $this->assertSame('#111827', Color::palette('red;position:fixed')['background']);
    }
}
