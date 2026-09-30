<?php

namespace Tests\Feature\CombosCalculados;

use App\Article;
use App\Buyer;
use App\Client;
use App\Combo;
use App\Http\Controllers\Helpers\AjustesDeClienteHelper;
use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use App\Image;
use App\PriceType;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\CombosYRangos\ArmaComercioConCombosYRangos;

/**
 * Fixtures de los tests de la mision combos-calculados (30/9/2026).
 *
 * Reusa las de `combos-y-rangos-de-precio` (`ArmaComercioConCombosYRangos`: comercio propio por
 * caso, articulo publicado, combo, carrito por el endpoint publico) y agrega lo que esta mision
 * necesita: componentes con su cantidad, listas de precio, precio del combo por lista, compradores
 * con y sin lista propia, foto del combo y la extension de rangos.
 *
 * ── El esquema ────────────────────────────────────────────────────────────────────────────────
 *
 * `tienda-api` no tiene migraciones: `combo_price_type` y los indices de `article_combo` los crea
 * `empresa-api` (2026_09_30_130100 / 130200). La base de testing del slot los tiene aplicados
 * (mismo DDL, ver el informe de la mision). Estas clases NO los crean solas a proposito: el CREATE
 * TABLE es DDL, MySQL le hace commit implicito a la transaccion de `DatabaseTransactions` y el
 * primer caso dejaria filas sueltas fuera del rollback. Si falta, `exigirElEsquemaDePrecios()`
 * falla con el mensaje de que hay que correr la migracion, en vez de dar verdes vacuos.
 */
trait ArmaCombosCalculados
{
    use ArmaComercioConCombosYRangos;

    /**
     * Frena el caso si la base no tiene la tabla del precio por lista: sin ella todo lo de esta
     * carpeta mediria el camino "sin esquema" y daria verde sin probar nada.
     *
     * @return void
     */
    protected function exigirElEsquemaDePrecios()
    {
        $this->assertTrue(
            Schema::hasTable('combo_price_type'),
            'La base de testing no tiene combo_price_type: correr la migracion 2026_09_30_130100 de empresa-api contra esta base.'
        );

        $this->assertTrue(ComboEsquemaHelper::precios_por_lista_disponible());
    }

    /**
     * Descarta TODAS las memorias estaticas que tocan estos caminos.
     *
     * @return void
     */
    protected function olvidarTodo()
    {
        $this->olvidarLasMemorias();
        AjustesDeClienteHelper::olvidarMemoria();
    }

