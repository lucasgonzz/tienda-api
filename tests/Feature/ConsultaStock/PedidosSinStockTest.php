<?php

namespace Tests\Feature\ConsultaStock;

use App\Article;
use App\ArticleVariant;
use App\Buyer;
use App\Cart;
use App\Order;
use App\PaymentMethod;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\CombosYRangos\ArmaComercioConCombosYRangos;
use Tests\TestCase;

class PedidosSinStockTest extends TestCase
{
    use ArmaComercioConCombosYRangos, DatabaseTransactions;

    private $comercio;
    private $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasColumn('article_cart', 'stock_a_consultar')) {
            $this->markTestSkipped('Aplicá la migración de consultas de stock de empresa-api en la base de prueba de tienda.');
        }

        Bus::fake();
        $this->comercio = $this->comercioConTienda();
        $this->comercio->online_configuration()->update([
            'register_to_buy' => false,
            'pausar_tienda_online' => false,
            'stock_null_equal_0' => false,
        ]);
        $this->comprador = Buyer::create([
            'name' => 'Comprador de prueba de stock',
            'email' => 'consulta-stock-'.Str::random(8).'@test.local',
            'user_id' => $this->comercio->id,
        ]);
        if (!DB::table('order_statuses')->where('name', 'Sin confirmar')->exists()) {
            DB::table('order_statuses')->insert(['name' => 'Sin confirmar']);
        }
    }

    public function test_la_opcion_arranca_apagada_y_es_por_comercio()
    {
        $otro = $this->comercioConTienda();
        $this->getJson('/api/commerce/'.$this->comercio->id)
            ->assertOk()->assertJsonPath('commerce.consultas_de_stock', false);

        $this->activar();
        $this->getJson('/api/commerce/'.$this->comercio->id)
            ->assertOk()->assertJsonPath('commerce.consultas_de_stock', true);
        $this->getJson('/api/commerce/'.$otro->id)
            ->assertOk()->assertJsonPath('commerce.consultas_de_stock', false);

        $this->comercio->online_configuration()->update(['ignorar_stock' => true]);
        $this->getJson('/api/commerce/'.$this->comercio->id)
            ->assertOk()->assertJsonPath('commerce.consultas_de_stock', false);
    }

    public function test_api_nueva_con_esquema_anterior_no_ofrece_ni_graba_consultas()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 3]);
        $esquema = Schema::getFacadeRoot();
        $anterior = \Mockery::mock($esquema)->makePartial();
        $anterior->shouldReceive('hasColumn')->with('online_configurations', 'permitir_pedidos_sin_stock')->andReturn(false);
        $anterior->shouldReceive('hasColumn')->with('article_cart', 'stock_a_consultar')->andReturn(false);
        $anterior->shouldReceive('hasColumn')->with('article_order', 'stock_a_consultar')->andReturn(false);
        $anterior->shouldReceive('hasColumn')->with('carts', 'preferencia_online_emitida')->andReturn(false);
        Schema::swap($anterior);

        try {
            $this->getJson('/api/commerce/'.$this->comercio->id)
                ->assertOk()->assertJsonPath('commerce.consultas_de_stock', false);
            $respuesta = $this->guardarCarrito([$this->linea($articulo, 2)])->assertCreated();
            $respuesta->assertJsonMissingPath('cart.articles.0.pivot.stock_a_consultar');
            $this->pedido($respuesta->json('cart.id'))->assertCreated();
        } finally {
            Schema::swap($esquema);
        }
    }

    public function test_se_ve_el_cero_publicado_en_el_listado_y_la_ficha_sin_falsear_stock()
    {
        $publicado = $this->articuloPublicado($this->comercio, ['stock' => 0, 'name' => 'Consulta Nueva']);
        $oculto = $this->articuloPublicado($this->comercio, ['stock' => 0, 'name' => 'Consulta Oculta', 'online' => false]);
        $negativo = $this->articuloPublicado($this->comercio, ['stock' => -1, 'name' => 'Consulta Negativa']);
        $this->comercio->online_configuration()->update(['show_articles_without_stock' => false]);
        $lista = '/api/articles/search/Consulta/'.$this->comercio->id;

        $this->getJson($lista)->assertOk()->assertDontSee($publicado->name);
        $this->activar();
        $this->getJson($lista)->assertOk()->assertSee($publicado->name)->assertDontSee($oculto->name)->assertDontSee($negativo->name);
        $stock = $this->getJson('/api/articles/'.$publicado->slug.'/'.$this->comercio->id)
            ->assertOk()->json('article.stock');
        $this->assertTrue(is_numeric($stock));
        $this->assertSame(0.0, (float) $stock);
    }

    public function test_pedido_de_consulta_con_variantes_mezcladas_copia_cada_renglon_sin_cobrar()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 8, 'final_price' => 200]);
        $variante_cero = $this->variante($articulo, 0);
        $variante_ocho = $this->variante($articulo, 8);
        $carrito_id = $this->guardarCarrito([
            $this->linea($articulo, 2, $variante_cero->id),
            $this->linea($articulo, 4, $variante_ocho->id),
        ], ['payment_card_info_id' => 123, 'payment_method_id' => $this->medioDePago()->id])
            ->assertCreated()->json('cart.id');

        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $carrito_id, 'variant_id' => $variante_cero->id, 'stock_a_consultar' => 1, 'price' => 200,
        ]);
        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $carrito_id, 'variant_id' => $variante_ocho->id, 'stock_a_consultar' => 0,
        ]);
        $this->pedido($carrito_id)->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');
        $pedido_id = $this->pedido($carrito_id, true)->assertCreated()->json('order_id');
        $this->pedido($carrito_id, true)->assertCreated()->assertJsonPath('order_id', $pedido_id);

        $pedido = Order::findOrFail($pedido_id);
        $this->assertSame('Sin confirmar', $pedido->order_status->name);
        $this->assertNull($pedido->payment_method_id);
        $this->assertNull($pedido->payment_card_info_id);
        $this->assertNull($pedido->payment_id);
        $this->assertDatabaseHas('article_order', [
            'order_id' => $pedido_id, 'variant_id' => $variante_cero->id, 'stock_a_consultar' => 1, 'amount' => 2,
        ]);
        $this->assertDatabaseHas('article_order', [
            'order_id' => $pedido_id, 'variant_id' => $variante_ocho->id, 'stock_a_consultar' => 0, 'amount' => 4,
        ]);
        $this->assertSame(0, $variante_cero->fresh()->stock);
        $this->assertSame(8, $variante_ocho->fresh()->stock);
        $this->assertSame(8.0, (float) $articulo->fresh()->stock);
    }

    public function test_un_carrito_marcado_se_revalida_al_apagar_la_opcion()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 0]);
        $carrito_id = $this->guardarCarrito([$this->linea($articulo, 2)])->assertCreated()->json('cart.id');

        $this->comercio->online_configuration()->update(['permitir_pedidos_sin_stock' => false]);
        $this->pedido($carrito_id, true)->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');
        $this->pedido($carrito_id)->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');
        $this->assertDatabaseMissing('orders', ['user_id' => $this->comercio->id]);
    }

    public function test_en_modo_consulta_siguen_los_limites_y_las_condiciones_de_venta()
    {
        $this->activar();
        $positivo = $this->articuloPublicado($this->comercio, ['stock' => 3]);
        $pausado = $this->articuloPublicado($this->comercio, ['stock' => 0, 'precio_pausado' => true]);
        $negativo = $this->articuloPublicado($this->comercio, ['stock' => -1]);
        $sin_control = $this->articuloPublicado($this->comercio, ['stock' => null]);
        $oculto = $this->articuloPublicado($this->comercio, ['stock' => 0, 'online' => false]);
        $sin_precio = $this->articuloPublicado($this->comercio, ['stock' => 0, 'final_price' => 0]);

        foreach ([$positivo, $pausado, $negativo, $oculto, $sin_precio] as $articulo) {
            $this->guardarCarrito([$this->linea($articulo, 4)])
                ->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');
        }

        $sin_stock = $this->articuloPublicado($this->comercio, ['stock' => 0]);
        $this->guardarCarrito([$this->linea($sin_stock, 1.5)])
            ->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');

        $carrito_id = $this->guardarCarrito([$this->linea($positivo, 3), $this->linea($sin_control, 20)])
            ->assertCreated()->json('cart.id');
        $this->pedido($carrito_id)->assertCreated();
    }

    public function test_si_cambia_que_linea_esta_en_cero_hay_que_volver_a_guardar()
    {
        $this->activar();
        $primero = $this->articuloPublicado($this->comercio, ['stock' => 0]);
        $segundo = $this->articuloPublicado($this->comercio, ['stock' => 1]);
        $carrito_id = $this->guardarCarrito([$this->linea($primero, 1), $this->linea($segundo, 1)])
            ->assertCreated()->json('cart.id');
        $primero->update(['stock' => 1]);
        $segundo->update(['stock' => 0]);

        $this->pedido($carrito_id, true)->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');
        $this->sesionDelCarrito($carrito_id)->putJson('/api/carts/update-article-amount/'.$carrito_id, ['id' => $segundo->id, 'amount' => 2])
            ->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');
        $this->assertDatabaseHas('article_cart', ['cart_id' => $carrito_id, 'article_id' => $segundo->id, 'stock_a_consultar' => 0]);
        $this->assertDatabaseMissing('orders', ['user_id' => $this->comercio->id]);
    }

    public function test_reponer_stock_y_apagar_la_opcion_conserva_ambas_variantes_del_carrito()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 8]);
        $variante = $this->variante($articulo, 0);
        $otra = $this->variante($articulo, 8);
        $articulos = [$this->linea($articulo, 2, $variante->id), $this->linea($articulo, 3, $otra->id)];
        $carrito_id = $this->guardarCarrito($articulos)->assertCreated()->json('cart.id');

        DB::table('article_variants')->where('id', $variante->id)->update(['stock' => 8]);
        $this->comercio->online_configuration()->update(['permitir_pedidos_sin_stock' => false]);
        $this->sesionDelCarrito($carrito_id)->getJson('/api/carts/last-cart/'.$this->comercio->id)->assertOk()->assertJsonCount(2, 'last_cart.articles');
        $this->sesionDelCarrito($carrito_id)->putJson('/api/carts', ['id' => $carrito_id, 'articles' => $articulos, 'promociones_vinoteca' => []])
            ->assertOk()->assertJsonCount(2, 'cart.articles');

        $pedido_id = $this->pedido($carrito_id)->assertCreated()->json('order_id');
        $this->assertDatabaseHas('article_order', ['order_id' => $pedido_id, 'variant_id' => $variante->id, 'stock_a_consultar' => 0]);
        $this->assertDatabaseHas('article_order', ['order_id' => $pedido_id, 'variant_id' => $otra->id, 'stock_a_consultar' => 0]);
    }

    public function test_un_carrito_con_consulta_no_genera_preferencia_y_un_carrito_viejo_no_se_reutiliza()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 0]);
        $medio = $this->medioDePago();
        $carrito_id = $this->guardarCarrito([$this->linea($articulo, 1)])->assertCreated()->json('cart.id');
        $this->assertFalse(Cart::findOrFail($carrito_id)->preferencia_online_emitida);

        $payload = ['payment_method' => ['id' => $medio->id, 'user_id' => $this->comercio->id]];
        $this->sesionDelCarrito($carrito_id)->postJson('/api/mercado-pago/preference', $payload + ['cart_id' => $carrito_id])
            ->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock')
            ->assertJsonPath('message', 'El negocio tiene que revisar la disponibilidad antes de cobrar este pedido.');
        $this->postJson('/api/mercado-pago/preference', $payload)
            ->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');

        Cart::whereKey($carrito_id)->update(['preferencia_online_emitida' => true]);
        $this->pedido($carrito_id, true)
            ->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock_carrito_con_pago');
        $this->assertDatabaseMissing('orders', ['user_id' => $this->comercio->id]);
    }

    private function activar()
    {
        $this->comercio->online_configuration()->update(['permitir_pedidos_sin_stock' => true]);
    }

    private function medioDePago()
    {
        $id = DB::table('payment_methods')->insertGetId([
            'user_id' => $this->comercio->id,
            'name' => 'Mercado Pago de prueba de stock',
        ]);
        return PaymentMethod::findOrFail($id);
    }

    private function variante(Article $articulo, $stock)
    {
        $id = DB::table('article_variants')->insertGetId(['article_id' => $articulo->id, 'stock' => $stock]);
        return ArticleVariant::findOrFail($id);
    }

    private function linea(Article $articulo, $cantidad, $variante = null)
    {
        return [
            'id' => $articulo->id,
            'user_id' => $articulo->user_id,
            'cost' => null,
            'final_price' => 1, // La consulta conserva el precio que resuelve el servidor.
            'pivot' => ['amount' => $cantidad, 'notes' => null, 'variant_id' => $variante],
        ];
    }

    private function guardarCarrito(array $articulos, array $opciones = [])
    {
        $respuesta = $this->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart' => $opciones + ['articles' => $articulos, 'promociones_vinoteca' => []],
        ]);
        if ($respuesta->status() >= 500) {
            $this->fail($respuesta->getContent());
        }
        return $respuesta;
    }

    private function pedido($carrito_id, $acepta = false)
    {
        return $this->sesionDelCarrito($carrito_id)->postJson('/api/orders', [
            'cart_id' => $carrito_id,
            'commerce_id' => $this->comercio->id,
            'address' => 'Local de prueba 10',
            'aceptar_consulta_stock' => $acepta,
        ]);
    }

    private function sesionDelCarrito($carrito_id)
    {
        return $this->withSession([
            'carritos_propios' => [$carrito_id],
            'checkout_buyer_id' => $this->comprador->id,
        ]);
    }
}
