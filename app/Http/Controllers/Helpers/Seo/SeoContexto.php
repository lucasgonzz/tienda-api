<?php

namespace App\Http\Controllers\Helpers\Seo;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CommerceHelper;
use App\User;

/**
 * Todo lo que una respuesta SEO necesita saber del comercio, resuelto UNA vez por request
 * (mision seo-tiendas, 23/9/2026).
 *
 * Los dos endpoints de /api/seo los consume `seo.php`, la capa PHP que vive en el dist de
 * tienda-spa y que le arma el HTML crudo a los crawlers (Google, WhatsApp, Facebook, las IA).
 * Lo que sale de aca termina en el <head> y en el <body> estatico de la tienda, o sea que
 * cualquier regla que la SPA respete —que precio se ve, como se arma una URL, que imagen se
 * muestra— tiene que valer IGUAL aca. Si no, Google indexa una tienda que no es la que ve el
 * usuario (o peor: publica un precio que la tienda esconde).
 *
 * Por eso esta clase concentra:
 *   - el `sitio` (el origen con el que se arman las URLs absolutas);
 *   - la visibilidad de precio para un visitante ANONIMO (un crawler nunca tiene sesion);
 *   - la resolucion de imagenes a URL absoluta;
 *   - el `slugRuta` que usa la SPA para las rutas de categorias/marcas;
 *   - la limpieza de texto para las descripciones.
 */
class SeoContexto
{
    /**
     * Prefijo de Cloudinary que usa la SPA para las imagenes de un comercio con
     * `from_cloudinary` (components/catalogo/Category.vue::resolve_tile_image_url).
     */
    const CLOUDINARY = 'https://res.cloudinary.com/lucas-cn/image/upload/q_auto,f_auto/';

    /**
     * El `sitio` que se acepta del request. Es la regex del contrato A del plan: esquema, host
     * en minusculas y puerto opcional — nada de path, query, comillas ni `javascript:`.
     * Todo lo que no pase cae al `online` del comercio, y si tampoco, a URLs relativas.
     */
    const REGEX_SITIO = '/^https?:\/\/[a-z0-9.-]+(:\d+)?$/';

    /** @var \App\User */
    public $commerce;

    /** @var \App\OnlineConfiguration */
    public $config;

    /** @var string  Origen sin barra final, o '' si no hay ninguno valido. */
    public $sitio;

    /** @var string  Nombre publico del comercio (company_name, o name si no tiene). */
    public $nombre;

    /** @var bool|null  Memo de precio_publico(). */
    private $precio_publico = null;

    /**
     * Arma el contexto, o devuelve null si el comercio no existe o no tiene tienda configurada.
     *
     * 🔴 Ese null se traduce en un 404 HTTP (no en `estado: 404` adentro de un 200). La
     * diferencia importa: con un commerce_id mal compilado en el build, un `estado: 404`
     * haria que seo.php le conteste 404 a Google en TODAS las paginas de la tienda. Un 404
     * HTTP, en cambio, es "la API no pudo contestar" y seo.php cae al index.html de siempre.
     *
     * @param  int|string  $commerce_id
     * @param  string|null  $sitio_pedido  El `sitio` del query string, sin validar.
     * @return self|null
     */
    static function armar($commerce_id, $sitio_pedido)
    {
        $commerce = User::where('id', $commerce_id)
            ->select('id', 'name', 'company_name', 'email', 'phone', 'image_url', 'hosting_image_url', 'from_cloudinary', 'online')
            ->with('addresses', 'online_configuration.online_price_type')
            ->first();

        if (is_null($commerce) || is_null($commerce->online_configuration)) {
            return null;
        }

        $contexto = new self;
        $contexto->commerce = $commerce;
        $contexto->config   = $commerce->online_configuration;
        $contexto->nombre   = self::primeroNoVacio([$commerce->company_name, $commerce->name], 'Tienda online');
        $contexto->sitio    = self::resolverSitio($sitio_pedido, $commerce->online);

        return $contexto;
    }

    /**
     * El origen para las URLs absolutas: el `sitio` del request si pasa la regex; si no, el
     * `online` del comercio (que en la base suele estar SIN esquema, ej. `tienda.local:8001`,
     * y por eso se le antepone https://); si tampoco, '' y todo sale relativo.
     *
     * @param  string|null  $sitio_pedido
     * @param  string|null  $online
     * @return string
     */
    static function resolverSitio($sitio_pedido, $online)
    {
        if (is_string($sitio_pedido)) {
            $sitio = rtrim(strtolower(trim($sitio_pedido)), '/');
            if (preg_match(self::REGEX_SITIO, $sitio)) {
                return $sitio;
            }
        }

        if (is_string($online) && trim($online) !== '') {
            $sitio = rtrim(strtolower(trim($online)), '/');
            if (!preg_match('/^https?:\/\//', $sitio)) {
                $sitio = 'https://'.$sitio;
            }
            if (preg_match(self::REGEX_SITIO, $sitio)) {
                return $sitio;
            }
        }

        return '';
    }

