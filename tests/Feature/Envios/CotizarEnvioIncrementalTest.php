<?php

namespace Tests\Feature\Envios;

use App\Cart;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ZipnovaEsquemaHelper;
use App\Services\Zipnova\ZipnovaQuoteNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `POST /api/envios/cotizar` con `articles_extra`: cuánto MÁS le cuesta el envío al comprador si
 * agrega el artículo que está mirando (misión tienda-boton-compra-y-envio, 17/9/2026).
 *
 * ── Qué fija esta clase ───────────────────────────────────────────────────────────────────────
 *
 * 1. Con `articles_extra` se cotiza dos veces —la base sola y la base con el extra— y lo que se
 *    devuelve en `opciones` es lo del CONJUNTO (es lo que el comprador va a pagar si agrega),
 *    más un bloque `incremental` con la diferencia.
 * 2. La diferencia sale de la MISMA opción de envío en las dos corridas, buscada por `key`. Si esa
 *    opción no está en el conjunto, se cae a la más barata de cada una y sale `misma_opcion: false`.
 * 3. La diferencia puede dar NEGATIVA cuando el extra hace llegar al envío gratis, y ahí lo que
 *    manda son `envio_gratis_base` / `envio_gratis_total` / `queda_gratis`, no el número.
 * 4. Sin base —carrito vacío, carrito ajeno, nada que viaje— sale `hay_base: false` y el costo que
 *    se muestra es el completo, como siempre.
 * 5. Un id que ya está en el carrito SUMA su cantidad en vez de abrir una línea nueva, y la
 *    cantidad del extra cambia lo que se le pide a Zipnova (el envío se cotiza por unidad).
 * 6. Sin `articles_extra` la respuesta no cambia en una sola clave.
 * 7. 🔴 Las dos corridas NO comparten la clave de caché. Si colisionaran, las dos devolverían lo
 *    mismo y la diferencia daría 0 SIEMPRE: una falla muda y perfectamente creíble.
 *
 * ⚠️ Nada sale a la red: `Http::fake()` en todos los casos. Sobre la base: tienda-api no tiene
 * migraciones (el esquema es de empresa-api); se corre contra la base del slot, con
 * DatabaseTransactions.
 */
class CotizarEnvioIncrementalTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConZipnova;

    const RUTA = '/api/envios/cotizar';

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article Lo que ya está en el carrito. */
    private $en_el_carrito;

    /** @var \App\Article Lo que el comprador está mirando en la ficha. */
    private $de_la_ficha;

    protected function setUp(): void
    {
        parent::setUp();

        ZipnovaEsquemaHelper::olvidar();
        ArticleHelper::olvidar_visibilidad_del_anonimo();

        $this->assertTrue(ZipnovaEsquemaHelper::disponible(), 'La base del slot tiene que tener las columnas de envío (migración 2026_09_14_100200 de empresa-api).');

        $this->comercio = $this->comercioConTienda();
        $this->en_el_carrito = $this->articuloConMedidas($this->comercio, ['name' => 'Lo que ya compro']);
        $this->de_la_ficha = $this->articuloConMedidas($this->comercio, ['name' => 'Lo que esta mirando']);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | La diferencia
    |---------------------------------------------------------------------------------------------
    */

    public function test_devuelve_las_opciones_del_conjunto_y_la_diferencia_de_la_misma_opcion()
    {
        $this->conectorZipnova($this->comercio);

        /* El paquete más pesado sale más caro: mismos correos, $1.000 más cada uno. */
        $this->zipnovaCotizaDosVeces(
            $this->fixtureConPrecios([]),
            $this->fixtureConPrecios([
                self::KEY_RETIRO_CORREO_ARG    => 4993,
                self::KEY_DOMICILIO_CORREO_ARG => 5840,
                self::KEY_DOMICILIO_ANDREANI   => 7473.5,
            ])
        );

        $cart = $this->carritoCon($this->en_el_carrito, 2, 2000);

        $respuesta = $this->cotizarConCarrito($cart, [['id' => $this->de_la_ficha->id, 'amount' => 1]]);

        $respuesta->assertStatus(200);

        /* Lo que se muestra es el conjunto: es lo que va a pagar si agrega. */
        $this->assertSame(4993.0, (float) $respuesta->json('opciones.0.precio'), 'las opciones son las del carrito CON el artículo');

        $incremental = $respuesta->json('incremental');
        $this->assertTrue($incremental['hay_base']);
        $this->assertTrue($incremental['misma_opcion']);
        $this->assertSame(self::KEY_RETIRO_CORREO_ARG, $incremental['key_base']);
        $this->assertSame(self::KEY_RETIRO_CORREO_ARG, $incremental['key'], 'la misma opción de las dos corridas');
        $this->assertSame(self::PRECIO_RETIRO_CORREO_ARG, (float) $incremental['precio_base']);
        $this->assertSame(4993.0, (float) $incremental['precio_total']);
        $this->assertSame(1000.0, (float) $incremental['diferencia'], 'lo ÚNICO que el comprador tiene que pagar de más');
        $this->assertFalse($incremental['envio_gratis_base']);
        $this->assertFalse($incremental['envio_gratis_total']);
        $this->assertFalse($incremental['queda_gratis']);

        /* Dos corridas: el carrito solo (2 unidades) y el carrito con el artículo (3). */
        Http::assertSentCount(2);
        $this->assertSame([2, 3], $this->unidadesDeCadaCorrida());
    }

    public function test_si_la_opcion_de_la_base_no_esta_en_el_conjunto_cae_a_la_mas_barata_de_cada_una_y_lo_marca()
    {
        $this->conectorZipnova($this->comercio);

        /* Con más peso el retiro en sucursal del correo deja de estar: menos transportistas. */
        $this->zipnovaCotizaDosVeces(
            $this->fixtureConPrecios([]),
            $this->fixtureSinLaOpcion(self::KEY_RETIRO_CORREO_ARG)
        );

        $cart = $this->carritoCon($this->en_el_carrito, 1, 1000);

        $respuesta = $this->cotizarConCarrito($cart, [['id' => $this->de_la_ficha->id, 'amount' => 1]]);

        $respuesta->assertStatus(200);

        $incremental = $respuesta->json('incremental');
        $this->assertTrue($incremental['hay_base']);
        $this->assertFalse($incremental['misma_opcion'], 'la opción de la base no está en el conjunto: el SPA no puede afirmar de más');
        $this->assertSame(self::KEY_RETIRO_CORREO_ARG, $incremental['key_base']);
        $this->assertSame(self::KEY_DOMICILIO_CORREO_ARG, $incremental['key'], 'la más barata del conjunto');
        $this->assertSame(self::PRECIO_RETIRO_CORREO_ARG, (float) $incremental['precio_base']);
        $this->assertSame(self::PRECIO_DOMICILIO_CORREO_ARG, (float) $incremental['precio_total']);
        $this->assertSame(
            round(self::PRECIO_DOMICILIO_CORREO_ARG - self::PRECIO_RETIRO_CORREO_ARG, 2),
            (float) $incremental['diferencia']
        );
    }

    public function test_la_cantidad_del_extra_cambia_lo_que_se_cotiza_y_la_diferencia()
    {
        $this->conectorZipnova($this->comercio);

        /* Tres respuestas: la base, el conjunto con 1 unidad y el conjunto con 3. */
        Http::fake([
            self::URL_QUOTE => Http::sequence()
                ->push($this->fixtureConPrecios([]), 200)
                ->push($this->fixtureConPrecios([self::KEY_RETIRO_CORREO_ARG => 4993]), 200)
                ->push($this->fixtureConPrecios([self::KEY_RETIRO_CORREO_ARG => 6993]), 200),
        ]);

        $cart = $this->carritoCon($this->en_el_carrito, 1, 1000);

        $una = $this->cotizarConCarrito($cart, [['id' => $this->de_la_ficha->id, 'amount' => 1]]);
        $tres = $this->cotizarConCarrito($cart, [['id' => $this->de_la_ficha->id, 'amount' => 3]]);

        $una->assertStatus(200);
        $tres->assertStatus(200);

        $this->assertSame(1000.0, (float) $una->json('incremental.diferencia'));
        $this->assertSame(3000.0, (float) $tres->json('incremental.diferencia'), 'tres unidades no cuestan lo mismo que una');

        /* La base de la segunda cotización sale de la caché: solo se le pide el conjunto nuevo. */
        Http::assertSentCount(3);
        $this->assertSame([1, 2, 4], $this->unidadesDeCadaCorrida());
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Envío gratis: la diferencia negativa
    |---------------------------------------------------------------------------------------------
    */

    public function test_cuando_el_extra_hace_llegar_al_envio_gratis_la_diferencia_da_negativa_y_queda_gratis()
    {
        $this->conectorZipnova($this->comercio, ['envio_gratis_desde' => 5000]);
        $this->zipnovaCotiza();

        /* 2 × 1000 = 2000 en el carrito (no llega); + 4 × 1000 del artículo = 6000 (llega). */
        $cart = $this->carritoCon($this->en_el_carrito, 2, 2000);

        $respuesta = $this->cotizarConCarrito($cart, [['id' => $this->de_la_ficha->id, 'amount' => 4]]);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('envio_gratis'));

        $incremental = $respuesta->json('incremental');
        $this->assertTrue($incremental['hay_base']);
        $this->assertFalse($incremental['envio_gratis_base'], 'el carrito solo no llegaba al monto');
        $this->assertTrue($incremental['envio_gratis_total']);
        $this->assertTrue($incremental['queda_gratis'], 'es lo que el SPA cuenta como "agregando esto el envío te queda gratis"');
        $this->assertSame(self::PRECIO_RETIRO_CORREO_ARG, (float) $incremental['precio_base']);
        $this->assertSame(0.0, (float) $incremental['precio_total']);
        $this->assertSame(-self::PRECIO_RETIRO_CORREO_ARG, (float) $incremental['diferencia'], 'negativa: no se muestra como número, se muestra como "gratis"');
    }

    public function test_si_el_carrito_ya_tenia_envio_gratis_no_queda_gratis_por_este_articulo()
    {
        $this->conectorZipnova($this->comercio, ['envio_gratis_desde' => 1500]);
        $this->zipnovaCotiza();

        /* 2 × 1000 = 2000: el carrito solo ya pasa el monto. */
        $cart = $this->carritoCon($this->en_el_carrito, 2, 2000);

        $respuesta = $this->cotizarConCarrito($cart, [['id' => $this->de_la_ficha->id, 'amount' => 1]]);

        $respuesta->assertStatus(200);

        $incremental = $respuesta->json('incremental');
        $this->assertTrue($incremental['envio_gratis_base']);
        $this->assertTrue($incremental['envio_gratis_total']);
        $this->assertFalse($incremental['queda_gratis'], 'ya era gratis antes: no hay nada que anunciar');
        $this->assertSame(0.0, (float) $incremental['diferencia'], 'gratis más gratis no suma nada');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Sin base
    |---------------------------------------------------------------------------------------------
    */

    public function test_con_el_carrito_vacio_no_hay_base_y_se_muestra_el_costo_completo()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id'    => $this->comercio->id,
            'zipcode'        => '5000',
            'articles'       => [],
            'articles_extra' => [['id' => $this->de_la_ficha->id, 'amount' => 1]],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame(self::PRECIO_RETIRO_CORREO_ARG, (float) $respuesta->json('opciones.0.precio'));

        $incremental = $respuesta->json('incremental');
        $this->assertFalse($incremental['hay_base'], 'sin base no hay diferencia: hay un precio a secas');
        $this->assertNull($incremental['diferencia'], 'null y no el total: una diferencia igual al total sería indistinguible de una real');
        $this->assertNull($incremental['precio_base']);
        $this->assertNull($incremental['key_base']);
        $this->assertFalse($incremental['misma_opcion']);
        $this->assertSame(self::KEY_RETIRO_CORREO_ARG, $incremental['key']);
        $this->assertSame(self::PRECIO_RETIRO_CORREO_ARG, (float) $incremental['precio_total']);
        $this->assertFalse($incremental['queda_gratis']);

        /* Una sola corrida: la base sin nada que enviar ni siquiera llega a Zipnova. */
        Http::assertSentCount(1);
    }

    public function test_un_carrito_con_solo_articulos_digitales_tampoco_es_base()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $digital = $this->articuloConMedidas($this->comercio, ['requires_shipping' => 0]);
        $cart = $this->carritoCon($digital, 2, 2000);

        $respuesta = $this->cotizarConCarrito($cart, [['id' => $this->de_la_ficha->id, 'amount' => 1]]);

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('incremental.hay_base'), 'nada de lo que hay en el carrito viaja');
        $this->assertNull($respuesta->json('incremental.diferencia'));
        Http::assertSentCount(1);
    }

    public function test_un_carrito_ajeno_con_articles_extra_cotiza_el_articulo_solo_en_vez_de_dar_403()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        /* El carrito existe pero no es de esta sesión (sin `carritos_propios`). */
        $ajeno = $this->carritoCon($this->en_el_carrito, 2, 2000);

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id'    => $this->comercio->id,
            'zipcode'        => '5000',
            'cart_id'        => $ajeno->id,
            'articles_extra' => [['id' => $this->de_la_ficha->id, 'amount' => 1]],
        ]);

        $respuesta->assertStatus(200, 'la ficha tiene que mostrar un precio aunque el carrito se haya vencido del otro lado');
        $this->assertFalse($respuesta->json('incremental.hay_base'));
        $this->assertSame(self::PRECIO_RETIRO_CORREO_ARG, (float) $respuesta->json('opciones.0.precio'));
    }

    public function test_sin_articles_extra_un_carrito_ajeno_sigue_dando_403()
    {
        $this->conectorZipnova($this->comercio);
        Http::fake();

        $ajeno = $this->carritoCon($this->en_el_carrito, 2, 2000);

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'cart_id'     => $ajeno->id,
        ]);

        $respuesta->assertStatus(403);
        $this->assertSame('carrito', $respuesta->json('codigo'));
        Http::assertNothingSent();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Cómo se suman las líneas
    |---------------------------------------------------------------------------------------------
    */

    public function test_un_articulo_que_ya_esta_en_el_carrito_suma_la_cantidad_en_vez_de_abrir_otra_linea()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotizaDosVeces(
            $this->fixtureConPrecios([]),
            $this->fixtureConPrecios([self::KEY_RETIRO_CORREO_ARG => 5993])
        );

        $cart = $this->carritoCon($this->en_el_carrito, 2, 2000);

        /* El comprador está mirando LO MISMO que ya tiene en el carrito y quiere llevar 3 más. */
        $respuesta = $this->cotizarConCarrito($cart, [['id' => $this->en_el_carrito->id, 'amount' => 3]]);

        $respuesta->assertStatus(200);
        $this->assertSame(2000.0, (float) $respuesta->json('incremental.diferencia'));

        Http::assertSentCount(2);
        $this->assertSame([2, 5], $this->unidadesDeCadaCorrida(), '2 del carrito y 2 + 3 en el conjunto');

        /* Una sola línea de artículo: cinco ítems iguales, no dos grupos separados. */
        $descripciones = array_unique(array_column($this->corridas()[1]['items'], 'description'));
        $this->assertSame(['Lo que ya compro'], array_values($descripciones));
    }

    public function test_un_extra_de_otro_comercio_no_suma_nada_y_la_diferencia_da_cero()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $otro_comercio = $this->comercioConTienda();
        $ajeno = $this->articuloConMedidas($otro_comercio, ['peso' => 9]);

        $cart = $this->carritoCon($this->en_el_carrito, 2, 2000);

        $respuesta = $this->cotizarConCarrito($cart, [['id' => $ajeno->id, 'amount' => 1]]);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('incremental.hay_base'));
        $this->assertSame(0.0, (float) $respuesta->json('incremental.diferencia'), 'no se agrega nada que viaje: no cuesta nada más');

        /* El conjunto es idéntico a la base, así que sale de la caché: una sola llamada. */
        Http::assertSentCount(1);
        $this->assertSame([2], $this->unidadesDeCadaCorrida());
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Compatibilidad hacia atrás
    |---------------------------------------------------------------------------------------------
    */

    public function test_sin_articles_extra_la_respuesta_no_cambia_en_una_sola_clave()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'zipcode'     => '5000',
            'articles'    => [['id' => $this->de_la_ficha->id, 'amount' => 2]],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame(
            ['zipcode', 'city', 'state', 'envio_gratis', 'opciones'],
            array_keys($respuesta->json()),
            'un SPA viejo no se puede enterar de nada'
        );
        Http::assertSentCount(1);
    }

    public function test_una_lista_vacia_de_articles_extra_es_lo_mismo_que_no_mandarla()
    {
        $this->conectorZipnova($this->comercio);
        $this->zipnovaCotiza();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id'    => $this->comercio->id,
            'zipcode'        => '5000',
            'articles'       => [['id' => $this->de_la_ficha->id, 'amount' => 1]],
            'articles_extra' => [],
        ]);

        $respuesta->assertStatus(200);
        $this->assertArrayNotHasKey('incremental', $respuesta->json());
        Http::assertSentCount(1);
    }

    public function test_un_articles_extra_mal_formado_responde_validacion()
    {
        $this->conectorZipnova($this->comercio);
        Http::fake();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id'    => $this->comercio->id,
            'zipcode'        => '5000',
            'articles'       => [['id' => $this->en_el_carrito->id, 'amount' => 1]],
            'articles_extra' => [['amount' => 2]],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('codigo'));
        Http::assertNothingSent();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | La caché
    |---------------------------------------------------------------------------------------------
    */

    public function test_la_corrida_base_y_la_del_conjunto_no_comparten_la_clave_de_cache()
    {
        $this->conectorZipnova($this->comercio);

        /*
         * 🔴 Si las dos corridas colisionaran en la caché, la segunda devolvería la respuesta de
         * la primera y la diferencia daría 0 SIEMPRE, sin ningún error de por medio. Por eso las
         * dos respuestas del fake son distintas: con clave compartida este test da 0 y falla.
         */
        Http::fake([
            self::URL_QUOTE => Http::sequence()
                ->push($this->fixtureConPrecios([]), 200)
                ->push($this->fixtureConPrecios([self::KEY_RETIRO_CORREO_ARG => 4993]), 200)
                ->push($this->fixtureConPrecios([self::KEY_RETIRO_CORREO_ARG => 5993]), 200),
        ]);

        $cart = $this->carritoCon($this->en_el_carrito, 2, 2000);

        $primera = $this->cotizarConCarrito($cart, [['id' => $this->de_la_ficha->id, 'amount' => 1]]);
        $primera->assertStatus(200);
        $this->assertSame(1000.0, (float) $primera->json('incremental.diferencia'), 'con las claves colisionando esto daría 0');
        Http::assertSentCount(2);

        /* Otra ficha, el mismo carrito: la base ya está cacheada, solo se pide el conjunto nuevo. */
        $otro = $this->articuloConMedidas($this->comercio, ['name' => 'Otro que esta mirando']);
        $segunda = $this->cotizarConCarrito($cart, [['id' => $otro->id, 'amount' => 1]]);

        $segunda->assertStatus(200);
        Http::assertSentCount(3);
        $this->assertSame(
            self::PRECIO_RETIRO_CORREO_ARG,
            (float) $segunda->json('incremental.precio_base'),
            'la base de la segunda ficha sale de la caché, con el mismo precio'
        );
        $this->assertSame(2000.0, (float) $segunda->json('incremental.diferencia'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures de esta clase
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Un carrito de esta sesión con una línea.
     *
     * @param \App\Article $articulo
     * @param int $amount
     * @param float $total
     * @return \App\Cart
     */
    private function carritoCon($articulo, $amount, $total)
    {
        $cart = Cart::create([
            'user_id'  => $this->comercio->id,
            'buyer_id' => null,
            'total'    => $total,
            'deliver'  => 1,
        ]);
        $cart->articles()->attach($articulo->id, ['price' => 1000, 'amount' => $amount]);

        return $cart;
    }

    /**
     * Lo que manda la ficha del artículo cuando el comprador ya tiene un carrito: el carrito como
     * base (`cart_id`) y el artículo que está mirando como extra.
     *
     * @param \App\Cart $cart
     * @param array $extra
     * @return \Illuminate\Testing\TestResponse
     */
    private function cotizarConCarrito($cart, array $extra)
    {
        return $this->withSession(['carritos_propios' => [$cart->id]])
            ->postJson(self::RUTA, [
                'commerce_id'    => $this->comercio->id,
                'zipcode'        => '5000',
                'cart_id'        => $cart->id,
                'articles_extra' => $extra,
            ]);
    }

    /**
     * Zipnova contesta una cosa a la primera cotización (la base) y otra a la segunda (el
     * conjunto): un paquete más pesado cuesta más.
     *
     * @param array $base
     * @param array $con_extra
     * @return void
     */
    private function zipnovaCotizaDosVeces(array $base, array $con_extra)
    {
        Http::fake([
            self::URL_QUOTE => Http::sequence()
                ->push($base, 200)
                ->push($con_extra, 200),
        ]);
    }

    /**
     * La fixture de Zipnova con otros precios (`[key => price_incl_tax]`). Lo que cambia con el
     * peso es el precio de cada correo, no la lista de correos.
     *
     * @param array $precios_por_key
     * @return array
     */
    private function fixtureConPrecios(array $precios_por_key)
    {
        $quote = $this->fixture('zipnova_quote.json');

        foreach ($quote['all_results'] as $i => $resultado) {
            $key = ZipnovaQuoteNormalizer::key(
                $resultado['carrier']['id'],
                $resultado['service_type']['code'],
                $resultado['logistic_type']
            );
            if (isset($precios_por_key[$key])) {
                $quote['all_results'][$i]['amounts']['price_incl_tax'] = $precios_por_key[$key];
            }
        }

        return $quote;
    }

    /**
     * La fixture sin una de las opciones: con más peso quedan menos transportistas.
     *
     * @param string $key
     * @return array
     */
    private function fixtureSinLaOpcion($key)
    {
        $quote = $this->fixture('zipnova_quote.json');

        $quote['all_results'] = array_values(array_filter($quote['all_results'], function ($resultado) use ($key) {
            $suya = ZipnovaQuoteNormalizer::key(
                $resultado['carrier']['id'],
                $resultado['service_type']['code'],
                $resultado['logistic_type']
            );

            return $suya !== $key;
        }));

        return $quote;
    }

    /**
     * Los bodies que se le mandaron a Zipnova, en orden.
     *
     * @return array
     */
    private function corridas()
    {
        $corridas = [];

        Http::assertSent(function ($request) use (&$corridas) {
            $corridas[] = $request->data();

            return true;
        });

        return $corridas;
    }

    /**
     * Cuántas unidades (ítems) se cotizaron en cada corrida, en orden. El envío se cotiza por
     * unidad, así que esto es lo que separa una corrida de la otra.
     *
     * @return array
     */
    private function unidadesDeCadaCorrida()
    {
        return array_map(function ($body) {
            return count($body['items']);
        }, $this->corridas());
    }
}
