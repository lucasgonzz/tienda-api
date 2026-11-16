<?php

namespace Tests\Feature\ReservaStock;

use App\Article;
use App\Buyer;
use App\Cart;
use App\Order;
use App\Http\Controllers\Helpers\ReservaStockWebHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\CombosYRangos\ArmaComercioConCombosYRangos;
use Tests\TestCase;

class ReservaStockPedidosWebTest extends TestCase
{
    use DatabaseTransactions, ArmaComercioConCombosYRangos;

    private $comercio;
    private $comprador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(ReservaStockWebHelper::disponible(), 'Aplicar migración oficial de Empresa a la base local del slot.');
        Bus::fake();
        $this->comercio = $this->comercioConTienda();
        $this->comercio->online_configuration()->update(['register_to_buy' => false, 'pausar_tienda_online' => false, 'stock_null_equal_0' => false]);
        $this->comprador = Buyer::create(['name' => 'Comprador reserva', 'email' => 'reserva-'.Str::random(8).'@test.local', 'user_id' => $this->comercio->id]);
        if (!DB::table('order_statuses')->where('name', 'Sin confirmar')->exists()) {
            DB::table('order_statuses')->insert(['name' => 'Sin confirmar']);
        }
    }

    public function test_default_apagado_no_reserva_carritos_ni_pedidos_y_no_hay_retroactividad()
    {
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        $this->assertFalse($this->comercio->online_configuration->reservar_stock_pedidos_web);
        $carrito = $this->carrito([$this->lineaDelPayload($articulo, 6)]);
        $pedido = $this->enviar($carrito)->assertCreated()->json('order_id');
        $this->assertDatabaseMissing('order_stock_reservations', ['order_id' => $pedido]);
        $this->assertSame(10.0, (float) $articulo->fresh()->stock);
        $this->activar();
        $this->assertDatabaseMissing('order_stock_reservations', ['order_id' => $pedido]);
        $this->assertFalse((bool) Order::find($pedido)->reserva_stock_web);
    }

    public function test_diez_unidades_pedido_seis_muestra_cuatro_sin_tocar_fisico_ni_venta_y_reintento_es_idempotente()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        $carrito = $this->carrito([$this->lineaDelPayload($articulo, 6)]);
        $this->assertDatabaseMissing('order_stock_reservations', ['user_id' => $this->comercio->id]);
        $ventas = DB::table('sales')->count();
        $movimientos = DB::table('stock_movements')->count();
        $caja = DB::table('movimiento_cajas')->count();
        $cuenta_corriente = DB::table('current_acounts')->count();
        $pedido = $this->enviar($carrito)->assertCreated()->json('order_id');
        $this->enviar($carrito)->assertCreated()->assertJsonPath('order_id', $pedido);
        $this->assertSame(1, DB::table('orders')->where('user_id', $this->comercio->id)->count());
        $this->assertDatabaseHas('order_stock_reservations', ['order_id' => $pedido, 'article_id' => $articulo->id, 'amount' => 6]);
        $this->assertSame(10, (int) DB::table('articles')->where('id', $articulo->id)->value('stock'));
        $this->assertSame($ventas, DB::table('sales')->count());
        $this->assertSame($movimientos, DB::table('stock_movements')->count());
        $this->assertSame($caja, DB::table('movimiento_cajas')->count());
        $this->assertSame($cuenta_corriente, DB::table('current_acounts')->count());
        $this->getJson('/api/articles/'.$articulo->slug.'/'.$this->comercio->id)->assertOk()->assertJsonPath('article.stock', 4);
    }

    public function test_carrito_enviado_no_se_puede_reescribir_y_la_reserva_conserva_su_pedido()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        $carrito = $this->carrito([$this->lineaDelPayload($articulo, 6)]);
        $pedido = $this->enviar($carrito)->assertCreated()->json('order_id');
        $this->withSession(['carritos_propios' => [$carrito]])->putJson('/api/carts', ['id' => $carrito, 'articles' => [$this->lineaDelPayload($articulo, 2)], 'promociones_vinoteca' => []])
            ->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');
        $this->assertDatabaseHas('article_order', ['order_id' => $pedido, 'article_id' => $articulo->id, 'amount' => 6]);
        $this->assertDatabaseHas('order_stock_reservations', ['order_id' => $pedido, 'amount' => 6]);
    }

    public function test_reserva_impide_otro_pedido_que_supera_disponible_y_revierte_todo()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        $primero = $this->carrito([$this->lineaDelPayload($articulo, 6)]);
        $segundo = $this->carrito([$this->lineaDelPayload($articulo, 5)]);
        $this->enviar($primero)->assertCreated();
        $this->enviar($segundo)->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');
        $this->assertSame(1, DB::table('orders')->where('user_id', $this->comercio->id)->count());
        $this->assertSame(6.0, (float) DB::table('order_stock_reservations')->where('user_id', $this->comercio->id)->sum('amount'));
        $this->assertNull(Cart::find($segundo)->order_id);
    }

    public function test_agotado_por_reservas_conserva_consultar_y_no_reserva_inexistentes_ni_cobra()
    {
        $this->activar();
        $this->comercio->online_configuration()->update(['permitir_pedidos_sin_stock' => true, 'show_articles_without_stock' => false]);
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 2, 'name' => 'Reserva consultar']);
        $this->enviar($this->carrito([$this->lineaDelPayload($articulo, 2)]))->assertCreated();
        $this->getJson('/api/articles/search/Reserva/'.$this->comercio->id)->assertOk()->assertSee('Reserva consultar');
        $this->getJson('/api/articles/'.$articulo->slug.'/'.$this->comercio->id)->assertOk()->assertJsonPath('article.stock', 0);
        $carrito = $this->carrito([$this->lineaDelPayload($articulo, 8)]);
        $pedido = $this->enviar($carrito, true)->assertCreated()->json('order_id');
        $this->assertDatabaseHas('article_order', ['order_id' => $pedido, 'article_id' => $articulo->id, 'stock_a_consultar' => true]);
        $this->assertDatabaseMissing('order_stock_reservations', ['order_id' => $pedido]);
        $this->assertNull(Order::find($pedido)->payment_method_id);
        $this->assertNull(Order::find($pedido)->payment_id);
        $this->assertSame(2, (int) DB::table('articles')->where('id', $articulo->id)->value('stock'));
    }

    public function test_agotado_por_reserva_se_oculta_sin_consultar_si_no_se_muestran_agotados()
    {
        $this->activar();
        $this->comercio->online_configuration()->update(['show_articles_without_stock' => false]);
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 1, 'name' => 'Reserva ultima']);
        $this->enviar($this->carrito([$this->lineaDelPayload($articulo, 1)]))->assertCreated();
        $this->getJson('/api/articles/search/Reserva/'.$this->comercio->id)->assertOk()->assertDontSee('Reserva ultima');
    }

    public function test_variantes_reservan_su_deposito_sin_afectar_otra_variante_o_otro_deposito()
    {
        $this->activar();
        $deposito = DB::table('addresses')->insertGetId(['user_id' => $this->comercio->id, 'street' => 'Depósito principal', 'default_address' => true]);
        $otro = DB::table('addresses')->insertGetId(['user_id' => $this->comercio->id, 'street' => 'Otro depósito']);
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 30]);
        $variante = DB::table('article_variants')->insertGetId(['article_id' => $articulo->id, 'stock' => 30]);
        $otra = DB::table('article_variants')->insertGetId(['article_id' => $articulo->id, 'stock' => 4]);
        DB::table('address_article_variant')->insert([
            ['address_id' => $deposito, 'article_variant_id' => $variante, 'amount' => 10],
            ['address_id' => $otro, 'article_variant_id' => $variante, 'amount' => 20],
        ]);
        $pedido = $this->enviar($this->carrito([$this->lineaDelPayload($articulo, 6, ['pivot' => ['amount' => 6, 'notes' => null, 'variant_id' => $variante]])]))->assertCreated()->json('order_id');
        $this->assertDatabaseHas('order_stock_reservations', ['order_id' => $pedido, 'article_variant_id' => $variante, 'address_id' => $deposito, 'amount' => 6]);
        $respuesta = $this->getJson('/api/articles/'.$articulo->slug.'/'.$this->comercio->id)->assertOk();
        $variantes = collect($respuesta->json('article.article_variants'))->keyBy('id');
        $this->assertSame(4.0, (float) $variantes[$variante]['stock']);
        $this->assertSame(4.0, (float) $variantes[$otra]['stock']);
        $this->assertSame(20, (int) DB::table('address_article_variant')->where('address_id', $otro)->value('amount'));
        $this->assertSame($deposito, (int) Order::find($pedido)->address_id);
    }

    public function test_domicilio_del_comprador_no_se_usa_como_deposito()
    {
        $this->activar();
        $principal = DB::table('addresses')->insertGetId(['user_id' => $this->comercio->id, 'street' => 'Principal', 'default_address' => true]);
        $domicilio = DB::table('addresses')->insertGetId(['user_id' => $this->comercio->id, 'buyer_id' => $this->comprador->id, 'street' => 'Domicilio comprador']);
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        $carrito = $this->carrito([$this->lineaDelPayload($articulo, 6)], ['address_id' => $domicilio, 'deliver' => true]);
        $pedido = $this->enviar($carrito)->assertCreated()->json('order_id');
        $this->assertSame($principal, (int) Order::find($pedido)->address_id);
        $this->assertDatabaseHas('order_stock_reservations', ['order_id' => $pedido, 'address_id' => $principal]);
        $this->assertDatabaseMissing('order_stock_reservations', ['order_id' => $pedido, 'address_id' => $domicilio]);
    }

    public function test_combo_y_articulo_comparten_recurso_y_su_disponibilidad()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        $combo = $this->combo($this->comercio);
        $combo->articles()->attach($articulo->id, ['amount' => 2]);
        $carrito = $this->carrito([$this->lineaDelPayload($articulo, 3)], ['combos' => [$this->lineaDeComboDelPayload($combo, 2)]]);
        $pedido = $this->enviar($carrito)->assertCreated()->json('order_id');
        $this->assertDatabaseHas('order_stock_reservations', ['order_id' => $pedido, 'article_id' => $articulo->id, 'amount' => 7]);
        $this->assertSame(3.0, (float) $articulo->fresh()->stock);
        $this->assertSame(1, \App\Http\Controllers\Helpers\ComboStockHelper::para([$combo->id])[$combo->id]);
    }

    public function test_combo_mas_articulo_no_supera_fisico_aunque_cada_linea_individual_quepa()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        $combo = $this->combo($this->comercio);
        $combo->articles()->attach($articulo->id, ['amount' => 2]);
        $carrito = $this->carrito([$this->lineaDelPayload($articulo, 7)], ['combos' => [$this->lineaDeComboDelPayload($combo, 2)]]);
        $this->enviar($carrito)->assertUnprocessable();
        $this->assertDatabaseMissing('orders', ['user_id' => $this->comercio->id]);
        $this->assertDatabaseMissing('order_stock_reservations', ['user_id' => $this->comercio->id]);
    }

    public function test_combo_publicado_puede_reservar_componentes_no_publicados_y_null_no_limita()
    {
        $this->activar();
        $this->comercio->online_configuration()->update(['stock_null_equal_0' => true]);
        $oculto = $this->articuloPublicado($this->comercio, ['stock' => 5, 'online' => false]);
        $sin_control = $this->articuloPublicado($this->comercio, ['stock' => null, 'online' => false]);
        $combo = $this->combo($this->comercio);
        $combo->articles()->attach($oculto->id, ['amount' => 1]);
        $combo->articles()->attach($sin_control->id, ['amount' => 1]);
        $pedido = $this->enviar($this->carrito([], ['combos' => [$this->lineaDeComboDelPayload($combo, 2)]]))->assertCreated()->json('order_id');
        $this->assertDatabaseHas('order_stock_reservations', ['order_id' => $pedido, 'article_id' => $oculto->id, 'amount' => 2]);
        $this->assertDatabaseMissing('order_stock_reservations', ['order_id' => $pedido, 'article_id' => $sin_control->id]);
    }

    public function test_combo_fraccionario_y_articulo_entero_respetan_precision_del_contrato()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 1.3]);
        $combo = $this->combo($this->comercio);
        $combo->articles()->attach($articulo->id, ['amount' => 1]);
        $respuesta = $this->enviar($this->carrito([$this->lineaDelPayload($articulo, 1)], ['combos' => [$this->lineaDeComboDelPayload($combo, 0.3)]]));
        $this->assertSame(201, $respuesta->status(), $respuesta->getContent());
        $pedido = $respuesta->json('order_id');
        $this->assertDatabaseHas('order_stock_reservations', ['order_id' => $pedido, 'article_id' => $articulo->id, 'amount' => 1.3]);
    }

    public function test_articulo_fraccionario_se_rechaza_antes_de_crear_pedido_o_truncar_reserva_solo_con_opcion_activa()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        $carrito = $this->carrito([$this->lineaDelPayload($articulo, 1.2)]);
        $this->enviar($carrito)->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock')
            ->assertJsonPath('message', 'Ingresá una cantidad entera y mayor que cero para cada artículo del pedido.');
        $this->assertDatabaseMissing('orders', ['user_id' => $this->comercio->id]);
        $this->assertDatabaseMissing('order_stock_reservations', ['user_id' => $this->comercio->id]);
        $this->assertNull(Cart::find($carrito)->order_id);
        $this->comercio->online_configuration()->update(['reservar_stock_pedidos_web' => false]);
        $this->enviar($carrito)->assertCreated();
        $this->assertDatabaseMissing('order_stock_reservations', ['user_id' => $this->comercio->id]);
    }

    public function test_cantidad_entera_fuera_del_rango_del_pedido_devuelve_422_sin_escritura_parcial()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => null, 'final_price' => 1]);
        $carrito = $this->carrito([$this->lineaDelPayload($articulo, 2147483648)]);
        $this->enviar($carrito)->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock')
            ->assertJsonPath('message', 'La cantidad de un artículo supera el máximo permitido para un pedido.');
        $this->assertDatabaseMissing('orders', ['user_id' => $this->comercio->id]);
        $this->assertDatabaseMissing('order_stock_reservations', ['user_id' => $this->comercio->id]);
    }

    public function test_combo_con_componente_de_variantes_no_envia_un_pedido_que_no_se_puede_confirmar()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        DB::table('article_variants')->insert(['article_id' => $articulo->id, 'stock' => 10]);
        $combo = $this->combo($this->comercio);
        $combo->articles()->attach($articulo->id, ['amount' => 1]);
        $carrito = $this->carrito([], ['combos' => [$this->lineaDeComboDelPayload($combo, 1)]]);
        $this->enviar($carrito)->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');
        $this->assertDatabaseMissing('order_stock_reservations', ['user_id' => $this->comercio->id]);
        $this->assertDatabaseMissing('orders', ['user_id' => $this->comercio->id]);
    }

    public function test_variantes_agotadas_por_reservas_se_ocultan_o_consultan_segun_configuracion()
    {
        $this->activar();
        $this->comercio->online_configuration()->update(['show_articles_without_stock' => false]);
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 2, 'name' => 'Variante reservada']);
        $variante = DB::table('article_variants')->insertGetId(['article_id' => $articulo->id, 'stock' => 2]);
        $carrito = $this->carrito([$this->lineaDelPayload($articulo, 2, ['pivot' => ['amount' => 2, 'notes' => null, 'variant_id' => $variante]])]);
        $this->enviar($carrito)->assertCreated();
        $this->getJson('/api/articles/'.$articulo->slug.'/'.$this->comercio->id)->assertOk()->assertJsonPath('article.stock', 0);
        $this->getJson('/api/articles/search/Variante/'.$this->comercio->id)->assertOk()->assertDontSee('Variante reservada');
        $this->comercio->online_configuration()->update(['permitir_pedidos_sin_stock' => true]);
        $this->getJson('/api/articles/search/Variante/'.$this->comercio->id)->assertOk()->assertSee('Variante reservada');
    }

    public function test_apagar_y_reactivar_no_borra_la_identidad_del_pedido_ni_duplica_al_reintentar_checkout()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        $carrito = $this->carrito([$this->lineaDelPayload($articulo, 6)]);
        $pedido = $this->enviar($carrito)->assertCreated()->json('order_id');
        // Es el contrato compartido del apagado en Empresa: libera participación, conserva origen inmutable.
        $this->comercio->online_configuration()->update(['reservar_stock_pedidos_web' => false]);
        DB::table('order_stock_reservations')->where('order_id', $pedido)->delete();
        DB::table('orders')->where('id', $pedido)->update(['reserva_stock_web_liberada' => true]);
        $this->enviar($carrito)->assertCreated()->assertJsonPath('order_id', $pedido);
        $this->activar();
        $this->enviar($carrito)->assertCreated()->assertJsonPath('order_id', $pedido);
        $this->assertSame(1, DB::table('orders')->where('user_id', $this->comercio->id)->count());
        $this->assertDatabaseHas('orders', ['id' => $pedido, 'reserva_stock_web' => true, 'reserva_stock_web_liberada' => true]);
        $this->assertDatabaseMissing('order_stock_reservations', ['order_id' => $pedido]);
        $this->assertSame(10, (int) DB::table('articles')->where('id', $articulo->id)->value('stock'));
    }

    public function test_configuracion_duplicada_no_duplica_componentes_ni_queries_con_opcion_apagada()
    {
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        $combo = $this->combo($this->comercio);
        $combo->articles()->attach($articulo->id, ['amount' => 2]);
        \App\OnlineConfiguration::create(['user_id' => $this->comercio->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $stocks = \App\Http\Controllers\Helpers\ComboStockHelper::para([$combo->id]);
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(5, $stocks[$combo->id]);
        $this->assertCount(1, $consultas);
    }

    public function test_ficha_con_cinco_variantes_no_agrega_consultas_por_variante_con_opcion_apagada()
    {
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        for ($i = 0; $i < 5; $i++) {
            DB::table('article_variants')->insert(['article_id' => $articulo->id, 'stock' => 2]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/articles/'.$articulo->slug.'/'.$this->comercio->id)->assertOk()->assertJsonCount(5, 'article.article_variants');
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();
        $busquedas_dueno = array_filter($consultas, function ($consulta) {
            return strpos($consulta['query'], 'join `articles` on `articles`.`id` = `article_variants`.`article_id`') !== false;
        });
        $this->assertCount(0, $busquedas_dueno, 'La pertenencia ya viene de los artículos leídos; no consultar una vez por variante con la opción apagada.');
    }

    public function test_pivotes_duplicados_del_deposito_no_producen_una_reserva_que_duplique_el_egreso()
    {
        $this->activar();
        $deposito = DB::table('addresses')->insertGetId(['user_id' => $this->comercio->id, 'street' => 'Principal duplicado', 'default_address' => true]);
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        DB::table('address_article')->insert([
            ['address_id' => $deposito, 'article_id' => $articulo->id, 'amount' => 4],
            ['address_id' => $deposito, 'article_id' => $articulo->id, 'amount' => 6],
        ]);
        $carrito = $this->carrito([$this->lineaDelPayload($articulo, 6)]);
        $this->enviar($carrito)->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock')
            ->assertJsonPath('message', 'El stock del depósito tiene registros duplicados. Pedí al equipo de soporte que lo revise antes de reservar este artículo.');
        $this->assertDatabaseMissing('orders', ['user_id' => $this->comercio->id]);
        $this->assertDatabaseMissing('order_stock_reservations', ['user_id' => $this->comercio->id]);
        $this->assertSame(10.0, (float) DB::table('address_article')->where('article_id', $articulo->id)->sum('amount'));
        $this->assertSame(10, (int) DB::table('articles')->where('id', $articulo->id)->value('stock'));
    }

    public function test_promocion_reserva_stock_propio_y_sin_stock_no_se_confirma()
    {
        $this->activar();
        $promo = $this->promocionVinoteca($this->comercio, 200);
        $promo->update(['stock' => 5]);
        $primero = $this->carrito([], ['promociones_vinoteca' => [$this->lineaDePromoDelPayload($promo, 3)]]);
        $segundo = $this->carrito([], ['promociones_vinoteca' => [$this->lineaDePromoDelPayload($promo, 3)]]);
        $pedido = $this->enviar($primero)->assertCreated()->json('order_id');
        $this->assertDatabaseHas('order_stock_reservations', ['order_id' => $pedido, 'promocion_vinoteca_id' => $promo->id, 'amount' => 3]);
        $this->assertSame(2.0, (float) $promo->fresh()->stock);
        $this->enviar($segundo)->assertUnprocessable();
    }

    public function test_stock_sin_control_no_crea_unidades_ficticias_en_la_reserva()
    {
        $this->activar();
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => null]);
        $pedido = $this->enviar($this->carrito([$this->lineaDelPayload($articulo, 100)]))->assertCreated()->json('order_id');
        $this->assertDatabaseMissing('order_stock_reservations', ['order_id' => $pedido]);
        $this->assertNull($articulo->fresh()->stock);
        $this->comercio->online_configuration()->update(['stock_null_equal_0' => true]);
        $this->postJson('/api/carts', ['commerce_id' => $this->comercio->id, 'cart' => ['articles' => [$this->lineaDelPayload($articulo, 1)], 'promociones_vinoteca' => []]])->assertUnprocessable();
    }

    public function test_contrato_vivo_no_publica_metadata_y_sin_esquema_usa_comportamiento_anterior()
    {
        $this->getJson('/api/commerce/reserva-stock-capability/'.$this->comercio->id)->assertOk()
            ->assertJsonPath('contract', 1)->assertHeader('Cache-Control');
        $cabecera = $this->getJson('/api/commerce/reserva-stock-capability/'.$this->comercio->id)->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cabecera);
        $this->assertStringContainsString('no-cache', $cabecera);
        $this->assertStringContainsString('must-revalidate', $cabecera);
        $this->getJson('/api/commerce/'.$this->comercio->id)->assertOk()
            ->assertJsonMissingPath('commerce.online_configuration.reserva_stock_tienda_url')
            ->assertJsonMissingPath('commerce.online_configuration.reserva_stock_tienda_protocolo');
        $esquema = Schema::getFacadeRoot();
        $anterior = \Mockery::mock($esquema)->makePartial();
        $anterior->shouldReceive('hasColumn')->with('online_configurations', 'reservar_stock_pedidos_web')->andReturn(false);
        Schema::swap($anterior);
        try {
            $this->getJson('/api/commerce/reserva-stock-capability/'.$this->comercio->id)->assertOk()->assertJsonPath('contract', 0);
            $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
            $this->enviar($this->carrito([$this->lineaDelPayload($articulo, 6)]))->assertCreated();
            $this->assertSame(10.0, (float) $articulo->fresh()->stock);
        } finally {
            Schema::swap($esquema);
        }
    }

    public function test_activar_solo_bandera_sin_protocolo_compatible_no_reserva()
    {
        $this->comercio->online_configuration()->update(['reservar_stock_pedidos_web' => true]);
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => 10]);
        $pedido = $this->enviar($this->carrito([$this->lineaDelPayload($articulo, 6)]))->assertCreated()->json('order_id');
        $this->assertDatabaseMissing('order_stock_reservations', ['order_id' => $pedido]);
    }

    private function activar()
    {
        $this->comercio->online_configuration()->update(['reservar_stock_pedidos_web' => true, 'reserva_stock_tienda_protocolo' => 1]);
    }

    private function carrito(array $articulos, array $opciones = [])
    {
        return $this->postJson('/api/carts', ['commerce_id' => $this->comercio->id, 'cart' => $opciones + ['articles' => $articulos, 'promociones_vinoteca' => []]])
            ->assertCreated()->json('cart.id');
    }

    private function enviar($carrito, $consulta = false)
    {
        return $this->withSession(['carritos_propios' => [$carrito], 'checkout_buyer_id' => $this->comprador->id])
            ->postJson('/api/orders', ['cart_id' => $carrito, 'commerce_id' => $this->comercio->id, 'address' => 'Local de prueba 10', 'aceptar_consulta_stock' => $consulta]);
    }
}