    /**
     * URL (absoluta si hay sitio) de un path que ya viene armado con segmentos codificados.
     *
     * @param  string  $path  Empieza con '/'.
     * @return string
     */
    function url($path)
    {
        return $this->sitio.$path;
    }

    /**
     * Arma un path a partir de segmentos crudos, codificando cada uno con codificarSegmento().
     *
     * @param  array  $segmentos
     * @return string
     */
    static function path(array $segmentos)
    {
        $codificados = [];
        foreach ($segmentos as $segmento) {
            $codificados[] = self::codificarSegmento((string) $segmento);
        }
        return '/'.implode('/', $codificados);
    }

    /**
     * Codifica un segmento de path SOLO en lo que rompe la URL, y deja las letras no ASCII
     * (tildes, eñe) tal cual.
     *
     * Por que no rawurlencode() a secas: la SPA arma sus rutas con routeString() —minusculas
     * y espacios a guiones, nada mas— y vue-router las deja con la eñe cruda. La canonica
     * tiene que dar igual en los dos lados, asi que aca tampoco se tocan las letras. Lo que si
     * se codifica es lo que partiria la URL en otro lado: '/', '?', '#', '%', comillas, '<'...
     * (una categoria "Vinos/Espumantes" no puede convertirse en dos segmentos).
     *
     * El sitemap, que por protocolo necesita URLs RFC 3986, pasa esto despues por
     * SeoSitemap::uriAscii().
     *
     * @param  string  $segmento
     * @return string
     */
    static function codificarSegmento($segmento)
    {
        return preg_replace_callback('/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\x80-\xFF]/', function ($match) {
            return rawurlencode($match[0]);
        }, $segmento);
    }

    /**
     * El slug de ruta de la SPA: `value.toLowerCase().replaceAll(' ', '-')` (routeString, en
     * src/mixins/generals.js). NO quita tildes ni otros caracteres, a proposito: la SPA
     * tampoco, y si este lado normalizara distinto, la canonica de la API no coincidiria con
     * la de la SPA.
     *
     * @param  string|null  $nombre
     * @return string
     */
    static function slugRuta($nombre)
    {
        return str_replace(' ', '-', mb_strtolower((string) $nombre, 'UTF-8'));
    }

    /**
     * ¿Un visitante sin sesion ve precios en esta tienda? Es la pregunta que se hace un
     * crawler, y la respuesta tiene que ser la MISMA que la de la tienda: publicar en el
     * JSON-LD un precio que la tienda esconde es filtrarlo, y a Google encima.
     *
     * Se cumple solo si se cumplen las tres:
     *   1. ArticleHelper::anonimo_puede_ver_precios() — el espejo servidor de
     *      puede_ver_precios() de la SPA (register_to_buy + slug de online_price_type). Es la
     *      misma regla que ya aplica checkPriceTypes() y que clava
     *      tests/Feature/VisibilidadDePrecios.
     *   2. `online_price_type` cargado: la ficha de la SPA (components/article/components/
     *      data/Price.vue:3) no dibuja precio sin el, aunque el punto 1 de verdadero.
     *   3. SIN la extension `lista_de_precios_por_rango_de_cantidad_vendida`. Con ella el
     *      precio que muestra la SPA sale de los rangos por categoria (articlePriceEfectivo)
     *      y no de final_price. Replicar ese calculo aca para un dato opcional del JSON-LD es
     *      un riesgo sin premio: ante la duda, sin precio.
     *
     * @return bool
     */
    function precioPublico()
    {
        if (is_null($this->precio_publico)) {
            $this->precio_publico = ArticleHelper::anonimo_puede_ver_precios($this->commerce->id)
                && !is_null($this->config->online_price_type)
                && !CommerceHelper::hasExtencion('lista_de_precios_por_rango_de_cantidad_vendida', null, $this->commerce->id);
        }
        return $this->precio_publico;
    }

    /**
     * El precio que la tienda le muestra al anonimo para un articulo o promocion, o null.
     *
     * Para un Article, `final_price` tiene que venir YA resuelto por
     * ArticleHelper::checkPriceTypes() (lista de position mas alta visible al publico). Aca se
     * suman las reglas de la SPA que viven en articlePriceEfectivo() (mixins/generals.js):
     * `precio_pausado` → no hay importe (la SPA muestra un texto), y el recargo
     * `online_price_surchage` con Math.round.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $modelo
     * @return float|null
     */
    function precio($modelo)
    {
        if (!$this->precioPublico()) {
            return null;
        }
        if ((int) $modelo->precio_pausado === 1) {
            return null;
        }
        if (is_null($modelo->final_price) || !is_numeric($modelo->final_price)) {
            return null;
        }

        $precio = (float) $modelo->final_price;
        if ($precio <= 0) {
            return null;
        }

        $recargo = $this->config->online_price_surchage;
        if (!empty($recargo) && is_numeric($recargo)) {
            $precio += $precio * (float) $recargo / 100;
            $precio = round($precio);
        }

        return round($precio, 2);
    }

