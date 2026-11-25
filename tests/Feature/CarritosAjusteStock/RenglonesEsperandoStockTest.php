<?php

namespace Tests\Feature\CarritosAjusteStock;

use App\Article;
use App\Buyer;
use App\Cart;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\CombosYRangos\ArmaComercioConCombosYRangos;
use Tests\TestCase;

/**
 * Mision carritos-ajuste-stock, criterio CA17 (tienda-api): los renglones "esperando stock" no
 * bloquean el guardado ni el checkout.
 *
 * Un renglon esperando stock es el que empresa-api dejo en `amount = 0` con
 * `amount_insuficiente > 0` (se vendio todo y el comprador espera la reposicion). Ese renglon:
 *  - sigue guardado en el carrito con su faltante (el PUT no lo descarta ni lo rechaza);
 *  - no entra al control de stock del pedido (ni consultas ni reserva) ni se copia al pedido;
 *  - si es lo unico que hay en el carrito, el pedido se corta con un mensaje claro.
 *
 * Un renglon en 0 SIN faltante sigue rechazandose como hoy: eso lo fija
 * `QuitarLineasDelCarritoTest::test_el_guardado_y_checkout_siguen_rechazando_la_cantidad_invalida_remanente`
 * y no se toca.
 */
class RenglonesEsperandoStockTest extends TestCase
{
    use ArmaComercioConCombosYRangos, DatabaseTransactions;

    /** Mensaje exacto cuando el carrito no tiene ninguna unidad para enviar. */
    const MENSAJE_SIN_UNIDADES = 'Tu carrito no tiene unidades disponibles para enviar.';

    /** @var \App\User */
    private $comercio;

    /** @var \App\Buyer Comprador que confirma el pedido. */
    private $comprador;

    /** @var \App\Cart Carrito del test. */
    private $carrito;

    /**
     * Comercio, comprador, estado de pedido y carrito propio (molde de `PedidosSinStockTest`).
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
            'name' => 'Comprador esperando stock',
            'email' => 'espera-'.Str::random(8).'@test.local',
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

    /**
     * Consultas de stock activas: el PUT guarda el renglon en 0 con su faltante, el pedido se crea
     * y el pedido solo copia el renglon con unidades.
     *
     * @return void
     */
    public function test_con_consultas_activas_el_guardado_y_el_pedido_ignoran_el_renglon_que_espera()
    {
        $this->comercio->online_configuration()->update(['permitir_pedidos_sin_stock' => 1]);
        $espera = $this->articulo(['stock' => 0]);
        $normal = $this->articulo(['stock' => 10]);
        $this->renglon($espera, 0, 5);
        $this->renglon($normal, 2, null);

        // El guardado completo del SPA devuelve el renglon que espera, sin rechazarlo.
        $this->guardar([$this->linea_que_espera($espera), $this->lineaDelPayload($normal, 2)])->assertOk();
        $this->assertDatabaseHas('article_cart', [
            'cart_id' => $this->carrito->id, 'article_id' => $espera->id, 'amount' => 0, 'amount_insuficiente' => 5,
        ]);

        $this->enviar_pedido()->assertCreated();

        $pedido_id = DB::table('orders')->where('user_id', $this->comercio->id)->value('id');
        $this->assertNotNull($pedido_id, 'El pedido se tiene que haber creado.');
        $copiados = DB::table('article_order')->where('order_id', $pedido_id)->pluck('article_id')->all();
        $this->assertSame([$normal->id], array_map('intval', $copiados), 'Solo el renglon con unidades llega al pedido.');
    }

    /**
     * Reserva de stock activa: el pedido se crea y solo se reserva el renglon con unidades.
     *
     * @return void
     */
    public function test_con_reserva_activa_solo_se_reserva_el_renglon_con_unidades()
    {
        $this->comercio->online_configuration()->update([
            'reservar_stock_pedidos_web' => 1,
            'reserva_stock_erp_protocolo' => 1,
            'reserva_stock_tienda_protocolo' => 1,
        ]);
        $espera = $this->articulo(['stock' => 0]);
        $normal = $this->articulo(['stock' => 10]);
        $this->renglon($espera, 0, 5);
        $this->renglon($normal, 2, null);

        $this->enviar_pedido()->assertCreated();

        $pedido_id = DB::table('orders')->where('user_id', $this->comercio->id)->value('id');
        $reservados = DB::table('order_stock_reservations')->where('order_id', $pedido_id)->pluck('article_id')->all();
        $this->assertSame([$normal->id], array_map('intval', $reservados));
        $this->assertSame(
            [$normal->id],
            array_map('intval', DB::table('article_order')->where('order_id', $pedido_id)->pluck('article_id')->all())
        );
    }

