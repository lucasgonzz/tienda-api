<?php
namespace Tests\Feature;

require_once __DIR__.'/OrderEmailsTest.php';

use App\Buyer;
use App\User;
use App\OnlineConfiguration;
use App\Http\Controllers\Helpers\OrderMailDataHelper as Data;
use App\Mail\NuevoPedidoComercio;
use App\Mail\PedidoRecibidoComprador;
use Illuminate\Support\Facades\Mail;

class OrderMailLocalRenderingTest extends OrderMailFixture
{
    protected $sandbox;
    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox=base_path('.mision/QI-T-runtime/sandboxes/mail-'.bin2hex(random_bytes(8)));
        mkdir($this->sandbox,0777,true);
        foreach (['a'=>[10,20,30],'b'=>[100,80,60]] as $name=>$rgb) {
            $im=imagecreatetruecolor(1,1); imagealphablending($im,false); imagesavealpha($im,true);
            imagesetpixel($im,0,0,imagecolorallocatealpha($im,$rgb[0],$rgb[1],$rgb[2],127));
            imagepng($im,$this->sandbox.'/'.$name.'.jpg'); imagedestroy($im);
        }
        config(['app.url'=>'https://example.com','filesystems.disks.public'=>[
            'driver'=>'local','root'=>$this->sandbox,'url'=>'https://example.com/storage'],
            'mail.image_public_addresses'=>[], 'mail.image_allowed_hosts'=>[]]);
    }
    protected function tearDown(): void
    {
        try { foreach (glob($this->sandbox.'/*') as $p) unlink($p); rmdir($this->sandbox); }
        finally { parent::tearDown(); }
    }
    public function test_ambos_mails_cid_png_alpha_prioridad_fallback_y_duenos_sucesivos()
    {
        $a=$this->owner; $ca=$this->configuration;
        $a->update(['company_name'=>'Local <A>','image_url'=>'/storage/b.jpg']);
        $ca->update(['logo_url'=>'/storage/a.jpg']);
        $orderA=$this->checkout();
        $b=User::create(['name'=>'Local B','company_name'=>'Local B','email'=>uniqid().'@example.test','password'=>bcrypt('fixture')]);
        $this->owners[]=$b->id;
        $cb=OnlineConfiguration::create(['user_id'=>$b->id,'logo_url'=>'/storage/b.jpg']);
        $this->actingAs($b);
        app('events')->forget(\Illuminate\Mail\Events\MessageSending::class);
        foreach ([new NuevoPedidoComercio($orderA),new PedidoRecibidoComprador($orderA)] as $mail) {
            $sent=Mail::to('local@example.test')->send($mail);
            $message=$sent->getSymfonySentMessage()->getOriginalMessage();
            $this->assertStringContainsString('Local &lt;A&gt;',$message->getHtmlBody());
            $this->assertCount(1,$message->getAttachments());
            $part=$message->getAttachments()[0];
            $this->assertSame('inline',$part->getDisposition());
            $this->assertSame('png',$part->getMediaSubtype());
            $this->assertSame('image/png',getimagesizefromstring($part->getBody())['mime']);
            // Symfony sustituye el alias logo.png al generar la parte HTML MIME (no getHtmlBody).
            $wireHtml=$message->getBody()->getParts()[0]->getBody();
            $this->assertStringContainsString('cid:'.$part->getContentId(),$wireHtml);
            $this->assertStringContainsString('Content-ID:',$message->toString());
            $im=imagecreatefromstring($part->getBody());
            $pixel=imagecolorsforindex($im,imagecolorat($im,0,0)); imagedestroy($im);
            $this->assertSame(127,$pixel['alpha']); $this->assertSame(10,$pixel['red']);
        }
        $this->owner=$b; $this->configuration=$cb;
        $this->buyer=Buyer::create(['name'=>'B fixture','email'=>'b@example.test','user_id'=>$b->id]);
        $orderB=$this->checkout();
        $imageB=Data::build($orderB)['logo_image'];
        $this->assertNotNull($imageB);
        $this->assertNotSame(Data::build($orderA)['logo_image']['bytes'],$imageB['bytes']);
        $ca->update(['logo_url'=>'/storage/missing']);
        $orderA->unsetRelation('user');
        $this->assertSame($imageB['bytes'],Data::build($orderA)['logo_image']['bytes']);
        $a->update(['image_url'=>null]); $orderA->unsetRelation('user');
        $this->assertNull(Data::build($orderA)['logo_image']);
        $html=(new NuevoPedidoComercio($orderA))->render();
        $this->assertStringContainsString('Local &lt;A&gt;',$html);
        $this->assertStringNotContainsString('<img ',$html);
    }
}
