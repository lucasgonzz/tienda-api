<?php

namespace App\Http\Controllers\Helpers\Seo;

use App\Article;
use App\Bodega;
use App\Brand;
use App\Category;
use App\Cepa;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\PromocionVinoteca;
use App\SubCategory;

/**
 * Resuelve una `ruta` de la tienda y arma la respuesta de GET /api/seo/pagina (contrato A de la
 * mision seo-tiendas; el plan esta en _cruzado/misiones/20260923-seo-tiendas/plan.md).
 *
 * 🔴 Las nueve claves de la respuesta son el contrato con seo.php (tienda-spa) y se llaman
 * EXACTAMENTE asi: estado, titulo, descripcion, canonica, imagen, robots, og_type, json_ld,
 * cuerpo_html. seo.php no inventa ninguna ni depende de otra. Cambiar un nombre aca deja a
 * todas las tiendas con el head de fallback sin que nada avise.
 *
 * Las reglas de ruta son las de la SPA (src/router/index.js + App.vue::getCategory()), porque
 * la canonica que arma la API tiene que coincidir con la que arma la SPA:
 *   - `/`, `/inicio`, `/inicio/ultimos-ingresados` → home.
 *   - `/inicio/marca|bodega|cepa/{x}` → marca / bodega / cepa.
 *   - `/inicio/{cat}` y `/inicio/{cat}/{sub}` → categoria / subcategoria.
 *   - `/articulos/{slug}/{commerce_id}` → ficha (Article online, o PromocionVinoteca online).
 *   - institucionales → index; privadas/funcionales → noindex; el resto → 404.
 * Los segmentos se comparan en minusculas y despues de rawurldecode, como hace la SPA
 * (routeString(name).toLowerCase() == param.toLowerCase()).
 */
class SeoPaginas
{
    /** Fichas en la home ("ultimos ingresados"). */
    const FICHAS_EN_LA_HOME = 48;

    /** Fichas en una categoria / subcategoria / marca / bodega / cepa. */
    const FICHAS_EN_UN_LISTADO = 96;

    /** "Productos similares" en la ficha. */
    const SIMILARES_EN_LA_FICHA = 12;

    /** Un path mas largo que esto no es una ruta de la tienda: 404 sin mirar la base. */
    const LARGO_MAXIMO_DE_RUTA = 1000;

    /** Nombre del filtro de categorias que la tienda esconde (HomeController@categories). */
    const CATEGORIA_EXCLUIDA = 'La de siempre';

    const INDEX = 'index,follow';
    const NOINDEX = 'noindex,follow';

    /**
     * Institucionales: se indexan con su propia ruta como canonica.
     *
     * @var array<string, string>  primer segmento => titulo
     */
    const INSTITUCIONALES = [
        'contacto'               => 'Contacto',
        'quienes-somos'          => 'Quiénes somos',
        'ayuda'                  => 'Ayuda',
        'terminos-y-condiciones' => 'Términos y condiciones',
        'politica-de-privacidad' => 'Política de privacidad',
        'promociones'            => 'Promociones',
        'catalogo'               => 'Catálogo',
    ];

    /**
     * Privadas o funcionales: existen en la SPA (200) pero no tienen nada que indexar.
     * `noindex,follow` y no `nofollow`: los links del header y el footer si sirven.
     *
     * @var array<string, string>  primer segmento => titulo
     */
    const PRIVADAS = [
        'buscar'                => 'Buscar',
        'carrito'               => 'Carrito',
        'compras'               => 'Mis compras',
        'favoritos'             => 'Favoritos',
        'login'                 => 'Ingresar',
        'recuperar-clave'       => 'Recuperar clave',
        'confirmar-compra'      => 'Confirmar compra',
        'pagar'                 => 'Pagar',
        'configuracion'         => 'Configuración',
        'mensajes'              => 'Mensajes',
        'notificaciones'        => 'Notificaciones',
        'cupones'               => 'Cupones',
        'cuenta-corriente'      => 'Cuenta corriente',
        'tarjetas'              => 'Tarjetas',
        'ubicacion'             => 'Ubicación',
        'mapas'                 => 'Mapas',
        'social-login'          => 'Ingresar',
        'gracias-por-tu-compra' => 'Gracias por tu compra',
        'preguntas'             => 'Preguntas',
    ];

    /**
     * Privadas que la SPA define con subrutas (`/registro/:view`, `/auth/:provider/callback`,
     * `/seleccion-especial/:articles_id`): matchean por el primer segmento, con lo que venga
     * detras.
     *
     * @var array<string, string>
     */
    const PRIVADAS_CON_SUBRUTA = [
        'registro'           => 'Registro',
        'auth'               => 'Ingresar',
        'seleccion-especial' => 'Selección especial',
    ];

    /** @var SeoContexto */
    private $ctx;

    /** @var \Illuminate\Support\Collection|null  Memo de categoriasConArticulos(). */
    private $categorias = null;

    function __construct(SeoContexto $ctx)
    {
        $this->ctx = $ctx;
    }

