<?php

namespace App\Http\Controllers\Helpers\Seo;

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
 * webp, si la descarga falla o tarda, o si lo descargado no decodifica como webp, esto tiene
 * que devolver lo mismo que el comprador ve HOY (sin conversion), nunca un 500 ni una
 * respuesta rota.
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

    /**
     * Los unicos dos hosts que puede haber armado SeoContexto::absolutizarImagen(): el storage
     * propio de cada comercio (api-<slug>.comerciocity.com) y Cloudinary. Cualquier otro host
     * se rechaza antes de pedir nada.
     */
    const HOST_CLOUDINARY = 'res.cloudinary.com';

    const REGEX_HOST_COMERCIOCITY = '/(^|\.)comerciocity\.com$/i';

    /**
     * ¿Es seguro pedirle esta URL a un servidor externo? `https` nada mas, y el host tiene que
     * matchear el allowlist. SIEMPRE con parse_url() sobre el host solo -nunca un regex contra
     * la URL completa, que un host como `comerciocity.com.evil.com` burla.
     *
     * @param  string|null  $src
     * @return bool
     */
    static function origenPermitido($src)
    {
        if (!is_string($src) || trim($src) === '') {
            return false;
        }
        if (parse_url($src, PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        $host = parse_url($src, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return preg_match(self::REGEX_HOST_COMERCIOCITY, $host) === 1 || strcasecmp($host, self::HOST_CLOUDINARY) === 0;
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
     * descarga que no llega, timeout, webp corrupto): el llamador cae al redirect.
     *
     * @param  string  $src
     * @return string|null  Los bytes del JPEG.
     */
    private static function convertir($src)
    {
        if (!function_exists('imagecreatefromwebp')) {
            return null;
        }

        try {
            $respuesta = Http::timeout(self::TIMEOUT_TOTAL)->connectTimeout(self::TIMEOUT_CONEXION)->get($src);
        } catch (\Throwable $e) {
            Log::warning('SeoImagenCompartir: no se pudo descargar el origen.', ['src' => $src, 'error' => $e->getMessage()]);
            return null;
        }

        if (!$respuesta->successful()) {
            return null;
        }

        $origen = self::decodificarWebp($respuesta->body());
        if (is_null($origen)) {
            return null;
        }

        return self::aplanarYCodificar($origen);
    }

    /**
     * imagecreatefromwebp() pide un archivo, no un string: se vuelca a un temporal y se borra
     * apenas se decodifica (haya salido bien o no). Antes de llamarlo, pareceWebpCompleto()
     * -ver el motivo ahi- descarta lo que ni vale la pena intentar.
     *
     * @param  string  $binario
     * @return \GdImage|resource|null
     */
    private static function decodificarWebp($binario)
    {
        if (!self::pareceWebpCompleto($binario)) {
            return null;
        }

        $temporal = tempnam(sys_get_temp_dir(), 'seowebp');
        if ($temporal === false) {
            return null;
        }

        try {
            file_put_contents($temporal, $binario);
            $origen = @imagecreatefromwebp($temporal);
            return $origen !== false ? $origen : null;
        } finally {
            @unlink($temporal);
        }
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
