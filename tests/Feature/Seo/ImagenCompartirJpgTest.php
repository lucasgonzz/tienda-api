<?php

namespace Tests\Feature\Seo;

use App\Http\Controllers\Helpers\Seo\SeoContexto;
use App\Http\Controllers\Helpers\Seo\SeoImagenCompartir;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GET /api/seo/imagen-compartir/{token}.jpg: la ruta nueva del conversor (mision
 * og-imagen-android, 26/11/2026).
 *
 * Por que existe: WhatsApp en Android arma la vista previa desde el telefono y es mas exigente
 * que iPhone. La ruta vieja (`?src=<url codificada>`) no termina en extension, lleva `%3A%2F%2F`
 * en el query, respondia chunked (sin Content-Length), sin medidas fijas y con la conversion en
 * frio a cargo del primer pedido. La ruta nueva es una URL limpia que termina en `.jpg` y
 * entrega SIEMPRE un JPEG 1200x630, baseline, de hasta 150 KB, con su largo declarado.
 *
 * Igual que ImagenCompartirTest: no toca la base, `Http::fake()` en todo (nada sale a la red) y
 * `Storage::fake('local')` para no ensuciar storage/app ni arrastrar cache entre corridas.
 *
 * 🔴 Lo que esta clase clava y no se puede aflojar:
 *   - El token es sOLO otra codificacion del `src`: se valida con el MISMO allowlist
 *     (origenPermitido()), sin copia, y los mismos topes (sin seguir redirects, 8 MB, 16 MP).
 *   - La respuesta nunca abre sesion (sin Set-Cookie) aunque el pedido venga con el Referer de
 *     la tienda, y declara Content-Length, Accept-Ranges y cache `immutable`.
 *   - Lo que no se puede convertir cae SIEMPRE a un 302 al original: nunca un 500.
 */
class ImagenCompartirJpgTest extends TestCase
{
    /** Origen permitido de prueba (host *.comerciocity.com). */
    const ORIGEN = 'https://api-imgtest.comerciocity.com/public/storage/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Token base64url (sin relleno, `-` y `_`) del `src`, calculado a mano: no usa el codigo
     * bajo prueba.
     *
     * @param  string  $src
     * @return string
     */
    private function token($src)
    {
        return rtrim(strtr(base64_encode($src), '+/', '-_'), '=');
    }

    /**
     * Pide la imagen por la ruta nueva.
     *
     * @param  string  $src
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedirToken($src)
    {
        return $this->get('/api/seo/imagen-compartir/'.$this->token($src).'.jpg');
    }

    /**
     * Pide la imagen por la ruta vieja (`?src=`).
     *
     * @param  string  $src
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedirVieja($src)
    {
        return $this->get('/api/seo/imagen-compartir?src='.urlencode($src));
    }

    /**
     * Bytes de una imagen GD en el formato pedido.
     *
     * @param  \GdImage|resource  $imagen
     * @param  string  $formato  jpeg | png | gif | webp | bmp
     * @return string
     */
    private function bytesDe($imagen, $formato)
    {
        ob_start();
        switch ($formato) {
            case 'png':
                imagesavealpha($imagen, true);
                imagepng($imagen);
                break;
            case 'gif':
                imagegif($imagen);
                break;
            case 'webp':
                imagewebp($imagen, null, 80);
                break;
            case 'bmp':
                imagebmp($imagen);
                break;
            default:
                imagejpeg($imagen, null, 90);
        }
        $bytes = ob_get_clean();
        imagedestroy($imagen);

        return $bytes;
    }

