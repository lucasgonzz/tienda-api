<?php

namespace App\Http\Controllers\Helpers\Seo;

/**
 * Los pedazos de HTML de `cuerpo_html` (mision seo-tiendas).
 *
 * Este HTML es lo que ve un crawler SIN JavaScript: seo.php lo mete adentro de `#app` y Vue lo
 * reemplaza al montar. O sea que no es para el usuario — es para que Google, WhatsApp, Bing y
 * las IA vean una tienda con estructura: un <header> con la navegacion, un <main> con un h1 y
 * links <a href> reales a las fichas (hoy la SPA navega con $router.push y Google no descubre
 * ni un producto), y un <footer> con los datos de contacto.
 *
 * 🔴 TODO lo que viene de la base pasa por e(). Los nombres de articulos y categorias los
 * carga el comercio en el ERP, y este HTML se inyecta crudo en la pagina: un "<script>" en un
 * nombre seria un XSS servido desde el dominio de la tienda. seo.php NO vuelve a escapar
 * (el contrato dice "HTML ya escapado por la API").
 *
 * Sin estilos inline: no se ve, y cada byte de mas lo paga el primer render de cada visita.
 */
class SeoHtml
{
    /** Los links institucionales del footer, en el orden de la SPA. */
    const INSTITUCIONALES = [
        '/contacto'                => 'Contacto',
        '/quienes-somos'           => 'Quiénes somos',
        '/terminos-y-condiciones'  => 'Términos y condiciones',
        '/politica-de-privacidad'  => 'Política de privacidad',
    ];

    /** Tope de categorias en la navegacion del header. */
    const CATEGORIAS_EN_EL_HEADER = 40;

    /**
     * El <header>: logo con alt, nombre del comercio y la navegacion por categorias.
     *
     * @param  SeoContexto  $ctx
     * @param  iterable  $categorias  Con `id` y `name`.
     * @return string
     */
    static function header(SeoContexto $ctx, $categorias)
    {
        $home = e($ctx->url('/'));
        $html = '<header>';

        $logo = $ctx->logo();
        if (!is_null($logo)) {
            $html .= '<a href="'.$home.'"><img src="'.e($logo).'" alt="'.e($ctx->nombre).'"></a>';
        }
        $html .= '<p><a href="'.$home.'">'.e($ctx->nombre).'</a></p>';

        $items = '';
        $cantidad = 0;
        foreach ($categorias as $categoria) {
            if ($cantidad >= self::CATEGORIAS_EN_EL_HEADER) {
                break;
            }
            $url = $ctx->url(SeoContexto::path(['inicio', SeoContexto::slugRuta($categoria->name)]));
            $items .= '<li><a href="'.e($url).'">'.e($categoria->name).'</a></li>';
            $cantidad++;
        }
        if ($items !== '') {
            $html .= '<nav aria-label="Categorías"><ul>'.$items.'</ul></nav>';
        }

        return $html.'</header>';
    }

    /**
     * El <footer>: nombre, telefono, mail, direccion y los links institucionales.
     *
     * @param  SeoContexto  $ctx
     * @return string
     */
    static function footer(SeoContexto $ctx)
    {
        $html = '<footer><p>'.e($ctx->nombre).'</p>';

        $telefono = trim((string) $ctx->commerce->phone);
        if ($telefono !== '') {
            $tel = preg_replace('/[^0-9+]/', '', $telefono);
            $html .= '<p>Teléfono: <a href="tel:'.e($tel).'">'.e($telefono).'</a></p>';
        }

        $mail = trim((string) $ctx->commerce->email);
        if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $html .= '<p>Mail: <a href="mailto:'.e($mail).'">'.e($mail).'</a></p>';
        }

        $direccion = SeoPaginas::direccionEnTexto($ctx);
        if ($direccion !== '') {
            $html .= '<p>'.e($direccion).'</p>';
        }

        $items = '';
        foreach (self::INSTITUCIONALES as $path => $texto) {
            $items .= '<li><a href="'.e($ctx->url($path)).'">'.e($texto).'</a></li>';
        }
        $html .= '<nav aria-label="Información"><ul>'.$items.'</ul></nav>';

        return $html.'</footer>';
    }

    /**
     * Lista de links a fichas, con el precio al lado solo si es publico.
     *
     * @param  SeoContexto  $ctx
     * @param  iterable  $articulos  Articulos (o promociones) con `slug`, `name` y precio resuelto.
     * @return string
     */
    static function listaDeFichas(SeoContexto $ctx, $articulos)
    {
        $items = '';
        foreach ($articulos as $articulo) {
            $items .= '<li><a href="'.e(SeoPaginas::urlFicha($ctx, $articulo)).'">'.e($articulo->name).'</a>';
            $precio = $ctx->precio($articulo);
            if (!is_null($precio)) {
                $items .= ' <span>'.e(SeoContexto::formatearPrecio($precio)).'</span>';
            }
            $items .= '</li>';
        }
        if ($items === '') {
            return '';
        }
        return '<ul>'.$items.'</ul>';
    }

    /**
     * Lista de links genericos (subcategorias, categorias del catalogo, etc.).
     *
     * @param  array  $links  Pares [texto, url].
     * @return string
     */
    static function listaDeLinks(array $links)
    {
        if (!count($links)) {
            return '';
        }
        $items = '';
        foreach ($links as $link) {
            $items .= '<li><a href="'.e($link[1]).'">'.e($link[0]).'</a></li>';
        }
        return '<ul>'.$items.'</ul>';
    }

    /**
     * Las migas de pan visibles. El ultimo item va sin link (es la pagina actual).
     *
     * @param  array  $migas  Pares [nombre, url].
     * @return string
     */
    static function migas(array $migas)
    {
        $items = '';
        $ultimo = count($migas) - 1;
        foreach ($migas as $indice => $miga) {
            if ($indice === $ultimo) {
                $items .= '<li>'.e($miga[0]).'</li>';
            } else {
                $items .= '<li><a href="'.e($miga[1]).'">'.e($miga[0]).'</a></li>';
            }
        }
        return '<nav aria-label="Migas de pan"><ol>'.$items.'</ol></nav>';
    }

    /**
     * Parrafos a partir de texto plano (ya sin HTML). Un parrafo por renglon no vacio.
     *
     * @param  string  $texto
     * @return string
     */
    static function parrafos($texto)
    {
        $html = '';
        foreach (preg_split('/\R+/u', (string) $texto) as $renglon) {
            $renglon = trim($renglon);
            if ($renglon !== '') {
                $html .= '<p>'.e($renglon).'</p>';
            }
        }
        return $html;
    }
}