    /**
     * La respuesta completa para una ruta.
     *
     * @param  string|null  $ruta  Tal como llega en el query string.
     * @return array  Las nueve claves del contrato.
     */
    function responder($ruta)
    {
        $segmentos = self::segmentos($ruta);
        if (is_null($segmentos)) {
            return $this->noEncontrada();
        }

        $cantidad = count($segmentos);
        $primero = $cantidad ? mb_strtolower($segmentos[0], 'UTF-8') : '';

        if ($cantidad === 0) {
            return $this->home();
        }

        if ($primero === 'inicio') {
            return $this->rutaDeInicio(array_slice($segmentos, 1));
        }

        if ($primero === 'articulos') {
            if ($cantidad === 3) {
                return $this->ficha($segmentos[1], $segmentos[2]);
            }
            return $this->noEncontrada();
        }

        if (array_key_exists($primero, self::INSTITUCIONALES) && ($cantidad === 1 || ($primero === 'ayuda' && $cantidad === 2))) {
            return $this->institucional($primero, $segmentos);
        }

        if (array_key_exists($primero, self::PRIVADAS) && $cantidad === 1) {
            return $this->privada(self::PRIVADAS[$primero], $segmentos);
        }

        if (array_key_exists($primero, self::PRIVADAS_CON_SUBRUTA)) {
            return $this->privada(self::PRIVADAS_CON_SUBRUTA[$primero], $segmentos);
        }

        /* /pago-exitoso, /pago-pendiente, /pago-rechazado (y cualquier /pago-* que sume la SPA). */
        if ($cantidad === 1 && strpos($primero, 'pago-') === 0) {
            return $this->privada('Pago', $segmentos);
        }

        return $this->noEncontrada();
    }

    /**
     * Parte la ruta en segmentos decodificados. Sin '/' inicial se trata como si lo tuviera;
     * el query string y el fragmento se descartan; los segmentos vacios (barras dobles o
     * finales) no cuentan.
     *
     * @param  string|null  $ruta
     * @return array|null  null si la ruta es absurda (demasiado larga): va directo a 404.
     */
    static function segmentos($ruta)
    {
        $ruta = is_string($ruta) ? $ruta : '/';
        if (strlen($ruta) > self::LARGO_MAXIMO_DE_RUTA) {
            return null;
        }

        $ruta = preg_replace('/[?#].*$/s', '', $ruta);
        if ($ruta === '' || $ruta[0] !== '/') {
            $ruta = '/'.$ruta;
        }

        $segmentos = [];
        foreach (explode('/', $ruta) as $segmento) {
            if ($segmento === '') {
                continue;
            }
            $segmentos[] = rawurldecode($segmento);
        }
        return $segmentos;
    }

    // ------------------------------------------------------------------------------------------
    // Tipos de pagina
    // ------------------------------------------------------------------------------------------

    /**
     * La home: Store + WebSite, y los ultimos ingresados como links.
     *
     * @return array
     */
    private function home()
    {
        $ctx = $this->ctx;
        $categorias = $this->categoriasConArticulos();

        $articulos = $this->listado(function ($query) {
            return $query;
        }, self::FICHAS_EN_LA_HOME);

        $descripcion = SeoContexto::textoPlano($ctx->config->meta_description);
        if ($descripcion === '') {
            $top = $categorias->sortByDesc('articulos_online')->take(5)->pluck('name')->all();
            $descripcion = $ctx->nombre.': tienda online.';
            if (count($top)) {
                $descripcion .= ' '.implode(', ', $top).'.';
            }
            $descripcion .= ' Comprá online con envío o retiro en el local.';
        }
        $descripcion = SeoContexto::cortar($descripcion);

        $main = '<h1>'.e($ctx->nombre).'</h1>'
            .'<p>'.e($descripcion).'</p>';
        if ($articulos->count()) {
            $main .= '<section><h2>Últimos ingresados</h2>'.SeoHtml::listaDeFichas($ctx, $articulos).'</section>';
        }

        return self::pagina(
            200,
            $ctx->nombre,
            $descripcion,
            $ctx->url('/'),
            $ctx->logo(),
            self::INDEX,
            'website',
            [$this->jsonLdStore(), $this->jsonLdWebSite()],
            $this->cuerpo($main)
        );
    }

    /**
     * Todo lo que cuelga de /inicio/...
     *
     * @param  array  $resto  Segmentos despues de 'inicio'.
     * @return array
     */
    private function rutaDeInicio(array $resto)
    {
        $cantidad = count($resto);

        if ($cantidad === 0) {
            return $this->home();
        }

        $primero = mb_strtolower($resto[0], 'UTF-8');

        if ($cantidad === 1 && $primero === 'ultimos-ingresados') {
            return $this->home();
        }

        if ($cantidad === 2 && in_array($primero, ['marca', 'bodega', 'cepa'], true)) {
            return $this->agrupador($primero, $resto[1]);
        }

        if ($cantidad === 1) {
            return $this->categoria($resto[0]);
        }

        if ($cantidad === 2) {
            return $this->subcategoria($resto[0], $resto[1]);
        }

        return $this->noEncontrada();
    }

