<?php

namespace Tests\Feature\Seo;

use App\Http\Controllers\Helpers\Seo\SeoContexto;
use App\Http\Controllers\Helpers\Seo\SeoImagenCompartir;
use Illuminate\Support\Facades\Cache;
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
 *   - `src` fuera del allowlist (host distinto, esquema distinto de https, o un cloud_name de
 *     Cloudinary que no es el de ComercioCity) → 400, y NO se intenta ninguna descarga.
 *   - Un 3xx del origen NO se sigue (withoutRedirecting()): cae al fallback de la URL original,
 *     nunca al Location ajeno que haya mandado el origen.
 *   - Un body mas grande que el tope no se bufferea entero en memoria: fallback, no un agote.
 *   - El origen no disponible (mockeado) o un webp corrupto → redirect 302 al original, NUNCA
 *     un 500 ni una respuesta rota. Incluido el caso mas fino: un webp con el CONTENEDOR
 *     consistente pero el bitstream de adentro corrupto, que hace fatalear a GD igual -ahi lo
 *     que se prueba es el circuit breaker (con la marca "rota" pre-armada: nada en PHP puede
 *     probar en un test el fatal en si sin arriesgar tirar abajo la corrida entera).
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

    /**
     * Un webp con el CONTENEDOR RIFF perfectamente consistente (tamaño declarado = tamaño real,
     * asi que pareceWebpCompleto() lo deja pasar) pero el bitstream de ADENTRO corrupto: se
     * genera un webp real mas grande, se trunca a mitad del bitstream, y se reescribe el campo
     * de tamaño del RIFF (bytes 4-7) para que declare EXACTAMENTE lo que queda. Es el caso que
     * un segundo chequeo independiente encontro el 28/9/2026: getimagesizefromstring() lo
     * reconoce como webp valido igual, e imagecreatefromwebp() fatalea lo mismo.
     *
     * @return string
     */
    private function webpConBitstreamCorrupto()
    {
        $img = imagecreatetruecolor(200, 150);
        imagesavealpha($img, true);
        for ($x = 0; $x < 200; $x++) {
            for ($y = 0; $y < 150; $y++) {
                $color = imagecolorallocatealpha($img, ($x * 3) % 256, ($y * 2) % 256, ($x + $y) % 256, 0);
                imagesetpixel($img, $x, $y, $color);
            }
        }
        ob_start();
        imagewebp($img, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($img);

        $corte = (int) (strlen($bytes) * 0.4);
        $truncado = substr($bytes, 0, $corte);
        $nuevo_declarado = strlen($truncado) - 8;

        return substr_replace($truncado, pack('V', $nuevo_declarado), 4, 4);
    }

    /** @var string|null Memoiza webpGrandeDePrueba(): los dos tests del tope de tamaño lo piden
     *  cada uno, y a calidad 100 la codificacion tarda unos segundos -no vale la pena pagarlo dos
     *  veces en la misma corrida. */
    private static $webp_grande_de_prueba;

    /**
     * Un webp REAL y VALIDO de mas de SeoImagenCompartir::TOPE_BYTES (8 MB): lo que a los dos
     * tests del tope de tamaño les faltaba (chequeo independiente del 28/9/2026) para no
     * confundir el corte por TAMAÑO con el gate de FORMATO -ver el docblock de esos dos tests.
     *
     * Tiene que ser ruido genuino, no un patron -si no, WebP lo comprime por debajo del tope y
     * el fixture deja de servir-, pero pixel por pixel con imagesetpixel() en 2900x2900 (8.4
     * millones de pixeles) tarda mas de veinte segundos. En cambio, armar a mano un BMP de 24
     * bits sin compresion con random_bytes() y decodificarlo con imagecreatefromstring() -que
     * hace el trabajo pixel por pixel en C, no en PHP- tarda bien menos de un segundo. Medido en
     * esta maquina: 2900x2900 a calidad 100 da ~9.3 MB, con margen comodo sobre el tope de 8 MB.
     *
     * @return string
     */
    private function webpGrandeDePrueba()
    {
        if (self::$webp_grande_de_prueba !== null) {
            return self::$webp_grande_de_prueba;
        }

        $ancho = 2900;
        $alto = 2900;
        $tamano_fila = intdiv($ancho * 3 + 3, 4) * 4; // BMP: cada fila alineada a 4 bytes.
        $relleno = $tamano_fila - $ancho * 3;
        $tamano_pixeles = $tamano_fila * $alto;

        /* A archivo, fila por fila -no concatenado en un string gigante en memoria-: con los
           ~25 MB del BMP crudo mas la copia de la concatenacion, el limite de 128 MB de PHPUnit
           explotaba antes de llegar siquiera a imagecreatefromstring() (medido armando esta
           misma skill: "Allowed memory size of 134217728 bytes exhausted"). Escribiendo a disco
           el pico se queda en unos 80 MB (el buffer interno de GD para 2900x2900 truecolor mas
           la codificacion a webp), sin tocar memory_limit. */
        $temporal = tempnam(sys_get_temp_dir(), 'seobmp');
        $recurso = fopen($temporal, 'wb');
        fwrite($recurso, pack('a2VvvV', 'BM', 14 + 40 + $tamano_pixeles, 0, 0, 14 + 40));
        fwrite($recurso, pack('VVVvvVVVVVV', 40, $ancho, $alto, 1, 24, 0, $tamano_pixeles, 2835, 2835, 0, 0));
        $relleno_bytes = $relleno > 0 ? str_repeat("\0", $relleno) : '';
        for ($y = 0; $y < $alto; $y++) {
            fwrite($recurso, random_bytes($ancho * 3));
            if ($relleno > 0) {
                fwrite($recurso, $relleno_bytes);
            }
        }
        fclose($recurso);

        $img = imagecreatefrombmp($temporal);
        @unlink($temporal);

        ob_start();
        imagewebp($img, null, 100);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return self::$webp_grande_de_prueba = $bytes;
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

    /**
     * origenPermitido() en detalle: esquema, host exacto, y las variantes maliciosas -incluida
     * la de Cloudinary, que es un CDN compartido (multi-tenant por path) y no un host exclusivo
     * de ComercioCity: solo el prefijo EXACTO de nuestra cuenta (SeoContexto::CLOUDINARY) pasa,
     * no cualquier cloud_name (segundo hallazgo del chequeo independiente del 28/9/2026).
     */
    public function test_origen_permitido_valida_esquema_y_host_exacto()
    {
        $this->assertTrue(SeoImagenCompartir::origenPermitido('https://api-cliente.comerciocity.com/x.webp'));
        $this->assertTrue(
            SeoImagenCompartir::origenPermitido(SeoContexto::CLOUDINARY.'algun-public-id.webp'),
            'la cuenta de Cloudinary de ComercioCity, con su transformacion exacta'
        );

        $this->assertFalse(SeoImagenCompartir::origenPermitido('http://api-cliente.comerciocity.com/x.webp'), 'http, no https');
        $this->assertFalse(SeoImagenCompartir::origenPermitido('https://comerciocity.com.evil.com/x.webp'), 'sufijo, no el dominio real');
        $this->assertFalse(
            SeoImagenCompartir::origenPermitido('https://res.cloudinary.com/demo/x.webp'),
            'CDN compartido: un cloud_name ajeno (cualquiera con una cuenta gratis) no es el de ComercioCity'
        );
        $this->assertFalse(
            SeoImagenCompartir::origenPermitido('https://res.cloudinary.com/lucas-cn-evil/image/upload/q_auto,f_auto/x.webp'),
            'cloud_name parecido pero no exacto'
        );
        $this->assertFalse(SeoImagenCompartir::origenPermitido('https://res.cloudinary.com.evil.com/x.webp'), 'idem con Cloudinary por sufijo de host');
        $this->assertFalse(SeoImagenCompartir::origenPermitido('https://evil.com/x.webp'), 'host ajeno');
        $this->assertFalse(SeoImagenCompartir::origenPermitido('javascript:alert(1)'));
        $this->assertFalse(SeoImagenCompartir::origenPermitido(null));
        $this->assertFalse(SeoImagenCompartir::origenPermitido(''));
    }

    /**
     * 🔴 SSRF via redirect: un host que SI esta en el allowlist pero responde un 3xx no puede
     * hacer que se termine pidiendo lo que sea que diga su `Location` -sin withoutRedirecting(),
     * Guzzle lo seguiria solo, corriendo el allowlist entero. Cae al fallback de siempre: la URL
     * ORIGINAL, nunca el Location ajeno, y sin una segunda request de por medio.
     *
     * 🔴 El Location tiene su PROPIO fake, con un webp valido y decodificable (webpDePrueba()):
     * asi, si el dia de mañana alguien saca el withoutRedirecting() del codigo real, Guzzle SI
     * seguiria el redirect, bajaria y decodificaria ese webp, y responder() devolveria 200 con
     * ESE jpeg -nunca un 302 al original-, asi que assertStatus(302) ya alcanza para que la
     * regresion se note sola. Antes de este fixture el señuelo no tenia ninguna respuesta armada:
     * un intento real de seguirlo (aunque el codigo lo intentara) fallaria por timeout contra una
     * IP que no responde, y una request que termina en excepcion nunca se graba en
     * Http::recorded() -asi que ni assertSentCount(1) ni assertStatus(302)/assertRedirect()
     * distinguian "no se siguio" (rapido) de "se siguio y fallo" (~6s de timeout): la unica
     * diferencia real era el TIEMPO, y ningun assert lo miraba (chequeo independiente del
     * 28/9/2026).
     */
    public function test_un_redirect_del_origen_no_se_sigue_y_cae_al_fallback_del_original()
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('Este PHP no tiene soporte GD para webp.');
        }

        $src = 'https://api-imgtest.comerciocity.com/public/storage/con-redirect.webp';
        $location_ajeno = 'http://169.254.169.254/latest/meta-data/senuelo.webp';

        Http::fake([
            $src => Http::response('', 302, ['Location' => $location_ajeno]),
            $location_ajeno => Http::response($this->webpDePrueba(), 200, ['Content-Type' => 'image/webp']),
        ]);

        $respuesta = $this->pedir($src);

        $respuesta->assertStatus(302);
        $respuesta->assertRedirect($src);
        Http::assertSentCount(1, 'una sola request -al original- nunca se sigue el Location ajeno: si se siguiera, esto daria 200 con el jpeg del señuelo, no 302');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Tope de tamaño: no bufferear un origen que manda -o miente que va a mandar- de mas
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 Antes este test (y el siguiente) usaban contenido BASURA como body fake ('poca cosa',
     * 9 bytes): esa basura ya fallaba pareceWebpCompleto() ANTES de que el corte por tamaño
     * entrara en juego, asi que si alguien sacara el corte por tamaño del codigo real, el test
     * seguia en verde igual -por el motivo EQUIVOCADO, el gate de formato- (chequeo independiente
     * del 28/9/2026). Con webpGrandeDePrueba() -un webp REAL y VALIDO de mas de TOPE_BYTES- sacar
     * el corte por tamaño hace que esto decodifique bien y devuelva 200, no 302: recien ahi el
     * 302 de este test prueba lo que dice probar.
     */
    public function test_un_content_length_declarado_mas_grande_que_el_tope_no_descarga_nada_mas()
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('Este PHP no tiene soporte GD para webp.');
        }

        $binario = $this->webpGrandeDePrueba();
        $this->assertTrue(
            SeoImagenCompartir::pareceWebpCompleto($binario),
            'el fixture tiene que pasar el gate de formato: si no, este test prueba ese gate, no el corte por tamaño'
        );

        $src = 'https://api-imgtest.comerciocity.com/public/storage/gigante.webp';
        Http::fake([
            // El Content-Length MENTIDO es mas grande incluso que el binario real -asi el corte
            // por header tiene que disparar solo, sin que haga falta leer nada del body.
            $src => Http::response($binario, 200, ['Content-Type' => 'image/webp', 'Content-Length' => '999999999']),
        ]);

        $respuesta = $this->pedir($src);

        $respuesta->assertStatus(302);
        $respuesta->assertRedirect($src);
    }

    /** Ver el docblock del test anterior: mismo motivo, mismo arreglo. */
    public function test_un_body_mas_grande_que_el_tope_corta_sin_agotar_memoria()
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('Este PHP no tiene soporte GD para webp.');
        }

        $binario = $this->webpGrandeDePrueba();
        $this->assertTrue(
            SeoImagenCompartir::pareceWebpCompleto($binario),
            'el fixture tiene que pasar el gate de formato: si no, este test prueba ese gate, no el corte por tamaño'
        );
        $this->assertGreaterThan(
            SeoImagenCompartir::TOPE_BYTES,
            strlen($binario),
            'el fixture tiene que superar el tope de verdad -si no, esto no ejercita el loop de lectura'
        );

        $src = 'https://api-imgtest.comerciocity.com/public/storage/body-gigante.webp';
        Http::fake([
            // Sin Content-Length -Http::response() no lo calcula solo (GuzzleHttp\Psr7\Response
            // no deriva el header del body: confirmado leyendo su constructor)-: el chequeo por
            // header no dispara, y lo que corta el body es el loop de leerConTope() leyendo de a
            // bloques, que es lo que este test tiene que ejercitar.
            $src => Http::response($binario, 200, ['Content-Type' => 'image/webp']),
        ]);

        $respuesta = $this->pedir($src);

        $respuesta->assertStatus(302);
        $respuesta->assertRedirect($src);
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

    /**
     * 🔴 El caso que pareceWebpCompleto() NO agarra: contenedor RIFF consistente, bitstream
     * corrupto adentro. Ese primer fatal de GD es imposible de probar en un test sin arriesgar
     * tirar abajo la corrida ENTERA (confirmado con un script standalone fuera de PHPUnit, ver
     * el docblock de decodificarWebp()) -asi que lo que se prueba aca es el circuit breaker con
     * la marca "rota" YA ARMADA, como si un pedido anterior a esta MISMA imagen ya hubiera
     * fataleado: el pedido siguiente tiene que ir derecho al fallback, sin ni intentar la
     * descarga.
     */
    public function test_una_imagen_marcada_rota_por_el_circuit_breaker_cae_al_fallback_sin_descargar()
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('Este PHP no tiene soporte GD para webp.');
        }

        $src = 'https://api-imgtest.comerciocity.com/public/storage/bitstream-corrupto.webp';
        $binario = $this->webpConBitstreamCorrupto();

        /* Sin esto el caso no prueba nada especifico del circuit breaker: si el fixture no
           pasara pareceWebpCompleto(), ya estaria cubierto por el test del webp corrupto de
           arriba. */
        $this->assertTrue(
            SeoImagenCompartir::pareceWebpCompleto($binario),
            'el fixture tiene que pasar el gate de estructura para que este test pruebe el circuit breaker y no otra cosa'
        );

        Cache::put(SeoImagenCompartir::claveRoto($src), true, 3600);

        Http::fake([
            'api-imgtest.comerciocity.com/*' => Http::response($binario, 200, ['Content-Type' => 'image/webp']),
        ]);

        $respuesta = $this->pedir($src);

        $respuesta->assertStatus(302);
        $respuesta->assertRedirect($src);
        Http::assertNothingSent('el circuit breaker corta ANTES de la descarga, no solo antes del decode');
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
