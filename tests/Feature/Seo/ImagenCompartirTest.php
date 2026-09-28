<?php

namespace Tests\Feature\Seo;

use App\Http\Controllers\Helpers\Seo\SeoContexto;
use App\Http\Controllers\Helpers\Seo\SeoImagenCompartir;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GET /api/seo/imagen-compartir: el conversor de webp a jpeg que arma
 * SeoContexto::paraCompartir() (mision og-image-webp-whatsapp, 28/9/2026).
 *
 * No depende de ningun comercio ni toca la base -por eso no usa DatabaseTransactions ni
 * ArmaTiendaParaSeo-: valida un host y convierte bytes. `Http::fake()` en todos los casos:
 * nada sale a la red real. `Storage::fake('local')` para no ensuciar storage/app del slot ni
 * arrastrar cache entre corridas.
 *
 * 🔴 Lo que esta clase clava y que no se puede aflojar:
 *   - `src` fuera del allowlist (host distinto, o esquema distinto de https) → 400, y NO se
 *     intenta ninguna descarga.
 *   - El origen no disponible (mockeado) o un webp corrupto → redirect 302 al original, NUNCA
 *     un 500 ni una respuesta rota.
 *   - Un webp valido → 200, Content-Type image/jpeg, Cache-Control largo, y los primeros bytes
 *     son el magic number de JPEG (FF D8 FF).
 */
class ImagenCompartirTest extends TestCase
{
    const RUTA = '/api/seo/imagen-compartir';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Un webp de 20x10 con transparencia, generado con GD (mismo motor que el conversor).
     *
     * @return string
     */
    private function webpDePrueba()
    {
        $img = imagecreatetruecolor(20, 10);
        imagesavealpha($img, true);
        $transparente = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparente);
        $rojo = imagecolorallocate($img, 200, 30, 30);
        imagefilledellipse($img, 10, 5, 12, 8, $rojo);

        ob_start();
        imagewebp($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function pedir($src)
    {
        return $this->get(self::RUTA.'?src='.urlencode($src));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Camino feliz
    |---------------------------------------------------------------------------------------------
    */

    public function test_convierte_un_webp_real_a_jpeg_con_cache_control_largo()
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('Este PHP no tiene soporte GD para webp.');
        }

        $src = 'https://api-imgtest.comerciocity.com/public/storage/nota-de-cata.webp';
        Http::fake([
            'api-imgtest.comerciocity.com/*' => Http::response($this->webpDePrueba(), 200, ['Content-Type' => 'image/webp']),
        ]);

        $respuesta = $this->pedir($src);

        $respuesta->assertStatus(200);
        $respuesta->assertHeader('Content-Type', 'image/jpeg');
        /* Symfony reordena las directivas del header al serializarlo (sale "max-age=..., public",
           no como se setea): se verifican las dos por separado, no el string armado entero. */
        $cache_control = $respuesta->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache_control);
        $this->assertStringContainsString('max-age=2592000', $cache_control);

        $bytes = $respuesta->getContent();
        $this->assertSame("\xFF\xD8\xFF", substr($bytes, 0, 3), 'los primeros bytes son el magic number de JPEG');

