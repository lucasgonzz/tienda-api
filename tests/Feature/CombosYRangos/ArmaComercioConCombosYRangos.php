<?php

namespace Tests\Feature\CombosYRangos;

use App\Article;
use App\ArticlePriceRange;
use App\Combo;
use App\Http\Controllers\Helpers\ArticlePriceRangeHelper;
use App\OnlineConfiguration;
use App\PromocionVinoteca;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fixtures de los tests de la mision combos-y-rangos-de-precio (16/9/2026).
 *
 * ── Un comercio PROPIO por test, y no `User::first()` ─────────────────────────────────────────
 *
 * El molde de la suite (`NovedadesDeLaHomeTest`) toma el comercio sembrado del slot, y para lo que
 * prueba esta bien. Acá no: estas clases cuentan combos publicados y articulos con tramos, o sea
 * que el resultado dependeria de lo que otro test —o la siembra del slot— haya dejado colgando de
 * ese comercio. Con un comercio nuevo por caso, las colecciones de la home arrancan vacias y
 * contar es una asercion honesta.
 *
 * Todo lo que se crea acá se va con `DatabaseTransactions`; ninguna clase de esta carpeta escribe
 * afuera de la transaccion, salvo la de guarda de esquema, que lo dice en su propio docblock.
 *
 * ── Las memorias estaticas ───────────────────────────────────────────────────────────────────
 *
 * `ArticlePriceRangeHelper` y `ComboEsquemaHelper` memoizan en estaticas. Bajo PHP-FPM eso muere
 * con el request; en phpunit el proceso sigue vivo entre casos, asi que `olvidarLasMemorias()` va
 * en el `setUp` Y en el `tearDown` de cada clase que use este trait.
 */
trait ArmaComercioConCombosYRangos
{
    /** El precio de lista del articulo de la reproduccion (el mismo numero de toda la mision). */
    const PRECIO_NORMAL = 3948.00;

    /** Tramo `Mayor o igual 5`. */
    const TRAMO_5 = 3500.00;

    /** Tramo `Igual 3` — el que prueba que el modo estricto viaja de punta a punta. */
    const TRAMO_IGUAL_3 = 3700.00;

    /** Tramo `Mayor o igual 10`, el mas profundo de la escala normal. */
    const TRAMO_10 = 3000.00;

    /** Precio del combo publicado de las fixtures. */
    const PRECIO_COMBO = 9000.00;

    /** Costo del combo publicado (lo que NO tiene que viajar al navegador). */
    const COSTO_COMBO = 4000.00;