    /**
     * /inicio/{cat}
     *
     * @param  string  $segmento
     * @return array
     */
    private function categoria($segmento)
    {
        $categoria = self::buscarPorSlug(
            Category::where('user_id', $this->ctx->commerce->id)
                ->where('name', '!=', self::CATEGORIA_EXCLUIDA)
                /* Sin select: `descripcion` es una columna nueva de `categories` y la base de
                   un cliente viejo puede no tenerla. Con el modelo entero, falta = null. */
                ->get(),
            $segmento
        );
        if (is_null($categoria)) {
            return $this->noEncontrada();
        }

        $subcategorias = SubCategory::where('category_id', $categoria->id)
            ->whereHas('articles', function ($query) {
                $this->filtroOnline($query)->checkStock();
            })
            ->select('id', 'name', 'category_id')
            ->orderBy('name', 'ASC')
            ->get();

        $links = [];
        foreach ($subcategorias as $subcategoria) {
            $links[] = [$subcategoria->name, $this->ctx->url(SeoContexto::path([
                'inicio', SeoContexto::slugRuta($categoria->name), SeoContexto::slugRuta($subcategoria->name),
            ]))];
        }

        $path = SeoContexto::path(['inicio', SeoContexto::slugRuta($categoria->name)]);

        return $this->listadoDePagina(
            $categoria->name,
            $categoria->descripcion,
            $path,
            [[$categoria->name, $this->ctx->url($path)]],
            function ($query) use ($categoria) {
                return $query->where('category_id', $categoria->id);
            },
            $links
        );
    }

    /**
     * /inicio/{cat}/{sub}
     *
     * @param  string  $segmento_categoria
     * @param  string  $segmento_subcategoria
     * @return array
     */
    private function subcategoria($segmento_categoria, $segmento_subcategoria)
    {
        $categoria = self::buscarPorSlug(
            Category::where('user_id', $this->ctx->commerce->id)
                ->where('name', '!=', self::CATEGORIA_EXCLUIDA)
                ->select('id', 'name')
                ->get(),
            $segmento_categoria
        );
        if (is_null($categoria)) {
            return $this->noEncontrada();
        }

        $subcategoria = self::buscarPorSlug(
            SubCategory::where('category_id', $categoria->id)->select('id', 'name', 'category_id')->get(),
            $segmento_subcategoria
        );
        if (is_null($subcategoria)) {
            return $this->noEncontrada();
        }

        $path_categoria = SeoContexto::path(['inicio', SeoContexto::slugRuta($categoria->name)]);
        $path = SeoContexto::path([
            'inicio', SeoContexto::slugRuta($categoria->name), SeoContexto::slugRuta($subcategoria->name),
        ]);

        return $this->listadoDePagina(
            $subcategoria->name,
            null,
            $path,
            [
                [$categoria->name, $this->ctx->url($path_categoria)],
                [$subcategoria->name, $this->ctx->url($path)],
            ],
            function ($query) use ($subcategoria) {
                return $query->where('sub_category_id', $subcategoria->id);
            }
        );
    }

    /**
     * /inicio/marca/{x}, /inicio/bodega/{x}, /inicio/cepa/{x}
     *
     * @param  string  $tipo  marca | bodega | cepa
     * @param  string  $segmento
     * @return array
     */
    private function agrupador($tipo, $segmento)
    {
        $clases = ['marca' => Brand::class, 'bodega' => Bodega::class, 'cepa' => Cepa::class];
        $columnas = ['marca' => 'brand_id', 'bodega' => 'bodega_id', 'cepa' => 'cepa_id'];
        $clase = $clases[$tipo];
        $columna = $columnas[$tipo];

        $modelo = self::buscarPorSlug(
            $clase::where('user_id', $this->ctx->commerce->id)->select('id', 'name')->get(),
            $segmento
        );
        if (is_null($modelo)) {
            return $this->noEncontrada();
        }

        $path = SeoContexto::path(['inicio', $tipo, SeoContexto::slugRuta($modelo->name)]);

        return $this->listadoDePagina(
            $modelo->name,
            null,
            $path,
            [[$modelo->name, $this->ctx->url($path)]],
            function ($query) use ($columna, $modelo) {
                return $query->where($columna, $modelo->id);
            }
        );
    }

    /**
     * Lo comun a categoria, subcategoria, marca, bodega y cepa: h1, texto, links y JSON-LD
     * BreadcrumbList + ItemList.
     *
     * Un agrupador que existe pero no tiene NINGUN articulo online sale 200 (la SPA lo muestra)
     * pero con `noindex,follow`: una pagina vacia indexada es justo lo que Search Console marca
     * como "soft 404".
     *
     * @param  string  $nombre
     * @param  string|null  $texto  Descripcion propia del agrupador, si la tiene.
     * @param  string  $path  Path canonico, ya codificado.
     * @param  array  $migas  Pares [nombre, url] despues de "Inicio".
     * @param  callable  $filtro
     * @param  array  $links_extra  Pares [texto, url] (subcategorias).
     * @return array
     */
    private function listadoDePagina($nombre, $texto, $path, array $migas, callable $filtro, array $links_extra = [])
    {
        $ctx = $this->ctx;
        $total = $filtro($this->filtroOnline(Article::query())->checkStock())->count();
        $articulos = $this->listado($filtro, self::FICHAS_EN_UN_LISTADO);

        $texto = SeoContexto::textoPlano($texto);
        if ($texto !== '') {
            $descripcion = $nombre.' en '.$ctx->nombre.': '.$texto;
        } else {
            $descripcion = $nombre.' en '.$ctx->nombre.': '.$total.' '.($total === 1 ? 'producto' : 'productos').'.';
            $primeros = $articulos->take(4)->pluck('name')->all();
            if (count($primeros)) {
                $descripcion .= ' '.implode(', ', $primeros).'…';
            }
        }
        $descripcion = SeoContexto::cortar($descripcion);

        $migas = array_merge([['Inicio', $ctx->url('/')]], $migas);

        $main = SeoHtml::migas($migas)
            .'<h1>'.e($nombre).'</h1>'
            .'<p>'.e($texto !== '' ? SeoContexto::cortar($texto, 1000, 995) : $descripcion).'</p>';
        if (count($links_extra)) {
            $main .= SeoHtml::listaDeLinks($links_extra);
        }
        $main .= SeoHtml::listaDeFichas($ctx, $articulos);

        $item_list = [];
        $posicion = 1;
        foreach ($articulos as $articulo) {
            $item_list[] = [
                '@type'    => 'ListItem',
                'position' => $posicion++,
                'url'      => self::urlFicha($ctx, $articulo),
            ];
        }

        $json_ld = [self::jsonLdMigas($migas)];
        if (count($item_list)) {
            $json_ld[] = [
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => $nombre,
                'numberOfItems'   => count($item_list),
                'itemListElement' => $item_list,
            ];
        }

        $imagen = null;
        foreach ($articulos as $articulo) {
            $imagen = $ctx->imagenPrincipal($articulo);
            if (!is_null($imagen)) {
                break;
            }
        }

        return self::pagina(
            200,
            $nombre.' | '.$ctx->nombre,
            $descripcion,
            $ctx->url($path),
            $imagen ?? $ctx->logo(),
            $total > 0 ? self::INDEX : self::NOINDEX,
            'website',
            $json_ld,
            $this->cuerpo($main)
        );
    }