    /**
     * Precio en formato argentino para el texto: "$ 12.345" o "$ 12.345,50".
     *
     * @param  float  $precio
     * @return string
     */
    static function formatearPrecio($precio)
    {
        $decimales = (round($precio) == $precio) ? 0 : 2;
        return '$ '.number_format($precio, $decimales, ',', '.');
    }

    /**
     * ¿El articulo esta disponible? Mismo criterio que scopeCheckStock de Article, pero
     * mirando UN articulo (la ficha no filtra por stock: se ve igual, y el JSON-LD dice
     * OutOfStock).
     *
     * @param  \Illuminate\Database\Eloquent\Model  $modelo
     * @return bool
     */
    function hayStock($modelo)
    {
        if ($this->config->ignorar_stock) {
            return true;
        }
        $stock = $modelo->stock;
        if (is_null($stock)) {
            return !$this->config->stock_null_equal_0;
        }
        return (float) $stock > 0;
    }

    /**
     * Pasa una URL de imagen guardada en la base a URL absoluta, o null si no se puede.
     *
     * Replica components/catalogo/Category.vue::resolve_tile_image_url(): lo que ya es http(s)
     * va tal cual; con `from_cloudinary` lo demas es un public id de Cloudinary. Un path
     * relativo sin Cloudinary no tiene un host conocido desde aca: null (el llamador cae a la
     * imagen por defecto o al logo), porque una URL relativa en og:image no le sirve a nadie.
     *
     * @param  string|null  $cruda
     * @return string|null
     */
    function absolutizarImagen($cruda)
    {
        if (!is_string($cruda) || trim($cruda) === '') {
            return null;
        }
        $cruda = trim($cruda);
        if (preg_match('/^https?:\/\//i', $cruda)) {
            return $cruda;
        }
        if (strpos($cruda, '//') === 0) {
            return 'https:'.$cruda;
        }
        if ($this->commerce->from_cloudinary) {
            return self::CLOUDINARY.ltrim($cruda, '/');
        }
        return null;
    }

    /**
     * Todas las imagenes de un articulo/promocion, absolutas, en el orden en que la SPA las
     * muestra (images[0] es la principal: mixins/generals.js::articleImage()).
     *
     * @param  \Illuminate\Database\Eloquent\Model  $modelo  Con `images` cargada.
     * @return array<int, string>
     */
    function imagenes($modelo)
    {
        $urls = [];
        foreach ($modelo->images as $imagen) {
            $url = $this->absolutizarImagen($imagen->hosting_url);
            if (!is_null($url)) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /**
     * La imagen principal de un articulo: la primera propia, o la imagen por defecto de la
     * tienda (online_configuration.default_article_image_url), igual que articleImage().
     *
     * @param  \Illuminate\Database\Eloquent\Model  $modelo
     * @return string|null
     */
    function imagenPrincipal($modelo)
    {
        $imagenes = $this->imagenes($modelo);
        if (count($imagenes)) {
            return $imagenes[0];
        }
        return $this->absolutizarImagen($this->config->default_article_image_url);
    }

    /**
     * El logo de la tienda: online_configuration.logo_url, o el image_url del comercio
     * (components/nav/BrandBtn.vue).
     *
     * @return string|null
     */
    function logo()
    {
        $logo = $this->absolutizarImagen($this->config->logo_url);
        if (is_null($logo)) {
            $logo = $this->absolutizarImagen($this->commerce->image_url);
        }
        if (is_null($logo)) {
            $logo = $this->absolutizarImagen($this->commerce->hosting_image_url);
        }
        return $logo;
    }

    /**
     * Texto plano a partir de algo que puede traer HTML (las descripciones del ERP se cargan
     * con editor y la SPA las pinta con v-html): sin etiquetas, sin entidades, con los
     * espacios colapsados.
     *
     * @param  string|null  $html
     * @return string
     */
    static function textoPlano($html)
    {
        $texto = preg_replace('/<[^>]*>/u', ' ', (string) $html);
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace('/\s+/u', ' ', $texto);
        return trim((string) $texto);
    }

    /**
     * Corta un texto plano para la meta description: si pasa de 160 caracteres, lo corta en
     * 155 en el ultimo espacio y le pone '…'. Nunca devuelve mas de 160.
     *
     * @param  string  $texto
     * @return string
     */
    static function cortar($texto, $maximo = 160, $corte = 155)
    {
        $texto = trim($texto);
        if (mb_strlen($texto, 'UTF-8') <= $maximo) {
            return $texto;
        }
        $cortado = mb_substr($texto, 0, $corte, 'UTF-8');
        $espacio = mb_strrpos($cortado, ' ', 0, 'UTF-8');
        if ($espacio !== false && $espacio > 60) {
            $cortado = mb_substr($cortado, 0, $espacio, 'UTF-8');
        }
        return rtrim($cortado, " \t,.;:-–—").'…';
    }

    /**
     * El primer valor no vacio de la lista (tras trim), o el default.
     *
     * @param  array  $valores
     * @param  string  $default
     * @return string
     */
    static function primeroNoVacio(array $valores, $default = '')
    {
        foreach ($valores as $valor) {
            if (is_string($valor) && trim($valor) !== '') {
                return trim($valor);
            }
        }
        return $default;
    }
}
