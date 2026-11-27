<?php

namespace Tests\Feature\Seo;

use App\Http\Controllers\Helpers\Seo\SeoImagenCompartir;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mision og-imagen-android (26/11/2026): la imagen de la vista previa queda convertida ANTES de
 * que el telefono la pida.
 *
 * Problema: la primera conversion cuesta 1-2 s (medido en el hosting de Truvari). Si la paga el
 * primer pedido de la imagen, es el telefono de Android que esta armando la vista previa, y
 * WhatsApp abandona rapido. Solucion: al servir la pagina de una ficha (`/api/seo/pagina/{id}`,
 * que seo.php pide cuando alguien abre el producto), la API registra un callback `terminating`
 * que convierte la imagen DESPUES de responder. El que comparte casi siempre abrio la ficha
 * antes en su navegador.
 *
 * 🔴 Guarda: sin `fastcgi_finish_request`/`litespeed_finish_request` el callback correria ANTES
 * de cerrar la conexion y seo.php (que espera a la API con un tope de 2 s) servirian el index
 * sin og:image: peor que no precalentar. En los tests `runningUnitTests()` lo habilita, y
 * `SeoImagenCompartir::$forzar_cierre_anticipado = false` simula el hosting sin cierre anticipado.
 */
class PrecalentarImagenCompartirTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaTiendaParaSeo;

    /** Imagen de articulo de prueba, en un host permitido. */
    const SRC = 'https://api-imgtest.comerciocity.com/public/storage/malbec.webp';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->armarComercio();
    }

    protected function tearDown(): void
    {
        /* Propiedad estatica: no puede quedar forzada para el resto de la suite. */
        if (property_exists(SeoImagenCompartir::class, 'forzar_cierre_anticipado')) {
            SeoImagenCompartir::$forzar_cierre_anticipado = null;
        }
        parent::tearDown();
    }

    /**
     * Un webp real de prueba.
     *
     * @return string
     */
    private function webp()
    {
        $imagen = imagecreatetruecolor(300, 200);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 200, 30, 30));
        ob_start();
        imagewebp($imagen, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($imagen);

        return $bytes;
    }

    /** Falsea el origen con el webp de prueba. */
    private function origenFalseado()
    {
        Http::fake([self::SRC => Http::response($this->webp(), 200, ['Content-Type' => 'image/webp'])]);
    }

    /**
     * Un articulo online con la imagen webp de prueba, y la ruta de su ficha.
     *
     * @return string
     */
    private function fichaConImagen()
    {
        $articulo = $this->articulo('Malbec Precalentado');
        $this->imagen($articulo, self::SRC);

        return $this->rutaDe($articulo);
    }

    /**
     * Ruta de la ficha del articulo (la misma que arma la SPA).
     *
     * @param  \App\Article  $articulo
     * @return string
     */
    private function rutaDe($articulo)
    {
        return '/articulos/'.$articulo->slug.'/'.$this->comercio->id;
    }

    /** Ruta de la imagen convertida en el cache a disco. */
    private function rutaDeCache()
    {
        return 'seo/imagen-compartir/v3/'.md5(self::SRC).'.jpg';
    }

    /** URL nueva esperada para SRC. */
    private function urlNueva()
    {
        return url('/api/seo/imagen-compartir/'.rtrim(strtr(base64_encode(self::SRC), '+/', '-_'), '=').'.jpg');
    }

    /**
     * CA21: pedir la pagina de la ficha deja la imagen convertida en el cache al terminar el
     * request (una sola descarga), y el pedido posterior de la imagen responde 200 sin volver a
     * descargar (Http::assertSentCount(1) en total).
     */
    public function test_la_ficha_deja_la_imagen_convertida()
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('Este PHP no tiene soporte GD para webp.');
        }
        $this->origenFalseado();
        $ruta = $this->fichaConImagen();

        $pagina = $this->pagina($ruta);

        $this->assertSame($this->urlNueva(), $pagina['imagen']);
        Storage::assertExists($this->rutaDeCache());
        Http::assertSentCount(1);

        $this->get($pagina['imagen'])->assertStatus(200)->assertHeader('Content-Type', 'image/jpeg');
        Http::assertSentCount(1);
    }

    /**
     * CA22: sin cierre anticipado de la conexion NO se precalienta (el callback correria antes
     * de responder y sumaria su tiempo al tope de 2 s de seo.php): no se escribe nada ni se
     * descarga, y el JSON de la pagina es el mismo.
     */
    public function test_sin_cierre_anticipado_no_precalienta()
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('Este PHP no tiene soporte GD para webp.');
        }
        $this->origenFalseado();
        $ruta = $this->fichaConImagen();

        SeoImagenCompartir::$forzar_cierre_anticipado = false;
        $sin_precalentar = $this->pagina($ruta);

        $this->assertSame($this->urlNueva(), $sin_precalentar['imagen']);
        $this->assertEmpty(Storage::allFiles('seo/imagen-compartir'), 'no se escribio nada');
        Http::assertNothingSent();

        SeoImagenCompartir::$forzar_cierre_anticipado = null;
        $precalentando = $this->pagina($ruta);

        $this->assertSame($precalentando, $sin_precalentar, 'la respuesta JSON es identica con y sin precalentado');
    }

    /** CA23: si la imagen ya esta en el cache, la pagina no descarga nada. */
    public function test_cacheada_no_descarga()
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('Este PHP no tiene soporte GD para webp.');
        }
        $this->origenFalseado();
        $ruta = $this->fichaConImagen();

        $imagen = imagecreatetruecolor(1200, 630);
        ob_start();
        imagejpeg($imagen, null, 82);
        Storage::put($this->rutaDeCache(), ob_get_clean());

        $pagina = $this->pagina($ruta);

        $this->assertSame($this->urlNueva(), $pagina['imagen']);
        Http::assertNothingSent();
    }

    /** CA23: si la imagen esta marcada rota (circuit breaker), la pagina no la descarga. */
    public function test_rota_no_descarga()
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('Este PHP no tiene soporte GD para webp.');
        }
        $this->origenFalseado();
        $ruta = $this->fichaConImagen();
        Cache::put(SeoImagenCompartir::claveRoto(self::SRC), true, 3600);

        $pagina = $this->pagina($ruta);

        $this->assertSame($this->urlNueva(), $pagina['imagen']);
        Http::assertNothingSent();
    }

    /**
     * CA20: un archivo de cache v3 truncado (sin el marcador final `FF D9`) no se sirve: se
     * reconvierte y la respuesta es un JPEG completo 1200x630. Tampoco quedan temporales
     * `*.tmp-*`: la escritura es atomica (archivo temporal + rename).
     */
    public function test_cache_truncado_se_reconvierte_sin_dejar_temporales()
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('Este PHP no tiene soporte GD para webp.');
        }
        $this->origenFalseado();

        $entero = imagecreatetruecolor(1200, 630);
        ob_start();
        imagejpeg($entero, null, 82);
        $jpeg = ob_get_clean();
        $truncado = substr($jpeg, 0, intdiv(strlen($jpeg), 2));
        $this->assertNotSame("\xFF\xD9", substr($truncado, -2), 'el fixture esta truncado');
        Storage::put($this->rutaDeCache(), $truncado);

        $respuesta = $this->get($this->urlNueva());

        $respuesta->assertStatus(200);
        $bytes = $respuesta->getContent();
        $this->assertSame("\xFF\xD9", substr($bytes, -2), 'JPEG completo');
        $medidas = getimagesizefromstring($bytes);
        $this->assertSame([1200, 630], [$medidas[0], $medidas[1]]);
        Http::assertSentCount(1);

        foreach (Storage::allFiles('seo/imagen-compartir/v3') as $archivo) {
            $this->assertStringNotContainsString('.tmp-', $archivo, 'no queda ningun temporal');
        }
        $this->assertSame($bytes, Storage::get($this->rutaDeCache()), 'el cache quedo con el JPEG completo');
    }
}