        /* La esquina del webp de prueba es 100% transparente: si el aplanado sobre blanco anda
           bien, esa esquina en el jpeg final tiene que ser blanca (no negra: jpeg no tiene
           canal alfa, y sin aplanar de antes el negro del RGB(0,0,0) de base se cuela). */
        $decodificada = imagecreatefromstring($bytes);
        $this->assertNotFalse($decodificada, 'el jpeg devuelto tiene que decodificar');
        $esquina = imagecolorat($decodificada, 0, 0);
        $rgb = imagecolorsforindex($decodificada, $esquina);
        $this->assertGreaterThan(240, $rgb['red']);
        $this->assertGreaterThan(240, $rgb['green']);
        $this->assertGreaterThan(240, $rgb['blue']);
        imagedestroy($decodificada);
    }

    public function test_la_segunda_pedida_de_la_misma_imagen_no_vuelve_a_descargar_el_origen()
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('Este PHP no tiene soporte GD para webp.');
        }

        $src = 'https://api-imgtest.comerciocity.com/public/storage/cacheada.webp';
        Http::fake([
            'api-imgtest.comerciocity.com/*' => Http::response($this->webpDePrueba(), 200, ['Content-Type' => 'image/webp']),
        ]);

        $primera = $this->pedir($src);
        $primera->assertStatus(200);

        $segunda = $this->pedir($src);
        $segunda->assertStatus(200);

        $this->assertSame($primera->getContent(), $segunda->getContent());
        Http::assertSentCount(1);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | SSRF: el allowlist de host, siempre ANTES de pedir nada
    |---------------------------------------------------------------------------------------------
    */

    public function test_un_host_fuera_del_allowlist_da_400_y_no_descarga_nada()
    {
        Http::fake();

        $respuesta = $this->pedir('https://evil.com/foto.webp');

        $respuesta->assertStatus(400);
        Http::assertNothingSent();
    }

    /** 🔴 El caso concreto que un regex contra la URL completa dejaria pasar. */
    public function test_un_host_que_imita_comerciocity_con_un_sufijo_no_pasa_el_allowlist()
    {
        Http::fake();

        $respuesta = $this->pedir('https://comerciocity.com.evil.com/foto.webp');

        $respuesta->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_http_sin_https_da_400_y_no_descarga_nada()
    {
        Http::fake();

        $respuesta = $this->pedir('http://api-imgtest.comerciocity.com/foto.webp');

        $respuesta->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_sin_src_da_400_y_no_descarga_nada()
    {
        Http::fake();

        $this->getJson(self::RUTA)->assertStatus(400);
        Http::assertNothingSent();
    }

    /** origenPermitido() en detalle: esquema, host exacto, y las dos variantes maliciosas. */
    public function test_origen_permitido_valida_esquema_y_host_exacto()
    {
        $this->assertTrue(SeoImagenCompartir::origenPermitido('https://api-cliente.comerciocity.com/x.webp'));
        $this->assertTrue(SeoImagenCompartir::origenPermitido('https://res.cloudinary.com/demo/x.webp'), 'Cloudinary, el otro host posible');

        $this->assertFalse(SeoImagenCompartir::origenPermitido('http://api-cliente.comerciocity.com/x.webp'), 'http, no https');
        $this->assertFalse(SeoImagenCompartir::origenPermitido('https://comerciocity.com.evil.com/x.webp'), 'sufijo, no el dominio real');
        $this->assertFalse(SeoImagenCompartir::origenPermitido('https://res.cloudinary.com.evil.com/x.webp'), 'idem con Cloudinary');
        $this->assertFalse(SeoImagenCompartir::origenPermitido('https://evil.com/x.webp'), 'host ajeno');
        $this->assertFalse(SeoImagenCompartir::origenPermitido('javascript:alert(1)'));
        $this->assertFalse(SeoImagenCompartir::origenPermitido(null));
        $this->assertFalse(SeoImagenCompartir::origenPermitido(''));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fallback: nunca un 500, nunca una respuesta rota
    |---------------------------------------------------------------------------------------------
    */

    public function test_origen_no_disponible_redirige_302_al_original_sin_romper()
    {
        $src = 'https://api-imgtest.comerciocity.com/public/storage/no-responde.webp';
        Http::fake([
            'api-imgtest.comerciocity.com/*' => Http::response('', 500),
        ]);

        $respuesta = $this->pedir($src);

        $respuesta->assertStatus(302);
        $respuesta->assertRedirect($src);
    }

    public function test_un_webp_corrupto_tambien_redirige_302_al_original()
    {
        $src = 'https://api-imgtest.comerciocity.com/public/storage/corrupto.webp';
        Http::fake([
            'api-imgtest.comerciocity.com/*' => Http::response('esto no es un webp valido', 200, ['Content-Type' => 'image/webp']),
        ]);

        $respuesta = $this->pedir($src);

        $respuesta->assertStatus(302);
        $respuesta->assertRedirect($src);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | SeoContexto::paraCompartir() -la funcion pura que arma la URL de este endpoint
    |---------------------------------------------------------------------------------------------
    */

    /** Solo reescribe lo que termina en .webp, sin importar mayusculas ni un ?query detras. */
    public function test_para_compartir_solo_reescribe_webp_sin_importar_mayusculas_ni_query()
    {
        $this->assertNull(SeoContexto::paraCompartir(null));
        $this->assertSame('', SeoContexto::paraCompartir(''));
        $this->assertSame('https://cdn.test/foto.jpg', SeoContexto::paraCompartir('https://cdn.test/foto.jpg'), 'no webp: tal cual');

        $convertida = SeoContexto::paraCompartir('https://cdn.test/foto.webp');
        $this->assertStringContainsString('/api/seo/imagen-compartir?src='.rawurlencode('https://cdn.test/foto.webp'), $convertida);

        $mayusculas = SeoContexto::paraCompartir('https://cdn.test/FOTO.WEBP');
        $this->assertStringContainsString('/api/seo/imagen-compartir?src='.rawurlencode('https://cdn.test/FOTO.WEBP'), $mayusculas);

        $con_query = SeoContexto::paraCompartir('https://cdn.test/foto.webp?v=2');
        $this->assertStringContainsString('/api/seo/imagen-compartir?src='.rawurlencode('https://cdn.test/foto.webp?v=2'), $con_query);
    }
}
