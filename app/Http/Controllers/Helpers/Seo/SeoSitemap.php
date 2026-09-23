<?php

namespace App\Http\Controllers\Helpers\Seo;

use App\Article;
use App\Bodega;
use App\Brand;
use App\Cepa;
use App\PromocionVinoteca;
use App\SubCategory;

/**
 * El sitemap de la tienda: GET /api/seo/sitemap/{commerce_id} (mision seo-tiendas).
 *
 * Lo sirve seo.php como `https://<dominio>/sitemap.xml` (con su propia cache de 6 h), y es la
 * UNICA forma en que Google descubre las fichas hoy: la SPA navega a los productos con
 * $router.push, sin un solo <a href>.
 *
 * Trae: home, categorias, subcategorias, marcas, bodegas y cepas con articulos online, y las
 * fichas online (Article con checkOnline + checkStock, y PromocionVinoteca online), cada una
 * con `lastmod` y su primera imagen. Tope de 45 000 URLs (el protocolo admite 50 000 por
 * archivo; el margen es para no tener que partirlo nunca).
 *
 * Performance: esto lo piden bots. Nada de withAll(): las fichas se recorren por id en tandas
 * de 1000 con cuatro columnas y la relacion `images`, y el resultado se cachea una hora (ver
 * SeoController@sitemap).
 */
class SeoSitemap
{
    const TOPE_DE_URLS = 45000;

    const TANDA = 1000;

    /** @var SeoContexto */
    private $ctx;

    /** @var SeoPaginas */
    private $paginas;

    /** @var array<int, string>  Los <url> ya armados. */
    private $urls = [];

    function __construct(SeoContexto $ctx)
    {
        $this->ctx = $ctx;
        $this->paginas = new SeoPaginas($ctx);
    }