    /**
     * Imagen de prueba con la mitad superior roja y la inferior blanca (sobre fondo transparente
     * para png), en el formato pedido.
     *
     * @param  string  $formato
     * @param  int  $ancho
     * @param  int  $alto
     * @return string
     */
    private function imagen($formato, $ancho, $alto)
    {
        $imagen = imagecreatetruecolor($ancho, $alto);
        if ($formato === 'png') {
            imagealphablending($imagen, false);
            imagefill($imagen, 0, 0, imagecolorallocatealpha($imagen, 0, 0, 0, 127));
        } else {
            imagefill($imagen, 0, 0, imagecolorallocate($imagen, 255, 255, 255));
        }
        $rojo = imagecolorallocate($imagen, 200, 30, 30);
        imagefilledrectangle($imagen, 0, 0, $ancho - 1, intdiv($alto, 2), $rojo);

        return $this->bytesDe($imagen, $formato);
    }

    /**
     * Ruido puro de 1080x1080 entregado como JPEG calidad 95: el peor caso de peso. Se arma con
     * el truco del BMP de ImagenCompartirTest::webpGrandeDePrueba() (bytes al azar que GD
     * decodifica en C, mucho mas rapido que imagesetpixel() pixel por pixel).
     *
     * @return string
     */
    private function ruidoPuro()
    {
        $lado = 1080;
        $fila = intdiv($lado * 3 + 3, 4) * 4;
        $relleno = $fila - $lado * 3;
        $pixeles = $fila * $lado;

        $bmp = pack('a2VvvV', 'BM', 14 + 40 + $pixeles, 0, 0, 14 + 40)
            .pack('VVVvvVVVVVV', 40, $lado, $lado, 1, 24, 0, $pixeles, 2835, 2835, 0, 0);
        for ($y = 0; $y < $lado; $y++) {
            $bmp .= random_bytes($lado * 3).str_repeat("\0", $relleno);
        }

        $imagen = imagecreatefromstring($bmp);
        $this->assertNotFalse($imagen, 'el fixture BMP de ruido tiene que decodificar');
        ob_start();
        imagejpeg($imagen, null, 95);
        $jpeg = ob_get_clean();
        imagedestroy($imagen);

        return $jpeg;
    }

