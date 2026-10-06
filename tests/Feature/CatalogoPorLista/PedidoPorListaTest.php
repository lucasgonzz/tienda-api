<?php

namespace Tests\Feature\CatalogoPorLista;

use App\Article;
use App\Buyer;
use App\Cart;
use App\Http\Controllers\Helpers\AjustesDeClienteHelper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (e) La red de seguridad del PEDIDO (mision catalogo-por-lista-tienda, 5/10/2026).
 *
 * El carrito ya descarta lo no habilitado al guardarse; esto cubre lo que llega igual: un carrito
 * guardado antes de que cambiara la lista, el de un visitante que se logueo como mayorista sin
 * volver a guardarlo, o un POST armado a mano. Contrato C3:
 *
 *   `422 {"codigo": "articulos_no_disponibles", "message": string, "articulos": [{id, name}]}`
 *
 * y ANTES de cualquier escritura: ni pedido creado, ni el carrito tocado. Un pedido normal queda
 * exactamente como siempre.
 */
class PedidoPorListaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCatalogoPorLista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();
        $this->armarFerretotal();

        /* `orders.order_status_id` es NOT NULL y store() lo busca por nombre. Se crea adentro de la
           transaccion, asi que se va sola. */
        if (!DB::table('order_statuses')->where('name', 'Sin confirmar')->exists()) {
            DB::table('order_statuses')->insert([
                'name'       => 'Sin confirmar',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * 🔴 Un carrito guardado con un articulo que el mayorista ya no puede ver: 422 con el codigo del
     * contrato, el pedido NO se crea y el carrito queda exactamente como estaba.
     *
     * ── El cliente del mayorista TIENE un descuento vinculado, y no es un detalle (T1 de la revision) ─
     * `OrderController@store` corre `CartHelper::set_total()` antes de crear el pedido SOLO si el
     * cliente del comprador tiene ajustes. Sin ninguno, `set_total()` no se ejecuta nunca y la
     * asercion de abajo —"el 422 va antes de `set_total()`: el total no se toco"— no podia fallar: daba
     * verde aunque el 422 se moviera despues del bloque de ajustes. Con el descuento, mover el 422 despues
     * de ese bloque cambia `carts.total` (y los precios de las lineas) y este caso se pone rojo.
     */
    public function test_un_carrito_con_no_habilitados_da_422_y_no_crea_nada()
    {
        $mayorista = $this->compradorConLista($this->comercio, $this->mayorista->id);

        $this->vincularUnDescuento($mayorista, 10);

        /* El total guardado NO coincide con las lineas a proposito: si el 422 llegara despues de
           `set_total()`, el total cambiaria y este caso lo veria. */
        $cart = $this->carritoGuardado($mayorista, [
            [$this->habilitado, 1, 1000],
            [$this->sin_marcar, 2, 2000],
        ], 12345);

        $pedidos_antes = DB::table('orders')->where('buyer_id', $mayorista->id)->count();

        $this->comoComprador($mayorista);

        /* Que el caso no sea vacuo: el comprador de la sesion tiene ajustes, o sea que `set_total()`
           correria si el 422 no cortara antes. */
        $this->assertTrue(
            AjustesDeClienteHelper::tiene_ajustes(AjustesDeClienteHelper::del_comprador($this->comercio->id)),
            'el cliente del comprador tiene un descuento vinculado'
        );

        $respuesta = $this->postJson('/api/orders', [
            'cart_id'     => $cart->id,
            'commerce_id' => $this->comercio->id,
            'address'     => 'San Martin 100',
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame('articulos_no_disponibles', $respuesta->json('codigo'));
        $this->assertSame([['id' => $this->sin_marcar->id, 'name' => $this->sin_marcar->name]], $respuesta->json('articulos'));
        $this->assertIsString($respuesta->json('message'));
        $this->assertStringContainsString($this->sin_marcar->name, $respuesta->json('message'));

        $this->assertSame($pedidos_antes, DB::table('orders')->where('buyer_id', $mayorista->id)->count(), 'no se creo ningun pedido');

        $guardado = DB::table('carts')->where('id', $cart->id)->first();
        $this->assertNotNull($guardado, 'el carrito sigue existiendo');
        $this->assertNull($guardado->order_id, 'y no quedo atado a ningun pedido');
        $this->assertEquals(12345, $guardado->total, 'el 422 va antes de set_total(): el total no se toco');
        $this->assertEquals(
            [1000, 2000],
            DB::table('article_cart')->where('cart_id', $cart->id)->orderBy('id')->pluck('price')->map(function ($precio) { return (float) $precio; })->all(),
            'ni los precios de las lineas: el descuento del cliente no se les aplico'
        );
        $this->assertSame(
            $this->ordenados([$this->habilitado->id, $this->sin_marcar->id]),
            $this->ordenados(DB::table('article_cart')->where('cart_id', $cart->id)->pluck('article_id')->all()),
            'las lineas siguen ahi: el comprador las saca y vuelve a confirmar'
        );
    }

    /** Un pedido con solo lo habilitado se crea como siempre. */
    public function test_un_pedido_del_mayorista_con_solo_habilitados_se_crea_como_siempre()
    {
        $mayorista = $this->compradorConLista($this->comercio, $this->mayorista->id);

        $cart = $this->carritoGuardado($mayorista, [[$this->habilitado, 2, 1000]], 2000);

        $this->comoComprador($mayorista);

        $respuesta = $this->postJson('/api/orders', [
            'cart_id'     => $cart->id,
            'commerce_id' => $this->comercio->id,
            'address'     => 'San Martin 100',
        ]);

        $respuesta->assertStatus(201);

        $order_id = (int) $respuesta->json('order_id');
        $this->assertGreaterThan(0, $order_id);
        $this->assertSame(
            [$this->habilitado->id],
            DB::table('article_order')->where('order_id', $order_id)->pluck('article_id')->map(function ($id) { return (int) $id; })->all()
        );
    }

    /**
     * El minorista (lista sin restriccion) pide los tres sin ningun problema: sin lista restringida
     * el pedido es el de siempre.
     */
    public function test_el_minorista_pide_cualquier_articulo()
    {
        $minorista = $this->compradorConLista($this->comercio, $this->minorista->id);

        $cart = $this->carritoGuardado($minorista, [
            [$this->habilitado, 1, 1500],
            [$this->sin_marcar, 1, 2500],
            [$this->deshabilitado, 1, 3500],
        ], 7500);

        $this->comoComprador($minorista);

        $respuesta = $this->postJson('/api/orders', [
            'cart_id'     => $cart->id,
            'commerce_id' => $this->comercio->id,
            'address'     => 'San Martin 100',
        ]);

        $respuesta->assertStatus(201);
        $this->assertSame(3, DB::table('article_order')->where('order_id', (int) $respuesta->json('order_id'))->count());
    }

    /**
     * Vincula un descuento de venta al CLIENTE del comprador, como lo hace la ficha del ERP (tablas
     * `discounts` y `client_discount`, que existen en la base del slot). Todo dentro de la transaccion
     * del caso.
     *
     * @param  \App\Buyer  $buyer
     * @param  float  $porcentaje
     * @return void
     */
    private function vincularUnDescuento(Buyer $buyer, $porcentaje)
    {
        $descuento_id = DB::table('discounts')->insertGetId([
            'num'        => 1,
            'name'       => 'Descuento Catalogo Test',
            'percentage' => $porcentaje,
            'user_id'    => $this->comercio->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table(AjustesDeClienteHelper::TABLA_DESCUENTOS)->insert([
            'client_id'   => $buyer->comercio_city_client_id,
            'discount_id' => $descuento_id,
            'created_at'  => Carbon::now(),
            'updated_at'  => Carbon::now(),
        ]);
    }

    /**
     * Un carrito del comprador con las lineas escritas a mano, como lo dejo un guardado de antes de
     * que el comerciante restringiera la lista. No pasa por el endpoint a proposito: el endpoint ya
     * descarta, y lo que se mide es la red de seguridad del pedido.
     *
     * @param  \App\Buyer  $buyer
     * @param  array  $lineas  [[Article, cantidad, precio], ...]
     * @param  float  $total
     * @return \App\Cart
     */
    private function carritoGuardado(Buyer $buyer, array $lineas, $total)
    {
        $cart = Cart::create([
            'user_id'  => $this->comercio->id,
            'buyer_id' => $buyer->id,
            'total'    => $total,
        ]);

        foreach ($lineas as $linea) {
            /** @var \App\Article $articulo */
            list($articulo, $cantidad, $precio) = $linea;

            DB::table('article_cart')->insert([
                'cart_id'    => $cart->id,
                'article_id' => $articulo->id,
                'amount'     => $cantidad,
                'price'      => $precio,
                'cost'       => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        return $cart;
    }
}
