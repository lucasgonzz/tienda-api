<?php
namespace Tests\Unit;

require_once __DIR__.'/ImagenParaMailHelperTest.php';

use App\Http\Controllers\Helpers\ImagenParaMailHelper as Imagen;
use Tests\TestCase;

class ImagenParaMailLocalTest extends TestCase
{
    public $sandbox;
    protected $original;
    protected function setUp(): void
    {
        parent::setUp();
        $this->original = config('filesystems.disks.public');
        $this->sandbox = base_path('.mision/QI-T-runtime/sandboxes/local-'.bin2hex(random_bytes(8)));
        mkdir($this->sandbox.'/public/sub', 0777, true);
        file_put_contents($this->sandbox.'/public/good.jpg', base64_decode(ImagenParaMailHelperTest::PNG));
        file_put_contents($this->sandbox.'/outside.png', base64_decode(ImagenParaMailHelperTest::PNG));
        config(['filesystems.disks.public' => ['driver'=>'local','root'=>$this->sandbox.'/public','url'=>'https://example.com/storage'],
            'mail.image_public_addresses'=>[], 'mail.image_allowed_hosts'=>[]]);
    }
    protected function tearDown(): void
    {
        try {
            config(['filesystems.disks.public'=>$this->original]);
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->sandbox, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $p) { if ($p->isDir() && !$p->isLink()) rmdir($p->getPathname()); else { @chmod($p->getPathname(),0600); unlink($p->getPathname()); } }
            rmdir($this->sandbox);
        } finally { parent::tearDown(); }
    }
    protected function prepare($source)
    {
        return (new Imagen)->prepare($source, 'https://example.com', ['example.com']);
    }
    protected function control()
    {
        $r = $this->prepare('/storage/good.jpg');
        $this->assertNotNull($r, 'Control positivo local nativo sin mapa');
        $this->assertSame('image/png', getimagesizefromstring($r['bytes'])['mime']);
        return $r;
    }
    public function test_disponibilidad_nativa_formatos_alpha_y_frontera_bytes()
    {
        $this->control();
        config(['mail.image_public_addresses'=>'malformado']);
        file_put_contents($this->sandbox.'/public/sub/a b.jpg', base64_decode(ImagenParaMailHelperTest::JPEG));
        $r = $this->prepare('https://example.com/storage/sub/a%20b.jpg');
        $this->assertSame('image/png', $r['mime']);
        $im=imagecreatetruecolor(1,1); imagealphablending($im,false); imagesavealpha($im,true);
        imagesetpixel($im,0,0,imagecolorallocatealpha($im,40,70,90,127));
        ob_start(); imagepng($im); $png=ob_get_clean(); imagedestroy($im);
        file_put_contents($this->sandbox.'/public/alpha.png',$png);
        $r=$this->prepare('/storage/alpha.png'); $im=imagecreatefromstring($r['bytes']);
        $this->assertSame(127,imagecolorsforindex($im,imagecolorat($im,0,0))['alpha']); imagedestroy($im);
        file_put_contents($this->sandbox.'/public/exact.png',str_pad($png,Imagen::MAX_BYTES,"\0"));
        $this->assertNotNull($this->prepare('/storage/exact.png'));
        file_put_contents($this->sandbox.'/public/webp.jpg',base64_decode(ImagenParaMailHelperTest::WEBP));
        $r=$this->prepare('/storage/webp.jpg');
        if (function_exists('imagecreatefromwebp')) $this->assertSame('image/png',$r['mime']);
        else $this->assertNull($r);
        // La raíz configurada puede ser enlace de despliegue; no los descendientes.
        symlink($this->sandbox.'/public',$this->sandbox.'/deployment');
        config(['filesystems.disks.public.root'=>$this->sandbox.'/deployment']);
        $this->control();
    }
    public function test_urls_ambiguas_y_traversal_rechazados_con_control_por_caso()
    {
        foreach (['../outside.png','./good.jpg','%2e%2e/outside.png','%252e%252e/outside.png',
            'sub%2fgood.jpg','sub%5cgood.jpg','%00.png','bad%.png','a//b','a\\b','good.jpg?q=1','good.jpg#x',
            str_repeat('a',4097), implode('/',array_fill(0,65,'x'))] as $suffix) {
            $this->control(); $this->assertNull($this->prepare('https://example.com/storage/'.$suffix),$suffix);
        }
        $this->control();
        $this->assertNull((new Imagen)->prepare('/storage/good.jpg','https://example.com:444',['example.com']));
        foreach (['file:///tmp/good.jpg','data:image/png,x','//example.com/storage/good.jpg',
            'https://u:p@example.com/storage/good.jpg','https://example.com:444/storage/good.jpg',
            'https://evil.com/storage/good.jpg','https://example.com.evil.com/storage/good.jpg',
            'http://example.com/storage/good.jpg','https://example.com/storage-other/good.jpg',
            "https://example.com/storage/good.jpg\n"] as $url) {
            $this->control(); $this->assertNull($this->prepare($url),$url);
        }
    }
    public static function urls_publicas_sin_sufijo(): array
    {
        return [
            'exacta' => ['https://example.com/storage'],
            'query' => ['https://example.com/storage?q=1'],
            'fragmento' => ['https://example.com/storage#x'],
            'relativa exacta' => ['/storage'],
            'relativa query' => ['/storage?q=1'],
            'relativa fragmento' => ['/storage#x'],
        ];
    }

    /** @dataProvider urls_publicas_sin_sufijo */
    public function test_namespace_publico_sin_sufijo_no_consulta_mapa_ni_descarga($url)
    {
        config(['mail.image_public_addresses' => ['example.com' => ['8.8.8.8']]]);
        $spy = new class extends Imagen {
            public $addressCalls = 0;
            public $downloadCalls = 0;
            public $remote = [];
            protected function addresses($host)
            {
                $this->addressCalls++;
                return parent::addresses($host);
            }
            protected function download($url, $host, $ip)
            {
                $this->downloadCalls++;
                $this->remote = [$url, $host, $ip];
                return base64_decode(ImagenParaMailHelperTest::PNG);
            }
        };
        // Hermano nativo disponible aun con mapa válido: no se usa transporte.
        $native = $spy->prepare('/storage/good.jpg', 'https://example.com', ['example.com']);
        $this->assertNotNull($native);
        $this->assertSame('image/png', getimagesizefromstring($native['bytes'])['mime']);
        $this->assertSame(0, $spy->addressCalls);
        $this->assertSame(0, $spy->downloadCalls);
        $this->assertNull($spy->prepare($url, 'https://example.com', ['example.com']));
        $this->assertSame(0, $spy->addressCalls);
        $this->assertSame(0, $spy->downloadCalls);
        // Fuera del namespace sigue habilitado el remoto pinneado, siempre fake.
        $remote = $spy->prepare('https://example.com/storage-other/good.jpg', 'https://example.com', ['example.com']);
        $this->assertNotNull($remote);
        $this->assertSame('image/png', getimagesizefromstring($remote['bytes'])['mime']);
        $this->assertSame(1, $spy->addressCalls);
        $this->assertSame(1, $spy->downloadCalls);
        $this->assertSame(['https://example.com/storage-other/good.jpg', 'example.com', '8.8.8.8'], $spy->remote);
    }

    public function test_symlinks_archivos_y_directorios_no_se_leen()
    {
        foreach (['internal'=>'public/good.jpg','external'=>'outside.png','dir'=>'public/sub','externaldir'=>'.','broken'=>'missing','loop'=>'public/loop'] as $name=>$target) {
            $this->control();
            symlink($this->sandbox.'/'.$target,$this->sandbox.'/public/'.$name);
            $this->assertNull($this->prepare('/storage/'.$name));
            if (in_array($name,['dir','externaldir'])) $this->assertNull($this->prepare('/storage/'.$name.'/good.jpg'));
        }
        foreach (['missing','sub'] as $path) { $this->control(); $this->assertNull($this->prepare('/storage/'.$path)); }
        $this->control();
        posix_mkfifo($this->sandbox.'/public/fifo',0600);
        $this->assertNull($this->prepare('/storage/fifo'));
    }
    public function test_mime_tamano_y_pixeles_rechazados_desde_archivos()
    {
        $png=base64_decode(ImagenParaMailHelperTest::PNG);
        foreach (['','corrupto','<svg/>','<html/>',str_repeat('x',Imagen::MAX_BYTES+1),
            substr_replace($png,pack('NN',4097,1),16,8),
            substr_replace($png,pack('NN',2100,2100),16,8)] as $bytes) {
            $this->control(); file_put_contents($this->sandbox.'/public/bad.png',$bytes);
            $this->assertNull($this->prepare('/storage/bad.png'));
        }
    }
    public function test_config_invalida_no_habilita_lectura_ni_descarga()
    {
        $base=config('filesystems.disks.public');
        foreach ([
            ['root'=>'/'],['root'=>$this->sandbox.'/missing'],['root'=>'relative'],['root'=>'file:///tmp'],
            ['driver'=>'s3'],['url'=>'https://example.com/storage?q=1'],['url'=>'https://example.com/storage/../storage'],
            ['url'=>'https://example.com/storage#x'],['url'=>'//example.com/storage']
        ] as $change) {
            config(['filesystems.disks.public'=>$base]); $this->control();
            config(['filesystems.disks.public'=>array_merge($base,$change)]);
            $this->assertNull($this->prepare('/storage/good.jpg'));
        }
        config(['filesystems.disks.public'=>$base]); $this->control();
        $spy=new class extends Imagen {
            public $calls=0;
            protected function addresses($host) { $this->calls++; throw new \RuntimeException('No debe consultar mapa local'); }
            protected function download($url,$host,$ip) { $this->calls++; throw new \RuntimeException('No debe descargar local'); }
        };
        $this->assertNull($spy->prepare('/storage/missing','https://example.com',['example.com']));
        $this->assertSame(0,$spy->calls);
        $this->assertNull($this->prepare('https://example.com/remote.png'));
    }
}