    /**
     * Sin ninguna opcion de stock prendida el renglon que espera tampoco se copia al pedido.
     *
     * @return void
     */
    public function test_sin_opciones_de_stock_el_pedido_no_copia_el_renglon_que_espera()
    {
        $espera = $this->articulo(['stock' => 0]);
        $normal = $this->articulo(['stock' => 10]);
        $this->renglon($espera, 0, 5);
        $this->renglon($normal, 2, null);

        $this->enviar_pedido()->assertCreated();

        $pedido_id = DB::table('orders')->where('user_id', $this->comercio->id)->value('id');
        $this->assertSame(
            [$normal->id],
            array_map('intval', DB::table('article_order')->where('order_id', $pedido_id)->pluck('article_id')->all())
        );
    }

    /**
     * Un carrito con SOLO renglones esperando stock se puede guardar (no bloquea al comprador),
     * pero el pedido se corta con 422 y mensaje claro, sin crear nada.
     *
     * @return void
     */
    public function test_carrito_solo_con_renglones_que_esperan_no_genera_pedido_con_consultas_activas()
    {
        $this->comercio->online_configuration()->update(['permitir_pedidos_sin_stock' => 1]);
        $espera = $this->articulo(['stock' => 0]);
        $this->renglon($espera, 0, 5);

        $this->guardar([$this->linea_que_espera($espera)])->assertOk();
        $this->assertDatabaseHas('article_cart', ['cart_id' => $this->carrito->id, 'article_id' => $espera->id, 'amount' => 0, 'amount_insuficiente' => 5]);

        $this->enviar_pedido()->assertUnprocessable()->assertJsonPath('message', self::MENSAJE_SIN_UNIDADES);
        $this->assertDatabaseMissing('orders', ['user_id' => $this->comercio->id]);
    }

    /**
     * Lo mismo sin ninguna opcion de stock y con reserva activa: el corte vale en todos los modos.
     *
     * @return void
     */
    public function test_carrito_solo_con_renglones_que_esperan_no_genera_pedido_en_ningun_modo()
    {
        $espera = $this->articulo(['stock' => 0]);
        $this->renglon($espera, 0, 5);

        // Modo comun (sin opciones).
        $this->enviar_pedido()->assertUnprocessable()->assertJsonPath('message', self::MENSAJE_SIN_UNIDADES);

        // Modo reserva activa.
        $this->comercio->online_configuration()->update([
            'reservar_stock_pedidos_web' => 1,
            'reserva_stock_erp_protocolo' => 1,
            'reserva_stock_tienda_protocolo' => 1,
        ]);
        $this->enviar_pedido()->assertUnprocessable()->assertJsonPath('message', self::MENSAJE_SIN_UNIDADES);

        $this->assertDatabaseMissing('orders', ['user_id' => $this->comercio->id]);
        $this->assertDatabaseMissing('order_stock_reservations', ['user_id' => $this->comercio->id]);
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
     * @param  int|float|null  $faltante  `amount_insuficiente` del renglon.
     * @return void
     */
    private function renglon(Article $articulo, $cantidad, $faltante)
    {
        $this->carrito->articles()->attach($articulo->id, [
            'amount' => $cantidad, 'amount_insuficiente' => $faltante, 'price' => 100, 'cost' => 0, 'variant_id' => null,
        ]);
    }

    /**
     * Linea del payload del SPA para un renglon que espera stock (cantidad 0 con faltante en el pivote).
     *
     * @param  \App\Article  $articulo
     * @return array
     */
    private function linea_que_espera(Article $articulo)
    {
        return $this->lineaDelPayload($articulo, 0, [
            'pivot' => ['amount' => 0, 'amount_insuficiente' => 5, 'notes' => null, 'variant_id' => null],
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
