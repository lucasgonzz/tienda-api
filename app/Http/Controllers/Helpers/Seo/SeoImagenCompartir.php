<?php

namespace App\Http\Controllers\Helpers\Seo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * GET /api/seo/imagen-compartir?src=<url-absoluta> (mision og-image-webp-whatsapp, 28/9/2026).
 *
 * Convierte a JPEG, con cache a disco, la imagen que SeoContexto::paraCompartir() manda cuando
 * el og:image/twitter:image de una ficha o promocion es un .webp: WhatsApp -sobre todo
 * Android- no siempre lo decodifica para armar el preview al compartir un link, aunque el
 * archivo sea valido y cualquier navegador lo abra sin drama.
 *
 * 🔴 `src` es un query PUBLICO: antes de pedirle nada a nadie se valida con origenPermitido()
 * contra un allowlist de host (parse_url(), nunca un regex sobre la URL completa). Y el
 * fallback es SIEMPRE un redirect 302 al `$src` original -ya validado-: si GD no tiene soporte
 * webp, si la descarga falla o tarda, si el origen manda mas de la cuenta, o si lo descargado
 * no decodifica como webp, esto tiene que devolver lo mismo que el comprador ve HOY (sin
 * conversion), nunca un 500 ni una respuesta rota.
 *
 * 🔴 SSRF, tres capas (chequeo independiente del 28/9/2026, los tres casos reales que encontro):
 *   1. El allowlist de host de origenPermitido() -que ademas exige el PATH exacto para
 *      Cloudinary, no solo el host: `res.cloudinary.com` es el CDN compartido de cualquiera con
 *      una cuenta gratis, no un host exclusivo de ComercioCity.
 *   2. `withoutRedirecting()` en la descarga: sin esto, un host que SI esta en el allowlist
 *      pero responde un 3xx corre el allowlist entero -Guzzle sigue el redirect solo, hacia
 *      donde sea (una IP interna, el metadata de una nube), sin volver a validar nada.
 *   3. Un tope de bytes leyendo con `stream => true` (leerConTope()): sin esto, un origen que
 *      manda -o miente que va a mandar- un archivo gigante hace que cada pedido bufferee eso
 *      entero en memoria antes de llegar siquiera a mirar si es un webp valido.
 *
 * 🔴 Y aparte del SSRF, un circuit breaker (segundo chequeo independiente del 28/9/2026): un
 * webp con el CONTENEDOR bien formado pero el bitstream de adentro corrupto pasa
 * pareceWebpCompleto() y hace fatalear a GD igual -no hay forma de evitar ESE primer fatal sin
 * aislar en un subproceso, ver el motivo en pareceWebpCompleto(). decodificarWebp() arma un
 * `register_shutdown_function()` para que, si eso pasa, la imagen quede marcada "rota" y los
 * pedidos siguientes a ESA MISMA imagen vayan directo al fallback sin volver a arriesgarse.
 */
class SeoImagenCompartir
{
    /** Calidad del JPEG de salida (mismo criterio que ImageAssignment\CandidateImageProcessor
     *  de empresa-api). */
    const CALIDAD_JPEG = 85;

    /** Segundos de conexion antes de abandonar la descarga del origen. */
    const TIMEOUT_CONEXION = 3;

    /** Segundos totales antes de abandonar la descarga del origen. */
    const TIMEOUT_TOTAL = 6;

    /** Cache-Control de la respuesta: 30 dias. A diferencia del cache de pagina de seo.php (6 h,
     *  porque el precio y el stock cambian), el nombre del archivo -o su hash- ya es la clave y
     *  el contenido bajo esa clave nunca cambia. */
    const MAX_AGE = 2592000;

    /** Techo de bytes que se acepta descargar: unos pocos MB de sobra para una foto de articulo.
     *  Frena un origen que manda -o miente, via Content-Length, que va a mandar- un archivo
     *  gigante: sin esto cada pedido bufferea eso entero en memoria antes de mirar si es un
     *  webp valido siquiera. */
    const TOPE_BYTES = 8388608; // 8 MB

    /** Cuanto queda marcada "rota" una imagen que hizo fatalear a GD, antes de volver a
     *  arriesgarla: ni permanente (por si el origen se corrige) ni tan corto que un crawler
     *  insistente dispare el mismo fatal de nuevo en minutos. Mismo orden de magnitud que el
     *  cache del sitemap (SeoController::CACHE_DEL_SITEMAP). */
    const TTL_ROTO = 3600;

    const REGEX_HOST_COMERCIOCITY = '/(^|\.)comerciocity\.com$/i';

