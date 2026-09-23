<?php

namespace Tests\Feature\AjustesDeCliente;

use App\Http\Controllers\Helpers\AjustesDeClienteHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El pedido que sale de un carrito con descuentos y recargos del cliente (mision
 * descuentos-recargos-por-cliente, 23/9/2026). Le pega a `POST /api/orders` de verdad.
 *
 * 🔴 La invariante: los pivots `discount_order` / `order_surchage` de un pedido son EXACTAMENTE
 * los ajustes con los que se pricearon sus renglones, con el porcentaje de ese momento (la foto).
 * Con eso el ERP arma la venta dividiendo cada renglon por el factor y colgando los mismos
 * ajustes, y el total da lo mismo.
 *
 * ⚠️ Sin DatabaseTransactions: el trait crea tablas (DDL, commit implicito). Se limpia a mano.
 */
class PedidoConAjustesTest extends TestCase
{
    use ArmaAjustesDeCliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armarAjustes();

        /* `orders.order_status_id` es NOT NULL y store() lo busca por nombre. */
        if (!DB::table('order_statuses')->where('name', 'Sin confirmar')->exists()) {
            $this->anotar('order_statuses', DB::table('order_statuses')->insertGetId([
                'name'       => 'Sin confirmar',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]));
        }
    }

    protected function tearDown(): void
    {
        $this->limpiarAjustes();

        parent::tearDown();
    }

    /** El caso del plan: pedido con 10% + 5%, renglon al precio ajustado y los dos pivots. */
    public function test_el_pedido_guarda_los_ajustes_con_su_porcentaje()
    {
        $comprador = $this->compradorVinculado();
        $descuento = $this->crearDescuento(10);
        $recargo = $this->crearRecargo(5);
        $this->vincular($comprador, [$descuento], [$recargo]);
        $articulo = $this->crearArticulo(1000);
        $this->actingAs($comprador, 'buyer');

        $cart_id = $this->guardarCarrito([$this->lineaDelSpa($articulo, 2)]);

        $order_id = $this->crearPedido($cart_id);

        $this->assertSame(
            $this->conRecargoOnline(945.0),
            (float) DB::table('article_order')->where('order_id', $order_id)->value('price'),
            'el renglon del pedido es el precio de la linea del carrito (+ el recargo online de siempre)'
        );
        $this->assertSame(1890.0, (float) DB::table('orders')->where('id', $order_id)->value('total'));

        $descuentos = DB::table(AjustesDeClienteHelper::TABLA_DESCUENTOS_DEL_PEDIDO)->where('order_id', $order_id)->get();
        $recargos = DB::table(AjustesDeClienteHelper::TABLA_RECARGOS_DEL_PEDIDO)->where('order_id', $order_id)->get();

        $this->assertCount(1, $descuentos);
        $this->assertSame($descuento, (int) $descuentos[0]->discount_id);
        $this->assertSame(10.0, (float) $descuentos[0]->percentage);

        $this->assertCount(1, $recargos);
        $this->assertSame($recargo, (int) $recargos[0]->surchage_id);
        $this->assertSame(5.0, (float) $recargos[0]->percentage);
    }

    /**
     * 🔴 El comerciante cambia el recargo DESPUES del ultimo guardado del carrito y ANTES del
     * pedido. El pedido tiene que salir coherente: renglones re-priceados con el 10% y el pivot con
     * el 10%, no el renglon del 5% con un pivot que dice 10%.
     */
    public function test_si_los_ajustes_cambiaron_el_pedido_sale_coherente()
    {
        $comprador = $this->compradorVinculado();
        $recargo = $this->crearRecargo(5);
        $this->vincular($comprador, [$this->crearDescuento(10)], [$recargo]);
        $articulo = $this->crearArticulo(1000);
        $this->actingAs($comprador, 'buyer');

        $cart_id = $this->guardarCarrito([$this->lineaDelSpa($articulo)]);

        DB::table('surchages')->where('id', $recargo)->update(['percentage' => 10]);
        $this->olvidarMemorias();

        $order_id = $this->crearPedido($cart_id);

        /* 1000 × 0,9 × 1,1 = 990 */
        $this->assertSame($this->conRecargoOnline(990.0), (float) DB::table('article_order')->where('order_id', $order_id)->value('price'));
        $this->assertSame(990.0, (float) DB::table('orders')->where('id', $order_id)->value('total'));
        $this->assertSame(10.0, (float) DB::table(AjustesDeClienteHelper::TABLA_RECARGOS_DEL_PEDIDO)->where('order_id', $order_id)->value('percentage'));
    }

    /** Un pedido sin ajustes no escribe pivots: para el ERP es el pedido de siempre. */
    public function test_un_pedido_sin_ajustes_no_escribe_pivots()
    {
        $comprador = $this->compradorVinculado();
        $articulo = $this->crearArticulo(1000);
        $this->actingAs($comprador, 'buyer');

        $cart_id = $this->guardarCarrito([$this->lineaDelSpa($articulo)]);

        $order_id = $this->crearPedido($cart_id);

        $this->assertSame(0, DB::table(AjustesDeClienteHelper::TABLA_DESCUENTOS_DEL_PEDIDO)->where('order_id', $order_id)->count());
        $this->assertSame(0, DB::table(AjustesDeClienteHelper::TABLA_RECARGOS_DEL_PEDIDO)->where('order_id', $order_id)->count());
        $this->assertSame($this->conRecargoOnline(1000.0), (float) DB::table('article_order')->where('order_id', $order_id)->value('price'));
    }

    /**
     * 🔴 Tablas de lectura presentes pero SIN las del pedido (esquema a medio llegar): el pedido se
     * crea igual, sin pivots, y el comprador recibe su 201.
     */
    public function test_sin_las_tablas_del_pedido_el_pedido_se_crea_igual()
    {
        $comprador = $this->compradorVinculado();
        $this->vincular($comprador, [$this->crearDescuento(10)]);
        $articulo = $this->crearArticulo(1000);
        $this->actingAs($comprador, 'buyer');

        $cart_id = $this->guardarCarrito([$this->lineaDelSpa($articulo)]);

        $this->esconderTabla(AjustesDeClienteHelper::TABLA_DESCUENTOS_DEL_PEDIDO);

        $order_id = $this->crearPedido($cart_id);

        $this->restaurarTablas();

        $this->assertSame($this->conRecargoOnline(900.0), (float) DB::table('article_order')->where('order_id', $order_id)->value('price'));
        $this->assertSame(0, DB::table(AjustesDeClienteHelper::TABLA_DESCUENTOS_DEL_PEDIDO)->where('order_id', $order_id)->count());
    }

    /**
     * POST /api/carts, como el SPA. Devuelve el id del carrito.
     *
     * @param array $articulos
     * @return int
     */
    private function guardarCarrito(array $articulos)
    {
        $this->olvidarMemorias();

        $respuesta = $this->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart'        => [
                'articles'             => $articulos,
                'promociones_vinoteca' => [],
                'combos'               => [],
            ],
        ]);

        $respuesta->assertStatus(201);

        $cart_id = (int) $respuesta->json('cart.id');
        $this->anotarCarrito($cart_id);

        return $cart_id;
    }

    /**
     * POST /api/orders. Devuelve el id del pedido.
     *
     * @param int $cart_id
     * @return int
     */
    private function crearPedido($cart_id)
    {
        $this->olvidarMemorias();

        $respuesta = $this->postJson('/api/orders', [
            'cart_id'     => $cart_id,
            'commerce_id' => $this->comercio->id,
            'address'     => 'San Martin 100',
        ]);

        $respuesta->assertStatus(201);

        $order_id = (int) $respuesta->json('order_id');
        $this->anotar('orders', $order_id);

        return $order_id;
    }

    /**
     * El renglon del pedido lleva encima el recargo online de la configuracion del comercio, como
     * siempre (OrderHelper::attachArticles). Se replica aca para que el caso no dependa de como
     * este sembrado el comercio del slot.
     *
     * @param float $precio
     * @return float
     */
    private function conRecargoOnline($precio)
    {
        $recargo_online = $this->comercio->online_configuration->online_price_surchage;

        if (!is_null($recargo_online)) {
            $precio += $precio * (float) $recargo_online / 100;
        }

        return round($precio, 2);
    }
}
