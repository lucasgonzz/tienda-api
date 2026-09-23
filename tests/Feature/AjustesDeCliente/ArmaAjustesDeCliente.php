<?php

namespace Tests\Feature\AjustesDeCliente;

use App\Article;
use App\Buyer;
use App\Cart;
use App\Client;
use App\Http\Controllers\Helpers\AjustesDeClienteHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ArticlePriceRangeHelper;
use App\Http\Controllers\Helpers\ClientOfferHelper;
use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use App\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Arma el escenario de la mision descuentos-recargos-por-cliente contra la base del slot.
 *
 * ── El esquema ────────────────────────────────────────────────────────────────────────────────
 * Las cuatro tablas del contrato las crea `empresa-api` (migraciones 2026_09_23_10000[1-4]) y la
 * base del slot no las tiene. Se crean aca SI FALTAN, copiando la forma EXACTA de esas migraciones
 * con el mismo Blueprint (no un CREATE TABLE a mano, para que los tipos no diverjan por una
 * transcripcion). Idempotente y NO destructivo: si el esquema de verdad ya llego, no se toca, y no
 * se dropea al terminar (es parte del esquema que empresa va a crear igual). Mismo criterio que
 * `PromocionPersonalizada\CreaElEsquemaDeOfertas`.
 *
 * ⚠️ El CREATE TABLE es DDL y MySQL le hace commit implicito a la transaccion abierta, asi que las
 * clases que usan este trait NO usan DatabaseTransactions: cada fila que se crea se anota y se
 * borra a mano en `limpiarAjustes()`.
 */
trait ArmaAjustesDeCliente
{
    /** @var \App\User */
    protected $comercio;

    /** @var array Filas creadas por el caso, por tabla, para borrarlas en el tearDown. */
    protected $creados = [];

    /** @var array Tablas escondidas con RENAME: [nombre_real => nombre_escondido]. */
    protected $escondidas = [];

    /**
     * Preparacion comun: memorias limpias, esquema presente y el comercio sembrado del slot.
     *
     * @return void
     */
    protected function armarAjustes()
    {
        $this->olvidarMemorias();

        $this->crearEsquemaDeAjustesSiFalta();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        /* Sin la extension de rangos por categoria: esa extension pisa el precio por otro camino
           y estos casos miden el camino comun. Si el comercio sembrado la tuviera, lo que se
           mediria seria otra cosa. */
        $this->assertFalse(
            \App\Http\Controllers\Helpers\CommerceHelper::hasExtencion('lista_de_precios_por_rango_de_cantidad_vendida', null, $this->comercio->id),
            'El comercio sembrado del slot tiene la extension de rangos por categoria: estos casos no medirian el camino comun.'
        );
    }

