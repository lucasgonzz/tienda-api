<?php

namespace Tests\Feature\Seo;

use App\Article;
use App\Brand;
use App\Category;
use App\Description;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\OnlinePriceType;
use App\SubCategory;
use App\User;

/**
 * Arma, adentro de la transaccion del test, una tienda chica para probar /api/seo
 * (mision seo-tiendas).
 *
 * ⚠️ Mismo criterio que el resto de la suite (ver tests/Feature/VisibilidadDePrecios): tienda-api
 * no tiene database/migrations, asi que se corre contra la base real del slot con
 * DatabaseTransactions, sobre el primer comercio sembrado. Por eso todo lo que se crea lleva un
 * sufijo unico: la base del slot ya trae categorias y articulos de ese comercio.
 */
trait ArmaTiendaParaSeo
{
    /** @var \App\User */
    protected $comercio;

    /** @var string  Sufijo unico para los nombres de este test. */
    protected $sufijo;

    /**
     * Deja el comercio con una configuracion conocida: precios visibles para el anonimo, sin
     * exigir imagenes, sin recargo, y con `online` en un host controlado.
     */
    protected function armarComercio()
    {
        ArticleHelper::olvidar_visibilidad_del_anonimo();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        $this->sufijo = substr(uniqid(), -6);

        User::where('id', $this->comercio->id)->update([
            'company_name' => 'Vinoteca Test',
            'online'       => 'mitienda.test',
            'phone'        => '+54 9 342 555-1234',
            'email'        => 'ventas@mitienda.test',
        ]);

        $this->configurar([
            'show_articles_without_images' => 1,
            'show_articles_without_stock'  => 0,
            'stock_null_equal_0'           => 0,
            'online_price_surchage'        => null,
            'default_article_image_url'    => null,
            'logo_url'                     => null,
            'meta_description'             => null,
        ]);
        $this->visibilidad('all', 0);
    }

    /**
     * @param  array  $columnas  Columnas de online_configurations.
     */
    protected function configurar(array $columnas)
    {
        $this->comercio->online_configuration()->update($columnas);
        ArticleHelper::olvidar_visibilidad_del_anonimo();
    }

    /**
     * @param  string  $slug  all | only_registered | only_buyers_with_comerciocity_client
     * @param  int  $register_to_buy
     */
    protected function visibilidad($slug, $register_to_buy)
    {
        $id = OnlinePriceType::where('slug', $slug)->value('id');
        $this->assertNotNull($id, 'La base del slot tiene que tener el catalogo online_price_types sembrado.');
        $this->configurar(['register_to_buy' => $register_to_buy, 'online_price_type_id' => $id]);
    }

    protected function categoria($nombre)
    {
        return Category::create(['name' => $nombre.' '.$this->sufijo, 'user_id' => $this->comercio->id]);
    }

    protected function subcategoria($categoria, $nombre)
    {
        $sub = new SubCategory;
        $sub->name = $nombre.' '.$this->sufijo;
        $sub->category_id = $categoria->id;
        $sub->user_id = $this->comercio->id;
        $sub->save();
        return $sub;
    }

    protected function marca($nombre)
    {
        $marca = new Brand;
        $marca->name = $nombre.' '.$this->sufijo;
        $marca->user_id = $this->comercio->id;
        $marca->save();
        return $marca;
    }

    /**
     * Un articulo online, activo y con stock del comercio del test.
     *
     * @param  string  $nombre
     * @param  array  $extra
     * @return \App\Article
     */
    protected function articulo($nombre, array $extra = [])
    {
        return Article::create(array_merge([
            'name'        => $nombre,
            'slug'        => 'seo-test-'.uniqid(),
            'user_id'     => $this->comercio->id,
            'status'      => 'active',
            'online'      => 1,
            'stock'       => 10,
            'final_price' => 1234.5,
        ], $extra));
    }

    protected function imagen($articulo, $url)
    {
        return $articulo->images()->create(['hosting_url' => $url]);
    }

    protected function descripcion($articulo, $titulo, $contenido)
    {
        $descripcion = new Description;
        $descripcion->title = $titulo;
        $descripcion->content = $contenido;
        $descripcion->article_id = $articulo->id;
        $descripcion->save();
        return $descripcion;
    }

    /**
     * La respuesta de /api/seo/pagina, validando que sea 200 JSON.
     *
     * @param  string  $ruta
     * @param  string|null  $sitio
     * @return array
     */
    protected function pagina($ruta, $sitio = 'https://mitienda.test')
    {
        $query = ['ruta' => $ruta];
        if (!is_null($sitio)) {
            $query['sitio'] = $sitio;
        }
        return $this->getJson('/api/seo/pagina/'.$this->comercio->id.'?'.http_build_query($query))
            ->assertStatus(200)
            ->json();
    }

    /**
     * El JSON-LD de un tipo dado, o null.
     *
     * @param  array  $pagina
     * @param  string  $tipo
     * @return array|null
     */
    protected function jsonLd(array $pagina, $tipo)
    {
        foreach ($pagina['json_ld'] as $objeto) {
            if (($objeto['@type'] ?? null) === $tipo) {
                return $objeto;
            }
        }
        return null;
    }

    /** Slug de ruta de la SPA (routeString). */
    protected function slugRuta($nombre)
    {
        return str_replace(' ', '-', mb_strtolower($nombre, 'UTF-8'));
    }
}