    /**
     * Le cuelga un articulo a la receta del combo.
     *
     * @param  \App\Combo  $combo
     * @param  \App\Article  $articulo
     * @param  int  $cantidad
     * @return \App\Combo
     */
    protected function componente(Combo $combo, Article $articulo, $cantidad = 1)
    {
        DB::table('article_combo')->insert([
            'article_id' => $articulo->id,
            'combo_id'   => $combo->id,
            'amount'     => $cantidad,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $combo;
    }

    /**
     * Un articulo del comercio con un stock dado (null = no lleva control de stock).
     *
     * @param  \App\User  $comercio
     * @param  mixed  $stock
     * @param  array  $atributos
     * @return \App\Article
     */
    protected function articuloConStock(User $comercio, $stock, array $atributos = [])
    {
        return $this->articuloPublicado($comercio, array_merge(['stock' => $stock], $atributos));
    }

    /**
     * Una lista de precios del comercio. `PriceType` no declara fillable: asignacion directa.
     *
     * @param  \App\User  $comercio
     * @param  string  $nombre
     * @param  int|null  $position
     * @param  int|null  $oculta  `ocultar_al_publico`
     * @return \App\PriceType
     */
    protected function lista(User $comercio, $nombre, $position, $oculta = null)
    {
        $lista = new PriceType;
        $lista->name               = $nombre;
        $lista->position           = $position;
        $lista->ocultar_al_publico = $oculta;
        $lista->user_id            = $comercio->id;
        $lista->save();

        return $lista;
    }

    /**
     * La fila de `combo_price_type`: el precio del combo en una lista, como la deja el ERP.
     *
     * @param  \App\Combo  $combo
     * @param  \App\PriceType  $lista
     * @param  float  $precio
     * @return void
     */
    protected function precioPorLista(Combo $combo, PriceType $lista, $precio)
    {
        DB::table('combo_price_type')->insert([
            'combo_id'      => $combo->id,
            'price_type_id' => $lista->id,
            'price'         => $precio,
            'created_at'    => Carbon::now(),
            'updated_at'    => Carbon::now(),
        ]);
    }

    /**
     * Un comprador CON CUENTA (contrasena: los ajustes de cliente valen solo para una cuenta)
     * vinculado a un cliente del ERP, que puede tener lista de precios propia.
     *
     * @param  \App\User  $comercio
     * @param  \App\PriceType|null  $lista  La lista del cliente, o null si no tiene.
     * @return \App\Buyer
     */
    protected function compradorConLista(User $comercio, PriceType $lista = null)
    {
        $client = Client::create([
            'name'    => 'Cliente Combos Test',
            'user_id' => $comercio->id,
        ]);

        if (!is_null($lista)) {
            $client->price_type_id = $lista->id;
            $client->save();
        }

        return Buyer::create([
            'name'                    => 'Comprador Combos Test',
            'email'                   => 'combos-'.Str::random(10).'@test.local',
            'password'                => bcrypt('secreto-combos'),
            'comercio_city_client_id' => $client->id,
            'user_id'                 => $comercio->id,
        ]);
    }

    /**
     * Un comprador con cuenta pero SIN cliente del ERP (no puede tener lista propia).
     *
     * @param  \App\User  $comercio
     * @return \App\Buyer
     */
    protected function compradorSinCliente(User $comercio)
    {
        return Buyer::create([
            'name'     => 'Comprador Sin Cliente Combos Test',
            'email'    => 'combos-sin-cliente-'.Str::random(10).'@test.local',
            'password' => bcrypt('secreto-combos'),
            'user_id'  => $comercio->id,
        ]);
    }

    /**
     * Le pone al combo una foto propia, como la deja el ABM de empresa (`imageable_type` = alias).
     *
     * @param  \App\Combo  $combo
     * @param  string  $url
     * @param  string  $tipo  El alias del morph map; solo cambia en el caso que prueba el alias.
     * @return \App\Image
     */
    protected function fotoDelCombo(Combo $combo, $url = 'https://cdn.test/combo.jpg', $tipo = 'combo')
    {
        $imagen = new Image;
        $imagen->hosting_url    = $url;
        $imagen->imageable_id   = $combo->id;
        $imagen->imageable_type = $tipo;
        $imagen->save();

        return $imagen;
    }

    /**
     * Una foto de un articulo, como la deja el ABM (`imageable_type` = 'article').
     *
     * @param  \App\Article  $articulo
     * @param  string  $url
     * @return \App\Image
     */
    protected function fotoDelArticulo(Article $articulo, $url = 'https://cdn.test/articulo.jpg')
    {
        $imagen = new Image;
        $imagen->hosting_url    = $url;
        $imagen->imageable_id   = $articulo->id;
        $imagen->imageable_type = 'article';
        $imagen->save();

        return $imagen;
    }

    /**
     * Prende la extension `lista_de_precios_por_rango_de_cantidad_vendida` del comercio. Las
     * tablas son `extencion_empresas` / `extencion_empresa_user` (no `extencions`). Todo dentro de
     * la transaccion del caso.
     *
     * @param  \App\User  $comercio
     * @return void
     */
    protected function activarExtensionDeRangos(User $comercio)
    {
        $slug = 'lista_de_precios_por_rango_de_cantidad_vendida';

        $extencion_id = DB::table('extencion_empresas')->where('slug', $slug)->value('id');

        if (is_null($extencion_id)) {
            $extencion_id = DB::table('extencion_empresas')->insertGetId([
                'name' => 'Lista de precios por rango de cantidad vendida',
                'slug' => $slug,
            ]);
        }

        DB::table('extencion_empresa_user')->insert([
            'extencion_empresa_id' => $extencion_id,
            'user_id'              => $comercio->id,
        ]);
    }

    /**
     * Un descuento de venta del comercio vinculado al cliente del comprador, como lo hace la
     * ficha del ERP (tablas `discounts` y `client_discount`, que existen en la base del slot).
     *
     * @param  \App\User  $comercio
     * @param  \App\Buyer  $buyer
     * @param  float  $porcentaje
     * @return void
     */
    protected function descuentoDeCliente(User $comercio, Buyer $buyer, $porcentaje)
    {
        $descuento_id = DB::table('discounts')->insertGetId([
            'num'        => 1,
            'name'       => 'Descuento combos '.$porcentaje,
            'percentage' => $porcentaje,
            'user_id'    => $comercio->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table(AjustesDeClienteHelper::TABLA_DESCUENTOS)->insert([
            'client_id'   => $buyer->comercio_city_client_id,
            'discount_id' => $descuento_id,
            'created_at'  => Carbon::now(),
            'updated_at'  => Carbon::now(),
        ]);

        AjustesDeClienteHelper::olvidarMemoria();
    }

    /**
     * Los combos que devuelve la home para el comercio, tal cual los ve el navegador.
     *
     * @param  \App\User  $comercio
     * @return array
     */
    protected function combosDeLaHome(User $comercio)
    {
        return $this->json('GET', '/api/articles/featured-last-uploads/'.$comercio->id.'?page=1')
                    ->assertStatus(200)
                    ->json('combos');
    }

    /**
     * El combo de la home con ese id, o falla el caso si no esta.
     *
     * @param  \App\User  $comercio
     * @param  \App\Combo  $combo
     * @return array
     */
    protected function comboEnLaHome(User $comercio, Combo $combo)
    {
        foreach ($this->combosDeLaHome($comercio) as $en_la_home) {
            if ($en_la_home['id'] == $combo->id) {
                return $en_la_home;
            }
        }

        $this->fail('el combo '.$combo->id.' no esta en la home');
    }

    /**
     * La linea de combo que guardo el carrito, de la base y no de la respuesta: lo que se cobra al
     * confirmar el pedido es esta fila.
     *
     * @param  int  $cart_id
     * @param  \App\Combo  $combo
     * @return object|null
     */
    protected function lineaDeComboGuardada($cart_id, Combo $combo)
    {
        return DB::table('cart_combo')->where('cart_id', $cart_id)->where('combo_id', $combo->id)->first();
    }
}
