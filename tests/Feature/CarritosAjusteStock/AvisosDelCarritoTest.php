<?php

namespace Tests\Feature\CarritosAjusteStock;

use App\Article;
use App\Cart;
use App\Http\Controllers\CartController;
use App\Http\Controllers\Helpers\CartHelper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CombosYRangos\ArmaComercioConCombosYRangos;
use Tests\TestCase;

/**
 * Mision carritos-ajuste-stock, criterios CA14, CA15, CA16 y CA19 (tienda-api).
 *
 * Cubre lo que el comprador ve y toca en su carrito cuando empresa-api le recorto una cantidad:
 *  - CA14: la respuesta del carrito trae `puede_agotarse` por renglon y los dos campos del pivote
 *    (`completar_al_reponer` y `amount_insuficiente`).
 *  - CA15: `PATCH /api/carts/{id}/items` cambia la casilla "completar al reponer" de un renglon.
 *  - CA16: guardar el carrito conserva el faltante y la casilla si la cantidad no cambio, y los
 *    resetea si el comprador la cambio.
 *  - CA19: con una base vieja (sin las columnas nuevas) todo sigue como hoy.
 *
 * Las columnas nuevas las crea la migracion de empresa-api; en la base de test del slot se
 * aplican a mano (contrato 3.2 del plan). "Base vieja" se simula con las memorias por request
 * de `ConsultaStockHelper::hay_columna()`, no borrando columnas.
 */
class AvisosDelCarritoTest extends TestCase
{
    use ArmaComercioConCombosYRangos, DatabaseTransactions;

    /** Memoria por request de la columna `article_cart.completar_al_reponer`. */
    const MEMO_COMPLETAR = 'tienda.consulta_stock.article_cart.completar_al_reponer';

    /** Memoria por request de la columna `online_configurations.avisar_puede_agotarse`. */
    const MEMO_PUEDE_AGOTARSE = 'tienda.consulta_stock.online_configurations.avisar_puede_agotarse';

    /** @var \App\User Comercio propio de cada test. */
    private $comercio;

    /** @var \App\Cart Carrito del comprador del test. */
    private $carrito;

    /**
     * Comercio con tienda, carrito propio y sesion que lo reconoce como suyo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->olvidarLasMemorias();
        $this->comercio = $this->comercioConTienda();
        $this->carrito = Cart::create(['user_id' => $this->comercio->id, 'total' => 0]);
        $this->withSession(['carritos_propios' => [$this->carrito->id]]);
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

    // ───────────────────────── CA14: avisos en la respuesta ─────────────────────────

    /**
     * Stock 10, carrito propio con 6 y otro carrito activo con 5 del mismo articulo: 11 > 10, avisa.
     *
     * @return void
     */
    public function test_puede_agotarse_si_los_carritos_activos_juntos_superan_el_stock()
    {
        $articulo = $this->articulo(['stock' => 10]);
        $this->linea_propia($articulo, 6);
        $this->carrito_ajeno($articulo, 5);

        $this->ultimo_carrito()->assertOk()->assertJsonPath('last_cart.articles.0.puede_agotarse', true);
    }

    /**
     * Propio 5 + otro 5 con stock 10: justo alcanza, no hay aviso (no "supera").
     *
     * @return void
     */
    public function test_no_avisa_si_la_suma_justo_alcanza_el_stock()
    {
        $articulo = $this->articulo(['stock' => 10]);
        $this->linea_propia($articulo, 5);
        $this->carrito_ajeno($articulo, 5);

        $this->ultimo_carrito()->assertOk()->assertJsonPath('last_cart.articles.0.puede_agotarse', false);
    }

    /**
     * El otro carrito ya se envio como pedido (`order_id`): no compite por el stock.
     *
     * @return void
     */
    public function test_el_carrito_ajeno_enviado_como_pedido_no_cuenta()
    {
        $articulo = $this->articulo(['stock' => 10]);
        $this->linea_propia($articulo, 6);
        $this->carrito_ajeno($articulo, 5, ['order_id' => 4321]);

        $this->ultimo_carrito()->assertOk()->assertJsonPath('last_cart.articles.0.puede_agotarse', false);
    }

    /**
     * El otro carrito esta inactivo (sin actividad hace 16 dias, mas de los 15 de ventana).
     *
     * @return void
     */
    public function test_el_carrito_ajeno_con_mas_de_15_dias_sin_actividad_no_cuenta()
    {
        $articulo = $this->articulo(['stock' => 10]);
        $this->linea_propia($articulo, 6);
        $ajeno = $this->carrito_ajeno($articulo, 5);
        DB::table('carts')->where('id', $ajeno->id)->update(['updated_at' => Carbon::now()->subDays(16)]);

        $this->ultimo_carrito()->assertOk()->assertJsonPath('last_cart.articles.0.puede_agotarse', false);
    }