    /**
     * Falsea la descarga del origen con `$cuerpo`.
     *
     * @param  string  $src
     * @param  string  $cuerpo
     * @param  string  $tipo
     * @return void
     */
    private function origenDevuelve($src, $cuerpo, $tipo = 'image/png')
    {
        Http::fake([$src => Http::response($cuerpo, 200, ['Content-Type' => $tipo])]);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA3: token largo cae a la ruta vieja
    |---------------------------------------------------------------------------------------------
    */

    /**
     * CA3: un token de mas de 200 caracteres (hay Apache/LiteSpeed que devuelven 403 con segmentos
     * de path mayores a 255) NO sale por la ruta nueva: sale por la vieja `?src=`, que sigue
     * andando exactamente como hoy.
     */
    public function test_token_largo_cae_a_la_ruta_vieja()
    {
        $src = SeoContexto::CLOUDINARY.'carpeta/'.str_repeat('a', 160).'.png';
        $this->assertGreaterThan(200, strlen($this->token($src)), 'el fixture tiene que pasar el limite');

        $this->assertSame(
            url('/api/seo/imagen-compartir').'?src='.rawurlencode($src),
            SeoContexto::paraCompartir($src)
        );
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA5: la ruta nueva y la vieja sirven lo mismo
    |---------------------------------------------------------------------------------------------
    */

    public function test_la_ruta_nueva_y_la_vieja_sirven_los_mismos_bytes()
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('Este PHP no tiene soporte GD para webp.');
        }

        $src = self::ORIGEN.'malbec.webp';
        $this->origenDevuelve($src, $this->imagen('webp', 800, 600), 'image/webp');

        $nueva = $this->pedirToken($src);
        $nueva->assertStatus(200);
        $nueva->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame("\xFF\xD8\xFF", substr($nueva->getContent(), 0, 3));

        $vieja = $this->pedirVieja($src);
        $vieja->assertStatus(200);

        $this->assertSame($nueva->getContent(), $vieja->getContent(), 'mismos bytes por las dos rutas');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA6, CA7, CA8: seguridad (las mismas garantias que la ruta vieja)
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Tokens que la ruta nueva tiene que rechazar con 400: el `src` decodificado no pasa el
     * allowlist (host ajeno, sufijo, http, cloud_name ajeno de Cloudinary) o el token ni
     * siquiera decodifica (`A`: base64 estricto invalido).
     *
     * @return array
     */
    public function tokensNoPermitidos()
    {
        $codificar = function ($src) {
            return rtrim(strtr(base64_encode($src), '+/', '-_'), '=');
        };

        return [
            'host ajeno' => [$codificar('https://evil.com/x.webp')],
            'sufijo de comerciocity' => [$codificar('https://comerciocity.com.evil.com/x.webp')],
            'http sin s' => [$codificar('http://api-x.comerciocity.com/x.webp')],
            'cloudinary de otra cuenta' => [$codificar('https://res.cloudinary.com/otra-cuenta/image/upload/x.webp')],
            'token que no decodifica' => ['A'],
        ];
    }

    /**
     * CA6.
     *
     * @dataProvider tokensNoPermitidos
     */
    public function test_token_no_permitido_da_400($token)
    {
        Http::fake();

        $respuesta = $this->getJson('/api/seo/imagen-compartir/'.$token.'.jpg');

        $respuesta->assertStatus(400);
        $respuesta->assertJson(['message' => 'src invalido o con un host no permitido.']);
        Http::assertNothingSent();
    }

    /**
     * CA6: un segmento con caracteres fuera de `[A-Za-z0-9_-]` ni matchea la ruta: 404 de ruta.
     * Antes se comprueba que un token bueno SI matchea (si no, el 404 seria el de "la ruta no
     * existe" y este test pasaria por el motivo equivocado).
     */
    public function test_un_path_con_caracteres_fuera_del_alfabeto_es_404()
    {
        $src = self::ORIGEN.'ok.png';
        $this->origenDevuelve($src, $this->imagen('png', 100, 100));
        $this->pedirToken($src)->assertStatus(200);

        $this->get('/api/seo/imagen-compartir/ab@c.jpg')->assertStatus(404);
    }

    /** CA7: un 3xx del origen no se sigue (mismo fixture que la ruta vieja). */
    public function test_redirect_del_origen_no_se_sigue_por_token()
    {
        $src = self::ORIGEN.'con-redirect.png';
        $ajeno = 'http://169.254.169.254/latest/meta-data/senuelo.png';
        Http::fake([
            $src => Http::response('', 302, ['Location' => $ajeno]),
            $ajeno => Http::response($this->imagen('png', 100, 100), 200, ['Content-Type' => 'image/png']),
        ]);

        $respuesta = $this->pedirToken($src);

        $respuesta->assertStatus(302);
        $respuesta->assertRedirect($src);
        Http::assertSentCount(1);
    }

    /** CA7: Content-Length declarado mayor al tope (8 MB): 302 sin leer el cuerpo. */
    public function test_content_length_mayor_al_tope_por_token()
    {
        $src = self::ORIGEN.'gigante.png';
        Http::fake([
            $src => Http::response($this->imagen('png', 100, 100), 200, ['Content-Type' => 'image/png', 'Content-Length' => '999999999']),
        ]);

        $respuesta = $this->pedirToken($src);

        $respuesta->assertStatus(302);
        $respuesta->assertRedirect($src);
    }

    /**
     * CA8: un PNG que en su cabecera declara 5000x5000 (25 MP, mas que el tope de 16 MP) no se
     * decodifica: GD pediria ~100 MB y un fatal de memoria no se puede atrapar. 302 al original.
     * El fixture es la firma PNG + el chunk IHDR (unos 33 bytes) y nada mas.
     */
    public function test_png_de_25_megapixeles_no_se_decodifica()
    {
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 5000, 5000, 8, 6, 0, 0, 0).pack('N', 0);
        $medidas = getimagesizefromstring($png);
        $this->assertSame([5000, 5000], [$medidas[0], $medidas[1]], 'el fixture tiene que declarar 5000x5000');

        $src = self::ORIGEN.'enorme.png';
        $this->origenDevuelve($src, $png);

        $respuesta = $this->pedirToken($src);

        $respuesta->assertStatus(302);
        $respuesta->assertRedirect($src);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA9, CA10, CA11, CA12: formato de salida
    |---------------------------------------------------------------------------------------------
    */

    /**
     * CA9: "contain" centrado. 1500x1000 -> 945x630 en x=127: margenes laterales blancos, la
     * mitad superior (roja) adentro del producto y la inferior (blanca) tambien.
     */
    public function test_lienzo_contain_centrado()
    {
        $src = self::ORIGEN.'apaisada.png';
        $this->origenDevuelve($src, $this->imagen('jpeg', 1500, 1000), 'image/jpeg');

        $respuesta = $this->pedirToken($src);
        $respuesta->assertStatus(200);

        $medidas = getimagesizefromstring($respuesta->getContent());
        $this->assertSame([1200, 630, IMAGETYPE_JPEG], [$medidas[0], $medidas[1], $medidas[2]]);

        $lienzo = imagecreatefromstring($respuesta->getContent());
        $izquierda = imagecolorsforindex($lienzo, imagecolorat($lienzo, 5, 100));
        $producto = imagecolorsforindex($lienzo, imagecolorat($lienzo, 600, 100));
        $this->assertGreaterThan(240, $izquierda['green'], 'el margen izquierdo es blanco aunque la fila sea roja en el producto');
        $this->assertGreaterThan(150, $producto['red']);
        $this->assertLessThan(80, $producto['green'], 'el producto entero esta adentro del lienzo');
    }

    /**
     * Formatos de entrada que tienen que salir en el mismo lienzo: jpeg, png con transparencia y gif.
     *
     * @return array
     */
    public function formatosDeEntrada()
    {
        return [
            'jpeg' => ['jpeg', 'image/jpeg'],
            'png con transparencia' => ['png', 'image/png'],
            'gif' => ['gif', 'image/gif'],
        ];
    }

    /**
     * CA10: JPEG, PNG con transparencia y GIF tambien salen 1200x630 JPEG; la transparencia del
     * PNG queda blanca (esquina (0,0) > 240).
     *
     * @dataProvider formatosDeEntrada
     */
    public function test_jpeg_png_y_gif_salen_en_el_lienzo($formato, $tipo)
    {
        $src = self::ORIGEN.'entrada.'.$formato;
        $this->origenDevuelve($src, $this->imagen($formato, 400, 300), $tipo);

        $respuesta = $this->pedirToken($src);

        $respuesta->assertStatus(200);
        $respuesta->assertHeader('Content-Type', 'image/jpeg');
        $medidas = getimagesizefromstring($respuesta->getContent());
        $this->assertSame([1200, 630, IMAGETYPE_JPEG], [$medidas[0], $medidas[1], $medidas[2]]);

        $lienzo = imagecreatefromstring($respuesta->getContent());
        $esquina = imagecolorsforindex($lienzo, imagecolorat($lienzo, 0, 0));
        $this->assertGreaterThan(240, $esquina['red']);
        $this->assertGreaterThan(240, $esquina['green']);
        $this->assertGreaterThan(240, $esquina['blue']);
    }

    /**
     * CA11: JPEG baseline (marcador SOF0 `FF C0`, sin SOF2 `FF C2` progresivo) y sin perfil ICC.
     * Un JPEG progresivo o con perfil de color raro es lo que algunos visores de Android no
     * muestran en una miniatura.
     */
    public function test_jpeg_baseline_sin_icc()
    {
        $src = self::ORIGEN.'baseline.png';
        $this->origenDevuelve($src, $this->imagen('png', 400, 300));

        $respuesta = $this->pedirToken($src);
        $respuesta->assertStatus(200);
        $bytes = $respuesta->getContent();

        $this->assertSame("\xFF\xD8\xFF", substr($bytes, 0, 3));
        $this->assertStringContainsString("\xFF\xC0", $bytes, 'SOF0: baseline');
        $this->assertStringNotContainsString("\xFF\xC2", $bytes, 'sin SOF2: no es progresivo');
        $this->assertStringNotContainsString('ICC_PROFILE', $bytes);
    }

    /**
     * CA12: el peor caso (ruido puro 1080x1080) entra en 150 KB; una imagen lisa sale a la
     * calidad mas alta (82): mas grande que la misma imagen recodificada a calidad 72.
     */
    public function test_ruido_puro_no_pasa_de_150_kb()
    {
        $src = self::ORIGEN.'ruido.jpg';
        $ruido = $this->ruidoPuro();
        $this->assertGreaterThan(SeoImagenCompartir::TOPE_BYTES / 8, strlen($ruido), 'el fixture es de verdad pesado');
        $this->origenDevuelve($src, $ruido, 'image/jpeg');

        $respuesta = $this->pedirToken($src);

        $respuesta->assertStatus(200);
        $this->assertLessThanOrEqual(153600, strlen($respuesta->getContent()), 'el peor caso pesa a lo sumo 150 KB');
        $medidas = getimagesizefromstring($respuesta->getContent());
        $this->assertSame([1200, 630], [$medidas[0], $medidas[1]]);
    }

    /** CA12 (el otro extremo): una foto "liviana" no se degrada: sale a calidad 82. */
    public function test_una_imagen_liviana_sale_a_calidad_82()
    {
        $imagen = imagecreatetruecolor(600, 400);
        for ($y = 0; $y < 400; $y++) {
            for ($x = 0; $x < 600; $x += 3) {
                $color = imagecolorallocate($imagen, ($x / 3 + $y) % 256, ($y * 2) % 256, (($x + $y) * 3 + (($x * $y) % 17)) % 256);
                imagefilledrectangle($imagen, $x, $y, $x + 2, $y, $color);
            }
        }
        $src = self::ORIGEN.'liviana.jpg';
        $this->origenDevuelve($src, $this->bytesDe($imagen, 'jpeg'), 'image/jpeg');

        $respuesta = $this->pedirToken($src);
        $respuesta->assertStatus(200);
        $bytes = $respuesta->getContent();
        $this->assertLessThanOrEqual(153600, strlen($bytes));

        /* Si hubiera salido a q72 o menos, recodificar lo decodificado a q82 daria algo
           claramente mas grande; a q82 da (casi) lo mismo. */
        $decodificada = imagecreatefromstring($bytes);
        ob_start();
        imagejpeg($decodificada, null, 72);
        $a_72 = ob_get_clean();
        $this->assertGreaterThan(strlen($a_72), strlen($bytes), 'salio mas grande que la misma imagen a q72: calidad 82');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA13, CA14: formatos que no se convierten, y Accept
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Lo que el origen entrega y no se puede (o no se debe) convertir.
     *
     * @return array
     */
    public function entradasQueCaenAlOriginal()
    {
        return [
            'avif' => ["\x00\x00\x00\x1cftypavif\x00\x00\x00\x00avifmif1miaf".str_repeat("\x00", 40), 'image/avif'],
            'bmp' => [null, 'image/bmp'],
            'basura' => ['esto no es una imagen', 'text/html'],
        ];
    }

    /**
     * CA13: AVIF (GD de este hosting no lo decodifica), BMP y basura caen al 302 del original.
     *
     * @dataProvider entradasQueCaenAlOriginal
     */
    public function test_formatos_no_soportados_o_corruptos_caen_al_original($cuerpo, $tipo)
    {
        if ($cuerpo === null) {
            $cuerpo = $this->bytesDe(imagecreatetruecolor(50, 50), 'bmp');
        }
        $src = self::ORIGEN.'rara.bin';
        $this->origenDevuelve($src, $cuerpo, $tipo);

        $respuesta = $this->pedirToken($src);

        $respuesta->assertStatus(302);
        $respuesta->assertRedirect($src);
    }

    /**
     * CA13: un JPEG truncado a la mitad y un PNG con header pero sin datos: 302 o 200 valido,
     * nunca un 500.
     */
    public function test_jpeg_y_png_corruptos_nunca_dan_500()
    {
        $jpeg = $this->imagen('jpeg', 400, 300);
        $png_sin_datos = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 40, 40, 8, 6, 0, 0, 0).pack('N', 0);

        foreach ([
            'jpeg-truncado.jpg' => substr($jpeg, 0, intdiv(strlen($jpeg), 2)),
            'png-sin-datos.png' => $png_sin_datos,
        ] as $nombre => $cuerpo) {
            $src = self::ORIGEN.$nombre;
            $this->origenDevuelve($src, $cuerpo);

            $respuesta = $this->pedirToken($src);

            $this->assertContains($respuesta->getStatusCode(), [200, 302], $nombre.': nunca un 500');
            if ($respuesta->getStatusCode() === 302) {
                $respuesta->assertRedirect($src);
            } else {
                $medidas = getimagesizefromstring($respuesta->getContent());
                $this->assertSame([1200, 630], [$medidas[0], $medidas[1]]);
            }
        }
    }

    /**
     * CA14: la descarga al origen manda un `Accept` que incluye image/jpeg y NO avif: Cloudinary
     * con `f_auto` decide el formato por ese header y GD de este hosting no decodifica avif.
     */
    public function test_pide_jpeg_al_origen_y_no_avif()
    {
        $src = self::ORIGEN.'accept.png';
        $this->origenDevuelve($src, $this->imagen('png', 100, 100));

        $this->pedirToken($src)->assertStatus(200);

        Http::assertSent(function ($request) {
            $accept = implode(',', $request->header('Accept'));

            return strpos($accept, 'image/jpeg') !== false && stripos($accept, 'avif') === false;
        });
    }

    /*
    |---------------------------------------------------------------------------------------------
    | CA15, CA16, CA17, CA18: respuesta HTTP
    |---------------------------------------------------------------------------------------------
    */

    /** CA15: Content-Length real, Content-Type, cache `immutable` y Accept-Ranges. */
    public function test_headers_de_la_respuesta()
    {
        $src = self::ORIGEN.'headers.png';
        $this->origenDevuelve($src, $this->imagen('png', 400, 300));

        $respuesta = $this->pedirToken($src);

        $respuesta->assertStatus(200);
        $respuesta->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame((string) strlen($respuesta->getContent()), $respuesta->headers->get('Content-Length'));
        $cache_control = $respuesta->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache_control);
        $this->assertStringContainsString('max-age=2592000', $cache_control);
        $this->assertStringContainsString('immutable', $cache_control);
        $respuesta->assertHeader('Accept-Ranges', 'bytes');
    }