    /**
     * /articulos/{slug}/{commerce_id}
     *
     * 🔴 Solo sale si el articulo pasa checkOnline() (activo, online, y con imagen si la
     * tienda lo exige). ArticleController@show NO filtra eso — devuelve cualquier articulo por
     * slug — pero una ficha que la tienda no lista no tiene por que estar en Google. NO se
     * filtra por stock: la ficha de un agotado existe y se ve, y el JSON-LD dice OutOfStock.
     *
     * El commerce_id de la URL tiene que ser el de esta tienda: en una base compartida por
     * varios comercios, /articulos/{slug}/{otro_id} es la ficha de otro negocio.
     *
     * @param  string  $slug
     * @param  string  $commerce_id_de_la_url
     * @return array
     */
    private function ficha($slug, $commerce_id_de_la_url)
    {
        $ctx = $this->ctx;
        if ((string) $commerce_id_de_la_url !== (string) $ctx->commerce->id) {
            return $this->noEncontrada();
        }

        $query = $this->filtroOnline(Article::query())
            ->where('slug', $slug)
            /* Sin select de columnas a proposito (vale tambien para listado()): `precio_pausado`
               la agrego empresa-api en abril de 2026 y la base de un cliente con una version
               anterior no la tiene. Nombrarla en un select seria un 500 en todas sus paginas; sin
               nombrarla, el atributo falta y se lee como null. */
            ->with([
                'images'       => function ($q) { $q->select('id', 'hosting_url', 'imageable_id', 'imageable_type')->orderBy('id', 'ASC'); },
                'descriptions' => function ($q) { $q->select('id', 'title', 'content', 'article_id')->orderBy('id', 'ASC'); },
                'brand:id,name',
                'bodega:id,name',
                'cepa:id,name',
                'category:id,name',
                'sub_category:id,name,category_id',
            ]);
        if ($ctx->precioPublico()) {
            $query->with('price_types');
        }
        $articulo = $query->first();

        if (!is_null($articulo)) {
            if ($ctx->precioPublico()) {
                ArticleHelper::checkPriceTypes(collect([$articulo]));
            }
            return $this->fichaDeArticulo($articulo);
        }

        $promocion = PromocionVinoteca::where('user_id', $ctx->commerce->id)
            ->where('slug', $slug)
            ->where('online', 1)
            ->with(['images' => function ($q) { $q->select('id', 'hosting_url', 'imageable_id', 'imageable_type')->orderBy('id', 'ASC'); }])
            ->first();

        if (!is_null($promocion)) {
            return $this->fichaDePromocion($promocion);
        }

        return $this->noEncontrada();
    }

