<?php

namespace Tests\Feature\Consultas;

use App\Article;
use App\Cart;
use App\Order;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ContextoDeComercio;
use App\Http\Controllers\Helpers\OrderHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CatalogoPorLista\ArmaCatalogoPorLista;
use Tests\TestCase;

class OptimizacionDeContextoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCatalogoPorLista;

    protected function setUp(): void
    {
        parent::setUp();
        $this->olvidarLasMemorias();
        $this->armarFerretotal();
        DB::table('article_price_type')->whereIn('article_id', $this->losTres())->update(['price' => DB::raw('final_price')]);
        $this->comoVisitante();
        // El kernel sigue siendo HTTP aunque PHPUnit arranque por CLI. El camino de consola
        // relee configuración deliberadamente y no sirve para medir las consultas de la tienda.
        $propiedad = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $propiedad->setAccessible(true);
        $propiedad->setValue($this->app, false);
    }

    public function test_la_portada_comparte_una_lectura_de_comercio_configuracion_y_extensiones()
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $respuesta = $this->getJson('/api/articles/featured-last-uploads/'.$this->comercio->id.'?page=1')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach (['users', 'online_configurations'] as $tabla) {
            $lecturas = array_filter($queries, function ($query) use ($tabla) {
                return str_starts_with($query['query'], 'select * from `'.$tabla.'`');
            });
            $this->assertCount(1, $lecturas, 'La portada debe resolver '.$tabla.' una sola vez.');
        }
        $extensiones = array_filter($queries, function ($query) {
            return str_contains($query['query'], 'from `extencion_empresas`');
        });
        $this->assertCount(1, $extensiones);
        $primero = collect($respuesta->json('articles.data'))->firstWhere('id', $this->habilitado->id);
        $this->assertEquals(1500, $primero['final_price']);
        $this->assertCount(3, $respuesta->json('featured'));
    }

    public function test_otro_request_del_mismo_worker_relee_visibilidad_y_stock_sin_reset_manual()
    {
        $url = '/api/articles/featured-last-uploads/'.$this->comercio->id.'?page=1';
        $this->getJson($url)->assertOk()->assertJsonPath('articles.total', 3);

        $tipo = DB::table('online_price_types')->where('slug', 'only_registered')->value('id');
        $this->assertNotNull($tipo);
        DB::table('online_configurations')->where('user_id', $this->comercio->id)->update([
            'register_to_buy' => 1, 'online_price_type_id' => $tipo,
            'show_articles_without_stock' => 0,
        ]);
        Article::where('id', $this->sin_marcar->id)->update(['stock' => 0]);

        $respuesta = $this->getJson($url)->assertOk()->assertJsonPath('articles.total', 2);
        foreach ($respuesta->json('articles.data') as $article) {
            $this->assertNull($article['final_price']);
            $this->assertSame([], $article['price_types']);
        }
    }

    public function test_el_contexto_separa_comercios_en_un_mismo_request()
    {
        $otro = $this->comercioConTienda();
        DB::table('online_configurations')->where('user_id', $otro->id)->update(['ignorar_stock' => 1]);
        $primero = ContextoDeComercio::comercio($this->comercio->id);
        $segundo = ContextoDeComercio::comercio($otro->id);
        $this->assertNotSame($primero, $segundo);
        $this->assertSame($primero, ContextoDeComercio::comercio($this->comercio->id));
        $this->assertNotEquals($primero->online_configuration->ignorar_stock, $segundo->online_configuration->ignorar_stock);
    }

    public function test_el_contexto_separa_conexiones_aun_con_el_mismo_id()
    {
        // Una cuenta ya sembrada es visible desde las dos conexiones: las fixtures nuevas de
        // esta transacción todavía no son visibles desde la conexión alternativa.
        $id = DB::table('users')->orderBy('id')->value('id');
        $original = DB::getDefaultConnection();
        config(['database.connections.contexto_prueba' => config('database.connections.'.$original)]);
        $primero = ContextoDeComercio::comercio($id);
        try {
            DB::setDefaultConnection('contexto_prueba');
            $segundo = ContextoDeComercio::comercio($id);
            $this->assertNotNull($segundo);
            $this->assertNotSame($primero, $segundo);
            $this->assertEquals($primero->id, $segundo->id);
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('contexto_prueba');
        }
    }

    public function test_los_rangos_compartidos_conservan_el_precio_de_cada_articulo_y_su_payload()
    {
        DB::table('category_price_type_ranges')->insert([
            'user_id' => $this->comercio->id,
            'category_id' => $this->herramientas->id, 'price_type_id' => $this->minorista->id,
            'min' => 1, 'max' => null,
        ]);
        // Ambos usan la misma subcategoría y categoría: el eager loading comparte esos modelos.
        Article::where('id', $this->sin_marcar->id)->update(['sub_category_id' => $this->taladros->id]);
        $articulos = Article::whereIn('id', [$this->habilitado->id, $this->sin_marcar->id])->with('price_types')->orderBy('id')->get();
        DB::enableQueryLog();
        DB::flushQueryLog();
        ArticleHelper::set_ranges($articulos);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $payload = $articulos->toArray();
        $this->assertEquals(1500, $payload[0]['ranges'][0]['price']);
        $this->assertEquals(2500, $payload[1]['ranges'][0]['price']);
        $this->assertEquals(1500, $payload[0]['category']['category_price_type_ranges'][0]['price']);
        $this->assertEquals(2500, $payload[1]['category']['category_price_type_ranges'][0]['price']);
        $this->assertSame([], $payload[0]['sub_category']['category_price_type_ranges']);
        $this->assertCount(4, $queries, 'Dos relaciones y sus tramos se cargan en cuatro consultas, independientemente de los artículos.');
    }

    public function test_los_tramos_de_subcategoria_no_agregan_la_categoria_al_payload()
    {
        DB::table('category_price_type_ranges')->insert([
            'user_id' => $this->comercio->id,
            'category_id' => $this->herramientas->id, 'sub_category_id' => $this->taladros->id,
            'price_type_id' => $this->minorista->id, 'min' => 1, 'max' => null,
        ]);
        Article::where('id', $this->sin_marcar->id)->update(['sub_category_id' => $this->taladros->id]);
        $articulos = Article::whereIn('id', [$this->habilitado->id, $this->sin_marcar->id])->with('price_types')->orderBy('id')->get();
        ArticleHelper::set_ranges($articulos);
        $payload = $articulos->first()->toArray();
        $this->assertArrayNotHasKey('category', $payload);
        $this->assertEquals(1500, $payload['ranges'][0]['price']);
        $this->assertEquals(2500, $articulos->last()->toArray()['ranges'][0]['price']);
    }

    public function test_el_carrito_resuelve_las_variantes_en_lote_sin_mezclar_renglones()
    {
        $comprador = $this->compradorSinCliente($this->comercio);
        $this->comoComprador($comprador);
        $lineas = [];
        foreach ([$this->habilitado, $this->sin_marcar] as $indice => $article) {
            $variante = DB::table('article_variants')->insertGetId([
                'article_id' => $article->id, 'variant_description' => 'Variante '.$indice,
                'stock' => 10 + $indice, 'price' => 200 + $indice,
            ]);
            $lineas[] = [
                'id' => $article->id, 'user_id' => $this->comercio->id, 'name' => $article->name,
                'final_price' => 1500 + 1000 * $indice, 'cost' => null,
                'pivot' => ['amount' => 1 + $indice, 'notes' => 'Línea '.$indice, 'variant_id' => $variante],
            ];
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $respuesta = $this->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart' => ['articles' => $lineas, 'promociones_vinoteca' => [], 'combos' => []],
        ])->assertCreated();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $selecciones = array_filter($queries, function ($query) {
            return str_starts_with($query['query'], 'select * from `article_variants`');
        });
        // Un lote del withAll de artículos y otro de la variante elegida (antes era uno por línea).
        $this->assertCount(2, $selecciones);
        $articulos = $respuesta->json('cart.articles');
        foreach ($lineas as $linea) {
            $guardado = collect($articulos)->firstWhere('id', $linea['id']);
            $this->assertEquals($linea['pivot']['variant_id'], $guardado['selected_variant']['id']);
            $this->assertEquals($linea['pivot']['amount'], $guardado['pivot']['amount']);
            $this->assertEquals($linea['final_price'], $guardado['pivot']['price']);
        }
        $this->assertEquals(6500, $respuesta->json('cart.total'));
        $this->assertEquals(10, DB::table('article_variants')->where('id', $lineas[0]['pivot']['variant_id'])->value('stock'));
    }

    public function test_copiar_articulos_al_pedido_consulta_los_existentes_una_vez_y_respeta_duplicados()
    {
        $comprador = $this->compradorSinCliente($this->comercio);
        $cart = Cart::create(['user_id' => $this->comercio->id, 'buyer_id' => $comprador->id, 'total' => 8000]);
        $order = Order::create(['user_id' => $this->comercio->id, 'buyer_id' => $comprador->id, 'num' => 1, 'total' => 8000, 'deliver' => 0, 'address_id' => 0, 'order_status_id' => 0]);
        foreach ([[$this->habilitado, 1, 1500], [$this->habilitado, 3, 2000], [$this->sin_marcar, 2, 2500]] as $linea) {
            $cart->articles()->attach($linea[0]->id, ['amount' => $linea[1], 'price' => $linea[2], 'cost' => 0]);
        }
        // El recargo afecta artículos del pedido y no stock ni precios guardados del carrito.
        DB::table('online_configurations')->where('user_id', $this->comercio->id)->update(['online_price_surchage' => 10]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        OrderHelper::attachArticles($cart, $order, null);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $existentes = array_filter($queries, function ($query) {
            return str_starts_with($query['query'], 'select') && str_contains($query['query'], 'inner join `article_order`');
        });
        $this->assertCount(1, $existentes);
        $filas = DB::table('article_order')->where('order_id', $order->id)->get()->keyBy('article_id');
        $this->assertCount(2, $filas);
        $this->assertEquals(1, $filas[$this->habilitado->id]->amount);
        $this->assertEquals(1650, $filas[$this->habilitado->id]->price);
        $this->assertEquals(2750, $filas[$this->sin_marcar->id]->price);
        $this->assertEquals(8000, $order->fresh()->total);
        $this->assertEquals($this->habilitado->stock, $this->habilitado->fresh()->stock);
    }
}