    /**
     * Crea las cuatro tablas del contrato (y `discounts` / `surchages` si faltaran) con la forma
     * de las migraciones de `empresa-api`.
     *
     * @return void
     */
    protected function crearEsquemaDeAjustesSiFalta()
    {
        if (!Schema::hasTable('discounts')) {
            Schema::create('discounts', function (Blueprint $table) {
                $table->id();
                $table->integer('num')->nullable();
                $table->string('name');
                $table->double('percentage');
                $table->integer('user_id')->unsigned();
                $table->integer('client_id')->unsigned()->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('surchages')) {
            Schema::create('surchages', function (Blueprint $table) {
                $table->id();
                $table->integer('num')->nullable();
                $table->string('name')->nullable();
                $table->decimal('percentage', 12, 2);
                $table->integer('user_id')->unsigned();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable(AjustesDeClienteHelper::TABLA_DESCUENTOS)) {
            Schema::create(AjustesDeClienteHelper::TABLA_DESCUENTOS, function (Blueprint $table) {
                $table->id();
                $table->integer('client_id')->unsigned()->index();
                $table->integer('discount_id')->unsigned();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable(AjustesDeClienteHelper::TABLA_RECARGOS)) {
            Schema::create(AjustesDeClienteHelper::TABLA_RECARGOS, function (Blueprint $table) {
                $table->id();
                $table->integer('client_id')->unsigned()->index();
                $table->integer('surchage_id')->unsigned();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable(AjustesDeClienteHelper::TABLA_DESCUENTOS_DEL_PEDIDO)) {
            Schema::create(AjustesDeClienteHelper::TABLA_DESCUENTOS_DEL_PEDIDO, function (Blueprint $table) {
                $table->id();
                $table->integer('order_id')->unsigned()->index();
                $table->integer('discount_id')->unsigned();
                $table->double('percentage');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable(AjustesDeClienteHelper::TABLA_RECARGOS_DEL_PEDIDO)) {
            Schema::create(AjustesDeClienteHelper::TABLA_RECARGOS_DEL_PEDIDO, function (Blueprint $table) {
                $table->id();
                $table->integer('order_id')->unsigned()->index();
                $table->integer('surchage_id')->unsigned();
                $table->decimal('percentage', 12, 2);
                $table->timestamps();
            });
        }
    }

    /**
     * El esquema de combos de `empresa-api` (2026_09_16_1000[0-2]), si falta. Solo lo usa el caso
     * de combos: la base del slot no lo trae y sin el la tienda no ofrece combos.
     *
     * @return void
     */
    protected function crearEsquemaDeCombosSiFalta()
    {
        if (!Schema::hasColumn('combos', 'online')) {
            Schema::table('combos', function (Blueprint $table) {
                $table->boolean('online')->default(0);
            });
        }

        if (!Schema::hasTable('cart_combo')) {
            Schema::create('cart_combo', function (Blueprint $table) {
                $table->id();
                $table->integer('cart_id')->unsigned();
                $table->integer('combo_id')->unsigned();
                $table->double('amount', 20, 2);
                $table->double('price', 20, 2);
                $table->double('cost', 20, 2)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_combo')) {
            Schema::create('order_combo', function (Blueprint $table) {
                $table->id();
                $table->integer('order_id')->unsigned();
                $table->integer('combo_id')->unsigned();
                $table->double('amount', 20, 2);
                $table->double('price', 20, 2);
                $table->double('cost', 20, 2)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        ComboEsquemaHelper::olvidar();
    }

    /**
     * Un cliente del ERP del comercio y una CUENTA de la tienda (con contraseña) vinculada a el.
     *
     * La contraseña no es decorativa: los ajustes valen solo para una cuenta, no para la ficha
     * sin credencial de un checkout de invitado (ver fichaDeInvitadoVinculada()).
     *
     * @return \App\Buyer
     */
    protected function compradorVinculado()
    {
        return $this->compradorDeUnCliente(['password' => bcrypt('secreto-ajustes')]);
    }

    /**
     * La ficha SIN credencial que deja un checkout de invitado, vinculada igual a un cliente del
     * ERP. `BuyerController::login()` le abre sesion en el guard cuando alguien compra con su
     * email.
     *
     * @return \App\Buyer
     */
    protected function fichaDeInvitadoVinculada()
    {
        return $this->compradorDeUnCliente([]);
    }

    /**
     * Un cliente del ERP del comercio y un comprador vinculado a el.
     *
     * @param array $atributos
     * @return \App\Buyer
     */
    protected function compradorDeUnCliente(array $atributos)
    {
        $client = Client::create([
            'name'    => 'Cliente Ajustes Test',
            'user_id' => $this->comercio->id,
        ]);

        $this->anotar('clients', $client->id);

        $buyer = Buyer::create(array_merge([
            'name'                    => 'Comprador Ajustes Test',
            'email'                   => 'ajustes-'.Str::random(10).'@test.local',
            'comercio_city_client_id' => $client->id,
            'user_id'                 => $this->comercio->id,
        ], $atributos));

        $this->anotar('buyers', $buyer->id);

        return $buyer;
    }

    /**
     * Un comprador SIN cliente del ERP.
     *
     * @return \App\Buyer
     */
    protected function compradorSinCliente()
    {
        $buyer = Buyer::create([
            'name'    => 'Comprador Sin Cliente Ajustes Test',
            'email'   => 'ajustes-sin-cliente-'.Str::random(10).'@test.local',
            'user_id' => $this->comercio->id,
        ]);

        $this->anotar('buyers', $buyer->id);

        return $buyer;
    }

    /**
     * Un descuento de venta del comercio (o de otro, si se pasa `user_id`).
     *
     * @param float $porcentaje
     * @param array $atributos
     * @return int
     */
    protected function crearDescuento($porcentaje, array $atributos = [])
    {
        $id = DB::table('discounts')->insertGetId(array_merge([
            'num'        => 1,
            'name'       => 'Descuento '.$porcentaje.' Test',
            'percentage' => $porcentaje,
            'user_id'    => $this->comercio->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $atributos));

        $this->anotar('discounts', $id);

        return $id;
    }

    /**
     * Un recargo de venta del comercio.
     *
     * @param float $porcentaje
     * @param array $atributos
     * @return int
     */
    protected function crearRecargo($porcentaje, array $atributos = [])
    {
        $id = DB::table('surchages')->insertGetId(array_merge([
            'num'        => 1,
            'name'       => 'Recargo '.$porcentaje.' Test',
            'percentage' => $porcentaje,
            'user_id'    => $this->comercio->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $atributos));

        $this->anotar('surchages', $id);

        return $id;
    }

    /**
     * Vincula descuentos y recargos al cliente del comprador, como lo hace la ficha del ERP.
     *
     * @param \App\Buyer $buyer
     * @param array $descuentos ids
     * @param array $recargos ids
     * @return void
     */
    protected function vincular($buyer, array $descuentos = [], array $recargos = [])
    {
        foreach ($descuentos as $discount_id) {
            $id = DB::table(AjustesDeClienteHelper::TABLA_DESCUENTOS)->insertGetId([
                'client_id'   => $buyer->comercio_city_client_id,
                'discount_id' => $discount_id,
                'created_at'  => Carbon::now(),
                'updated_at'  => Carbon::now(),
            ]);
            $this->anotar(AjustesDeClienteHelper::TABLA_DESCUENTOS, $id);
        }

        foreach ($recargos as $surchage_id) {
            $id = DB::table(AjustesDeClienteHelper::TABLA_RECARGOS)->insertGetId([
                'client_id'   => $buyer->comercio_city_client_id,
                'surchage_id' => $surchage_id,
                'created_at'  => Carbon::now(),
                'updated_at'  => Carbon::now(),
            ]);
            $this->anotar(AjustesDeClienteHelper::TABLA_RECARGOS, $id);
        }

        $this->olvidarMemorias();
    }

    /**
     * Desvincula un descuento del cliente, como lo haria el ERP con el carrito ya armado.
     *
     * @param \App\Buyer $buyer
     * @param int $discount_id
     * @return void
     */
    protected function desvincularDescuento($buyer, $discount_id)
    {
        DB::table(AjustesDeClienteHelper::TABLA_DESCUENTOS)
            ->where('client_id', $buyer->comercio_city_client_id)
            ->where('discount_id', $discount_id)
            ->delete();

        $this->olvidarMemorias();
    }

    /**
     * Un articulo del comercio con precio propio (la columna `final_price`).
     *
     * @param float $final_price
     * @return \App\Article
     */
    protected function crearArticulo($final_price)
    {
        $articulo = Article::create([
            'user_id'     => $this->comercio->id,
            'name'        => 'Articulo Ajustes Test '.Str::random(8),
            'slug'        => 'articulo-ajustes-test-'.Str::random(10),
            'status'      => 'active',
            'online'      => 1,
            'stock'       => 100,
            'final_price' => $final_price,
        ]);

        $this->anotar('articles', $articulo->id);

        return $articulo;
    }

    /**
     * El articulo tal como lo ve el comprador de la sesion (withAll + checkPriceTypes).
     *
     * @param \App\Article $articulo
     * @return \App\Article
     */
    protected function articuloComoLoVe($articulo)
    {
        $this->olvidarMemorias();

        $articulos = Article::where('id', $articulo->id)->withAll()->get();

        return ArticleHelper::checkPriceTypes($articulos)->first();
    }

    /**
     * Una linea del carrito como la manda el SPA: el articulo que le dio la API, con su pivot.
     *
     * @param \App\Article $articulo
     * @param float $cantidad
     * @return array
     */
    protected function lineaDelSpa($articulo, $cantidad = 1)
    {
        $visto = $this->articuloComoLoVe($articulo);

        $linea = json_decode(json_encode($visto), true);
        $linea['amount'] = $cantidad;
        $linea['pivot'] = ['amount' => $cantidad, 'notes' => null];

        return $linea;
    }

    /**
     * Anota una fila para el tearDown.
     *
     * @param string $tabla
     * @param int $id
     * @return void
     */
    protected function anotar($tabla, $id)
    {
        $this->creados[$tabla][] = $id;
    }

    /**
     * Anota un carrito recien creado por el endpoint (y sus lineas se borran por cart_id).
     *
     * @param int|null $cart_id
     * @return void
     */
    protected function anotarCarrito($cart_id)
    {
        if (!is_null($cart_id)) {
            $this->anotar('carts', (int) $cart_id);
        }
    }

    /**
     * Esconde una tabla con RENAME (el escenario "empresa todavia no hizo el release").
     *
     * @param string $tabla
     * @return void
     */
    protected function esconderTabla($tabla)
    {
        if (Schema::hasTable($tabla)) {
            $escondida = $tabla.'_escondida_test';
            DB::statement('RENAME TABLE '.$tabla.' TO '.$escondida);
            $this->escondidas[$tabla] = $escondida;
        }

        $this->olvidarMemorias();
    }

    /**
     * Devuelve las tablas escondidas a su nombre. Idempotente.
     *
     * @return void
     */
    protected function restaurarTablas()
    {
        foreach ($this->escondidas as $tabla => $escondida) {
            if (Schema::hasTable($escondida) && !Schema::hasTable($tabla)) {
                DB::statement('RENAME TABLE '.$escondida.' TO '.$tabla);
            }
        }

        $this->escondidas = [];

        $this->olvidarMemorias();
    }

    /**
     * Borra todo lo que el caso creo. Primero restaura las tablas: si quedaran escondidas se
     * llevarian puesta la suite entera.
     *
     * @return void
     */
    protected function limpiarAjustes()
    {
        $this->restaurarTablas();

        $carritos = isset($this->creados['carts']) ? $this->creados['carts'] : [];

        if (!empty($carritos)) {
            DB::table('article_cart')->whereIn('cart_id', $carritos)->delete();
            DB::table('cart_promocion_vinoteca')->whereIn('cart_id', $carritos)->delete();

            if (Schema::hasTable('cart_combo')) {
                DB::table('cart_combo')->whereIn('cart_id', $carritos)->delete();
            }
        }

        $pedidos = isset($this->creados['orders']) ? $this->creados['orders'] : [];

        if (!empty($pedidos)) {
            DB::table('article_order')->whereIn('order_id', $pedidos)->delete();
            DB::table('order_promocion_vinoteca')->whereIn('order_id', $pedidos)->delete();

            if (Schema::hasTable('order_combo')) {
                DB::table('order_combo')->whereIn('order_id', $pedidos)->delete();
            }

            DB::table(AjustesDeClienteHelper::TABLA_DESCUENTOS_DEL_PEDIDO)->whereIn('order_id', $pedidos)->delete();
            DB::table(AjustesDeClienteHelper::TABLA_RECARGOS_DEL_PEDIDO)->whereIn('order_id', $pedidos)->delete();
        }

        /* Orden: pivots primero, despues las filas que referencian. */
        $orden = [
            AjustesDeClienteHelper::TABLA_DESCUENTOS,
            AjustesDeClienteHelper::TABLA_RECARGOS,
            'carts',
            'orders',
            'order_statuses',
            'article_price_ranges',
            'articles',
            'promocion_vinotecas',
            'combos',
            'discounts',
            'surchages',
            'buyers',
            'clients',
            'client_offers',
        ];

        foreach ($orden as $tabla) {
            if (!empty($this->creados[$tabla]) && Schema::hasTable($tabla)) {
                DB::table($tabla)->whereIn('id', $this->creados[$tabla])->delete();
            }
        }

        $this->creados = [];

        $this->olvidarMemorias();
    }

    /**
     * Todas las memorias estaticas que tocan estos caminos.
     *
     * @return void
     */
    protected function olvidarMemorias()
    {
        AjustesDeClienteHelper::olvidarMemoria();
        ClientOfferHelper::olvidarMemoria();
        ArticleHelper::olvidar_visibilidad_del_anonimo();
        ArticlePriceRangeHelper::olvidar();
        ComboEsquemaHelper::olvidar();
    }
}
