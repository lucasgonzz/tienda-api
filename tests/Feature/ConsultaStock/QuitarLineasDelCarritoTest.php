<?php

namespace Tests\Feature\ConsultaStock;

use App\Article;
use App\Buyer;
use App\Cart;
use App\ArticlePriceTypeGroup;
use App\Category;
use App\CategoryPriceTypeRange;
use App\PriceType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\CombosYRangos\ArmaComercioConCombosYRangos;
use Tests\TestCase;

class QuitarLineasDelCarritoTest extends TestCase
{
    use ArmaComercioConCombosYRangos, DatabaseTransactions;

    private $comercio;
    private $carrito;

    protected function setUp(): void
    {
        parent::setUp();
        $this->olvidarLasMemorias();
        $this->comercio = $this->comercioConTienda();
        $this->carrito = Cart::create(['user_id' => $this->comercio->id, 'total' => 0]);
        $this->withSession(['carritos_propios' => [$this->carrito->id]]);
    }

    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();
        parent::tearDown();
    }

    public function test_quitar_admite_otras_lineas_en_cero_y_sin_stock_sin_reescribirlas()
    {
        $this->comercio->online_configuration()->update(['permitir_pedidos_sin_stock' => true]);
        $quitar = $this->linea(2, 10);
        $cero = $this->linea(0, 20);
        $sin_stock = $this->linea(4, 30, ['stock' => 1]);
        $antes = DB::table('article_cart')->where('cart_id', $this->carrito->id)
            ->whereIn('article_id', [$cero->id, $sin_stock->id])->orderBy('id')->get()->toArray();

        // El camino anterior del botón Quitar volvía a guardar todas las líneas restantes:
        // la cantidad cero de OTRA línea lo bloqueaba antes de eliminar la elegida (ticket119).
        $this->putJson('/api/carts', [
            'id' => $this->carrito->id,
            'articles' => [$this->lineaDelPayload($cero, 0), $this->lineaDelPayload($sin_stock, 4)],
            'promociones_vinoteca' => [],
        ])->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');

        $respuesta = $this->quitar($quitar)->assertOk()->assertJsonCount(2, 'cart.articles');
        $this->assertSame(120.0, (float) $respuesta->json('cart.total'));

        $despues = DB::table('article_cart')->where('cart_id', $this->carrito->id)->orderBy('id')->get()->toArray();
        $this->assertEquals($antes, $despues, 'Quitar no normaliza ni descarta otras líneas.');
    }

    public function test_sacar_una_variante_conserva_las_otras_y_los_duplicados_remanentes()
    {
        $articulo = $this->articuloPublicado($this->comercio);
        $primera = DB::table('article_variants')->insertGetId(['article_id' => $articulo->id, 'stock' => 3]);
        $segunda = DB::table('article_variants')->insertGetId(['article_id' => $articulo->id, 'stock' => 3]);
        foreach ([[$primera, 1], [$segunda, 0], [$segunda, 2]] as [$variante, $cantidad]) {
            $this->carrito->articles()->attach($articulo->id, ['variant_id' => $variante, 'amount' => $cantidad, 'price' => 15, 'cost' => 0]);
        }

        $this->quitar($articulo, ['variant_id' => $primera])->assertOk()->assertJsonCount(2, 'cart.articles');
        $this->assertSame(2, DB::table('article_cart')->where('cart_id', $this->carrito->id)->where('variant_id', $segunda)->count());
        $this->assertEquals(30, $this->carrito->fresh()->total);
    }

    public function test_no_elige_otra_variante_si_la_solicitada_no_esta()
    {
        $articulo = $this->linea(2, 15);
        $variante = DB::table('article_variants')->insertGetId(['article_id' => $articulo->id, 'stock' => 3]);
        $this->carrito->articles()->updateExistingPivot($articulo->id, ['variant_id' => $variante]);
        $this->quitar($articulo)->assertOk()->assertJsonCount(1, 'cart.articles');
        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $articulo->id, 'variant_id' => $variante]);
    }

    public function test_promociones_y_combos_se_quitan_solo_de_su_coleccion()
    {
        $articulo = $this->linea(0, 15);
        $promo = $this->promocionVinoteca($this->comercio, 20);
        $combo = $this->combo($this->comercio);
        $this->carrito->promociones_vinoteca()->attach($promo->id, ['amount' => 2, 'price' => 20, 'cost' => 0]);
        $this->carrito->combos()->attach($combo->id, ['amount' => 3, 'price' => 30, 'cost' => 0]);

        $this->deleteJson($this->ruta(), ['tipo' => 'promocion_vinoteca', 'id' => $promo->id])
            ->assertOk()->assertJsonCount(1, 'cart.articles')->assertJsonCount(0, 'cart.promociones_vinoteca')->assertJsonCount(1, 'cart.combos');
        $this->assertEquals(90, $this->carrito->fresh()->total);
        $this->deleteJson($this->ruta(), ['tipo' => 'combo', 'id' => $combo->id])
            ->assertOk()->assertJsonCount(1, 'cart.articles')->assertJsonCount(0, 'cart.combos');
        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $articulo->id, 'amount' => 0]);
    }

    public function test_quitar_el_ultimo_item_elimina_el_carrito()
    {
        $articulo = $this->linea(1, 10);
        $this->quitar($articulo)->assertOk()->assertJsonPath('cart', null);
        $this->assertDatabaseMissing('carts', ['id' => $this->carrito->id]);
        $this->assertDatabaseMissing('article_cart', ['cart_id' => $this->carrito->id]);
    }

    public function test_quitar_una_linea_ausente_no_elimina_ninguna_otra()
    {
        $articulo = $this->linea(2, 15);
        $ausente = $this->articuloPublicado($this->comercio);
        $this->quitar($ausente)->assertOk()->assertJsonCount(1, 'cart.articles');
        $this->quitar($ausente)->assertOk()->assertJsonCount(1, 'cart.articles');
        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $articulo->id, 'amount' => 2]);
    }

    public function test_quitar_invalida_la_cotizacion_de_envio_y_conserva_el_destino()
    {
        $quitar = $this->linea(2, 15);
        $this->linea(1, 10);
        $destino = ['calle' => 'Prueba', 'numero' => '10', 'codigo_postal' => '5000'];
        $this->carrito->update([
            'deliver' => 1,
            'envio_opcion' => ['key' => 'correo-prueba', 'precio' => 150],
            'envio_cotizacion' => ['items_hash' => 'hash-viejo'],
            'envio_precio' => 150,
            'envio_destino' => $destino,
        ]);
        $this->quitar($quitar)->assertOk();
        $guardado = $this->carrito->fresh();
        $this->assertNull($guardado->envio_opcion);
        $this->assertNull($guardado->envio_cotizacion);
        $this->assertNull($guardado->envio_precio);
        $this->assertSame($destino, $guardado->envio_destino);
    }

    public function test_quitar_recalcula_el_precio_del_grupo_con_las_cantidades_que_quedan()
    {
        $slug = 'lista_de_precios_por_rango_de_cantidad_vendida';
        $extension = DB::table('extencion_empresas')->where('slug', $slug)->value('id');
        if (!$extension) {
            $extension = DB::table('extencion_empresas')->insertGetId(['name' => 'Rangos Quitar', 'slug' => $slug]);
        }
        DB::table('extencion_empresa_user')->insert(['extencion_empresa_id' => $extension, 'user_id' => $this->comercio->id]);
        $categoria = Category::create(['name' => 'Categoría Quitar', 'user_id' => $this->comercio->id]);
        $lista_normal = new PriceType;
        $lista_normal->name = 'Normal Quitar';
        $lista_normal->position = 1;
        $lista_normal->user_id = $this->comercio->id;
        $lista_normal->save();
        $lista_volumen = new PriceType;
        $lista_volumen->name = 'Volumen Quitar';
        $lista_volumen->position = 2;
        $lista_volumen->user_id = $this->comercio->id;
        $lista_volumen->save();
        foreach ([[$lista_normal, 1, 9], [$lista_volumen, 10, null]] as [$lista, $min, $max]) {
            $rango = new CategoryPriceTypeRange;
            $rango->category_id = $categoria->id;
            $rango->user_id = $this->comercio->id;
            $rango->price_type_id = $lista->id;
            $rango->min = $min;
            $rango->max = $max;
            $rango->save();
        }
        $quitar = $this->linea(5, 80, ['category_id' => $categoria->id]);
        $queda = $this->linea(5, 80, ['category_id' => $categoria->id]);
        foreach ([$quitar, $queda] as $articulo) {
            $articulo->price_types()->attach($lista_normal->id, ['price' => 100, 'final_price' => 100]);
            $articulo->price_types()->attach($lista_volumen->id, ['price' => 80, 'final_price' => 80]);
        }
        $grupo = new ArticlePriceTypeGroup;
        $grupo->name = 'Grupo Quitar';
        $grupo->user_id = $this->comercio->id;
        $grupo->save();
        $grupo->articles()->attach([$quitar->id, $queda->id]);

        $respuesta = $this->quitar($quitar)->assertOk();
        $this->assertSame(100.0, (float) $respuesta->json('cart.articles.0.pivot.price'));
        $this->assertSame(500.0, (float) $respuesta->json('cart.total'));
        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $queda->id, 'amount' => 5]);

        // Duplicado heredado de otra cantidad: no puede heredar el precio del último renglón
        // solo porque comparte artículo y variante con él.
        $this->carrito->articles()->attach($queda->id, ['amount' => 12, 'price' => 80, 'cost' => 0]);
        $this->quitar($quitar)->assertOk()->assertJsonCount(2, 'cart.articles');
        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $queda->id, 'amount' => 5, 'price' => 100]);
        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $queda->id, 'amount' => 12, 'price' => 80]);
        $this->assertSame(1460.0, (float) $this->carrito->fresh()->total);
    }

    public function test_quitar_conserva_el_recalculo_de_tramos_por_articulo()
    {
        $quitar = $this->linea(1, 10);
        $queda = $this->linea(10, 9999);
        $this->conLaEscalaDeLaReproduccion($queda);
        $respuesta = $this->quitar($quitar)->assertOk();
        $this->assertSame(self::TRAMO_10, (float) $respuesta->json('cart.articles.0.pivot.price'));
        $this->assertSame(self::TRAMO_10 * 10, (float) $respuesta->json('cart.total'));
    }

    public function test_un_tipo_o_identificador_invalido_no_modifica_el_carrito()
    {
        $articulo = $this->linea(2, 10);
        foreach ([['tipo' => 'pedido', 'id' => $articulo->id], ['tipo' => 'articulo', 'id' => 0], ['tipo' => 'articulo', 'id' => $articulo->id, 'variant_id' => -1]] as $datos) {
            $this->deleteJson($this->ruta(), $datos)->assertUnprocessable();
            $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $articulo->id, 'amount' => 2]);
        }
    }

    public function test_no_se_puede_quitar_de_un_carrito_ajeno()
    {
        $articulo = $this->linea(2, 10);
        $this->withSession(['carritos_propios' => []]);
        $this->quitar($articulo)->assertForbidden();
        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $articulo->id]);
    }

    public function test_el_comprador_autenticado_puede_quitar_su_carrito_sin_lista_de_sesion()
    {
        $comprador = Buyer::create(['user_id' => $this->comercio->id, 'name' => 'Comprador Quitar']);
        $this->carrito->update(['buyer_id' => $comprador->id]);
        $articulo = $this->linea(1, 10);
        $this->withSession(['carritos_propios' => []])->actingAs($comprador, 'buyer');
        $this->quitar($articulo)->assertOk()->assertJsonPath('cart', null);
    }

    public function test_no_se_modifica_un_carrito_ya_enviado_como_pedido()
    {
        $articulo = $this->linea(1, 10);
        $this->carrito->update(['order_id' => 123]);
        $this->quitar($articulo)->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');
        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $articulo->id]);
    }

    public function test_el_guardado_y_checkout_siguen_rechazando_la_cantidad_invalida_remanente()
    {
        if (!Schema::hasColumn('online_configurations', 'permitir_pedidos_sin_stock')) {
            $this->markTestSkipped('Falta el esquema de consultas de stock del ERP en el slot.');
        }
        $this->comercio->online_configuration()->update(['permitir_pedidos_sin_stock' => true]);
        $quitar = $this->linea(1, 10);
        $cero = $this->linea(0, 20);
        $this->quitar($quitar)->assertOk();
        $this->putJson('/api/carts', ['id' => $this->carrito->id, 'articles' => [$this->lineaDelPayload($cero, 0)], 'promociones_vinoteca' => []])
            ->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');
        $comprador = Buyer::create(['user_id' => $this->comercio->id, 'name' => 'Comprador Quitar']);
        $this->withSession(['carritos_propios' => [$this->carrito->id], 'checkout_buyer_id' => $comprador->id])
            ->postJson('/api/orders', ['cart_id' => $this->carrito->id, 'commerce_id' => $this->comercio->id, 'address' => 'Local de prueba'])
            ->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');
        $this->assertDatabaseMissing('orders', ['user_id' => $this->comercio->id]);
    }

    private function linea($cantidad, $precio, array $atributos = [])
    {
        $articulo = $this->articuloPublicado($this->comercio, $atributos);
        $this->carrito->articles()->attach($articulo->id, ['amount' => $cantidad, 'price' => $precio, 'cost' => 0, 'variant_id' => null]);
        return $articulo;
    }

    private function ruta()
    {
        return '/api/carts/'.$this->carrito->id.'/items';
    }

    private function quitar(Article $articulo, array $datos = [])
    {
        return $this->deleteJson($this->ruta(), $datos + ['tipo' => 'articulo', 'id' => $articulo->id]);
    }
}
