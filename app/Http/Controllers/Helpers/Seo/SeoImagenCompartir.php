<?php

namespace App\Http\Controllers\Helpers\Seo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * GET /api/seo/imagen-compartir/{token}.jpg y GET /api/seo/imagen-compartir?src=<url-absoluta>
 * (mision og-image-webp-whatsapp, 28/9/2026; reescrita en og-imagen-android, 26/11/2026).
 *
 * Convierte a JPEG, con cache a disco, la imagen que SeoContexto::paraCompartir() manda como
 * og:image/twitter:image de una pagina: WhatsApp -sobre todo Android- no siempre decodifica
 * webp, ni una imagen de forma/peso impredecibles, para armar el preview al compartir un link,
 * aunque el archivo sea valido y cualquier navegador lo abra sin drama.
 *
 * 🔴 og-imagen-android: el JPEG de salida es IDENTICO EN FORMA para cualquier origen (lienzo
 * fijo 1200x630, baseline, hasta 150 KB, con Content-Length) y se sirve desde una URL limpia que
 * termina en `.jpg` (token base64url del `src`, sin `?` ni `%`). Asi se declaran medidas
 * verdaderas en el HTML y se descartan de una vez las hipotesis de Android: URL sin extension,
 * respuesta chunked, medidas no declaradas, peso y proporcion, latencia en frio. La ruta vieja
 * `?src=` sigue viva (paginas cacheadas 6 h por seo.php y previews ya generadas) y comparte
 * responder(): tambien sirve el formato nuevo.
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
    /** Ancho fijo, en px, del lienzo de salida (relacion 1.91:1 que piden WhatsApp y Facebook). */
    const ANCHO_SALIDA = 1200;

    /** Alto fijo, en px, del lienzo de salida. */
    const ALTO_SALIDA = 630;

    /** Escalera de calidades JPEG: se usa la primera que deja el archivo en PESO_OBJETIVO. */
    const CALIDADES_JPEG = [82, 72, 62, 50];

    /** Peso objetivo del JPEG, en bytes (150 KB): una miniatura de preview no necesita mas y un
     *  archivo mas liviano llega antes en una conexion movil. Si ninguna calidad lo logra se
     *  sirve la mas baja: nunca se falla por peso. */
    const PESO_OBJETIVO = 153600;

    /** Tope de pixeles (ancho x alto) que se acepta decodificar: 16 MP son ~64 MB en GD
     *  truecolor. Se mide con getimagesizefromstring() ANTES de decodificar, porque un archivo
     *  chico en bytes puede declarar decenas de megapixeles y un fatal de memoria no se atrapa. */
    const TOPE_PIXELES = 16000000;

    /** Version de la cache a disco: se sube cuando cambia lo que se guarda (v3: lienzo fijo
     *  1200x630; lo convertido a v2, proporcional, no se sirve mas). */
    const VERSION_CACHE = 'v3';

    /** Largo maximo del token en la URL nueva: hay Apache/LiteSpeed que devuelven 403 con un
     *  segmento de path de mas de 255 bytes. Un token mas largo sale por la ruta vieja `?src=`. */
    const LARGO_MAXIMO_TOKEN = 200;

    /** Header Accept con el que se descarga del origen: Cloudinary con `f_auto` decide el formato
     *  por este header y nunca elige avif (GD de este hosting no lo decodifica). */
    const ACCEPT_ORIGEN = 'image/jpeg,image/png;q=0.9,image/webp;q=0.8';

    /** Para tests y operacion: null = automatico; true/false fuerza si el precalentado puede
     *  correr despues de responder (ver puedeTrabajarDespuesDeResponder()).
     *  @var bool|null */
    public static $forzar_cierre_anticipado = null;

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

            $cacheado = self::leerCacheValido($ruta);
            if (!is_null($cacheado)) {
                return self::respuestaJpeg($cacheado);
            }
            /* Cache ausente, vacio o a medio escribir: se reconvierte como si no hubiera nada,
               en vez de servir una imagen rota. */

            $jpeg = self::convertir($src);
            if (is_null($jpeg)) {
                return self::redirectAlOriginal($src);
            }

            self::guardarAtomico($ruta, $jpeg);

            return self::respuestaJpeg($jpeg);
        } catch (\Throwable $e) {
            Log::warning('SeoImagenCompartir: fallback al original por una excepcion.', [
                'src' => $src, 'error' => $e->getMessage(),
            ]);
            return self::redirectAlOriginal($src);
        }
    }

    /**
     * Codifica un `src` en el token de la URL nueva: base64url (`-` y `_` en lugar de `+` y `/`)
     * sin relleno `=`. Es SOLO otra forma de escribir el mismo `src`: lo que se decodifica pasa
     * por el mismo allowlist (origenPermitido()), no hay firma ni estado.
     *
     * @param  string  $src
     * @return string
     */
    static function tokenDe($src)
    {
        return rtrim(strtr(base64_encode($src), '+/', '-_'), '=');
    }

    /**
     * Decodifica el token de la URL nueva. Estricto: devuelve null si no es base64 valido o si lo
     * decodificado no es texto UTF-8 sin caracteres de control (un `src` real es una URL).
     *
     * @param  string  $token
     * @return string|null
     */
    static function srcDeToken($token)
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        $src = base64_decode(strtr($token, '-_', '+/'), true);
        if (!is_string($src) || $src === '') {
            return null;
        }
        if (!mb_check_encoding($src, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $src)) {
            return null;
        }

        return $src;
    }

    /**
     * La URL (absoluta) por la que se sirve el JPEG de `$src`: la ruta nueva `/<token>.jpg` o, si
     * el token pasa de LARGO_MAXIMO_TOKEN, la vieja `?src=` (que sigue andando como siempre).
     *
     * @param  string  $src  Ya validado por origenPermitido().
     * @return string
     */
    static function urlParaCompartir($src)
    {
        $token = self::tokenDe($src);

        if (strlen($token) > self::LARGO_MAXIMO_TOKEN) {
            return url('/api/seo/imagen-compartir').'?src='.rawurlencode($src);
        }

        return url('/api/seo/imagen-compartir/'.$token.'.jpg');
    }

    /**
     * Operacion inversa de urlParaCompartir(): el `src` que esconde una URL del conversor (de la
     * ruta nueva o de la vieja), o null si `$url` no es una URL del conversor.
     *
     * @param  string|null  $url
     * @return string|null
     */
    static function srcDeUrlParaCompartir($url)
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        if (preg_match('#/api/seo/imagen-compartir/([A-Za-z0-9_-]+)\.jpg$#', $path, $coincidencia)) {
            return self::srcDeToken($coincidencia[1]);
        }

        if (preg_match('#/api/seo/imagen-compartir$#', $path)) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            return isset($query['src']) && is_string($query['src']) ? $query['src'] : null;
        }

        return null;
    }

    /**
     * ¿Puede el servidor seguir trabajando despues de haberle entregado la respuesta al cliente?
     * Sin `fastcgi_finish_request` ni `litespeed_finish_request`, un callback `terminating`
     * correria ANTES de cerrar la conexion: seo.php espera a la API con un tope de 2 s y, si se
     * pasa, sirve el index sin og:image (peor que no precalentar).
     *
     * @return bool
     */
    static function puedeTrabajarDespuesDeResponder()
    {
        if (!is_null(self::$forzar_cierre_anticipado)) {
            return (bool) self::$forzar_cierre_anticipado;
        }

        return function_exists('fastcgi_finish_request')
            || function_exists('litespeed_finish_request')
            || app()->runningUnitTests();
    }

    /**
     * Registra el precalentado de la imagen de una pagina para DESPUES de responder: la primera
     * conversion cuesta 1-2 s y, si la paga el primer pedido de la imagen, es el telefono que
     * esta armando la vista previa. El que comparte casi siempre abrio la ficha antes, y esa
     * apertura pasa por aca. No hace nada si la imagen no es del conversor o si el servidor no
     * puede trabajar despues de responder.
     *
     * @param  string|null  $url_imagen  El campo `imagen` de la pagina.
     * @return void
     */
    static function programarPrecalentado($url_imagen)
    {
        if (!self::puedeTrabajarDespuesDeResponder()) {
            return;
        }

        $src = self::srcDeUrlParaCompartir($url_imagen);
        if (is_null($src)) {
            return;
        }

        app()->terminating(function () use ($src) {
            self::precalentar($src);
        });
    }

    /**
     * Convierte y guarda la imagen en el cache si todavia no esta (ni esta marcada rota). Nunca
     * propaga una excepcion: corre despues de responder y no puede romper nada.
     *
     * @param  string  $src
     * @return void
     */
    static function precalentar($src)
    {
        try {
            if (!self::origenPermitido($src)) {
                return;
            }

            $ruta = self::rutaDeCache($src);
            if (!is_null(self::leerCacheValido($ruta)) || Cache::has(self::claveRoto($src))) {
                return;
            }

            $jpeg = self::convertir($src);
            if (!is_null($jpeg)) {
                self::guardarAtomico($ruta, $jpeg);
            }
        } catch (\Throwable $e) {
            Log::warning('SeoImagenCompartir: fallo el precalentado.', ['src' => $src, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Lee el JPEG cacheado y lo devuelve solo si esta completo (empieza con FF D8 FF y termina
     * con FF D9). Un archivo a medio escribir o corrupto da null y se reconvierte.
     *
     * @param  string  $ruta
     * @return string|null
     */
    private static function leerCacheValido($ruta)
    {
        if (!Storage::exists($ruta)) {
            return null;
        }

        $cacheado = Storage::get($ruta);
        if (!is_string($cacheado) || strlen($cacheado) < 5) {
            return null;
        }
        if (substr($cacheado, 0, 3) !== "\xFF\xD8\xFF" || substr($cacheado, -2) !== "\xFF\xD9") {
            return null;
        }

        return $cacheado;
    }

    /**
     * Escribe el JPEG en el cache de forma atomica: primero a un temporal en la misma carpeta y
     * despues `move` (rename en el disco local) a la ruta final. Un pedido concurrente nunca lee
     * un archivo a medio escribir.
     *
     * @param  string  $ruta
     * @param  string  $jpeg
     * @return void
     */
    private static function guardarAtomico($ruta, $jpeg)
    {
        $temporal = $ruta.'.tmp-'.str_replace('.', '', uniqid('', true));

        try {
            Storage::put($temporal, $jpeg);
            Storage::move($temporal, $ruta);
        } catch (\Throwable $e) {
            /* Si el rename falla (disco raro), no se deja basura y se intenta la escritura directa. */
            try {
                Storage::delete($temporal);
            } catch (\Throwable $ignorada) {
                // Nada mas para hacer.
            }
            Storage::put($ruta, $jpeg);
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
        /* Circuit breaker (ver decodificarWebp()): si un pedido anterior a esta MISMA imagen ya
           hizo fatalear a GD, ni se intenta de nuevo -directo al fallback, sin gastar ni la
           descarga. */
        if (Cache::has(self::claveRoto($src))) {
            return null;
        }

        try {
            $respuesta = Http::timeout(self::TIMEOUT_TOTAL)
                ->connectTimeout(self::TIMEOUT_CONEXION)
                ->withHeaders(['Accept' => self::ACCEPT_ORIGEN])
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

        $origen = self::decodificar($binario, $src);
        if (is_null($origen)) {
            return null;
        }

        return self::aplanarYCodificar($origen);
    }

    /**
     * Decodifica `$binario` segun lo que DICEN LOS BYTES (no la extension ni el Content-Type, que
     * un origen puede mentir o que Cloudinary decide por Accept): webp, jpeg, png o gif. Cualquier
     * otra cosa (avif, bmp, basura) o una imagen que declara mas de TOPE_PIXELES da null y el
     * llamador cae al redirect.
     *
     * getimagesizefromstring() esta hecha para sondear datos no confiables y no puede crashear:
     * las medidas se miran ANTES de decodificar nada.
     *
     * @param  string  $binario
     * @param  string  $src  Para la clave del circuit breaker de webp.
     * @return \GdImage|resource|null
     */
    private static function decodificar($binario, $src)
    {
        $info = @getimagesizefromstring($binario);
        if ($info === false) {
            return null;
        }

        $ancho = (int) $info[0];
        $alto = (int) $info[1];
        if ($ancho < 1 || $alto < 1 || $ancho * $alto > self::TOPE_PIXELES) {
            return null;
        }

        switch ($info[2]) {
            case IMAGETYPE_WEBP:
                /* Camino de siempre, intacto: gate de estructura + circuit breaker. */
                if (!function_exists('imagecreatefromwebp')) {
                    return null;
                }
                return self::decodificarWebp($binario, $src);

            case IMAGETYPE_JPEG:
            case IMAGETYPE_PNG:
            case IMAGETYPE_GIF:
                $origen = @imagecreatefromstring($binario);
                return $origen !== false ? $origen : null;

            default:
                return null;
        }
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
     * Dibuja la imagen entera ("contain", sin recortar) y centrada sobre un lienzo BLANCO fijo de
     * ANCHO_SALIDA x ALTO_SALIDA, y la codifica como JPEG baseline. El blanco aplana la
     * transparencia (jpeg no tiene alfa: sin aplanar, el RGB(0,0,0) de base se cuela como negro).
     * Se agranda lo chico para llenar el lienzo: una miniatura de 20x10 sale 1200x600.
     *
     * Por que un lienzo fijo y no una reduccion proporcional: con la salida proporcional un
     * producto de 1080x1080 salia 1080x1080 y no se podian declarar `og:image:width/height`
     * verdaderos; con medidas fijas se declaran de antemano y todas las imagenes son iguales de
     * previsibles para el cliente que arma la vista previa.
     *
     * La calidad baja por una escalera (CALIDADES_JPEG) hasta entrar en PESO_OBJETIVO; si ninguna
     * entra se sirve la mas baja. `imageinterlace(false)` fuerza baseline (el progresivo es una
     * de las cosas que algunos visores de Android no muestran en una miniatura); GD no escribe
     * perfil ICC (medido).
     *
     * @param  \GdImage|resource  $origen
     * @return string|null  Los bytes del JPEG.
     */
    private static function aplanarYCodificar($origen)
    {
        $ancho_origen = imagesx($origen);
        $alto_origen  = imagesy($origen);

        /* Escala "contain": el producto entero adentro del lienzo, por el lado que mas aprieta. */
        $escala = min(self::ANCHO_SALIDA / $ancho_origen, self::ALTO_SALIDA / $alto_origen);
        $ancho  = max(1, min(self::ANCHO_SALIDA, (int) round($ancho_origen * $escala)));
        $alto   = max(1, min(self::ALTO_SALIDA, (int) round($alto_origen * $escala)));

        /* Centrado en el lienzo. */
        $desde_x = intdiv(self::ANCHO_SALIDA - $ancho, 2);
        $desde_y = intdiv(self::ALTO_SALIDA - $alto, 2);

        $lienzo = imagecreatetruecolor(self::ANCHO_SALIDA, self::ALTO_SALIDA);
        $blanco = imagecolorallocate($lienzo, 255, 255, 255);
        imagefilledrectangle($lienzo, 0, 0, self::ANCHO_SALIDA - 1, self::ALTO_SALIDA - 1, $blanco);
        imagealphablending($lienzo, true);
        imagecopyresampled($lienzo, $origen, $desde_x, $desde_y, 0, 0, $ancho, $alto, $ancho_origen, $alto_origen);
        imagedestroy($origen);

        imageinterlace($lienzo, false);

        $jpeg = null;
        foreach (self::CALIDADES_JPEG as $calidad) {
            ob_start();
            $ok = imagejpeg($lienzo, null, $calidad);
            $jpeg = ob_get_clean();

            if (!$ok || !is_string($jpeg) || $jpeg === '') {
                $jpeg = null;
                break;
            }
            if (strlen($jpeg) <= self::PESO_OBJETIVO) {
                break;
            }
        }

        imagedestroy($lienzo);

        return $jpeg;
    }

    /**
     * La clave de cache: hash de la URL completa, no el nombre de archivo solo -Cloudinary arma
     * el suyo con `/` adentro del public id, y eso no es un nombre de archivo valido tal cual.
     * Va dentro de una carpeta de version (VERSION_CACHE): los JPEG viejos (v2, proporcionales)
     * quedan en su carpeta y ya no se sirven.
     *
     * @param  string  $src
     * @return string
     */
    private static function rutaDeCache($src)
    {
        return 'seo/imagen-compartir/'.self::VERSION_CACHE.'/'.md5($src).'.jpg';
    }

    /**
     * La respuesta 200 con el JPEG y todos los headers que una vista previa agradece: largo
     * declarado (sin Content-Length LiteSpeed/h2 responde chunked), cache de 30 dias `immutable`
     * (la clave de cache es el hash de la URL: el contenido bajo esa clave no cambia),
     * Accept-Ranges, `inline` y `nosniff`. Sin Set-Cookie. Si el pedido trae un `Range` simple
     * satisfacible, responde 206 con ese tramo (ver responderConRango()).
     *
     * @param  string  $jpeg
     * @return \Illuminate\Http\Response
     */
    private static function respuestaJpeg($jpeg)
    {
        $headers = [
            'Content-Type'           => 'image/jpeg',
            'Content-Length'         => (string) strlen($jpeg),
            'Cache-Control'          => 'public, max-age='.self::MAX_AGE.', immutable',
            'Accept-Ranges'          => 'bytes',
            'Content-Disposition'    => 'inline; filename="imagen.jpg"',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $tramo = self::tramoPedido(request()->header('Range'), strlen($jpeg));
        if (!is_null($tramo)) {
            list($desde, $hasta) = $tramo;
            $headers['Content-Range'] = 'bytes '.$desde.'-'.$hasta.'/'.strlen($jpeg);
            $headers['Content-Length'] = (string) ($hasta - $desde + 1);

            return response(substr($jpeg, $desde, $hasta - $desde + 1), 206, $headers);
        }

        return response($jpeg, 200, $headers);
    }

    /**
     * El tramo `[desde, hasta]` (inclusive) que pide un header `Range`, o null si hay que
     * responder el archivo entero. Solo se atiende UN rango simple satisfacible (`bytes=a-b`,
     * `bytes=a-`, `bytes=-n`); uno multiple, malformado o insatisfacible se IGNORA y se manda el
     * archivo entero con 200 (RFC 9110 permite ignorar Range). Algunos clientes de imagenes de
     * Android piden por rangos.
     *
     * @param  string|null  $range
     * @param  int  $total  Largo del archivo en bytes.
     * @return array|null
     */
    private static function tramoPedido($range, $total)
    {
        if (!is_string($range) || $total < 1) {
            return null;
        }
        if (!preg_match('/^\s*bytes=(\d*)-(\d*)\s*$/i', $range, $partes)) {
            return null;
        }

        list(, $inicio, $fin) = $partes;
        if ($inicio === '' && $fin === '') {
            return null;
        }

        if ($inicio === '') {
            /* `bytes=-n`: los ultimos n bytes. */
            $ultimos = (int) $fin;
            if ($ultimos < 1) {
                return null;
            }
            return [max(0, $total - $ultimos), $total - 1];
        }

        $desde = (int) $inicio;
        $hasta = $fin === '' ? $total - 1 : min((int) $fin, $total - 1);
        if ($desde >= $total || $desde > $hasta) {
            return null;
        }

        return [$desde, $hasta];
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