    /**
     * Con `ignorar_stock` no hay tope, entonces tampoco hay "puede agotarse".
     *
     * @return void
     */
    public function test_ignorar_stock_nunca_avisa()
    {
        $this->comercio->online_configuration()->update(['ignorar_stock' => 1]);
        $articulo = $this->articulo(['stock' => 10]);
        $this->linea_propia($articulo, 6);
        $this->carrito_ajeno($articulo, 5);

        $this->ultimo_carrito()->assertOk()->assertJsonPath('last_cart.articles.0.puede_agotarse', false);
    }

    /**
     * Con la opcion del negocio apagada el aviso no se calcula: la clave no aparece (o es falsa).
     *
     * @return void
     */
    public function test_con_la_opcion_apagada_no_hay_aviso()
    {
        $this->comercio->online_configuration()->update(['avisar_puede_agotarse' => 0]);
        $articulo = $this->articulo(['stock' => 10]);
        $this->linea_propia($articulo, 6);
        $this->carrito_ajeno($articulo, 5);

        $articulo_json = $this->ultimo_carrito()->assertOk()->json('last_cart.articles.0');
        $this->assertNotTrue($articulo_json['puede_agotarse'] ?? false, 'Con la opcion apagada no se avisa.');
    }

    /**
     * Dos variantes del mismo articulo se cuentan por separado: la presion sobre una no marca la otra.
     *
     * @return void
     */
    public function test_las_variantes_cuentan_por_separado()
    {
        $articulo = $this->articulo(['stock' => 20]);
        $primera = $this->variante($articulo, 10);
        $segunda = $this->variante($articulo, 10);
        $this->linea_propia($articulo, 6, ['variant_id' => $primera]);
        $this->linea_propia($articulo, 6, ['variant_id' => $segunda]);
        // Otro comprador presiona SOLO la primera variante.
        $this->carrito_ajeno($articulo, 5, [], ['variant_id' => $primera]);

        $respuesta = $this->ultimo_carrito()->assertOk();
        $por_variante = collect($respuesta->json('last_cart.articles'))->keyBy(fn ($renglon) => $renglon['pivot']['variant_id']);
        $this->assertTrue($por_variante[$primera]['puede_agotarse']);
        $this->assertFalse($por_variante[$segunda]['puede_agotarse']);
    }

    /**
     * Con reserva activa el disponible descuenta lo reservado: fisico 10, reservado 6, propio 3,
     * otro 2 => disponible 4 y 3 + 2 = 5 > 4.
     *
     * @return void
     */
    public function test_con_reserva_activa_se_compara_contra_el_disponible()
    {
        $this->comercio->online_configuration()->update([
            'reservar_stock_pedidos_web' => 1,
            'reserva_stock_erp_protocolo' => 1,
            'reserva_stock_tienda_protocolo' => 1,
        ]);
        $articulo = $this->articulo(['stock' => 10]);
        $deposito = DB::table('addresses')->insertGetId(['user_id' => $this->comercio->id, 'street' => 'Principal', 'default_address' => 1]);
        DB::table('address_article')->insert(['address_id' => $deposito, 'article_id' => $articulo->id, 'amount' => 10]);
        DB::table('order_stock_reservations')->insert([
            'user_id' => $this->comercio->id, 'order_id' => 987654, 'article_id' => $articulo->id,
            'article_variant_id' => null, 'address_id' => $deposito, 'amount' => 6,
        ]);
        $this->linea_propia($articulo, 3);
        $this->carrito_ajeno($articulo, 2);

        $this->ultimo_carrito()->assertOk()->assertJsonPath('last_cart.articles.0.puede_agotarse', true);
    }

    /**
     * La respuesta trae los dos campos del pivote que consume tienda-spa.
     *
     * @return void
     */
    public function test_el_pivote_trae_la_casilla_y_el_faltante()
    {
        $articulo = $this->articulo(['stock' => 10]);
        $this->linea_propia($articulo, 2, ['amount_insuficiente' => 3, 'completar_al_reponer' => 0]);

        $pivote = $this->ultimo_carrito()->assertOk()->json('last_cart.articles.0.pivot');
        $this->assertArrayHasKey('completar_al_reponer', $pivote);
        $this->assertSame(0, (int) $pivote['completar_al_reponer']);
        $this->assertEquals(3, $pivote['amount_insuficiente']);
    }