    /**
     * @param  \App\Article  $articulo  Con el precio ya resuelto por checkPriceTypes().
     * @return array
     */
    private function fichaDeArticulo($articulo)
    {
        $ctx = $this->ctx;
        $canonica = self::urlFicha($ctx, $articulo);
        $precio = $ctx->precio($articulo);

        $titulo = $articulo->name;
        if (!is_null($articulo->bodega) && trim((string) $articulo->bodega->name) !== '') {
            $titulo .= ' - '.$articulo->bodega->name;
        }
        $titulo .= ' | '.$ctx->nombre;

        /* Lo mismo que pinta components/article/components/Description.vue: cada descripcion
           con su titulo y su contenido (que puede traer HTML del editor del ERP). */
        $renglones = [];
        foreach ($articulo->descriptions as $descripcion) {
            $renglones[] = SeoContexto::primeroNoVacio([$descripcion->title]);
            $renglones[] = self::textoConRenglones($descripcion->content);
        }
        $texto_largo = trim(implode("\n", array_filter($renglones, function ($r) { return $r !== ''; })));
        $texto_plano = SeoContexto::textoPlano($texto_largo);

        $descripcion = $texto_plano !== ''
            ? SeoContexto::cortar($texto_plano)
            : SeoContexto::cortar($this->descripcionGenerica($articulo->name, $precio));

        $imagenes = $ctx->imagenes($articulo);
        $imagen = count($imagenes) ? $imagenes[0] : $ctx->imagenPrincipal($articulo);

        $migas = [['Inicio', $ctx->url('/')]];
        if (!is_null($articulo->category) && $articulo->category->name !== self::CATEGORIA_EXCLUIDA) {
            $path_categoria = SeoContexto::path(['inicio', SeoContexto::slugRuta($articulo->category->name)]);
            $migas[] = [$articulo->category->name, $ctx->url($path_categoria)];
            if (!is_null($articulo->sub_category) && $articulo->sub_category->category_id == $articulo->category->id) {
                $migas[] = [$articulo->sub_category->name, $ctx->url(SeoContexto::path([
                    'inicio', SeoContexto::slugRuta($articulo->category->name), SeoContexto::slugRuta($articulo->sub_category->name),
                ]))];
            }
        }
        $migas[] = [$articulo->name, $canonica];

        $producto = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => $articulo->name,
            'url'      => $canonica,
        ];
        if (count($imagenes)) {
            $producto['image'] = $imagenes;
        } else if (!is_null($imagen)) {
            $producto['image'] = [$imagen];
        }
        $producto['description'] = $texto_plano !== '' ? SeoContexto::cortar($texto_plano, 5000, 4995) : $descripcion;
        if (trim((string) $articulo->bar_code) !== '') {
            $producto['sku'] = trim((string) $articulo->bar_code);
        }
        if (!is_null($articulo->brand) && trim((string) $articulo->brand->name) !== '') {
            $producto['brand'] = ['@type' => 'Brand', 'name' => $articulo->brand->name];
        }
        if (!is_null($precio)) {
            $producto['offers'] = self::oferta($precio, $ctx->hayStock($articulo), $canonica);
        }

        $main = SeoHtml::migas($migas)
            .'<article>'
            .'<h1>'.e($articulo->name).'</h1>';
        if (!is_null($imagen)) {
            $main .= '<img src="'.e($imagen).'" alt="'.e($articulo->name).'">';
        }
        if (!is_null($precio)) {
            $main .= '<p>Precio: <strong>'.e(SeoContexto::formatearPrecio($precio)).'</strong></p>';
        }
        $main .= '<p>'.($ctx->hayStock($articulo) ? 'Disponible' : 'Sin stock').'</p>';
        if (!is_null($articulo->brand) && trim((string) $articulo->brand->name) !== '') {
            $main .= '<p>Marca: <a href="'.e($ctx->url(SeoContexto::path(['inicio', 'marca', SeoContexto::slugRuta($articulo->brand->name)]))).'">'.e($articulo->brand->name).'</a></p>';
        }
        if (!is_null($articulo->bodega) && trim((string) $articulo->bodega->name) !== '') {
            $main .= '<p>Bodega: <a href="'.e($ctx->url(SeoContexto::path(['inicio', 'bodega', SeoContexto::slugRuta($articulo->bodega->name)]))).'">'.e($articulo->bodega->name).'</a></p>';
        }
        if (!is_null($articulo->cepa) && trim((string) $articulo->cepa->name) !== '') {
            $main .= '<p>Cepa: <a href="'.e($ctx->url(SeoContexto::path(['inicio', 'cepa', SeoContexto::slugRuta($articulo->cepa->name)]))).'">'.e($articulo->cepa->name).'</a></p>';
        }
        if ($texto_largo !== '') {
            $main .= '<section><h2>Descripción</h2>'.SeoHtml::parrafos($texto_largo).'</section>';
        }
        $main .= '</article>';

        $similares = $this->similares($articulo);
        if ($similares->count()) {
            $main .= '<section><h2>Productos relacionados</h2>'.SeoHtml::listaDeFichas($ctx, $similares).'</section>';
        }

