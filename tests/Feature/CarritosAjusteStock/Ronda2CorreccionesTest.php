<?php

namespace Tests\Feature\CarritosAjusteStock;

use App\Article;
use App\Buyer;
use App\Cart;
use App\Http\Controllers\Helpers\OnlinePaymentHelper;
use App\PaymentMethod;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\CombosYRangos\ArmaComercioConCombosYRangos;
use Tests\TestCase;

/**
 * Mision carritos-ajuste-stock, ronda 2 (tienda-api): correcciones de la revision 1.
 *
 *  - CA24 (R2-1): un renglon SIN tope (ignorar_stock) con un faltante viejo se sanea en silencio al
 *    abrir el carrito y al enviar el pedido.
 *  - CA25 (R2-2): `update-article-amount` no clasifica los renglones que esperan stock.
 *  - CA26 (R2-3): el cobro con Mercado Pago no se frena por un renglon que espera stock y no manda
 *    items con cantidad 0 a la preferencia. Nunca sale a la red: sin credencial el endpoint corta
 *    con 422 antes de hablar con Mercado Pago.
 *  - CA27 (R2-4): un PUT con la cantidad ORIGINAL pedida (pestaña vieja) conserva el recorte.
 */
class Ronda2CorreccionesTest extends TestCase
{
    use ArmaComercioConCombosYRangos, DatabaseTransactions;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Buyer */
    private $comprador;

    /** @var \App\Cart */
    private $carrito;

