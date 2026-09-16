<?php

namespace Tests\Feature\CombosYRangos;

use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `CartHelper::set_total()` con la TERCERA coleccion comprable (mision combos-y-rangos-de-precio,
 * 16/9/2026).
 *
 * ── El defecto que esta clase previene, y que ya paso una vez ─────────────────────────────────
 *
 * El carrito de esta tienda tiene ahora TRES colecciones que suman plata: articulos, promociones
 * de vinoteca y combos. `set_total()` las recorre a las tres, y olvidarse de una no se ve como un
 * error: se ve como un total que no cierra. El 14/9/2026 paso exactamente eso —el total del
 * carrito de invitado se olvidaba de una coleccion y la tienda mostraba "-"— y no hay ninguna
 * excepcion, ningun log y ningun 500 que lo denuncie.
 *
 * Por eso los casos de acá miden el total EXACTO de un carrito mezclado y no "que no sea cero": un
 * total que suma dos de tres tambien es un numero, y un numero pasa cualquier assert flojo.
 *
 * ⚠️ El comprador es un INVITADO, que es el flujo mas usado de la tienda y el que fallo aquella
 * vez. Y lo que se lee es `carts.total` de la base, no lo que diga la respuesta: es la columna que
 * despues arrastra el pedido y el cobro.
 */
class TotalConCombosTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConCombosYRangos;

    /** Precio de la promocion de vinoteca de las fixtures (la segunda coleccion). */
    const PRECIO_PROMO = 1500.00;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article */
    private $articulo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();

        $this->assertTrue(ComboEsquemaHelper::disponible(),
            'La base del slot tiene que tener el esquema de combos: sin eso estos casos serian vacuos.');

        $this->comercio = $this->comercioConTienda();
        $this->articulo = $this->conLaEscalaDeLaReproduccion($this->articuloPublicado($this->comercio));
    }

    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * 🔴 EL CASO DE LA CLASE: un carrito con las TRES colecciones suma las tres, exacto.
     *
     * Los tres numeros son distintos entre si y ninguno es multiplo de otro, a proposito: asi un
     * total que se olvida de una coleccion —o que cuenta una dos veces— no puede coincidir con el
     * esperado por casualidad.
     *
     *   10 unidades del articulo al tramo de $3.000  = $30.000
     *    2 promociones de vinoteca a $1.500          =  $3.000
     *    1 combo a $9.000                            =  $9.000
     *                                                 ---------
     *                                                  $42.000
     */
    public function test_el_total_suma_las_tres_colecciones()
    {
        $promo = $this->promocionVinoteca($this->comercio, self::PRECIO_PROMO);
        $combo = $this->combo($this->comercio);

        $respuesta = $this->crearCarrito($this->comercio, [
            'articles'             => [$this->lineaDelPayload($this->articulo, 10)],
            'promociones_vinoteca' => [$this->lineaDePromoDelPayload($promo, 2)],
            'combos'               => [$this->lineaDeComboDelPayload($combo, 1)],
        ]);

        $respuesta->assertStatus(201);
        $cart_id = (int) $respuesta->json('cart.id');

        $esperado = (self::TRAMO_10 * 10) + (self::PRECIO_PROMO * 2) + (self::PRECIO_COMBO * 1);

        $this->assertSame(42000.00, $esperado, 'la cuenta del docblock');

        $this->assertSame($esperado, $this->totalGuardado($cart_id),
            'el total tiene que sumar articulos + promociones de vinoteca + combos, las tres');

        $this->assertEquals($esperado, (float) $respuesta->json('cart.total'),
            'y lo que se le devuelve al SPA es el mismo numero');
    }

    /**
     * Un carrito de SOLO combos tiene total.
     *
     * Es la forma exacta del sintoma del 14/9: la coleccion que la suma se olvida es la unica que
     * hay, el total queda en cero y la tienda dibuja "-" donde va la plata. Con el articulo al
     * lado, ese mismo defecto se esconde detras de un numero que parece razonable.
     */
    public function test_un_carrito_de_solo_combos_tiene_total()
    {
        $combo = $this->combo($this->comercio);

        $respuesta = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($combo, 3)],
        ]);

        $respuesta->assertStatus(201);

        $this->assertSame(self::PRECIO_COMBO * 3, $this->totalGuardado((int) $respuesta->json('cart.id')),
            'un carrito de solo combos no puede quedar en cero: es el sintoma del "-" del 14/9/2026');
    }

    /**
     * Cambiar la cantidad de un COMBO desde "Actualizar" mueve el total.
     *
     * `update_article_amount()` apunta al tercer pivote con `is_combo`, igual que apunta al segundo
     * con `is_promocion_vinoteca`. Sin este caso, el metodo podria estar cambiando el `amount` del
     * ARTICULO con ese id y el total seguiria dando un numero.
     */
    public function test_cambiar_la_cantidad_de_un_combo_actualiza_el_total()
    {
        $combo = $this->combo($this->comercio);

        $respuesta = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($combo, 1)],
        ]);

        $cart_id = (int) $respuesta->json('cart.id');

        $this->assertSame(self::PRECIO_COMBO, $this->totalGuardado($cart_id));

        $this->actualizarCantidad($cart_id, [
            'id'       => $combo->id,
            'amount'   => 4,
            'is_combo' => true,
        ])->assertStatus(200);

        $filas = $this->combosGuardados($cart_id);

        $this->assertCount(1, $filas);
        $this->assertSame(4.0, (float) $filas[0]->amount,
            'el endpoint tiene que haber cambiado la cantidad del COMBO: si no, el caso no se ejercito');

        $this->assertSame(self::PRECIO_COMBO * 4, $this->totalGuardado($cart_id));
    }

    /**
     * Y guardar el carrito entero con otro contenido tambien recalcula las tres.
     *
     * `PUT /api/carts` sincroniza las tres colecciones a cero y las vuelve a colgar; es el camino
     * por el que el SPA guarda cada vez que el comprador toca algo. Acá se saca el combo y se deja
     * el articulo: el total tiene que BAJAR, no quedarse con el numero anterior.
     */
    public function test_sacar_el_combo_al_guardar_el_carrito_baja_el_total()
    {
        $combo = $this->combo($this->comercio);

        $respuesta = $this->crearCarrito($this->comercio, [
            'articles' => [$this->lineaDelPayload($this->articulo, 1)],
            'combos'   => [$this->lineaDeComboDelPayload($combo, 1)],
        ]);

        $cart_id = (int) $respuesta->json('cart.id');

        $this->assertSame(self::PRECIO_NORMAL + self::PRECIO_COMBO, $this->totalGuardado($cart_id));

        $this->withSession(['carritos_propios' => [$cart_id]])
            ->putJson('/api/carts', [
                'id'                   => $cart_id,
                'articles'             => [$this->lineaDelPayload($this->articulo, 1)],
                'promociones_vinoteca' => [],
                'combos'               => [],
            ])
            ->assertStatus(200);

        $this->assertCount(0, $this->combosGuardados($cart_id),
            'el combo tiene que haberse ido del carrito');

        $this->assertSame(self::PRECIO_NORMAL, $this->totalGuardado($cart_id),
            'y el total tiene que bajar: no puede quedarse con el combo que ya no esta');
    }

    /**
     * Un carrito SIN combos suma exactamente lo mismo que antes de esta mision.
     *
     * Es la garantia de compatibilidad del total: la coleccion nueva no puede sumarle nada de mas
     * a las tiendas que no la usan — que hoy son todas.
     */
    public function test_un_carrito_sin_combos_suma_lo_mismo_que_siempre()
    {
        $promo = $this->promocionVinoteca($this->comercio, self::PRECIO_PROMO);

        $respuesta = $this->crearCarrito($this->comercio, [
            'articles'             => [$this->lineaDelPayload($this->articulo, 5)],
            'promociones_vinoteca' => [$this->lineaDePromoDelPayload($promo, 1)],
        ]);

        $respuesta->assertStatus(201);

        $this->assertSame((self::TRAMO_5 * 5) + self::PRECIO_PROMO,
            $this->totalGuardado((int) $respuesta->json('cart.id')));
    }
}
