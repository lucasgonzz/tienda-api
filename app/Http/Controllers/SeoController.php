<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\Seo\SeoContexto;
use App\Http\Controllers\Helpers\Seo\SeoPaginas;
use App\Http\Controllers\Helpers\Seo\SeoSitemap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Los dos endpoints que consume seo.php, la capa PHP server-side de tienda-spa (mision
 * seo-tiendas, 23/9/2026; plan en _cruzado/misiones/20260923-seo-tiendas/plan.md).
 *
 * El problema que resuelven: una tienda es una SPA, y lo que ve un crawler sin JavaScript
 * —WhatsApp, Facebook, Bing en primera pasada, las IA, y Google antes de renderizar— es un
 * <div id=app> vacio con un <noscript> en ingles. Google llego a indexar truvaribebidas.com.ar
 * con la descripcion "We're sorry but Truvari doesn't work properly without JavaScript".
 *
 * seo.php le pide a esta API el head (titulo, descripcion, canonica, og, JSON-LD) y un cuerpo
 * estatico con links reales para cada ruta, y los inyecta en el index.html. Vue monta encima y
 * reemplaza el cuerpo: el usuario ve la tienda de siempre.
 *
 * 🔴 Contrato de compatibilidad (el plan, "Compatibilidad del contrato A"): cualquier
 * respuesta HTTP que no sea 200 es "la API no pudo contestar" y seo.php sirve el index.html
 * de hoy. Por eso `pagina` contesta 200 hasta para una ruta inexistente (el 404 va en
 * `estado`), y reserva el 404 HTTP para "este comercio no tiene tienda", que no es una pagina
 * que no existe sino una API que no puede contestar por el.
 */
class SeoController extends Controller
{
    /** Segundos de cache del sitemap. seo.php lo cachea ademas 6 h de su lado. */
    const CACHE_DEL_SITEMAP = 3600;

    /**
     * GET /api/seo/pagina/{commerce_id}?ruta=<path>&sitio=<origen>
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int|string  $commerce_id
     * @return \Illuminate\Http\JsonResponse
     */
    function pagina(Request $request, $commerce_id)
    {
        $ctx = $this->contexto($request, $commerce_id);
        if (is_null($ctx)) {
            return response()->json(['message' => 'El comercio no tiene tienda online.'], 404);
        }

        $pagina = (new SeoPaginas($ctx))->responder($request->query('ruta', '/'));

        return response()->json($pagina, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /api/seo/sitemap/{commerce_id}?sitio=<origen>
     *
     * Cacheado una hora por comercio + sitio (el sitio entra en la clave porque las URLs son
     * absolutas: la misma tienda pedida con otro host es otro XML). El driver es el del .env
     * (`file` por defecto en config/cache.php; `array` en los tests, que no persiste entre
     * requests de procesos distintos y no ensucia nada).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int|string  $commerce_id
     * @return \Illuminate\Http\Response
     */
    function sitemap(Request $request, $commerce_id)
    {
        $ctx = $this->contexto($request, $commerce_id);
        if (is_null($ctx)) {
            return response()->json(['message' => 'El comercio no tiene tienda online.'], 404);
        }

        $clave = 'seo:sitemap:'.$ctx->commerce->id.':'.md5($ctx->sitio);
        $xml = Cache::remember($clave, self::CACHE_DEL_SITEMAP, function () use ($ctx) {
            return (new SeoSitemap($ctx))->generar();
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * El contexto del comercio, o null si no existe o no tiene tienda.
     *
     * El merge de `commerce_id` no es decorativo: los scopes checkOnline()/checkStock() de
     * Article leen la configuracion del comercio de request()->commerce_id (mismo truco que
     * HomeController@brands).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int|string  $commerce_id
     * @return SeoContexto|null
     */
    private function contexto(Request $request, $commerce_id)
    {
        $request->merge(['commerce_id' => $commerce_id]);
        $sitio = $request->query('sitio');

        return SeoContexto::armar($commerce_id, is_string($sitio) ? $sitio : null);
    }
}
