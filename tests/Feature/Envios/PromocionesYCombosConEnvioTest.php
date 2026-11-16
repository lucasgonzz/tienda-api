<?php

namespace Tests\Feature\Envios;

use App\Cart;
use App\Combo;
use App\Order;
use App\PromocionVinoteca;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use App\Http\Controllers\Helpers\EnvioCartHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PromocionesYCombosConEnvioTest extends TestCase
{
    use DatabaseTransactions, ArmaComercioConZipnova;

    private $comercio;

    protected function setUp(): void
    {
        parent::setUp();
        ArticleHelper::olvidar_visibilidad_del_anonimo();
        ComboEsquemaHelper::olvidar();
        $this->comercio = $this->comercioConTienda();
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();
    }

    private function promo(array $campos = [])
    {
        return PromocionVinoteca::create(array_merge([
            'name' => 'Caja de seis vinos', 'user_id' => $this->comercio->id,
            'online' => 1, 'final_price' => 12000, 'peso' => 8.25,
            'alto' => 32.1, 'ancho' => 24, 'profundidad' => 18,
        ], $campos));
    }

    private function combo(array $campos = [])
    {
        return Combo::create(array_merge([
            'num' => 1, 'name' => 'Caja degustacion', 'user_id' => $this->comercio->id,
            'online' => 1, 'price' => 15000, 'peso' => 5.6,
            'alto' => 20, 'ancho' => 30, 'profundidad' => 40,
        ], $campos));
    }

    private function linea($producto, $cantidad = 1)
    {
        return ['id' => $producto->id, 'name' => $producto->name, 'final_price' => $producto->final_price ?? $producto->price,
            'cost' => null, 'pivot' => ['amount' => $cantidad, 'notes' => null]];
    }

    private function carrito(array $lineas)
    {
        $respuesta = $this->postJson('/api/carts', ['commerce_id' => $this->comercio->id, 'cart' => array_merge([
            'articles' => [], 'promociones_vinoteca' => [], 'combos' => [], 'deliver' => 1,
            'envio' => ['zipcode' => '5000', 'opcion_key' => self::KEY_DOMICILIO_CORREO_ARG, 'destino' => $this->destinoCompleto()],
        ], $lineas)])->assertStatus(201);

        return Cart::findOrFail($respuesta->json('cart.id'));
    }

    public function test_la_ficha_cotiza_promociones_y_combos_con_medidas_y_cantidades_de_la_base()
    {
        $promo = $this->promo();
        $combo = $this->combo();
        $this->postJson('/api/envios/cotizar', [
            'commerce_id' => $this->comercio->id, 'zipcode' => '5000',
            'promociones_vinoteca' => [['id' => $promo->id, 'amount' => 2, 'peso' => 0.001, 'final_price' => 999999]],
            'combos' => [['id' => $combo->id, 'amount' => 1, 'peso' => 0.001]],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $data = $request->data();
            $this->assertCount(3, $data['items']);
            $this->assertSame([8250, 8250, 5600], array_column($data['items'], 'weight'));
            $this->assertSame([33, 33, 20], array_column($data['items'], 'height'));
            $this->assertSame([24, 24, 30], array_column($data['items'], 'width'));
            $this->assertSame([18, 18, 40], array_column($data['items'], 'length'));
            $this->assertEquals(39000, $data['declared_value']);
            return true;
        });
    }

    public function test_un_carrito_solo_promociones_cotiza_y_conserva_el_envio_en_el_pedido()
    {
        $promo = $this->promo();
        $cart = $this->carrito(['promociones_vinoteca' => [$this->linea($promo, 2)]]);
        $this->assertEquals(self::PRECIO_DOMICILIO_CORREO_ARG, $cart->envio_precio);
        $this->assertSame(EnvioCartHelper::hash_del_carrito($cart), $cart->envio_cotizacion['items_hash']);
        $this->asegurarEstadoSinConfirmar();
        $buyer = $this->compradorDe($this->comercio);
        $id = $this->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $buyer->id])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id])
            ->assertStatus(201)->json('order_id');
        $order = Order::findOrFail($id);
        $this->assertSame('zipnova', $order->envio_proveedor);
        $this->assertEquals(self::PRECIO_DOMICILIO_CORREO_ARG, $order->envio_precio);
        Http::assertSentCount(1);
    }

    public function test_solo_combo_filtra_descartados_y_duplicados_antes_de_cotizar_y_firmar()
    {
        $combo = $this->combo();
        $cero = $this->combo(['price' => 0]);
        $oculto = $this->combo(['online' => 0]);
        $cart = $this->carrito(['combos' => [$this->linea($combo, 2), $this->linea($combo, 99), $this->linea($cero), $this->linea($oculto)]]);
        $this->assertEquals(30000, $cart->total);
        $this->assertSame(EnvioCartHelper::hash_del_carrito($cart), $cart->envio_cotizacion['items_hash']);
        Http::assertSent(fn ($request) => count($request->data()['items']) === 2 && $request->data()['declared_value'] == 30000);
        $this->asegurarEstadoSinConfirmar();
        $buyer = $this->compradorDe($this->comercio);
        $this->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $buyer->id])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id])->assertStatus(201);
    }

    public function test_las_medidas_incompletas_usan_el_bulto_del_comercio_campo_por_campo()
    {
        $promo = $this->promo(['peso' => null, 'alto' => null]);
        $this->postJson('/api/envios/cotizar', ['commerce_id' => $this->comercio->id, 'zipcode' => '5000',
            'promociones_vinoteca' => [['id' => $promo->id]]])->assertOk();
        Http::assertSent(function ($request) {
            $this->assertSame(['weight' => 500, 'height' => 10, 'width' => 24, 'length' => 18, 'description' => 'Caja de seis vinos'], $request->data()['items'][0]);
            return true;
        });
    }

    public function test_cambiar_un_combo_invalida_la_cotizacion_y_el_pedido_no_acepta_el_hash_anterior()
    {
        $combo = $this->combo();
        $cart = $this->carrito(['combos' => [$this->linea($combo)]]);
        DB::table('cart_combo')->where('cart_id', $cart->id)->update(['amount' => 2]);
        $buyer = $this->compradorDe($this->comercio);
        $this->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $buyer->id])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id])
            ->assertStatus(422)->assertJson(['codigo' => 'opcion_envio']);
        Http::assertSentCount(1);
    }

    public function test_el_carrito_mixto_y_el_incremental_no_confunden_ids_de_distintos_modelos()
    {
        $articulo = $this->articuloConMedidas($this->comercio);
        $promo = $this->promo(['id' => $articulo->id]);
        $combo = $this->combo(['id' => $articulo->id]);
        $this->postJson('/api/envios/cotizar', ['commerce_id' => $this->comercio->id, 'zipcode' => '5000',
            'articles' => [['id' => $articulo->id]],
            'promociones_vinoteca_extra' => [['id' => $promo->id]], 'combos_extra' => [['id' => $combo->id]],
        ])->assertOk()->assertJsonPath('incremental.hay_base', true);
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return count($request->data()['items']) === 3 && array_column($request->data()['items'], 'weight') === [1500, 8250, 5600];
        });
    }

    public function test_no_usa_paquetes_de_otro_comercio_ni_un_tipo_de_producto_como_articulo()
    {
        $ajeno = $this->promo(['user_id' => $this->comercioConTienda()->id]);
        $this->postJson('/api/envios/cotizar', ['commerce_id' => $this->comercio->id, 'zipcode' => '5000',
            'promociones_vinoteca' => [['id' => $ajeno->id]]])->assertStatus(422)->assertJson(['codigo' => 'sin_articulos']);
        $this->postJson('/api/envios/cotizar', ['commerce_id' => $this->comercio->id, 'zipcode' => '5000',
            'articles' => [['id' => $ajeno->id, 'type' => 'combo']]])->assertStatus(422)->assertJson(['codigo' => 'validacion']);
        Http::assertNothingSent();
    }

    public function test_los_combos_cuentan_para_el_umbral_de_envio_gratis_con_precio_del_servidor()
    {
        $combo = $this->combo();
        $connector = \App\PlatformConnector::where('user_id', $this->comercio->id)->firstOrFail();
        $config = $connector->extra_config;
        $config['envio_gratis_desde'] = 30000;
        $connector->extra_config = $config;
        $connector->save();
        $cart = $this->carrito(['combos' => [$this->linea($combo, 2)]]);
        $this->assertEquals(0, $cart->envio_precio);
        $this->assertTrue($cart->envio_opcion['envio_gratis']);
    }

    public function test_las_promociones_repetidas_cotizan_todas_las_unidades_y_la_promo_oculta_se_conserva_en_checkout()
    {
        $promo = $this->promo(['online' => 0]);
        $cart = $this->carrito(['promociones_vinoteca' => [$this->linea($promo, 2), $this->linea($promo, 3)]]);
        $this->assertSame(EnvioCartHelper::hash_del_carrito($cart), $cart->envio_cotizacion['items_hash']);
        Http::assertSent(fn ($request) => count($request->data()['items']) === 5 && $request->data()['declared_value'] == 60000);
    }

    public function test_una_cotizacion_anterior_que_no_incluia_el_paquete_de_la_promo_no_llega_al_pedido()
    {
        $promo = $this->promo();
        $cart = $this->carrito(['promociones_vinoteca' => [$this->linea($promo)]]);
        $snapshot = $cart->envio_cotizacion;
        $snapshot['items_hash'] = md5('promo:'.$promo->id.'|1');
        $cart->envio_cotizacion = $snapshot;
        $cart->save();
        $buyer = $this->compradorDe($this->comercio);
        $this->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $buyer->id])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->comercio->id])
            ->assertStatus(422)->assertJson(['codigo' => 'opcion_envio']);
    }

    public function test_al_cambiar_las_medidas_la_cache_no_reutiliza_el_paquete_anterior()
    {
        $combo = $this->combo();
        $payload = ['commerce_id' => $this->comercio->id, 'zipcode' => '5000', 'combos' => [['id' => $combo->id]]];
        $this->postJson('/api/envios/cotizar', $payload)->assertOk();
        $combo->update(['peso' => 9.4]);
        $this->postJson('/api/envios/cotizar', $payload)->assertOk();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->data()['items'][0]['weight'] === 9400);
    }
}