    /**
     * CA15: aunque el pedido venga con el Referer/Origin de la tienda (un dominio "stateful" de
     * Sanctum), la respuesta no abre sesion ni manda cookies: una imagen publica con Set-Cookie
     * no es cacheable por los proxies y WhatsApp la trata distinto. Vale para las dos rutas.
     */
    public function test_sin_cookies_aunque_venga_de_la_tienda()
    {
        $src = self::ORIGEN.'cookies.png';
        $this->origenDevuelve($src, $this->imagen('png', 400, 300));
        $desde_la_tienda = ['Referer' => 'http://localhost/', 'Origin' => 'http://localhost'];

        $nueva = $this->withHeaders($desde_la_tienda)->get('/api/seo/imagen-compartir/'.$this->token($src).'.jpg');
        $nueva->assertStatus(200);
        $this->assertEmpty($nueva->headers->getCookies(), 'la ruta nueva no manda Set-Cookie');
        $this->assertFalse($nueva->headers->has('Set-Cookie'));

        $vieja = $this->withHeaders($desde_la_tienda)->get('/api/seo/imagen-compartir?src='.urlencode($src));
        $vieja->assertStatus(200);
        $this->assertEmpty($vieja->headers->getCookies(), 'la ruta vieja tampoco');
        $this->assertFalse($vieja->headers->has('Set-Cookie'));
    }