    /**
     * ¿Es seguro pedirle esta URL a un servidor externo? SIEMPRE con parse_url() sobre el host
     * solo -nunca un regex contra la URL completa, que un host como `comerciocity.com.evil.com`
     * burla- y solo dos caminos:
     *   - `https` + host `*.comerciocity.com` (el storage propio de cada comercio).
     *   - El prefijo EXACTO de la cuenta de Cloudinary de ComercioCity
     *     (`SeoContexto::CLOUDINARY`), no solo el host `res.cloudinary.com`: ese host es el CDN
     *     COMPARTIDO de cualquiera con una cuenta gratis de Cloudinary (multi-tenant por path,
     *     `res.cloudinary.com/<cloud_name>/...`), no algo exclusivo de ComercioCity -chequeo
     *     independiente del 28/9/2026, el segundo de los tres hallazgos reales.
     *
     * @param  string|null  $src
     * @return bool
     */
    static function origenPermitido($src)
    {
        if (!is_string($src) || trim($src) === '') {
            return false;
        }

        if (str_starts_with($src, SeoContexto::CLOUDINARY)) {
            return true;
        }

        if (parse_url($src, PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        $host = parse_url($src, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return preg_match(self::REGEX_HOST_COMERCIOCITY, $host) === 1;
    }

    /**
     * La respuesta HTTP para un `$src` YA VALIDADO por origenPermitido(): el JPEG (de cache o
     * recien convertido) o, ante cualquier falla, un redirect 302 al original.
     *
     * Nada de lo que pasa aca adentro puede terminar en una excepcion sin atrapar: el contrato
     * completo con el controller es "esto nunca tira un 500".
     *
     * @param  string  $src
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    static function responder($src)
    {
        try {
            $ruta = self::rutaDeCache($src);

            if (Storage::exists($ruta)) {
                $cacheado = Storage::get($ruta);
                if (is_string($cacheado) && $cacheado !== '') {
                    return self::respuestaJpeg($cacheado);
                }
                /* Cache vacio o corrupto (raro, pero no imposible): se reconvierte como si no
                   hubiera nada, en vez de servir una imagen rota. */
            }

            $jpeg = self::convertir($src);
            if (is_null($jpeg)) {
                return self::redirectAlOriginal($src);
            }

            Storage::put($ruta, $jpeg);

            return self::respuestaJpeg($jpeg);
        } catch (\Throwable $e) {
            Log::warning('SeoImagenCompartir: fallback al original por una excepcion.', [
                'src' => $src, 'error' => $e->getMessage(),
            ]);
            return self::redirectAlOriginal($src);
        }
    }

    /**
     * Descarga `$src` y lo convierte a JPEG, o null ante CUALQUIER falla (GD sin soporte webp,
     * imagen ya marcada rota, descarga que no llega, timeout, mas bytes de la cuenta, webp
     * corrupto): el llamador cae al redirect.
     *
     * @param  string  $src
     * @return string|null  Los bytes del JPEG.
     */
    private static function convertir($src)
    {
        if (!function_exists('imagecreatefromwebp')) {
            return null;
        }

        /* Circuit breaker (ver decodificarWebp()): si un pedido anterior a esta MISMA imagen ya
           hizo fatalear a GD, ni se intenta de nuevo -directo al fallback, sin gastar ni la
           descarga. */
        if (Cache::has(self::claveRoto($src))) {
            return null;
        }

        try {
            $respuesta = Http::timeout(self::TIMEOUT_TOTAL)
                ->connectTimeout(self::TIMEOUT_CONEXION)
                ->withOptions(['stream' => true])
                ->withoutRedirecting()
                ->get($src);
        } catch (\Throwable $e) {
            Log::warning('SeoImagenCompartir: no se pudo descargar el origen.', ['src' => $src, 'error' => $e->getMessage()]);
            return null;
        }

        if (!$respuesta->successful()) {
            return null;
        }

        $binario = self::leerConTope($respuesta);
        if (is_null($binario)) {
            return null;
        }

        $origen = self::decodificarWebp($binario, $src);
        if (is_null($origen)) {
            return null;
        }

        return self::aplanarYCodificar($origen);
    }

