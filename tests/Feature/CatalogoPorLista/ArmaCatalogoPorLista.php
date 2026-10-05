<?php

namespace Tests\Feature\CatalogoPorLista;

use App\Article;
use App\Buyer;
use App\Category;
use App\Client;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ArticlePriceRangeHelper;
use App\Http\Controllers\Helpers\CatalogoPorListaHelper;
use App\Http\Controllers\Helpers\ClientOfferHelper;
use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use App\OnlineConfiguration;
use App\PriceType;
use App\SubCategory;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fixtures de la mision catalogo-por-lista-tienda (5/10/2026): el caso de Ferretotal en chico.
 *
 * ── El escenario estandar (`armarFerretotal()`) ───────────────────────────────────────────────
 *
 * Un comercio con DOS listas:
 *   - Minorista, `position` 2: la de position mas alta, o sea la del visitante. Sin restriccion.
 *   - Mayorista, `position` 1, RESTRINGIDA (`catalogo_restringido_en_tienda = 1`).
 *
 * Tres articulos, los tres con precio en las dos listas (como en Ferretotal: "tiene precio en la
 * lista" no decide nada) y los tres online, con stock y destacados:
 *   - `habilitado`:    fila de Mayorista con `visible_en_tienda = 1`.
 *   - `sin_marcar`:    fila de Mayorista con `visible_en_tienda` NULL (como nace un articulo).
 *   - `deshabilitado`: fila de Mayorista con `visible_en_tienda = 0`.
 *
 * NULL y 0 tienen que comportarse igual ("no habilitado"); por eso hay uno de cada uno.
 *
 * Y la estructura de navegacion:
 *   - Categoria "Herramientas" con `habilitado` (sub Taladros) y `sin_marcar` (sub Martillos).
 *   - Categoria "Solo Minorista" con `deshabilitado` (sub Pinturas): para el mayorista queda VACIA.
 *   - Marca "Habilitada" con `habilitado` y `sin_marcar`; marca "Solo Minorista" con `deshabilitado`.
 *
 * El precio de la COLUMNA `final_price` es un centinela (9999): si algun caso ve ese numero, es que
 * `checkPriceTypes()` no aplico el pivote de ninguna lista.
 *
 * ── Un comercio PROPIO por test ──────────────────────────────────────────────────────────────
 *
 * Mismo criterio que `CombosYRangos\ArmaComercioConCombosYRangos`: estos casos cuentan articulos y
 * categorias, y el resultado no puede depender de lo que la siembra del slot dejo colgando de otro
 * comercio. Todo lo que se crea acá se va con `DatabaseTransactions`, salvo en la clase de guarda de
 * esquema, que lo dice en su propio docblock.
 *
 * ── Las memorias ─────────────────────────────────────────────────────────────────────────────
 *
 * La de `CatalogoPorListaHelper` vive en el request y muere sola entre requests; igual se olvida en
 * `setUp`/`tearDown` junto con las estaticas de los otros helpers del camino, por los casos que
 * llaman al helper directo.
 */
trait ArmaCatalogoPorLista
{
    /** El centinela de la columna `final_price`. Ver el docblock del trait. */
    const PRECIO_DE_LA_COLUMNA = 9999.00;

    /** @var \App\User */
    protected $comercio;

    /** @var \App\PriceType */
    protected $minorista;

    /** @var \App\PriceType */
    protected $mayorista;

    /** @var \App\Article */
    protected $habilitado;

    /** @var \App\Article */
    protected $sin_marcar;

    /** @var \App\Article */
    protected $deshabilitado;

    /** @var \App\Category */
    protected $herramientas;

    /** @var \App\Category */
    protected $solo_minorista;

    /** @var \App\SubCategory */
    protected $taladros;

    /** @var \App\SubCategory */
    protected $martillos;

    /** @var \App\SubCategory */
    protected $pinturas;

    /** @var int */
    protected $marca_habilitada;

    /** @var int */
    protected $marca_solo_minorista;

    /** @var \App\Buyer|null El comprador con sesion abierta, o null si es un visitante. */
    protected $comprador_de_la_sesion = null;

    /**
     * Arma el escenario estandar. Ver el docblock del trait.
     *
     * @return void
     */
    protected function armarFerretotal()
    {
        $this->comercio = $this->comercioConTienda();

        $this->minorista = $this->lista($this->comercio, 'Minorista', 2);
        $this->mayorista = $this->lista($this->comercio, 'Mayorista', 1);
        $this->restringirLista($this->mayorista);

        $this->herramientas = $this->categoria($this->comercio, 'Herramientas');
        $this->solo_minorista = $this->categoria($this->comercio, 'Solo Minorista');

        $this->taladros = $this->subCategoria($this->herramientas, 'Taladros');
        $this->martillos = $this->subCategoria($this->herramientas, 'Martillos');
        $this->pinturas = $this->subCategoria($this->solo_minorista, 'Pinturas');

        $this->marca_habilitada = $this->marca($this->comercio, 'Habilitada');
        $this->marca_solo_minorista = $this->marca($this->comercio, 'Solo Minorista');

        $this->habilitado = $this->articulo($this->comercio, [
            'name'            => 'Catalogo Taladro Habilitado',
            'category_id'     => $this->herramientas->id,
            'sub_category_id' => $this->taladros->id,
            'brand_id'        => $this->marca_habilitada,
        ]);
        $this->precioEnLista($this->habilitado, $this->minorista, 1500);
        $this->precioEnLista($this->habilitado, $this->mayorista, 1000, 1);

        $this->sin_marcar = $this->articulo($this->comercio, [
            'name'            => 'Catalogo Martillo Sin Marcar',
            'category_id'     => $this->herramientas->id,
            'sub_category_id' => $this->martillos->id,
            'brand_id'        => $this->marca_habilitada,
        ]);
        $this->precioEnLista($this->sin_marcar, $this->minorista, 2500);
        $this->precioEnLista($this->sin_marcar, $this->mayorista, 2000, null);

        $this->deshabilitado = $this->articulo($this->comercio, [
            'name'            => 'Catalogo Pintura Deshabilitada',
            'category_id'     => $this->solo_minorista->id,
            'sub_category_id' => $this->pinturas->id,
            'brand_id'        => $this->marca_solo_minorista,
        ]);
        $this->precioEnLista($this->deshabilitado, $this->minorista, 3500);
        $this->precioEnLista($this->deshabilitado, $this->mayorista, 3000, 0);
    }

    /**
     * Los tres ids del escenario estandar, ordenados.
     *
     * @return array
     */
    protected function losTres()
    {
        return $this->ordenados([$this->habilitado->id, $this->sin_marcar->id, $this->deshabilitado->id]);
    }

    /**
     * Comercio nuevo con su configuracion online.
     *
     * Sin exigir imagenes ni stock: lo que estos casos miden es la lista, y un articulo escondido por
     * otra regla de `checkOnline()` daria verde por el motivo equivocado. `online_price_type_id` en
     * NULL: el visitante ve precios, como en una tienda comun.
     *
     * @return \App\User
     */
    protected function comercioConTienda()
    {
        $comercio = User::create([
            'name'     => 'Comercio Catalogo Por Lista Test',
            'email'    => 'catalogo-lista-'.Str::random(10).'@test.local',
            'password' => bcrypt('secreto'),
            'status'   => 'commerce',
        ]);

        OnlineConfiguration::create([
            'user_id'                      => $comercio->id,
            'show_articles_without_images' => 1,
            'show_articles_without_stock'  => 1,
        ]);

        return $comercio;
    }

    /**
     * Una lista de precios del comercio. `PriceType` no declara `$fillable`: va campo por campo.
     *
     * @param  \App\User  $comercio
     * @param  string  $nombre
     * @param  int|null  $position
     * @param  array  $atributos  Por ejemplo `ocultar_al_publico`.
     * @return \App\PriceType
     */
    protected function lista(User $comercio, $nombre, $position, array $atributos = [])
    {
        $lista = new PriceType;
        $lista->name = $nombre;
        $lista->position = $position;
        $lista->user_id = $comercio->id;

        foreach ($atributos as $columna => $valor) {
            $lista->{$columna} = $valor;
        }

        $lista->save();

        return $lista;
    }

    /**
     * Prende el interruptor de la lista, como lo deja el ABM de listas del ERP.
     *
     * @param  \App\PriceType  $lista
     * @return void
     */
    protected function restringirLista(PriceType $lista)
    {
        DB::table('price_types')->where('id', $lista->id)->update([
            CatalogoPorListaHelper::COLUMNA_LISTA => 1,
        ]);
    }

    /**
     * Un articulo publicado del comercio, con el centinela en la columna `final_price`.
     *
     * @param  \App\User  $comercio
     * @param  array  $atributos
     * @return \App\Article
     */
    protected function articulo(User $comercio, array $atributos = [])
    {
        return Article::create(array_merge([
            'name'        => 'Catalogo Articulo Test',
            'slug'        => 'catalogo-lista-test-'.Str::random(12),
            'user_id'     => $comercio->id,
            'status'      => 'active',
            'online'      => 1,
            'stock'       => 10,
            'featured'    => 1,
            'final_price' => self::PRECIO_DE_LA_COLUMNA,
        ], $atributos));
    }

    /**
     * La fila de pivote `(articulo, lista)` tal como la escribe el ERP.
     *
     * @param  \App\Article  $articulo
     * @param  \App\PriceType  $lista
     * @param  float  $final_price
     * @param  int|null  $visible  `visible_en_tienda`: 1, 0 o null.
     * @return int  El id de la fila.
     */
    protected function precioEnLista(Article $articulo, PriceType $lista, $final_price, $visible = null)
    {
        return DB::table('article_price_type')->insertGetId([
            'article_id'                         => $articulo->id,
            'price_type_id'                      => $lista->id,
            'final_price'                        => $final_price,
            CatalogoPorListaHelper::COLUMNA_PIVOTE => $visible,
            'created_at'                         => Carbon::now(),
            'updated_at'                         => Carbon::now(),
        ]);
    }

    /**
     * @param  \App\User  $comercio
     * @param  string  $nombre
     * @return \App\Category
     */
    protected function categoria(User $comercio, $nombre)
    {
        return Category::create(['name' => $nombre, 'user_id' => $comercio->id]);
    }

    /**
     * `SubCategory` no declara `$fillable`: va campo por campo.
     *
     * @param  \App\Category  $categoria
     * @param  string  $nombre
     * @return \App\SubCategory
     */
    protected function subCategoria(Category $categoria, $nombre)
    {
        $sub = new SubCategory;
        $sub->name = $nombre;
        $sub->category_id = $categoria->id;
        $sub->user_id = $categoria->user_id;
        $sub->save();

        return $sub;
    }

    /**
     * Una marca del comercio. `Brand` no declara `$fillable`: va por la tabla.
     *
     * @param  \App\User  $comercio
     * @param  string  $nombre
     * @return int
     */
    protected function marca(User $comercio, $nombre)
    {
        return DB::table('brands')->insertGetId([
            'name'       => $nombre,
            'user_id'    => $comercio->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Un comprador CON CUENTA vinculado a un cliente del ERP con esa lista.
     *
     * `$price_type_id` puede ser 0 a proposito: es como el ERP deja a un cliente "sin lista" en
     * algunas bases, y la tienda lo tiene que tratar igual que null.
     *
     * @param  \App\User  $comercio
     * @param  int|null  $price_type_id
     * @return \App\Buyer
     */
    protected function compradorConLista(User $comercio, $price_type_id)
    {
        $client = new Client;
        $client->name = 'Cliente Catalogo Test';
        $client->user_id = $comercio->id;
        $client->price_type_id = $price_type_id;
        $client->save();

        return Buyer::create([
            'name'                    => 'Comprador Catalogo Test',
            'email'                   => 'catalogo-comprador-'.Str::random(10).'@test.local',
            'password'                => bcrypt('secreto-catalogo'),
            'comercio_city_client_id' => $client->id,
            'user_id'                 => $comercio->id,
        ]);
    }

    /**
     * Un comprador con cuenta y SIN cliente del ERP.
     *
     * @param  \App\User  $comercio
     * @return \App\Buyer
     */
    protected function compradorSinCliente(User $comercio)
    {
        return Buyer::create([
            'name'     => 'Comprador Sin Cliente Catalogo Test',
            'email'    => 'catalogo-sin-cliente-'.Str::random(10).'@test.local',
            'password' => bcrypt('secreto-catalogo'),
            'user_id'  => $comercio->id,
        ]);
    }

    /**
     * Abre la sesion del comprador en el guard de la tienda.
     *
     * @param  \App\Buyer  $buyer
     * @return void
     */
    protected function comoComprador(Buyer $buyer)
    {
        $this->comprador_de_la_sesion = $buyer;

        $this->actingAs($buyer, 'buyer');
    }

    /**
     * Vuelve a visitante sin sesion. `actingAs()` no escribe la sesion, asi que olvidar el usuario
     * del guard alcanza.
     *
     * @return void
     */
    protected function comoVisitante()
    {
        $this->comprador_de_la_sesion = null;

        $this->app['auth']->guard('buyer')->forgetUser();
    }

    /**
     * Vuelve a cargar de la base al comprador de la sesion.
     *
     * ⚠️ `actingAs()` deja en el guard LA MISMA instancia del comprador para todos los requests del
     * caso, con sus relaciones cacheadas (`comercio_city_client` y su `price_type`). En produccion no
     * pasa: cada request lee al comprador de la sesion de cero. Un caso que cambia la configuracion
     * de una lista entre dos requests tiene que llamar a esto, o el segundo request veria la lista de
     * antes del cambio.
     *
     * @return void
     */
    protected function refrescarLaSesion()
    {
        if (!is_null($this->comprador_de_la_sesion)) {
            $this->comoComprador(Buyer::find($this->comprador_de_la_sesion->id));
        }
    }

    /**
     * Los ids de un listado de la respuesta (array de modelos serializados), ordenados.
     *
     * @param  array|null  $modelos
     * @return array
     */
    protected function idsDe($modelos)
    {
        $ids = [];

        foreach ((array) $modelos as $modelo) {
            if (is_array($modelo) && isset($modelo['id'])) {
                $ids[] = (int) $modelo['id'];
            }
        }

        return $this->ordenados($ids);
    }

    /**
     * @param  array  $ids
     * @return array
     */
    protected function ordenados(array $ids)
    {
        $ids = array_map('intval', $ids);
        sort($ids);

        return $ids;
    }

    /**
     * Todas las memorias estaticas del camino.
     *
     * @return void
     */
    protected function olvidarLasMemorias()
    {
        CatalogoPorListaHelper::olvidar();
        ArticleHelper::olvidar_visibilidad_del_anonimo();
        ArticlePriceRangeHelper::olvidar();
        ComboEsquemaHelper::olvidar();
        ClientOfferHelper::olvidarMemoria();
    }
}