    /** CA16: HEAD trae 200, el mismo Content-Length que el GET y ningun cuerpo. */
    public function test_head_trae_content_length_sin_cuerpo()
    {
        $src = self::ORIGEN.'head.png';
        $this->origenDevuelve($src, $this->imagen('png', 400, 300));
        $url = '/api/seo/imagen-compartir/'.$this->token($src).'.jpg';

        $get = $this->get($url);
        $get->assertStatus(200);

        $head = $this->call('HEAD', $url);

        $head->assertStatus(200);
        $this->assertSame('', (string) $head->getContent());
        $this->assertSame($get->headers->get('Content-Length'), $head->headers->get('Content-Length'));
        $this->assertNotEmpty($head->headers->get('Content-Length'));
    }

    /**
     * Pedidos con Range y lo que corresponde: rango simple satisfacible -> 206; rango multiple o
     * insatisfacible -> 200 con el archivo entero (RFC 9110 permite ignorar Range).
     *
     * @return array
     */
    public function rangos()
    {
        return [
            'primeros 100' => ['bytes=0-99', 206, 0, 100],
            'ultimos 10' => ['bytes=-10', 206, -10, 10],
            'multiple se ignora' => ['bytes=0-1,5-9', 200, null, null],
            'insatisfacible se ignora' => ['bytes=999999999-', 200, null, null],
        ];
    }

