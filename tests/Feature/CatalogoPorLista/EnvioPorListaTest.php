<?php

namespace Tests\Feature\CatalogoPorLista;

use App\Article;
use App\Cart;
use App\Http\Controllers\Helpers\EnvioCartHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Envios\ArmaComercioConZipnova;
use Tests\TestCase;

/**
 * (i) El ENVIO POR CORREO se cotiza y se firma con las lineas que el carrito GUARDA, no con las que
 * descarta (mision catalogo-por-lista-tienda, 5/10/2026, revision independiente: hallazgo M1).
 *
 * ── El defecto que fija ──────────────────────────────────────────────────────────────────────────
 *
 * `CartController` sincroniza el envio (`sync_checkout_fields()` -> `EnvioCartHelper::sincronizar()`)
 * con las lineas que trae el PAYLOAD: arma de ahi el `items_hash`, la cotizacion de Zipnova (peso y
 * subtotal) y `envio_precio`. Si el carrito descarta una linea por la lista del comprador pero el
 * envio se cotiza igual con ella:
 *
 *   - el peso y el subtotal que ve Zipnova no son los del carrito: el subtotal puede cruzar el umbral
 *     de envio gratis y mostrar "envio gratis" para un carrito que no lo merece;
 *   - el `items_hash` queda firmando lineas que no estan guardadas, y `OrderController@store` corta
 *     despues con 422 `opcion_envio` ("Cambio el carrito") por `motivo_para_recotizar()`.
 *
 * Los dos casos son el mismo: el envio mira el payload CRUDO, y el carrito guarda el LIMPIO.
 *
 * Escenario: el mayorista del escenario estandar (`ArmaCatalogoPorLista`) con Zipnova conectado y envio
 * gratis desde $2.500. Pide el habilitado ($1.000) y el deshabilitado ($3.000): con las dos lineas el
 * subtotal es $4.000 (gratis); con la que se guarda, $1.000 (no es gratis).
 *
 * ⚠️ Nada sale a la red (`Http::fake()`).
 */
class EnvioPorListaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCatalogoPorLista, ArmaComercioConZipnova {
        /* Las dos fixtures arman "el comercio con tienda": gana la del catalogo, que es la que prende
           `show_articles_without_images` y `show_articles_without_stock`. */
        ArmaCatalogoPorLista::comercioConTienda insteadof ArmaComercioConZipnova;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();
        ZipnovaEsquemaHelper::olvidar();

        $this->assertTrue(ZipnovaEsquemaHelper::disponible(), 'La base del slot tiene que tener las columnas de envío.');

        $this->armarFerretotal();

        /* Para que las lineas "viajen" en la cotizacion necesitan peso y medidas, como las deja el ERP. */
        Article::whereIn('id', [$this->habilitado->id, $this->sin_marcar->id, $this->deshabilitado->id])->update([
            'peso'        => 1.5,
            'alto'        => 20,
            'ancho'       => 15,
            'profundidad' => 30,
        ]);