    /**
     * Con la opcion prendida, un carrito de 5 renglones hace como maximo UNA consulta mas que con
     * la opcion apagada (el aviso se calcula con una sola consulta agrupada, sin N+1).
     *
     * @return void
     */
    public function test_el_aviso_cuesta_como_maximo_una_consulta_mas()
    {
        for ($i = 0; $i < 5; $i++) {
            $articulo = $this->articulo(['stock' => 10]);
            $this->linea_propia($articulo, 6);
            $this->carrito_ajeno($articulo, 5);
        }

        // Primero con la opcion apagada (referencia) y despues prendida.
        $this->comercio->online_configuration()->update(['avisar_puede_agotarse' => 0]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->ultimo_carrito()->assertOk();
        $con_opcion_apagada = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->comercio->online_configuration()->update(['avisar_puede_agotarse' => 1]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $respuesta_prendida = $this->ultimo_carrito()->assertOk();
        $con_opcion_prendida = count(DB::getQueryLog());
        DB::disableQueryLog();

        // La comparacion de consultas solo vale si el aviso realmente se calcula con la opcion prendida.
        $this->assertTrue($respuesta_prendida->json('last_cart.articles.0.puede_agotarse'), 'Con la opcion prendida el aviso viaja.');

        $this->assertLessThanOrEqual(
            $con_opcion_apagada + 1,
            $con_opcion_prendida,
            "Apagada: $con_opcion_apagada consultas; prendida: $con_opcion_prendida (maximo +1)."
        );
    }

    // ───────────────────────── CA15: PATCH de la casilla ─────────────────────────

    /**
     * El dueño del carrito desmarca la casilla: 200, queda en 0 en la base, el faltante no cambia.
     *
     * @return void
     */
    public function test_patch_casilla_del_duenio_la_guarda_y_responde_el_carrito()
    {
        $articulo = $this->articulo(['stock' => 10]);
        $this->linea_propia($articulo, 2, ['amount_insuficiente' => 3]);

        $respuesta = $this->cambiar_casilla($articulo, false)->assertOk();

        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $this->carrito->id, 'article_id' => $articulo->id,
            'completar_al_reponer' => 0, 'amount' => 2, 'amount_insuficiente' => 3,
        ]);
        $this->assertSame(0, (int) $respuesta->json('cart.articles.0.pivot.completar_al_reponer'));
        $this->assertEquals(3, $respuesta->json('cart.articles.0.pivot.amount_insuficiente'));
    }