    /**
     * Comercio nuevo con su configuracion online.
     *
     * La configuracion la necesitan `Article::scopeCheckOnline()` y `scopeCheckStock()` —que la
     * leen sin preguntar si existe— y `OrderHelper::attachArticles()`. Los defaults de la tabla ya
     * dejan al anonimo viendo precios (`online_price_type_id` NULL), que es el caso de la tienda
     * comun y el que estos tests quieren.
     *
     * @return \App\User
     */
    protected function comercioConTienda()
    {
        $comercio = User::create([
            'name'     => 'Comercio Combos Test',
            'email'    => 'combos-'.Str::random(10).'@test.local',
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
     * Un articulo publicado del comercio, con su precio de lista en la columna.
     *
     * Sin `price_types`: asi `ArticleHelper::checkPriceTypes()` cae en su Caso 4 y deja el
     * `final_price` de la columna, que es el numero contra el que miden estos tests.
     *
     * @param  \App\User  $comercio
     * @param  array  $atributos
     * @return \App\Article
     */
    protected function articuloPublicado(User $comercio, array $atributos = [])
    {
        return Article::create(array_merge([
            'name'        => 'Articulo Combos Test',
            'slug'        => 'combos-test-'.uniqid(),
            'user_id'     => $comercio->id,
            'status'      => 'active',
            'online'      => 1,
            'stock'       => 100,
            'final_price' => self::PRECIO_NORMAL,
        ], $atributos));
    }

    /**
     * La escala de tramos de la reproduccion, cargada en la base como la deja el ABM del ERP.
     *
     *   `Mayor o igual 5`  -> $3.500
     *   `Igual 3`          -> $3.700
     *   `Mayor o igual 10` -> $3.000
     *
     * El orden de insercion importa: `ArticlePriceRangeHelper::precargar()` ordena por `id ASC` y
     * "el primero del array" es un criterio del contrato (ver `MatcheoDeTramosTest`).
     *
     * @param  \App\Article  $articulo
     * @return \App\Article
     */
    protected function conLaEscalaDeLaReproduccion(Article $articulo)
    {
        $this->tramo($articulo, ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 5, self::TRAMO_5);
        $this->tramo($articulo, ArticlePriceRangeHelper::MODO_IGUAL, 3, self::TRAMO_IGUAL_3);
        $this->tramo($articulo, ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, self::TRAMO_10);

        return $articulo;
    }

    /**
     * Un tramo suelto del articulo.
     *
     * @param  \App\Article  $articulo
     * @param  string  $modo
     * @param  mixed  $amount
     * @param  mixed  $price
     * @return \App\ArticlePriceRange
     */
    protected function tramo(Article $articulo, $modo, $amount, $price)
    {
        return ArticlePriceRange::create([
            'article_id' => $articulo->id,
            'modo'       => $modo,
            'amount'     => $amount,
            'price'      => $price,
        ]);
    }

    /**
     * Un combo del comercio. `num` es NOT NULL sin default en el esquema del ERP, asi que va
     * siempre.
     *
     * @param  \App\User  $comercio
     * @param  array  $atributos
     * @return \App\Combo
     */
    protected function combo(User $comercio, array $atributos = [])
    {
        return Combo::create(array_merge([
            'num'     => random_int(100000, 999999),
            'name'    => 'Combo Test',
            'user_id' => $comercio->id,
            'price'   => self::PRECIO_COMBO,
            'cost'    => self::COSTO_COMBO,
            'online'  => 1,
        ], $atributos));
    }

    /**
     * Una promocion de vinoteca del comercio: la SEGUNDA coleccion comprable del carrito, la que
     * ya existia. Se usa para el carrito mezclado de `TotalConCombosTest`.
     *
     * @param  \App\User  $comercio
     * @param  float  $final_price
     * @return \App\PromocionVinoteca
     */
    protected function promocionVinoteca(User $comercio, $final_price)
    {
        return PromocionVinoteca::create([
            'name'        => 'Promo Vinoteca Test',
            'slug'        => 'promo-combos-test-'.uniqid(),
            'user_id'     => $comercio->id,
            'online'      => 1,
            'stock'       => 100,
            'final_price' => $final_price,
            'cost'        => 0,
        ]);
    }

    /**
     * Una linea de articulo tal cual la manda el SPA: el modelo entero mas su `pivot`.
     *
     * @param  \App\Article  $articulo
     * @param  int|float  $amount
     * @param  array  $atributos  Overrides, para los casos que mandan un payload adulterado.
     * @return array
     */
    protected function lineaDelPayload(Article $articulo, $amount = 1, array $atributos = [])
    {
        return array_merge([
            'id'          => $articulo->id,
            'user_id'     => $articulo->user_id,
            'name'        => $articulo->name,
            'final_price' => $articulo->final_price,
            'cost'        => null,
            'amount'      => $amount,
            'pivot'       => ['amount' => $amount, 'notes' => null, 'variant_id' => null],
        ], $atributos);
    }

    /**
     * Una linea de combo tal cual la manda el SPA.
     *
     * @param  \App\Combo  $combo
     * @param  int|float  $amount
     * @param  array  $atributos
     * @return array
     */
    protected function lineaDeComboDelPayload(Combo $combo, $amount = 1, array $atributos = [])
    {
        return array_merge([
            'id'          => $combo->id,
            'user_id'     => $combo->user_id,
            'name'        => $combo->name,
            'is_combo'    => true,
            'final_price' => $combo->price,
            'price'       => $combo->price,
            'cost'        => $combo->cost,
            'pivot'       => ['amount' => $amount, 'notes' => null],
        ], $atributos);
    }

    /**
     * Una linea de promocion de vinoteca tal cual la manda el SPA.
     *
     * @param  \App\PromocionVinoteca  $promo
     * @param  int|float  $amount
     * @return array
     */
    protected function lineaDePromoDelPayload(PromocionVinoteca $promo, $amount = 1)
    {
        return [
            'id'                    => $promo->id,
            'user_id'               => $promo->user_id,
            'name'                  => $promo->name,
            'is_promocion_vinoteca' => true,
            'final_price'           => $promo->final_price,
            'cost'                  => $promo->cost,
            'pivot'                 => ['amount' => $amount, 'notes' => null],
        ];
    }

    /**
     * Crea el carrito por el endpoint publico, como hace el SPA del comprador invitado — que es
     * el flujo mas usado de la tienda.
     *
     * @param  \App\User  $comercio
     * @param  array  $cart  Contenido del carrito (`articles`, `promociones_vinoteca`, `combos`).
     * @return \Illuminate\Testing\TestResponse
     */
    protected function crearCarrito(User $comercio, array $cart = [])
    {
        return $this->postJson('/api/carts', [
            'commerce_id' => $comercio->id,
            'cart'        => array_merge([
                'articles'             => [],
                'promociones_vinoteca' => [],
            ], $cart),
        ]);
    }

    /**
     * El boton "Actualizar" del carrito: `PUT /api/carts/update-article-amount/{cart_id}`.
     *
     * La sesion va en `carritos_propios` porque el carrito es de un invitado: es lo unico que lo
     * ata a quien lo creo (`CartOwnershipHelper`), y sin eso el endpoint contesta 403.
     *
     * @param  int  $cart_id
     * @param  array  $body
     * @return \Illuminate\Testing\TestResponse
     */
    protected function actualizarCantidad($cart_id, array $body)
    {
        return $this->withSession(['carritos_propios' => [$cart_id]])
                    ->putJson('/api/carts/update-article-amount/'.$cart_id, $body);
    }

    /**
     * El precio guardado de la linea de un articulo del carrito.
     *
     * Se lee de la base y no de la respuesta: lo que se cobra al confirmar el pedido es esta fila.
     *
     * @param  int  $cart_id
     * @param  int  $article_id
     * @return float
     */
    protected function precioGuardado($cart_id, $article_id)
    {
        return (float) DB::table('article_cart')
                            ->where('cart_id', $cart_id)
                            ->where('article_id', $article_id)
                            ->value('price');
    }

    /**
     * La cantidad guardada de la linea de un articulo del carrito.
     *
     * @param  int  $cart_id
     * @param  int  $article_id
     * @return float
     */
    protected function cantidadGuardada($cart_id, $article_id)
    {
        return (float) DB::table('article_cart')
                            ->where('cart_id', $cart_id)
                            ->where('article_id', $article_id)
                            ->value('amount');
    }

    /**
     * El total guardado del carrito.
     *
     * @param  int  $cart_id
     * @return float
     */
    protected function totalGuardado($cart_id)
    {
        return (float) DB::table('carts')->where('id', $cart_id)->value('total');
    }

    /**
     * Las filas del pivote de combos de un carrito.
     *
     * @param  int  $cart_id
     * @return \Illuminate\Support\Collection
     */
    protected function combosGuardados($cart_id)
    {
        return DB::table('cart_combo')->where('cart_id', $cart_id)->get();
    }

    /**
     * Descarta las memorias estaticas de los dos helpers de esta mision.
     *
     * @return void
     */
    protected function olvidarLasMemorias()
    {
        \App\Http\Controllers\Helpers\ArticlePriceRangeHelper::olvidar();
        \App\Http\Controllers\Helpers\ComboEsquemaHelper::olvidar();
        \App\Http\Controllers\Helpers\ArticleHelper::olvidar_visibilidad_del_anonimo();
    }
}