    /**
     * Lee el body de a bloques y corta apenas se pasa de TOPE_BYTES, sin llegar a acumular un
     * archivo entero en memoria si el origen manda -o miente que va a mandar, via
     * Content-Length- mas de la cuenta. Con `stream => true` puesto en la request, Guzzle
     * todavia no leyo nada del body en este punto: recien ahora se empieza a bajar.
     *
     * @param  \Illuminate\Http\Client\Response  $respuesta
     * @return string|null
     */
    private static function leerConTope($respuesta)
    {
        $largo_declarado = $respuesta->header('Content-Length');
        if (is_numeric($largo_declarado) && (int) $largo_declarado > self::TOPE_BYTES) {
            return null;
        }

        $stream = null;
        try {
            $stream = $respuesta->toPsrResponse()->getBody();
            $binario = '';
            while (!$stream->eof()) {
                $binario .= $stream->read(65536);
                if (strlen($binario) > self::TOPE_BYTES) {
                    return null;
                }
            }
            return $binario;
        } catch (\Throwable $e) {
            Log::warning('SeoImagenCompartir: fallo leyendo el body del origen.', ['error' => $e->getMessage()]);
            return null;
        } finally {
            if ($stream !== null) {
                try {
                    $stream->close();
                } catch (\Throwable $e) {
                    // Nada mas para hacer: ya se leyo o se corto, cerrar es prolijidad.
                }
            }
        }
    }

    /**
     * imagecreatefromwebp() pide un archivo, no un string: se vuelca a un temporal y se borra
     * apenas se decodifica (haya salido bien o no). Antes de llamarlo, pareceWebpCompleto()
     * -ver el motivo ahi- descarta lo que ni vale la pena intentar.
     *
     * 🔴 Circuit breaker para lo que pareceWebpCompleto() NO puede agarrar: un webp con el
     * contenedor RIFF perfectamente consistente (tamaño declarado = tamaño real) pero el
     * bitstream de adentro corrupto -medido con un segundo chequeo independiente el 28/9/2026:
     * getimagesizefromstring() lo reconoce como webp valido igual, y imagecreatefromwebp()
     * fatalea lo mismo. Nada en PHP evita ESTE primer fatal sin aislar en un subproceso (ver el
     * motivo en pareceWebpCompleto()), pero lo que SI se puede evitar es que CADA pedido
     * siguiente a esta MISMA imagen repita el mismo fatal:
     *   1. Se arma un `register_shutdown_function()` ANTES de arriesgar la llamada.
     *   2. Si `imagecreatefromwebp()` vuelve -bien o mal, no importa- se marca `$desarmado` y el
     *      shutdown handler, cuando corra (corre SIEMPRE, tambien en el camino feliz), no hace
     *      nada.
     *   3. Si el proceso termina sin haber llegado a desarmarlo es porque fataleo ahi adentro:
     *      el shutdown handler lo detecta con error_get_last() y cachea "esta imagen esta rota"
     *      por TTL_ROTO segundos (convertir() la chequea ANTES de siquiera descargar).
     *
     * Confirmado con un script standalone -fuera de PHPUnit, para no arriesgar la corrida
     * entera-: un register_shutdown_function() SI llega a correr despues de este fatal
     * especifico, en el GD de esta maquina.
     *
     * @param  string  $binario
     * @param  string  $src  Para la clave del circuit breaker si esto fatalea.
     * @return \GdImage|resource|null
     */
    private static function decodificarWebp($binario, $src)
    {
        if (!self::pareceWebpCompleto($binario)) {
            return null;
        }

        $temporal = tempnam(sys_get_temp_dir(), 'seowebp');
        if ($temporal === false) {
            return null;
        }

        $clave_rota = self::claveRoto($src);
        $desarmado = false;
        register_shutdown_function(function () use ($src, $clave_rota, &$desarmado) {
            if ($desarmado) {
                return;
            }
            try {
                $error = error_get_last();
                if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
                    Cache::put($clave_rota, true, self::TTL_ROTO);
                    Log::warning('SeoImagenCompartir: circuit breaker armado tras un fatal de GD decodificando.', ['src' => $src]);
                }
            } catch (\Throwable $e) {
                // El shutdown handler es la ULTIMA linea de codigo que corre: si esto tira, el
                // proximo pedido a esta imagen vuelve a arriesgar el mismo fatal -no es peor que
                // no tener circuit breaker, no vale la pena complicar mas esto.
            }
        });

