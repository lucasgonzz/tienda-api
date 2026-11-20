<?php

namespace Tests\Feature;

require_once __DIR__.'/OrderEmailsTest.php';
require_once __DIR__.'/../Unit/ImagenParaMailHelperTest.php';

use App\Http\Controllers\Helpers\ImagenParaMailHelper as Imagen;
use App\Http\Controllers\Helpers\OrderMailDataHelper as Data;
use App\Mail\NuevoPedidoComercio;
use App\Mail\PedidoRecibidoComprador;
use Illuminate\Support\Facades\Mail;
use Tests\Unit\ImagenParaMailHelperTest;

class OrderMailRenderingTest extends OrderMailFixture
{
    public function test_render_y_mime_cid_por_mismo_mailable_para_ambos_destinatarios()
    {
        $order = $this->checkout();
        $this->owner->update(['company_name' => 'Comercio <seguro>', 'online' => 'https://example.com/tienda',
            'default_version' => 'https://example.com/sistema']);
        $this->configuration->update(['logo_url' => '/logo.jpg', 'primary_color' => '#ffff00']);
        config(['app.url' => 'https://example.com']);
        app()->instance(Imagen::class, new class extends Imagen {
            protected function addresses($host) { return ['93.184.216.34']; }
            protected function download($url, $host, $ip) { return base64_decode(ImagenParaMailHelperTest::PNG); }
        });
        // Fixture U10 verifica claims sólo en el Job. Aquí capturamos el MIME del Mailable real.
        app('events')->forget(\Illuminate\Mail\Events\MessageSending::class);
        $order->unsetRelation('user');
        foreach ([new NuevoPedidoComercio($order), new PedidoRecibidoComprador($order)] as $mailable) {
            $sent = Mail::to('render@example.test')->send($mailable);
            $message = $sent->getSymfonySentMessage()->getOriginalMessage();
            $html = $message->getHtmlBody();
            $this->assertStringContainsString('src="cid:', $html);
            $this->assertStringContainsString('Comercio &lt;seguro&gt;', $html);
            $this->assertStringContainsString('background-color:#ffff00; color:#000000;', $html);
            $this->assertStringContainsString('href="https://example.com/', $html);
            $parts = $message->getAttachments();
            $this->assertCount(1, $parts);
            $this->assertSame('inline', $parts[0]->getDisposition());
            $this->assertSame('image', $parts[0]->getMediaType());
            $this->assertSame('png', $parts[0]->getMediaSubtype());
            $this->assertSame('image/png', getimagesizefromstring($parts[0]->getBody())['mime']);
            $this->assertStringContainsString('Content-ID:', $message->toString());
        }
    }

    public function test_fallback_nombre_escapado_sin_logo_ajeno_y_cta_inseguro_ausente()
    {
        $order = $this->checkout();
        $this->owner->update(['company_name' => 'Sin logo <img onerror=alert(1)>',
            'online' => 'javascript:alert(1)', 'default_version' => 'http://127.0.0.1']);
        $this->configuration->update(['logo_url' => 'https://evil.com/logo.png', 'primary_color' => 'red;position:fixed']);
        $order->unsetRelation('user');
        $data = Data::build($order);
        $this->assertNull($data['logo_image']);
        $this->assertNull($data['store_url']);
        $this->assertNull($data['erp_orders_url']);
        $this->assertSame('#111827', $data['accent_color']);
        foreach ([new NuevoPedidoComercio($order), new PedidoRecibidoComprador($order)] as $mailable) {
            $html = $mailable->render();
            $this->assertStringContainsString('Sin logo &lt;img onerror=alert(1)&gt;', $html);
            $this->assertStringNotContainsString('<img ', $html);
            $this->assertStringNotContainsString('href=', $html);
            $this->assertStringNotContainsString('position:fixed', $html);
        }
    }
}