    /**
     * Se puede volver a marcar la casilla.
     *
     * @return void
     */
    public function test_patch_casilla_se_puede_volver_a_marcar()
    {
        $articulo = $this->articulo(['stock' => 10]);
        $this->linea_propia($articulo, 2, ['amount_insuficiente' => 3, 'completar_al_reponer' => 0]);

        $this->cambiar_casilla($articulo, true)->assertOk();

        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $articulo->id, 'completar_al_reponer' => 1]);
    }

    /**
     * Un carrito ajeno (fuera de la sesion) no se toca: 403.
     *
     * @return void
     */
    public function test_patch_casilla_de_carrito_ajeno_es_403()
    {
        $articulo = $this->articulo(['stock' => 10]);
        $this->linea_propia($articulo, 2, ['amount_insuficiente' => 3]);
        $this->withSession(['carritos_propios' => []]);

        $this->cambiar_casilla($articulo, false)->assertForbidden();

        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $articulo->id, 'completar_al_reponer' => 1]);
    }

    /**
     * Un carrito inexistente responde 404 con `cart: null`.
     *
     * @return void
     */
    public function test_patch_casilla_de_carrito_inexistente_es_404()
    {
        $articulo = $this->articulo(['stock' => 10]);

        $this->patchJson('/api/carts/99999999/items', $this->cuerpo_casilla($articulo, false))
            ->assertNotFound()->assertJsonPath('cart', null);
    }

    /**
     * Un carrito ya enviado como pedido no se modifica: 422 `consulta_stock`.
     *
     * @return void
     */
    public function test_patch_casilla_de_carrito_con_pedido_es_422()
    {
        $articulo = $this->articulo(['stock' => 10]);
        $this->linea_propia($articulo, 2, ['amount_insuficiente' => 3]);
        $this->carrito->update(['order_id' => 123]);

        $this->cambiar_casilla($articulo, false)
            ->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');

        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $articulo->id, 'completar_al_reponer' => 1]);
    }

    /**
     * Un renglon que ya no esta en el carrito: 422 `consulta_stock`.
     *
     * @return void
     */
    public function test_patch_casilla_de_renglon_inexistente_es_422()
    {
        $articulo = $this->articulo(['stock' => 10]);
        $otro = $this->articulo(['stock' => 10]);
        $this->linea_propia($articulo, 2, ['amount_insuficiente' => 3]);

        $this->cambiar_casilla($otro, false)
            ->assertUnprocessable()->assertJsonPath('codigo', 'consulta_stock');
    }

    /**
     * Con variantes, el PATCH cambia solo el renglon de la variante pedida.
     *
     * @return void
     */
    public function test_patch_casilla_con_variantes_cambia_solo_la_variante_pedida()
    {
        $articulo = $this->articulo(['stock' => 20]);
        $primera = $this->variante($articulo, 10);
        $segunda = $this->variante($articulo, 10);
        $this->linea_propia($articulo, 2, ['variant_id' => $primera, 'amount_insuficiente' => 3]);
        $this->linea_propia($articulo, 2, ['variant_id' => $segunda, 'amount_insuficiente' => 3]);

        $this->cambiar_casilla($articulo, false, $segunda)->assertOk();

        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'variant_id' => $primera, 'completar_al_reponer' => 1]);
        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'variant_id' => $segunda, 'completar_al_reponer' => 0]);
    }

    // ───────────────────────── CA16: conservar y resetear ─────────────────────────

    /**
     * Renglon 2/3/casilla 0: guardar el carrito con la MISMA cantidad (y otro articulo nuevo)
     * conserva faltante y casilla; guardarlo con otra cantidad los resetea a 0 y 1.
     *
     * @return void
     */
    public function test_put_conserva_y_cambio_de_cantidad_resetea()
    {
        $articulo = $this->articulo(['stock' => 100]);
        $nuevo = $this->articulo(['stock' => 100]);
        $this->linea_propia($articulo, 2, ['amount_insuficiente' => 3, 'completar_al_reponer' => 0]);

        // Misma cantidad + un articulo nuevo: el renglon recortado conserva todo.
        $this->guardar_carrito([$this->lineaDelPayload($articulo, 2), $this->lineaDelPayload($nuevo, 1)])->assertOk();
        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $this->carrito->id, 'article_id' => $articulo->id,
            'amount' => 2, 'amount_insuficiente' => 3, 'completar_al_reponer' => 0,
        ]);

        // Otra cantidad: el comprador decidio, el faltante se olvida y la casilla vuelve a 1.
        $this->guardar_carrito([$this->lineaDelPayload($articulo, 1), $this->lineaDelPayload($nuevo, 1)])->assertOk();
        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $this->carrito->id, 'article_id' => $articulo->id,
            'amount' => 1, 'amount_insuficiente' => 0, 'completar_al_reponer' => 1,
        ]);
    }

    /**
     * `PUT /api/carts/update-article-amount/{id}` con otra cantidad resetea faltante y casilla
     * (camino sin control de stock).
     *
     * @return void
     */
    public function test_update_article_amount_resetea_el_faltante_y_la_casilla()
    {
        $articulo = $this->articulo(['stock' => 100]);
        $this->linea_propia($articulo, 2, ['amount_insuficiente' => 3, 'completar_al_reponer' => 0]);

        $this->actualizarCantidad($this->carrito->id, ['id' => $articulo->id, 'amount' => 1])->assertOk();

        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $this->carrito->id, 'article_id' => $articulo->id,
            'amount' => 1, 'amount_insuficiente' => 0, 'completar_al_reponer' => 1,
        ]);
    }

    /**
     * Lo mismo por el camino con control de stock (consultas de stock activas).
     *
     * @return void
     */
    public function test_update_article_amount_con_consultas_activas_resetea_el_faltante_y_la_casilla()
    {
        $this->comercio->online_configuration()->update(['permitir_pedidos_sin_stock' => 1]);
        $articulo = $this->articulo(['stock' => 100]);
        $this->linea_propia($articulo, 2, ['amount_insuficiente' => 3, 'completar_al_reponer' => 0]);

        $this->actualizarCantidad($this->carrito->id, ['id' => $articulo->id, 'amount' => 1])->assertOk();

        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $this->carrito->id, 'article_id' => $articulo->id,
            'amount' => 1, 'amount_insuficiente' => 0, 'completar_al_reponer' => 1,
        ]);
    }

    // ───────────────────────── CA19: base vieja ─────────────────────────

    /**
     * Con la base anterior (sin `completar_al_reponer` ni `avisar_puede_agotarse`) la respuesta del
     * carrito no trae los campos nuevos ni falla, y el PATCH responde 422 `no_disponible`.
     *
     * La ausencia de columnas se simula con las memorias por request de `hay_columna()`, el mismo
     * mecanismo que usa el resto de la suite (no se borran columnas de la base).
     *
     * @return void
     */
    public function test_base_vieja_sin_columnas()
    {
        $articulo = $this->articulo(['stock' => 10]);
        $this->linea_propia($articulo, 6);
        $this->carrito_ajeno($articulo, 5);
        request()->attributes->set(self::MEMO_COMPLETAR, false);
        request()->attributes->set(self::MEMO_PUEDE_AGOTARSE, false);

        // La respuesta del carrito no trae las claves nuevas y no revienta.
        $completo = CartHelper::getFullModel($this->carrito->id)->toArray();
        $renglon = $completo['articles'][0];
        $this->assertArrayNotHasKey('puede_agotarse', $renglon);
        $this->assertArrayNotHasKey('completar_al_reponer', $renglon['pivot']);

        // El PATCH, llamado directo al controlador con el request memorizado, avisa que no esta disponible.
        $peticion = Request::create('/api/carts/'.$this->carrito->id.'/items', 'PATCH', $this->cuerpo_casilla($articulo, false));
        $respuesta = app(CartController::class)->update_item($peticion, $this->carrito->id);
        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame('no_disponible', $respuesta->getData(true)['codigo']);
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
     * Variante del articulo con su propio stock; devuelve el id.
     *
     * @param  \App\Article  $articulo
     * @param  int  $stock
     * @return int
     */
    private function variante(Article $articulo, $stock)
    {
        return DB::table('article_variants')->insertGetId(['article_id' => $articulo->id, 'stock' => $stock]);
    }

    /**
     * Agrega un renglon al carrito propio (insert directo al pivote, como lo deja empresa-api).
     *
     * @param  \App\Article  $articulo
     * @param  int|float  $cantidad
     * @param  array  $extra  Columnas extra del pivote (`variant_id`, `amount_insuficiente`, `completar_al_reponer`).
     * @return void
     */
    private function linea_propia(Article $articulo, $cantidad, array $extra = [])
    {
        $this->carrito->articles()->attach($articulo->id, array_merge(
            ['amount' => $cantidad, 'price' => 100, 'cost' => 0, 'variant_id' => null],
            $extra
        ));
    }

    /**
     * Carrito de OTRO comprador del mismo comercio con una linea del articulo.
     *
     * @param  \App\Article  $articulo
     * @param  int|float  $cantidad
     * @param  array  $atributos_carrito  Columnas del carrito (`order_id`, ...).
     * @param  array  $extra_linea  Columnas extra del pivote (`variant_id`).
     * @return \App\Cart
     */
    private function carrito_ajeno(Article $articulo, $cantidad, array $atributos_carrito = [], array $extra_linea = [])
    {
        $otro = Cart::create(array_merge(['user_id' => $this->comercio->id, 'total' => 0], $atributos_carrito));
        $otro->articles()->attach($articulo->id, array_merge(
            ['amount' => $cantidad, 'price' => 100, 'cost' => 0, 'variant_id' => null],
            $extra_linea
        ));
        return $otro;
    }

    /**
     * `GET /api/carts/last-cart/{comercio}` con la sesion del comprador dueño del carrito propio.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function ultimo_carrito()
    {
        return $this->getJson('/api/carts/last-cart/'.$this->comercio->id);
    }

    /**
     * Cuerpo del PATCH de la casilla.
     *
     * @param  \App\Article  $articulo
     * @param  bool  $completar
     * @param  int|null  $variante
     * @return array
     */
    private function cuerpo_casilla(Article $articulo, $completar, $variante = null)
    {
        return ['tipo' => 'articulo', 'id' => $articulo->id, 'variant_id' => $variante, 'completar_al_reponer' => $completar];
    }

    /**
     * `PATCH /api/carts/{id}/items` sobre el carrito propio.
     *
     * @param  \App\Article  $articulo
     * @param  bool  $completar
     * @param  int|null  $variante
     * @return \Illuminate\Testing\TestResponse
     */
    private function cambiar_casilla(Article $articulo, $completar, $variante = null)
    {
        return $this->patchJson('/api/carts/'.$this->carrito->id.'/items', $this->cuerpo_casilla($articulo, $completar, $variante));
    }

    /**
     * `PUT /api/carts` con las lineas indicadas (el guardado completo del SPA).
     *
     * @param  array  $lineas
     * @return \Illuminate\Testing\TestResponse
     */
    private function guardar_carrito(array $lineas)
    {
        return $this->putJson('/api/carts', ['id' => $this->carrito->id, 'articles' => $lineas, 'promociones_vinoteca' => []]);
    }
}