        try {
            file_put_contents($temporal, $binario);
            $origen = @imagecreatefromwebp($temporal);
            $desarmado = true;
            return $origen !== false ? $origen : null;
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * La clave de cache del circuit breaker (decodificarWebp()). No privada a proposito: el
     * test que reproduce el bitstream corrupto necesita pre-armarla sin arriesgar el fatal real.
     *
     * @param  string  $src
     * @return string
     */
    static function claveRoto($src)
    {
        return 'seo:imagen-compartir:rota:'.md5($src);
    }

    /**
     * 🔴 Valida la ESTRUCTURA de `$binario` antes de arriesgar un imagecreatefromwebp(). Medido
     * en esta misma maquina (GD del php8.3.6 de wamp): un webp truncado o que directamente no es
     * un RIFF/WEBP NO SIEMPRE vuelve `false` como cualquier otro imagecreatefrom*() -"gd-webp
     * cannot get webp info" y "gd-webp cannot allocate temporary buffer" salen como FATAL error
     * de PHP, no warning, y ni el `@` ni un try/catch(\Throwable) los para: tiran abajo el
     * proceso entero (el worker que esta sirviendo ESE request, o la corrida de tests entera).
     * Por eso el filtro tiene que pasar ANTES, con funciones que no puedan crashear:
     *
     *   1. getimagesizefromstring() lo reconoce como webp -agarra la basura lisa y llana (un
     *      403 en HTML, un archivo vacio, cualquier cosa que no sea webp) sin arriesgar nada:
     *      esta familia de funciones esta hecha para sondear datos no confiables.
     *   2. El tamaño que el propio contenedor RIFF declara (bytes 4 a 7, entero de 32 bits poco
     *      endian) coincide con lo que realmente se termino de descargar -agarra el truncado por
     *      timeout o conexion cortada, que el chequeo 1 solo NO agarra: el header puede ser
     *      perfectamente valido y el resto faltar igual.
     *
     * No es una garantia matematica contra CUALQUIER bitstream interno corrupto (para eso haria
     * falta correr la decodificacion en un subproceso aislado, y `proc_open`/`exec` suelen venir
     * deshabilitados en el shared hosting donde vive tienda-api -romper la conversion en todos
     * lados por blindarla del todo en ninguno no es negocio). Cubre los dos casos reales que se
     * reprodujeron ahi: contenido que no es webp, y descarga cortada a mitad.
     *
     * 🔴 Lo que este chequeo NO agarra -un RIFF perfectamente consistente por fuera pero con el
     * bitstream corrupto por dentro- lo cubre el circuit breaker de decodificarWebp(): no evita
     * el primer fatal (nada lo evita sin el subproceso de arriba), pero evita que se repita en
     * cada pedido siguiente a la MISMA imagen.
     *
     * @param  string  $binario
     * @return bool
     */
    static function pareceWebpCompleto($binario)
    {
        if (!is_string($binario) || strlen($binario) < 12) {
            return false;
        }

        $info = @getimagesizefromstring($binario);
        if ($info === false || ($info[2] ?? null) !== IMAGETYPE_WEBP) {
            return false;
        }

        if (substr($binario, 0, 4) !== 'RIFF' || substr($binario, 8, 4) !== 'WEBP') {
            return false;
        }
        $declarado = unpack('V', substr($binario, 4, 4))[1];

        return strlen($binario) >= 8 + $declarado;
    }

    /**
     * Aplana sobre fondo BLANCO (un webp con canal alfa da colores rotos al pasarlo a jpeg, que
     * no tiene alfa) y codifica. Mismo patron que
     * ImageAssignment\CandidateImageProcessor::miniatura_para_ia() de empresa-api:
     * imagecreatetruecolor() + imagecolorallocate() blanco + imagefilledrectangle() +
     * imagealphablending(true) + imagecopyresampled() -aca sin reescalar, al tamaño original.
     *
     * @param  \GdImage|resource  $origen
     * @return string|null  Los bytes del JPEG.
     */
    private static function aplanarYCodificar($origen)
    {
        $ancho = imagesx($origen);
        $alto  = imagesy($origen);

        $destino = imagecreatetruecolor($ancho, $alto);
        $blanco  = imagecolorallocate($destino, 255, 255, 255);
        imagefilledrectangle($destino, 0, 0, $ancho - 1, $alto - 1, $blanco);
        imagealphablending($destino, true);
        imagecopyresampled($destino, $origen, 0, 0, 0, 0, $ancho, $alto, $ancho, $alto);

        ob_start();
        $ok = imagejpeg($destino, null, self::CALIDAD_JPEG);
        $jpeg = ob_get_clean();

        imagedestroy($destino);
        imagedestroy($origen);

        if (!$ok || !is_string($jpeg) || $jpeg === '') {
            return null;
        }
        return $jpeg;
    }

    /**
     * La clave de cache: hash de la URL completa, no el nombre de archivo solo -Cloudinary arma
     * el suyo con `/` adentro del public id, y eso no es un nombre de archivo valido tal cual.
     *
     * @param  string  $src
     * @return string
     */
    private static function rutaDeCache($src)
    {
        return 'seo/imagen-compartir/'.md5($src).'.jpg';
    }

    /**
     * @param  string  $jpeg
     * @return \Illuminate\Http\Response
     */
    private static function respuestaJpeg($jpeg)
    {
        return response($jpeg, 200, [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'public, max-age='.self::MAX_AGE,
        ]);
    }

    /**
     * @param  string  $src
     * @return \Illuminate\Http\RedirectResponse
     */
    private static function redirectAlOriginal($src)
    {
        return redirect()->away($src, 302);
    }
}
