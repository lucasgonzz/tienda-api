<?php

namespace Tests\Unit;

require_once __DIR__.'/../Feature/OrderEmailsTest.php';

use App\Http\Controllers\Helpers\ColorDeMailHelper as Color;
use App\Http\Controllers\Helpers\ImagenParaMailHelper as Imagen;
use App\Http\Controllers\Helpers\OrderMailDataHelper as Data;
use App\Mail\PedidoRecibidoComprador;
use App\OnlineConfiguration;
use App\User;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\OrderMailFixture;

/**
 * Corrección A (pedido de Truvari): color de los botones de los mails y logo remoto grande.
 * Sin red: la resolución de DNS y la descarga se reemplazan por falsos explícitos.
 */
class ImagenParaMailTruvariTest extends OrderMailFixture
{
    /** URL real del logo de Truvari (host de empresa-api distinto del host de la tienda). */
    const LOGO_URL = 'https://api-truvari.comerciocity.com/public/storage/images/logo.webp';

    /** Genera un WEBP con alpha (fondo transparente y un círculo opaco) de lado $side. */
    protected function make_webp($side)
    {
        $this->assertTrue(function_exists('imagewebp'), 'GD sin soporte WEBP');
        $image = imagecreatetruecolor($side, $side);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, intdiv($side, 2), intdiv($side, 2), intdiv($side, 2), intdiv($side, 2),
            imagecolorallocatealpha($image, 37, 2, 55, 0));
        ob_start();
        imagewebp($image, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    /** Helper de imágenes con DNS y descarga falsos; cuenta llamadas. */
    protected function fake_helper($bytes, array $ips = ['86.38.190.205'], $memory_ok = true)
    {
        return new class($bytes, $ips, $memory_ok) extends Imagen {
            public $bytes;
            public $ips;
            public $memory_ok;
            public $resolved = [];
            public $downloads = [];
            public function __construct($bytes, $ips, $memory_ok)
            {
                $this->bytes = $bytes;
                $this->ips = $ips;
                $this->memory_ok = $memory_ok;
            }
            protected function resolveHost($host) { $this->resolved[] = $host; return $this->ips; }
            protected function download($url, $host, $ip) { $this->downloads[] = [$url, $host, $ip]; return $this->bytes; }
            protected function hasMemoryFor($width, $height) { return $this->memory_ok; }
        };
    }

    /** Configuración de Truvari: app y disco público en el host de la tienda, logo en el de empresa-api. */
    protected function truvari_setup($logo_url = self::LOGO_URL)
    {
        config(['app.url' => 'https://api.truvaribebidas.com.ar/public',
            'filesystems.disks.public' => ['driver' => 'local', 'root' => sys_get_temp_dir(),
                'url' => 'https://api.truvaribebidas.com.ar/public/storage'],
            'mail.image_public_addresses' => [], 'mail.image_allowed_hosts' => []]);
        $this->configuration->update(['logo_url' => $logo_url]);
    }

    /** Lado mayor y píxel de la esquina (alpha) de un PNG. */
    protected function png_facts($bytes)
    {
        $info = getimagesizefromstring($bytes);
        $image = imagecreatefromstring($bytes);
        $alpha = imagecolorsforindex($image, imagecolorat($image, 0, 0))['alpha'];
        imagedestroy($image);
        return ['mime' => $info['mime'], 'max_side' => max($info[0], $info[1]), 'corner_alpha' => $alpha];
    }

    public function test_ca21_color_del_boton_usa_el_primer_candidato_que_contrasta_con_blanco()
    {
        $this->assertSame(['background' => '#250237', 'foreground' => '#ffffff'],
            Color::palette('#E9E7EA', ['#250237', '#250237']));
        $this->assertSame('#c5111d', Color::palette('#c5111d', ['#250237'])['background']);
        $this->assertSame('#111827', Color::palette('red;position:fixed', [null, '#fe7802'])['background']);
        $this->assertSame('#111827', Color::palette('#E9E7EA')['background']);
    }

    public function test_ca23_logo_remoto_grande_por_sufijo_sale_como_png_inline_en_el_mail()
    {
        $order = $this->checkout();
        $this->owner->update(['company_name' => 'Truvari', 'online' => 'https://truvaribebidas.com.ar']);
        $this->truvari_setup();
        $helper = $this->fake_helper($this->make_webp(3590));
        app()->instance(Imagen::class, $helper);
        $order->unsetRelation('user');
        $logo = Data::build($order)['logo_image'];
        $this->assertNotNull($logo, 'el logo de Truvari no se descargó');
        $facts = $this->png_facts($logo['bytes']);
        $this->assertSame('image/png', $logo['mime']);
        $this->assertSame('image/png', $facts['mime']);
        $this->assertLessThanOrEqual(440, $facts['max_side']);
        $this->assertSame(127, $facts['corner_alpha']);
        $this->assertCount(1, $helper->downloads);
        $this->assertSame('api-truvari.comerciocity.com', $helper->downloads[0][1]);
        $this->assertSame('86.38.190.205', $helper->downloads[0][2]);
        app('events')->forget(\Illuminate\Mail\Events\MessageSending::class);
        $sent = Mail::to('comprador@example.test')->send(new PedidoRecibidoComprador($order));
        $message = $sent->getSymfonySentMessage()->getOriginalMessage();
        $this->assertStringContainsString('src="cid:', $message->getHtmlBody());
        $this->assertCount(1, $message->getAttachments());
        $this->assertSame('inline', $message->getAttachments()[0]->getDisposition());
        $this->assertSame('png', $message->getAttachments()[0]->getMediaSubtype());
    }

    public function test_ca24_ssrf_solo_https_sufijo_exacto_y_todas_las_ip_publicas()
    {
        $this->truvari_setup();
        $webp = $this->make_webp(64);
        $logo = function ($helper, $url = self::LOGO_URL) {
            app()->instance(Imagen::class, $helper);
            return Data::logoImage($this->owner, new OnlineConfiguration(['logo_url' => $url]));
        };
        // Control positivo: con una IP pública el logo sale (si no, los negativos no prueban nada).
        $this->assertNotNull($logo($this->fake_helper($webp)), 'control positivo');
        foreach ([['10.0.0.5'], ['86.38.190.205', '192.168.0.1'], []] as $ips) {
            $helper = $this->fake_helper($webp, $ips);
            $this->assertNull($logo($helper), json_encode($ips));
            $this->assertCount(0, $helper->downloads, json_encode($ips));
        }
        foreach (['https://api-truvari.comerciocity.com.evil.com/a.webp', 'https://evilcomerciocity.com/a.webp',
            'http://api-truvari.comerciocity.com/a.webp', 'https://api-truvari.comerciocity.com:8443/a.webp'] as $url) {
            $helper = $this->fake_helper($webp);
            $this->assertNull($logo($helper, $url), $url);
            $this->assertSame([], $helper->resolved, $url);
            $this->assertSame([], $helper->downloads, $url);
        }
        config(['mail.image_allowed_host_suffixes' => []]);
        $helper = $this->fake_helper($webp);
        $this->assertNull($logo($helper));
        $this->assertSame([], $helper->downloads);
        // En testing el resolveHost real no sale a la red: devuelve [].
        config(['mail.image_allowed_host_suffixes' => ['comerciocity.com']]);
        $method = new \ReflectionMethod(Imagen::class, 'resolveHost');
        $method->setAccessible(true);
        $this->assertSame([], $method->invoke(new Imagen, 'api-truvari.comerciocity.com'));
    }

    public function test_ca25_from_bytes_reduce_a_440_limita_pixeles_y_memoria()
    {
        $png = (new Imagen)->fromBytes($this->make_webp(3590));
        $this->assertNotNull($png, 'WEBP 3590x3590 rechazado');
        $facts = $this->png_facts($png['bytes']);
        $this->assertLessThanOrEqual(440, $facts['max_side']);
        $this->assertSame(127, $facts['corner_alpha']);
        $wide = imagecreatetruecolor(4097, 1);
        ob_start(); imagepng($wide); $wide_bytes = ob_get_clean(); imagedestroy($wide);
        $this->assertNull((new Imagen)->fromBytes($wide_bytes));
        // Sin memoria suficiente: null y el mail sale con el nombre en texto, sin <img.
        $this->assertNull($this->fake_helper('', [], false)->fromBytes($this->make_webp(3590)));
        $order = $this->checkout();
        $this->owner->update(['company_name' => 'Truvari']);
        $this->truvari_setup();
        app()->instance(Imagen::class, $this->fake_helper($this->make_webp(3590), ['86.38.190.205'], false));
        $order->unsetRelation('user');
        $this->assertNull(Data::build($order)['logo_image']);
        $html = (new PedidoRecibidoComprador($order))->render();
        $this->assertStringContainsString('Truvari', $html);
        $this->assertStringNotContainsString('<img', $html);
    }
}