        return self::pagina(
            200,
            $titulo,
            $descripcion,
            $canonica,
            $imagen ?? $ctx->logo(),
            self::INDEX,
            'product',
            [$producto, self::jsonLdMigas($migas)],
            $this->cuerpo($main)
        );
    }

    /**
     * La ficha de una PromocionVinoteca online: la SPA la abre por la misma ruta que un
     * articulo (ArticleController@show cae a la promo cuando el slug no es de un Article).
     *
     * @param  \App\PromocionVinoteca  $promocion
     * @return array
     */
    private function fichaDePromocion($promocion)
    {
        $ctx = $this->ctx;
        $canonica = self::urlFicha($ctx, $promocion);
        $precio = $ctx->precio($promocion);
        $texto_largo = self::textoConRenglones($promocion->description);
        $texto_plano = SeoContexto::textoPlano($texto_largo);

        $descripcion = $texto_plano !== ''
            ? SeoContexto::cortar($texto_plano)
            : SeoContexto::cortar($this->descripcionGenerica($promocion->name, $precio));

        $imagenes = $ctx->imagenes($promocion);
        $imagen = count($imagenes) ? $imagenes[0] : $ctx->imagenPrincipal($promocion);

        $migas = [
            ['Inicio', $ctx->url('/')],
            ['Promociones', $ctx->url('/promociones')],
            [$promocion->name, $canonica],
        ];

        $producto = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            'name'        => $promocion->name,
            'url'         => $canonica,
            'description' => $texto_plano !== '' ? SeoContexto::cortar($texto_plano, 5000, 4995) : $descripcion,
        ];
        if (count($imagenes)) {
            $producto['image'] = $imagenes;
        } else if (!is_null($imagen)) {
            $producto['image'] = [$imagen];
        }
        if (!is_null($precio)) {
            $producto['offers'] = self::oferta($precio, $ctx->hayStock($promocion), $canonica);
        }

        $main = SeoHtml::migas($migas)
            .'<article><h1>'.e($promocion->name).'</h1>';
        if (!is_null($imagen)) {
            $main .= '<img src="'.e($imagen).'" alt="'.e($promocion->name).'">';
        }
        if (!is_null($precio)) {
            $main .= '<p>Precio: <strong>'.e(SeoContexto::formatearPrecio($precio)).'</strong></p>';
        }
        if ($texto_largo !== '') {
            $main .= '<section><h2>Descripción</h2>'.SeoHtml::parrafos($texto_largo).'</section>';
        }
        $main .= '</article>';

        return self::pagina(
            200,
            $promocion->name.' | '.$ctx->nombre,
            $descripcion,
            $canonica,
            $imagen ?? $ctx->logo(),
            self::INDEX,
            'product',
            [$producto, self::jsonLdMigas($migas)],
            $this->cuerpo($main)
        );
    }

    /**
     * Las institucionales: se indexan, con lo que el comercio haya cargado de cada una.
     *
     * @param  string  $clave  Primer segmento, en minusculas.
     * @param  array  $segmentos
     * @return array
     */
    private function institucional($clave, array $segmentos)
    {
        $ctx = $this->ctx;
        $titulo = self::INSTITUCIONALES[$clave];
        $path = SeoContexto::path(array_map(function ($s) { return mb_strtolower($s, 'UTF-8'); }, $segmentos));
        $contenido = '';

        if ($clave === 'quienes-somos') {
            $titulo = SeoContexto::primeroNoVacio([SeoContexto::textoPlano($ctx->config->titulo_quienes_somos)], $titulo);
            $texto = self::textoConRenglones($ctx->config->quienes_somos);
            $descripcion = $texto !== '' ? SeoContexto::textoPlano($texto) : 'Conocé a '.$ctx->nombre.': quiénes somos y cómo trabajamos.';
            $contenido = $texto !== '' ? SeoHtml::parrafos($texto) : '<p>'.e($descripcion).'</p>';
        } else if ($clave === 'contacto') {
            $descripcion = 'Contacto de '.$ctx->nombre.': teléfono, mail y dirección. Escribinos por tus pedidos y consultas.';
            $mensaje = self::textoConRenglones($ctx->config->mensaje_contacto);
            $contenido = '<p>'.e($descripcion).'</p>'.($mensaje !== '' ? SeoHtml::parrafos($mensaje) : '');
        } else if ($clave === 'catalogo') {
            $descripcion = 'Catálogo de '.$ctx->nombre.': todas las categorías de productos. Comprá online con envío o retiro en el local.';
            $links = [];
            foreach ($this->categoriasConArticulos() as $categoria) {
                $links[] = [$categoria->name, $ctx->url(SeoContexto::path(['inicio', SeoContexto::slugRuta($categoria->name)]))];
            }
            $contenido = '<p>'.e($descripcion).'</p>'.SeoHtml::listaDeLinks($links);
        } else if ($clave === 'promociones') {
            $descripcion = 'Promociones de '.$ctx->nombre.'. Comprá online con envío o retiro en el local.';
            $promociones = PromocionVinoteca::where('user_id', $ctx->commerce->id)
                ->where('online', 1)
                ->select('id', 'name', 'slug', 'user_id', 'final_price', 'stock')
                ->orderBy('id', 'DESC')
                ->limit(self::FICHAS_EN_UN_LISTADO)
                ->get();
            $contenido = '<p>'.e($descripcion).'</p>'.SeoHtml::listaDeFichas($ctx, $promociones);
        } else {
            $descripcion = $titulo.' de '.$ctx->nombre.'.';
            $contenido = '<p>'.e($descripcion).'</p>';
        }

        $descripcion = SeoContexto::cortar($descripcion);
        $migas = [['Inicio', $ctx->url('/')], [$titulo, $ctx->url($path)]];

        return self::pagina(
            200,
            $titulo.' | '.$ctx->nombre,
            $descripcion,
            $ctx->url($path),
            $ctx->logo(),
            self::INDEX,
            'website',
            [self::jsonLdMigas($migas)],
            $this->cuerpo(SeoHtml::migas($migas).'<h1>'.e($titulo).'</h1>'.$contenido)
        );
    }

    /**
     * Privadas y funcionales: existen, pero `noindex,follow` y sin JSON-LD.
     *
     * @param  string  $titulo
     * @param  array  $segmentos
     * @return array
     */
    private function privada($titulo, array $segmentos)
    {
        $ctx = $this->ctx;
        $path = SeoContexto::path($segmentos);
        $descripcion = SeoContexto::cortar($titulo.' en la tienda online de '.$ctx->nombre.'.');

        return self::pagina(
            200,
            $titulo.' | '.$ctx->nombre,
            $descripcion,
            $ctx->url($path),
            $ctx->logo(),
            self::NOINDEX,
            'website',
            [],
            $this->cuerpo('<h1>'.e($titulo).'</h1>')
        );
    }

    /**
     * La 404: `estado` 404, sin canonica, noindex. El header y el footer van igual: un crawler
     * que cae aca todavia puede seguir los links de la tienda.
     *
     * @return array
     */
    private function noEncontrada()
    {
        $ctx = $this->ctx;
        $main = '<h1>No encontramos esta página</h1>'
            .'<p>Puede que el producto ya no esté disponible o que el link esté mal escrito.</p>'
            .'<p><a href="'.e($ctx->url('/')).'">Volver al inicio de '.e($ctx->nombre).'</a></p>';

        return self::pagina(
            404,
            'Página no encontrada | '.$ctx->nombre,
            SeoContexto::cortar('La página que buscás no existe en '.$ctx->nombre.'.'),
            null,
            $ctx->logo(),
            self::NOINDEX,
            'website',
            [],
            $this->cuerpo($main)
        );
    }

    // ------------------------------------------------------------------------------------------
    // Consultas
    // ------------------------------------------------------------------------------------------

    /**
     * Articulos online de ESTE comercio, con checkOnline() de Article (la misma regla que la
     * tienda: activo, online, con imagen si la configuracion lo exige).
     *
     * checkOnline() le agrega un eager load de `questions` con sus respuestas, que la tienda
     * usa en la ficha y aca no sirve para nada: se le saca con without() para no pagar esa
     * query en cada listado.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function filtroOnline($query)
    {
        return $query->where('user_id', $this->ctx->commerce->id)
            ->checkOnline()
            ->without('questions');
    }

    /**
     * Un listado de fichas para el cuerpo: online + con stock (lo mismo que listan
     * HomeController y ArticleController), lo mas nuevo primero, con tope de filas.
     *
     * No usa withAll(): trae una docena de relaciones por articulo y esto lo pegan bots. Las
     * columnas no se acotan por el mismo motivo que en ficha() (precio_pausado). Solo
     * se carga `images` (para la imagen de la pagina) y, si el precio es publico,
     * `price_types` (lo necesita checkPriceTypes para resolver la lista del anonimo).
     *
     * @param  callable  $filtro
     * @param  int  $limite
     * @return \Illuminate\Support\Collection
     */
    private function listado(callable $filtro, $limite)
    {
        $query = $filtro($this->filtroOnline(Article::query())->checkStock())
            ->with(['images' => function ($q) { $q->select('id', 'hosting_url', 'imageable_id', 'imageable_type')->orderBy('id', 'ASC'); }])
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limite);
        if ($this->ctx->precioPublico()) {
            $query->with('price_types');
        }
        $articulos = $query->get();

        if ($this->ctx->precioPublico() && $articulos->count()) {
            ArticleHelper::checkPriceTypes($articulos);
        }
        return $articulos;
    }

    /**
     * "Productos relacionados": misma subcategoria (o categoria si no tiene), online y con
     * stock, sin el propio articulo. Es el criterio de ArticleController@similars.
     *
     * @param  \App\Article  $articulo
     * @return \Illuminate\Support\Collection
     */
    private function similares($articulo)
    {
        if (is_null($articulo->sub_category_id) && is_null($articulo->category_id)) {
            return collect();
        }
        return $this->listado(function ($query) use ($articulo) {
            $query->where('id', '!=', $articulo->id);
            if (!is_null($articulo->sub_category_id)) {
                return $query->where('sub_category_id', $articulo->sub_category_id);
            }
            return $query->where('category_id', $articulo->category_id);
        }, self::SIMILARES_EN_LA_FICHA);
    }

    /**
     * Las categorias que la tienda muestra y que tienen al menos un articulo online con stock,
     * por nombre, con la cantidad en `articulos_online`. Memo por instancia: la usan el header
     * y la descripcion de la home en el mismo request.
     *
     * @return \Illuminate\Support\Collection
     */
    function categoriasConArticulos()
    {
        if (is_null($this->categorias)) {
            $filtro = function ($query) {
                $this->filtroOnline($query)->checkStock();
            };
            $this->categorias = Category::where('user_id', $this->ctx->commerce->id)
                ->where('name', '!=', self::CATEGORIA_EXCLUIDA)
                ->whereHas('articles', $filtro)
                ->select('id', 'name')
                ->withCount(['articles as articulos_online' => $filtro])
                ->orderBy('name', 'ASC')
                ->get();
        }
        return $this->categorias;
    }

    /**
     * El modelo cuyo slugRuta(name) coincide con el segmento, comparando en minusculas como
     * App.vue::getCategory(). El segmento ya viene decodificado.
     *
     * @param  \Illuminate\Support\Collection  $modelos
     * @param  string  $segmento
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    static function buscarPorSlug($modelos, $segmento)
    {
        $buscado = mb_strtolower($segmento, 'UTF-8');
        foreach ($modelos as $modelo) {
            if (SeoContexto::slugRuta($modelo->name) === $buscado) {
                return $modelo;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * La URL de la ficha, como la arma la SPA: /articulos/{slug}/{commerce_id}.
     *
     * @param  SeoContexto  $ctx
     * @param  \Illuminate\Database\Eloquent\Model  $modelo
     * @return string
     */
    static function urlFicha(SeoContexto $ctx, $modelo)
    {
        return $ctx->url(SeoContexto::path(['articulos', $modelo->slug, $ctx->commerce->id]));
    }

    /**
     * La direccion del comercio en una linea, o '' si no tiene. Usa la marcada como
     * `default_address`, o la primera.
     *
     * @param  SeoContexto  $ctx
     * @return string
     */
    static function direccionEnTexto(SeoContexto $ctx)
    {
        $direccion = self::direccion($ctx);
        if (is_null($direccion)) {
            return '';
        }
        $calle = trim(trim((string) $direccion->street).' '.trim((string) $direccion->street_number));
        $partes = array_filter([$calle, trim((string) $direccion->city), trim((string) $direccion->province)], function ($p) {
            return $p !== '';
        });
        return implode(', ', $partes);
    }

    /**
     * @param  SeoContexto  $ctx
     * @return \App\Address|null
     */
    private static function direccion(SeoContexto $ctx)
    {
        $direcciones = $ctx->commerce->addresses;
        if (!count($direcciones)) {
            return null;
        }
        $principal = $direcciones->first(function ($d) { return (int) $d->default_address === 1; });
        return $principal ?? $direcciones->first();
    }

    /**
     * El <header> + <main> + <footer> completo.
     *
     * @param  string  $main  HTML ya escapado.
     * @return string
     */
    private function cuerpo($main)
    {
        return SeoHtml::header($this->ctx, $this->categoriasConArticulos())
            .'<main>'.$main.'</main>'
            .SeoHtml::footer($this->ctx);
    }

    /**
     * "<nombre> en <company>. Comprá online con envío o retiro en el local.", con el precio si
     * es publico.
     *
     * @param  string  $nombre
     * @param  float|null  $precio
     * @return string
     */
    private function descripcionGenerica($nombre, $precio)
    {
        $texto = $nombre;
        if (!is_null($precio)) {
            $texto .= ' a '.SeoContexto::formatearPrecio($precio);
        }
        return $texto.' en '.$this->ctx->nombre.'. Comprá online con envío o retiro en el local.';
    }

    /**
     * Texto plano conservando los cortes de renglon (para los parrafos del cuerpo).
     *
     * @param  string|null  $html
     * @return string
     */
    static function textoConRenglones($html)
    {
        $texto = preg_replace('/<\s*br\s*\/?>|<\/\s*(p|div|li|h[1-6])\s*>/iu', "\n", (string) $html);
        $texto = preg_replace('/<[^>]*>/u', ' ', $texto);
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $renglones = [];
        foreach (preg_split('/\R/u', $texto) as $renglon) {
            $renglon = trim(preg_replace('/[^\S\n]+/u', ' ', $renglon));
            if ($renglon !== '') {
                $renglones[] = $renglon;
            }
        }
        return implode("\n", $renglones);
    }

    /**
     * @param  float  $precio
     * @param  bool  $hay_stock
     * @param  string  $url
     * @return array
     */
    private static function oferta($precio, $hay_stock, $url)
    {
        return [
            '@type'         => 'Offer',
            'price'         => number_format($precio, 2, '.', ''),
            'priceCurrency' => 'ARS',
            'availability'  => $hay_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url'           => $url,
        ];
    }

    /**
     * @param  array  $migas  Pares [nombre, url].
     * @return array
     */
    private static function jsonLdMigas(array $migas)
    {
        $items = [];
        foreach ($migas as $indice => $miga) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $indice + 1,
                'name'     => $miga[0],
                'item'     => $miga[1],
            ];
        }
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * @return array
     */
    private function jsonLdStore()
    {
        $ctx = $this->ctx;
        $store = [
            '@context' => 'https://schema.org',
            '@type'    => 'Store',
            'name'     => $ctx->nombre,
            'url'      => $ctx->url('/'),
        ];
        $logo = $ctx->logo();
        if (!is_null($logo)) {
            $store['logo'] = $logo;
            $store['image'] = $logo;
        }
        if (trim((string) $ctx->commerce->phone) !== '') {
            $store['telephone'] = trim((string) $ctx->commerce->phone);
        }
        $mail = trim((string) $ctx->commerce->email);
        if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $store['email'] = $mail;
        }
        $direccion = self::direccion($ctx);
        if (!is_null($direccion)) {
            $postal = ['@type' => 'PostalAddress', 'addressCountry' => 'AR'];
            $calle = trim(trim((string) $direccion->street).' '.trim((string) $direccion->street_number));
            if ($calle !== '') {
                $postal['streetAddress'] = $calle;
            }
            if (trim((string) $direccion->city) !== '') {
                $postal['addressLocality'] = trim((string) $direccion->city);
            }
            if (trim((string) $direccion->province) !== '') {
                $postal['addressRegion'] = trim((string) $direccion->province);
            }
            if (count($postal) > 2) {
                $store['address'] = $postal;
            }
        }
        return $store;
    }

    /**
     * @return array
     */
    private function jsonLdWebSite()
    {
        return [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $this->ctx->nombre,
            'url'      => $this->ctx->url('/'),
        ];
    }

    /**
     * Las nueve claves del contrato A, en este orden y con estos nombres.
     *
     * @return array
     */
    static function pagina($estado, $titulo, $descripcion, $canonica, $imagen, $robots, $og_type, array $json_ld, $cuerpo_html)
    {
        return [
            'estado'      => $estado,
            'titulo'      => $titulo,
            'descripcion' => $descripcion,
            'canonica'    => $canonica,
            'imagen'      => $imagen,
            'robots'      => $robots,
            'og_type'     => $og_type,
            'json_ld'     => array_values($json_ld),
            'cuerpo_html' => $cuerpo_html,
        ];
    }
}
