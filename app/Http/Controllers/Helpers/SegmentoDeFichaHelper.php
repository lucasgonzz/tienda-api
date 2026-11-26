<?php

namespace App\Http\Controllers\Helpers;

use App\Article;

/**
 * El segmento de la URL de una ficha: `/articulos/{segmento}/{commerce_id}`
 * (mision tienda-articulos-sin-slug, 26/11/2026).
 *
 * Regla UNICA (PLAN §2.1), la misma que aplica tienda-spa en `src/helpers/segmento_ficha.js`:
 *   - Si el articulo tiene `slug` (string no vacio despues de `trim`), el segmento ES el slug.
 *   - Si no, el segmento es `~<id>` (tilde + id decimal del articulo).
 *
 * Por que la tilde: `Str::slug()` solo produce `[a-z0-9-]`, asi que ningun slug generado puede
 * contener `~`; un numero a secas NO sirve porque `Str::slug('2000')` es un slug real posible.
 *
 * Lo usan `ArticleController@show` (la API) y `SeoPaginas` (el SEO del servidor): una sola regla.
 */
class SegmentoDeFichaHelper
{
    /** Prefijo que marca un segmento como "id del articulo" y no como slug. */
    const PREFIJO = '~';

    /**
     * El id de articulo que nombra un segmento `~<id>`, o null si el segmento no es de ese formato.
     *
     * Solo valen enteros positivos sin ceros a la izquierda (`~823`); `~`, `~0`, `~abc`, `~12x`,
     * `~-5` y `~01` devuelven null. El `\z` (y no `$`) evita que un salto de linea final pase la
     * validacion, y el tope de 18 digitos evita un desborde del entero.
     *
     * @param  string|null  $segmento  Lo que llego en la posicion del slug.
     * @return int|null
     */
    public static function id_de_segmento($segmento)
    {
        if (!is_string($segmento)) {
            return null;
        }

        if (preg_match('/^~([1-9][0-9]{0,17})\z/', $segmento, $coincidencia) !== 1) {
            return null;
        }

        return (int) $coincidencia[1];
    }

    /**
     * El segmento de ficha de un articulo: su slug, o `~<id>` si no tiene.
     *
     * @param  \App\Article  $article
     * @return string
     */
    public static function segmento(Article $article)
    {
        $slug = trim((string) $article->slug);

        if ($slug !== '') {
            return $article->slug;
        }

        return self::PREFIJO.$article->id;
    }
}