    /**
     * CA17.
     *
     * @dataProvider rangos
     */
    public function test_range_simple($range, $estado, $desde, $largo)
    {
        $src = self::ORIGEN.'range.png';
        $this->origenDevuelve($src, $this->imagen('png', 400, 300));
        $url = '/api/seo/imagen-compartir/'.$this->token($src).'.jpg';

        $entero = $this->get($url)->getContent();
        $total = strlen($entero);

        $respuesta = $this->withHeaders(['Range' => $range])->get($url);

        $respuesta->assertStatus($estado);
        if ($estado === 206) {
            $inicio = $desde < 0 ? $total + $desde : $desde;
            $fin = $inicio + $largo - 1;
            $respuesta->assertHeader('Content-Range', 'bytes '.$inicio.'-'.$fin.'/'.$total);
            $respuesta->assertHeader('Content-Length', (string) $largo);
            $this->assertSame(substr($entero, $inicio, $largo), $respuesta->getContent());
        } else {
            $this->assertSame($entero, $respuesta->getContent(), 'el archivo entero');
            $this->assertFalse($respuesta->headers->has('Content-Range'));
        }
    }

    /**
     * CA18: el crawler de Meta/WhatsApp usa pocas IP compartidas y repite pedidos (HEAD + GET,
     * reintentos): 150 pedidos seguidos de una imagen ya cacheada no pueden dar 429.
     */
    public function test_150_pedidos_no_dan_429()
    {
        $src = self::ORIGEN.'muchos.png';
        $this->origenDevuelve($src, $this->imagen('png', 200, 150));
        $url = '/api/seo/imagen-compartir/'.$this->token($src).'.jpg';

        for ($i = 1; $i <= 150; $i++) {
            $estado = $this->get($url)->getStatusCode();
            $this->assertSame(200, $estado, 'pedido '.$i);
        }
    }
}