    /**
     * Comercio con tienda, comprador, estado de pedido y carrito propio con su sesion.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->olvidarLasMemorias();
        Bus::fake();
        $this->comercio = $this->comercioConTienda();
        $this->comercio->online_configuration()->update([
            'register_to_buy' => false,
            'pausar_tienda_online' => false,
            'stock_null_equal_0' => false,
        ]);
        $this->comprador = Buyer::create([
            'name' => 'Comprador ronda 2',
            'email' => 'ronda2-'.Str::random(8).'@test.local',
            'user_id' => $this->comercio->id,
        ]);
        if (!DB::table('order_statuses')->where('name', 'Sin confirmar')->exists()) {
            DB::table('order_statuses')->insert(['name' => 'Sin confirmar']);
        }
        $this->carrito = Cart::create(['user_id' => $this->comercio->id, 'total' => 0]);
    }

    /**
     * Limpia las memorias estaticas.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();
        parent::tearDown();
    }

    // ───────────────────────── CA24 (tienda): faltante viejo sin tope ─────────────────────────

    /**
     * `ignorar_stock` con el renglon 0/2 y casilla marcada: al abrir el carrito vuelve a tener 2 y
     * el faltante queda en 0 en la base.
     *
     * @return void
     */
    public function test_ca24_last_cart_sin_tope_devuelve_las_unidades_del_faltante_viejo()
    {
        $this->comercio->online_configuration()->update(['ignorar_stock' => 1]);
        $articulo = $this->articulo(['stock' => 0]);
        $this->renglon($articulo, 0, 2, 1);

        $respuesta = $this->withSession(['carritos_propios' => [$this->carrito->id]])->getJson('/api/carts/last-cart/'.$this->comercio->id)->assertOk();

        $pivote = $respuesta->json('last_cart.articles.0.pivot');
        $this->assertEquals(2, $pivote['amount'], 'El renglon recupera las unidades del faltante.');
        $this->assertEquals(0, $pivote['amount_insuficiente']);
        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $this->carrito->id, 'article_id' => $articulo->id, 'amount' => 2, 'amount_insuficiente' => 0,
        ]);
    }

    /**
     * Con la casilla desmarcada el faltante se olvida pero la cantidad queda como esta.
     *
     * @return void
     */
    public function test_ca24_sin_tope_y_casilla_desmarcada_solo_olvida_el_faltante()
    {
        $this->comercio->online_configuration()->update(['ignorar_stock' => 1]);
        $articulo = $this->articulo(['stock' => 0]);
        $this->renglon($articulo, 1, 2, 0);

        $this->withSession(['carritos_propios' => [$this->carrito->id]])->getJson('/api/carts/last-cart/'.$this->comercio->id)->assertOk();

        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $this->carrito->id, 'article_id' => $articulo->id, 'amount' => 1, 'amount_insuficiente' => 0,
        ]);
    }

    /**
     * El pedido con ese carrito se crea (201) con el renglon de 2 unidades: nunca "0 disponibles".
     *
     * @return void
     */
    public function test_ca24_el_pedido_sin_tope_envia_el_renglon_saneado()
    {
        $this->comercio->online_configuration()->update(['ignorar_stock' => 1]);
        $articulo = $this->articulo(['stock' => 0]);
        $this->renglon($articulo, 0, 2, 1);

        $this->enviar_pedido()->assertCreated();

        $pedido_id = DB::table('orders')->where('user_id', $this->comercio->id)->value('id');
        $this->assertNotNull($pedido_id);
        $this->assertEquals(2, DB::table('article_order')->where('order_id', $pedido_id)->where('article_id', $articulo->id)->value('amount'));
    }

    // ───────────────────────── CA25: update-article-amount ─────────────────────────

    /**
     * Consultas activas: A esperando stock (0/5) y B con 2 (stock 10). Cambiar B a 3 anda y A no se toca.
     *
     * @return void
     */
    public function test_ca25_update_article_amount_ignora_el_renglon_que_espera()
    {
        $this->comercio->online_configuration()->update(['permitir_pedidos_sin_stock' => 1]);
        // A conserva un tope (stock 3): con stock 0 y consultas activas el renglon seria "Consultar"
        // y el saneo de R2-1 le devolveria las unidades; aca tiene que seguir esperando.
        $a = $this->articulo(['stock' => 3]);
        $b = $this->articulo(['stock' => 10]);
        $this->renglon($a, 0, 5, 1);
        $this->renglon($b, 2, null, 1);

        $this->actualizarCantidad($this->carrito->id, ['id' => $b->id, 'amount' => 3])->assertOk();

        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $b->id, 'amount' => 3]);
        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $this->carrito->id, 'article_id' => $a->id, 'amount' => 0, 'amount_insuficiente' => 5,
        ]);
    }

    // ───────────────────────── CA26: cobro con Mercado Pago ─────────────────────────

    /**
     * La verificacion de stock del cobro no da el 422 de consulta por el renglon que espera: el
     * endpoint llega hasta pedir la credencial (sin red) y los items del cobro no incluyen A.
     *
     * @return void
     */
    public function test_ca26_el_cobro_ignora_el_renglon_que_espera()
    {
        $this->comercio->online_configuration()->update(['permitir_pedidos_sin_stock' => 1]);
        $a = $this->articulo(['stock' => 0]);
        $b = $this->articulo(['stock' => 10]);
        $this->renglon($a, 0, 5, 1);
        $this->renglon($b, 2, null, 1);
        $medio = PaymentMethod::findOrFail(DB::table('payment_methods')->insertGetId([
            'user_id' => $this->comercio->id, 'name' => 'Mercado Pago ronda 2',
        ]));

        $respuesta = $this->withSession(['carritos_propios' => [$this->carrito->id], 'checkout_buyer_id' => $this->comprador->id])
            ->postJson('/api/mercado-pago/preference', [
                'payment_method' => ['id' => $medio->id, 'user_id' => $this->comercio->id],
                'cart_id' => $this->carrito->id,
            ]);
        $this->assertNotSame('consulta_stock', $respuesta->json('codigo'), 'El renglon que espera no frena el cobro.');

        $items = OnlinePaymentHelper::items_del_carrito($this->carrito->fresh(), $this->comercio->fresh(), $medio);
        // Solo viaja B (2 unidades): el renglon A (0 unidades) no aporta ningun item.
        $this->assertCount(1, $items, 'Ningun item con cantidad 0 viaja a la preferencia.');
        $this->assertEquals(2, $items[0]['amount']);
    }

    // ───────────────────────── CA27: pestaña vieja ─────────────────────────

    /**
     * Carrito A 2/3 (pidio 5): un PUT con A=5 (valor viejo del navegador) conserva el recorte;
     * A=4 es una decision y resetea; A=2 conserva.
     *
     * @return void
     */
    public function test_ca27_el_put_con_la_cantidad_original_conserva_el_recorte()
    {
        $a = $this->articulo(['stock' => 100]);
        $b = $this->articulo(['stock' => 100]);
        $this->renglon($a, 2, 3, 0);

        // Pestaña vieja: manda 5 (lo que el comprador habia pedido) y suma un articulo nuevo.
        $respuesta = $this->guardar([$this->lineaDelPayload($a, 5), $this->lineaDelPayload($b, 1)])->assertOk();
        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $this->carrito->id, 'article_id' => $a->id, 'amount' => 2, 'amount_insuficiente' => 3, 'completar_al_reponer' => 0,
        ]);
        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $b->id, 'amount' => 1]);
        $linea_a = collect($respuesta->json('cart.articles'))->firstWhere('id', $a->id);
        $this->assertEquals(2, $linea_a['pivot']['amount'], 'La respuesta trae el carrito con la cantidad ajustada.');

        // Otra cantidad distinta de 5 y de 2: decision del comprador, se resetea.
        $this->guardar([$this->lineaDelPayload($a, 4)])->assertOk();
        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $this->carrito->id, 'article_id' => $a->id, 'amount' => 4, 'amount_insuficiente' => 0, 'completar_al_reponer' => 1,
        ]);

        // Volvemos a un renglon recortado y mandamos la cantidad actual (2): se conserva.
        DB::table('article_cart')->where('cart_id', $this->carrito->id)->where('article_id', $a->id)
            ->update(['amount' => 2, 'amount_insuficiente' => 3, 'completar_al_reponer' => 0]);
        $this->guardar([$this->lineaDelPayload($a, 2)])->assertOk();
        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $this->carrito->id, 'article_id' => $a->id, 'amount' => 2, 'amount_insuficiente' => 3, 'completar_al_reponer' => 0,
        ]);
    }

    // ───────────────────────── ayudas ─────────────────────────

    /**
     * Articulo publicado del comercio del test.
     *
     * @param  array  $atributos
     * @return \App\Article
     */
    private function articulo(array $atributos = [])
    {
        return $this->articuloPublicado($this->comercio, $atributos);
    }

    /**
     * Inserta un renglon en el carrito del test.
     *
     * @param  \App\Article  $articulo
     * @param  int|float  $cantidad
     * @param  int|float|null  $faltante
     * @param  int  $casilla  `completar_al_reponer`.
     * @return void
     */
    private function renglon(Article $articulo, $cantidad, $faltante, $casilla)
    {
        $this->carrito->articles()->attach($articulo->id, [
            'amount' => $cantidad, 'amount_insuficiente' => $faltante, 'completar_al_reponer' => $casilla,
            'price' => 100, 'cost' => 0, 'variant_id' => null,
        ]);
    }

    /**
     * `PUT /api/carts` con la sesion del dueño del carrito.
     *
     * @param  array  $lineas
     * @return \Illuminate\Testing\TestResponse
     */
    private function guardar(array $lineas)
    {
        return $this->withSession(['carritos_propios' => [$this->carrito->id]])
            ->putJson('/api/carts', ['id' => $this->carrito->id, 'articles' => $lineas, 'promociones_vinoteca' => []]);
    }

    /**
     * Confirma el carrito del test como pedido (`POST /api/orders`).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function enviar_pedido()
    {
        return $this->withSession(['carritos_propios' => [$this->carrito->id], 'checkout_buyer_id' => $this->comprador->id])
            ->postJson('/api/orders', [
                'cart_id' => $this->carrito->id,
                'commerce_id' => $this->comercio->id,
                'address' => 'Local de prueba 10',
                'aceptar_consulta_stock' => false,
            ]);
    }
}