    /**
     * El XML completo.
     *
     * Sin `sitio` valido (ni en el request ni en commerce->online) sale un <urlset> vacio pero
     * valido: un sitemap con URLs relativas no es un sitemap, y seo.php siempre manda el host.
     *
     * @return string
     */
    function generar()
    {
        if ($this->ctx->sitio !== '') {
            $this->agregarHome();
            $categorias = $this->agregarCategorias();
            $this->agregarSubcategorias($categorias);
            $this->agregarAgrupador(Brand::class, 'marca');
            $this->agregarAgrupador(Bodega::class, 'bodega');
            $this->agregarAgrupador(Cepa::class, 'cepa');
            $this->agregarPromociones();
            $this->agregarFichas();
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n"
            .implode("\n", $this->urls)
            .(count($this->urls) ? "\n" : '')
            .'</urlset>'."\n";
    }

    private function agregarHome()
    {
        $this->agregar('/', null, $this->ctx->logo());
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    private function agregarCategorias()
    {
        $categorias = $this->paginas->categoriasConArticulos();
        foreach ($categorias as $categoria) {
            $this->agregar(SeoContexto::path(['inicio', SeoContexto::slugRuta($categoria->name)]));
        }
        return $categorias;
    }

    /**
     * @param  \Illuminate\Support\Collection  $categorias  Las visibles, con id y name.
     */
    private function agregarSubcategorias($categorias)
    {
        if (!$categorias->count()) {
            return;
        }
        $por_id = $categorias->keyBy('id');
        $subcategorias = SubCategory::whereIn('category_id', $por_id->keys()->all())
            ->whereHas('articles', function ($query) {
                $this->paginas->filtroOnline($query)->checkStock();
            })
            ->select('id', 'name', 'category_id')
            ->orderBy('name', 'ASC')
            ->get();

        foreach ($subcategorias as $subcategoria) {
            $categoria = $por_id->get($subcategoria->category_id);
            $this->agregar(SeoContexto::path([
                'inicio', SeoContexto::slugRuta($categoria->name), SeoContexto::slugRuta($subcategoria->name),
            ]));
        }
    }

    /**
     * Marcas, bodegas o cepas del comercio con al menos un articulo online con stock.
     *
     * @param  string  $clase
     * @param  string  $segmento  marca | bodega | cepa
     */
    private function agregarAgrupador($clase, $segmento)
    {
        $modelos = $clase::where('user_id', $this->ctx->commerce->id)
            ->whereHas('articles', function ($query) {
                $this->paginas->filtroOnline($query)->checkStock();
            })
            ->select('id', 'name')
            ->orderBy('name', 'ASC')
            ->get();

        foreach ($modelos as $modelo) {
            $this->agregar(SeoContexto::path(['inicio', $segmento, SeoContexto::slugRuta($modelo->name)]));
        }
    }

    private function agregarPromociones()
    {
        $promociones = PromocionVinoteca::where('user_id', $this->ctx->commerce->id)
            ->where('online', 1)
            ->select('id', 'slug', 'updated_at')
            ->with(['images' => function ($q) { $q->select('id', 'hosting_url', 'imageable_id', 'imageable_type')->orderBy('id', 'ASC'); }])
            ->orderBy('id', 'ASC')
            ->get();

        foreach ($promociones as $promocion) {
            if ($this->lleno()) {
                return;
            }
            $this->agregarFicha($promocion);
        }
    }

    private function agregarFichas()
    {
        if ($this->lleno()) {
            return;
        }
        $this->paginas->filtroOnline(Article::query())
            ->checkStock()
            ->select('id', 'slug', 'user_id', 'updated_at')
            ->with(['images' => function ($q) { $q->select('id', 'hosting_url', 'imageable_id', 'imageable_type')->orderBy('id', 'ASC'); }])
            ->chunkById(self::TANDA, function ($articulos) {
                foreach ($articulos as $articulo) {
                    if ($this->lleno()) {
                        return false;
                    }
                    $this->agregarFicha($articulo);
                }
                return !$this->lleno();
            });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Model  $modelo  Con slug, updated_at e images.
     */
    private function agregarFicha($modelo)
    {
        if (trim((string) $modelo->slug) === '') {
            return;
        }
        $imagenes = $this->ctx->imagenes($modelo);
        $this->agregar(
            SeoContexto::path(['articulos', $modelo->slug, $this->ctx->commerce->id]),
            $modelo->updated_at,
            count($imagenes) ? $imagenes[0] : null
        );
    }

    /**
     * @param  string  $path  Ya codificado con SeoContexto::path().
     * @param  \DateTimeInterface|null  $lastmod
     * @param  string|null  $imagen
     */
    private function agregar($path, $lastmod = null, $imagen = null)
    {
        if ($this->lleno()) {
            return;
        }
        $xml = '<url><loc>'.self::xml(self::uriAscii($this->ctx->url($path))).'</loc>';
        if ($lastmod instanceof \DateTimeInterface) {
            $xml .= '<lastmod>'.$lastmod->format('c').'</lastmod>';
        }
        if (!is_null($imagen)) {
            $xml .= '<image:image><image:loc>'.self::xml(self::uriAscii($imagen)).'</image:loc></image:image>';
        }
        $this->urls[] = $xml.'</url>';
    }

    private function lleno()
    {
        return count($this->urls) >= self::TOPE_DE_URLS;
    }

    /**
     * El protocolo de sitemaps pide URLs RFC 3986: lo no ASCII (la eñe de una categoria) se
     * pasa a %XX. Es la misma URL que la canonica con la eñe cruda — Google las trata igual —,
     * solo escrita como la quiere el protocolo.
     *
     * @param  string  $url
     * @return string
     */
    static function uriAscii($url)
    {
        return preg_replace_callback('/[\x80-\xFF]|\s/', function ($match) {
            return rawurlencode($match[0]);
        }, $url);
    }

    /**
     * @param  string  $texto
     * @return string
     */
    private static function xml($texto)
    {
        return htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