        $this->conectorZipnova($this->comercio, ['envio_gratis_desde' => 2500]);
        $this->zipnovaCotiza();

        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));
    }

    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * 🔴 `POST /api/carts`: Zipnova cotiza con la linea que se guarda (1 item, $1.000 declarados), no
     * con las dos del payload; el envio NO es gratis; y la firma de la cotizacion es la de las lineas
     * guardadas, asi que el pedido no se corta despues por "Cambio el carrito".
     */
    public function test_al_crear_el_carrito_el_envio_se_cotiza_solo_con_las_lineas_que_se_guardan()
    {
        $respuesta = $this->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart'        => [
                'articles'             => [
                    $this->linea($this->habilitado, 1, 1000),
                    $this->linea($this->deshabilitado, 1, 3000),
                ],
                'promociones_vinoteca' => [],
                'deliver'              => 1,
                'envio'                => $this->envio(),
            ],
        ]);

        $respuesta->assertStatus(201);
        $this->assertSame(
            [['id' => $this->deshabilitado->id, 'name' => $this->deshabilitado->name]],
            $respuesta->json('articulos_no_disponibles')
        );

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return count($body['items']) === 1 && (float) $body['declared_value'] === 1000.0;
        });

        $this->assertElEnvioEsDeLasLineasGuardadas(Cart::find($respuesta->json('cart.id')));
    }

    /**
     * 🔴 `PUT /api/carts`: el comprador ya tenia el envio cotizado con el habilitado, y vuelve a
     * guardar con una linea que se descarta. Las lineas que importan no cambiaron, asi que la
     * cotizacion SIGUE vigente: no se vuelve a llamar a Zipnova ni cambia el precio del envio. Antes se
     * re-cotizaba con las dos lineas y el envio salia gratis.
     */
    public function test_al_guardar_el_carrito_el_envio_no_se_vuelve_a_cotizar_por_una_linea_descartada()
    {
        $cart = Cart::find($this->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart'        => [
                'articles'             => [$this->linea($this->habilitado, 1, 1000)],
                'promociones_vinoteca' => [],
                'deliver'              => 1,
                'envio'                => $this->envio(),
            ],
        ])->assertStatus(201)->json('cart.id'));

        Http::assertSentCount(1);

        $respuesta = $this->withSession(['carritos_propios' => [$cart->id]])->putJson('/api/carts', [
            'id'                   => $cart->id,
            'articles'             => [
                $this->linea($this->habilitado, 1, 1000),
                $this->linea($this->deshabilitado, 1, 3000),
            ],
            'promociones_vinoteca' => [],
            'deliver'              => 1,
            'envio'                => $this->envio(),
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame(
            [['id' => $this->deshabilitado->id, 'name' => $this->deshabilitado->name]],
            $respuesta->json('articulos_no_disponibles')
        );

        Http::assertSentCount(1);

        $this->assertElEnvioEsDeLasLineasGuardadas($cart->fresh());
    }

    /**
     * Control: sin descartes el envio se cotiza como siempre (una vez, con lo que se pidio), y volver
     * a guardar lo mismo no re-cotiza. Es lo que garantiza que arriba lo que cambia es el descarte y
     * no la cotizacion en general.
     */
    public function test_sin_descartes_el_envio_se_cotiza_como_siempre()
    {
        $cart = Cart::find($this->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart'        => [
                'articles'             => [$this->linea($this->habilitado, 2, 1000)],
                'promociones_vinoteca' => [],
                'deliver'              => 1,
                'envio'                => $this->envio(),
            ],
        ])->assertStatus(201)->json('cart.id'));

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return count($body['items']) === 2 && (float) $body['declared_value'] === 2000.0;
        });

        $this->withSession(['carritos_propios' => [$cart->id]])->putJson('/api/carts', [
            'id'                   => $cart->id,
            'articles'             => [$this->linea($this->habilitado, 2, 1000)],
            'promociones_vinoteca' => [],
            'deliver'              => 1,
            'envio'                => $this->envio(),
        ])->assertStatus(200)->assertJsonMissingPath('articulos_no_disponibles');

        Http::assertSentCount(1);

        $this->assertElEnvioEsDeLasLineasGuardadas($cart->fresh());
    }

    /**
     * El envio guardado corresponde a las lineas que el carrito tiene: no es gratis (el subtotal real,
     * $1.000, no llega al umbral de $2.500), cuesta lo que cotizo Zipnova, y su firma es la de las
     * lineas guardadas — que es lo que mira `OrderController@store` antes de crear el pedido.
     *
     * @param  \App\Cart  $cart
     * @return void
     */
    private function assertElEnvioEsDeLasLineasGuardadas(Cart $cart)
    {
        $this->assertFalse($cart->envio_opcion['envio_gratis'],
            'el subtotal de lo que se guarda no llega al umbral: el envio no es gratis');
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $cart->envio_precio);

        $this->assertSame(EnvioCartHelper::hash_del_carrito($cart), $cart->envio_cotizacion['items_hash'],
            'la cotizacion esta firmada con las lineas guardadas');
        $this->assertNull(EnvioCartHelper::motivo_para_recotizar($cart),
            'el pedido no se corta despues con "Cambio el carrito"');
    }

    /** @return array El payload de `envio` que manda el SPA al elegir una opcion. */
    private function envio()
    {
        return [
            'zipcode'    => '5000',
            'city'       => null,
            'state'      => null,
            'opcion_key' => self::KEY_DOMICILIO_CORREO_ARG,
            'point_id'   => null,
            'destino'    => $this->destinoCompleto(),
        ];
    }

    /**
     * Una linea tal cual la manda el SPA.
     *
     * @param  \App\Article  $articulo
     * @param  int  $cantidad
     * @param  float  $precio
     * @return array
     */
    private function linea(Article $articulo, $cantidad, $precio)
    {
        return [
            'id'          => $articulo->id,
            'user_id'     => $articulo->user_id,
            'name'        => $articulo->name,
            'final_price' => $precio,
            'cost'        => null,
            'amount'      => $cantidad,
            'pivot'       => ['amount' => $cantidad, 'notes' => null, 'variant_id' => null],
        ];
    }
}
