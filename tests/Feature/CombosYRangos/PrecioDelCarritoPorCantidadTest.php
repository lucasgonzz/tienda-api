<?php

namespace Tests\Feature\CombosYRangos;

use App\Http\Controllers\Helpers\ArticlePriceRangeHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * EL PEDIDO TEXTUAL DE LUCAS: "que en base a las cantidades que el usuario agregue al carrito sea
 * el precio que le va a aparecer en el carrito" (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * ── El hueco que la mision vino a tapar ───────────────────────────────────────────────────────
 *
 * `CartController::update_article_amount()` —el boton "Actualizar" del carrito— cambia el `amount`
 * del pivot con `updateExistingPivot` y NO vuelve a pasar por `get_price()`. Mientras el precio no
 * dependia de la cantidad eso era inofensivo. Con tramos por cantidad SI depende:
 *
 *   El comprador agrega 10 unidades  -> se guarda el tramo de 10, $3.000.
 *   Baja a 1 y aprieta "Actualizar"  -> el pivot queda en amount = 1 y price = $3.000.
 *
 * O sea: el descuento por volumen se conseguia con UNA unidad, sin manipular nada, apretando un
 * boton de la interfaz. Y la pantalla le mostraba $3.948 mientras el carrito guardaba $3.000 —
 * pantalla y servidor diciendo numeros distintos en el camino de la plata.
 *
 * El arreglo vive en `CartHelper::resincronizar_precios_por_rango()`, llamado desde `set_total()`,
 * que es el unico punto por el que pasan los TRES caminos que escriben el carrito (`store`,
 * `update` y `update_article_amount`).
 *
 * ── Por que estos casos le pegan al ENDPOINT y no al helper ───────────────────────────────────
 *
 * Porque el defecto esta justamente en el camino que NO pasa por `get_price()`. Un test del helper
 * no lo podia ver por definicion — es la misma razon que explica `ResincronizacionDelCarritoTest`
 * para la oferta personalizada, y es el mismo defecto por otro carril.
 *
 * ⚠️ Carrito de INVITADO, que es el flujo mas usado de la tienda. De paso eso deja afuera al
 * carril de ofertas personalizadas (`hayContrato()` corta sin comprador), asi que lo que se mide
 * acá es el de tramos y nada mas.
 *
 * ⚠️ Sobre la base: `tienda-api` no tiene `database/migrations` (el esquema lo gobierna
 * `empresa-api`), asi que no se usa `RefreshDatabase` ni `migrate`. Base real del slot con
 * `DatabaseTransactions`, igual que el resto de la suite.
 */
class PrecioDelCarritoPorCantidadTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConCombosYRangos;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article */
    private $articulo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();

        $this->assertTrue(ArticlePriceRangeHelper::hay_tabla(),
            'La base del slot tiene que tener article_price_ranges: sin eso estos casos serian vacuos.');

        $this->comercio = $this->comercioConTienda();
        $this->articulo = $this->conLaEscalaDeLaReproduccion($this->articuloPublicado($this->comercio));
    }

    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * Crea el carrito con N unidades del articulo de la escala y devuelve su id.
     *
     * @param  int|float  $amount
     * @param  array  $overrides  Overrides de la linea, para los payloads adulterados.
     * @return int
     */
    private function carritoCon($amount, array $overrides = [])
    {
        $respuesta = $this->crearCarrito($this->comercio, [
            'articles' => [$this->lineaDelPayload($this->articulo, $amount, $overrides)],
        ]);

        $respuesta->assertStatus(201);

        return (int) $respuesta->json('cart.id');
    }

    /**
     * Un articulo del comercio con UN tramo `Mayor o igual 10 -> 15%` y nada mas.
     *
     * Deliberadamente sin la escala de precios fijos: asi el unico numero que puede explicar lo
     * guardado es el del porcentaje.
     *
     * @param  string  $nombre
     * @return \App\Article
     */
    private function articuloConPorcentaje($nombre)
    {
        $articulo = $this->articuloPublicado($this->comercio, ['name' => $nombre]);

        $this->tramoConPorcentaje($articulo, ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, self::PORCENTAJE_15);

        return $articulo;
    }

    /**
     * Un carrito nuevo con N unidades de UN articulo cualquiera, y su id.
     *
     * @param  \App\Article  $articulo
     * @param  int|float  $amount
     * @return int
     */
    private function carritoDe($articulo, $amount)
    {
        $respuesta = $this->crearCarrito($this->comercio, [
            'articles' => [$this->lineaDelPayload($articulo, $amount)],
        ]);

        $respuesta->assertStatus(201);

        return (int) $respuesta->json('cart.id');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Agregar al carrito: el precio guardado sigue a la cantidad
    |---------------------------------------------------------------------------------------------
    */

    /**
     * El borde exacto: con 5 unidades ya corresponde el tramo `Mayor o igual 5`.
     *
     * Es el numero que la tarjeta del articulo le anuncia al comprador ("llevando 5 o mas,
     * $3.500"), asi que un `>` en vez de `>=` seria prometer un precio y cobrar otro justo en la
     * cantidad que el cartel nombra.
     */
    public function test_cinco_unidades_guardan_el_precio_del_tramo_de_cinco()
    {
        $cart_id = $this->carritoCon(5);

        $this->assertSame(self::TRAMO_5, $this->precioGuardado($cart_id, $this->articulo->id));
        $this->assertSame(self::TRAMO_5 * 5, $this->totalGuardado($cart_id));
    }

    /**
     * Y una unidad menos no alcanza: con 4 no matchea ningun tramo y la linea sale al precio
     * normal.
     *
     * Sin este caso, un helper que devolviera siempre el tramo mas barato daria verde arriba.
     */
    public function test_cuatro_unidades_no_alcanzan_ningun_tramo_y_salen_al_precio_normal()
    {
        $cart_id = $this->carritoCon(4);

        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $this->articulo->id));
        $this->assertSame(self::PRECIO_NORMAL * 4, $this->totalGuardado($cart_id));
    }

    /** Con 10 corresponde el tramo mas profundo de la escala. */
    public function test_diez_unidades_guardan_el_tramo_mas_profundo()
    {
        $cart_id = $this->carritoCon(10);

        $this->assertSame(self::TRAMO_10, $this->precioGuardado($cart_id, $this->articulo->id));
        $this->assertSame(self::TRAMO_10 * 10, $this->totalGuardado($cart_id));
    }

    /**
     * El tramo de modo `Igual` viaja igual de punta a punta: con EXACTAMENTE 3 unidades se cobra
     * su precio, y con 4 ya no.
     *
     * Es el caso que prueba que el modo estricto no se resuelve como un `>=` en ningun eslabon del
     * camino real (payload -> `get_price()` -> `resincronizar_precios_por_rango()`).
     */
    public function test_tres_unidades_exactas_toman_el_tramo_de_modo_igual()
    {
        $cart_id = $this->carritoCon(3);

        $this->assertSame(self::TRAMO_IGUAL_3, $this->precioGuardado($cart_id, $this->articulo->id));
    }

    /**
     * Una unidad: ningun tramo, precio normal. Es la linea de base contra la que se miden los dos
     * casos del boton "Actualizar".
     */
    public function test_una_unidad_sale_al_precio_normal()
    {
        $cart_id = $this->carritoCon(1);

        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $this->articulo->id));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El boton "Actualizar": el hueco que la mision vino a tapar
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 EL CASO QUE MAS IMPORTA DE TODA LA MISION: bajar la cantidad de algo que YA esta en el
     * carrito tiene que DEVOLVER el precio.
     *
     * Antes del arreglo, `update_article_amount()` escribia el `amount` nuevo y dejaba el `price`
     * viejo: el comprador agregaba 10 (tramo de $3.000), bajaba a 1 y se llevaba una unidad a
     * $3.000 en vez de $3.948. Sin manipular nada: con un boton de la interfaz.
     *
     * El endpoint es literalmente el boton, y lo que se mira es la fila de `article_cart`, que es
     * lo que se cobra al confirmar el pedido — no lo que diga la respuesta.
     */
    public function test_bajar_la_cantidad_desde_actualizar_devuelve_la_linea_al_precio_normal()
    {
        $cart_id = $this->carritoCon(10);

        $this->assertSame(self::TRAMO_10, $this->precioGuardado($cart_id, $this->articulo->id),
            'el escenario arranca con el precio del tramo de 10: si no, el caso no se ejercita');

        $this->actualizarCantidad($cart_id, ['id' => $this->articulo->id, 'amount' => 1])
            ->assertStatus(200);

        $this->assertSame(1.0, $this->cantidadGuardada($cart_id, $this->articulo->id),
            'el endpoint tiene que haber bajado la cantidad: si no, el caso no se ejercito');

        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $this->articulo->id),
            'con 1 unidad no corresponde ningun tramo: vuelve al precio normal, no al de 10');

        $this->assertSame(self::PRECIO_NORMAL, $this->totalGuardado($cart_id),
            'y el total sale del precio corregido, no del viejo');
    }

    /**
     * 🔴 Y hacia el otro lado tambien: subir la cantidad tiene que hacer valer el tramo mas
     * profundo.
     *
     * Sin este caso, el arreglo podria ser un "nunca bajes el precio guardado", que le cobraria de
     * mas justo al comprador que compra mas — al que la escala de tramos quiere premiar. Es la
     * contracara que impide volver la escritura simetrica en la direccion equivocada.
     */
    public function test_subir_la_cantidad_desde_actualizar_hace_valer_el_tramo_mas_profundo()
    {
        $cart_id = $this->carritoCon(1);

        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $this->articulo->id));

        $this->actualizarCantidad($cart_id, ['id' => $this->articulo->id, 'amount' => 10])
            ->assertStatus(200);

        $this->assertSame(10.0, $this->cantidadGuardada($cart_id, $this->articulo->id));

        $this->assertSame(self::TRAMO_10, $this->precioGuardado($cart_id, $this->articulo->id),
            'con 10 unidades corresponde el tramo mas profundo');

        $this->assertSame(self::TRAMO_10 * 10, $this->totalGuardado($cart_id));
    }

    /**
     * Bajar sin salirse de la escala: de 10 a 6 se cae al tramo del medio, no al precio normal ni
     * al tramo de 10.
     *
     * Es el caso que impide que los dos de arriba den verde con un "si bajo, precio normal; si
     * subo, el tramo mas barato".
     */
    public function test_bajar_la_cantidad_dentro_de_la_escala_toma_el_tramo_del_medio()
    {
        $cart_id = $this->carritoCon(10);

        $this->actualizarCantidad($cart_id, ['id' => $this->articulo->id, 'amount' => 6])
            ->assertStatus(200);

        $this->assertSame(self::TRAMO_5, $this->precioGuardado($cart_id, $this->articulo->id),
            'con 6 unidades corresponde el tramo de 5, ni el de 10 ni el precio normal');

        $this->assertSame(self::TRAMO_5 * 6, $this->totalGuardado($cart_id));
    }

    /**
     * Y el borde exacto tambien desde "Actualizar": bajar de 10 a 5 se queda en el tramo de 5.
     *
     * Un `>` en el camino de la resincronizacion —aunque el de `get_price()` estuviera bien—
     * devolveria esta linea al precio normal, que es otra vez pantalla y servidor en desacuerdo.
     */
    public function test_bajar_al_borde_exacto_de_un_tramo_se_queda_en_ese_tramo()
    {
        $cart_id = $this->carritoCon(10);

        $this->actualizarCantidad($cart_id, ['id' => $this->articulo->id, 'amount' => 5])
            ->assertStatus(200);

        $this->assertSame(self::TRAMO_5, $this->precioGuardado($cart_id, $this->articulo->id));
    }

    /**
     * El camino del medio: `PUT /api/carts` (el guardado completo del carrito) tambien recalcula.
     *
     * Son TRES los caminos que escriben el carrito y los tres pasan por `set_total()`. Este caso
     * fija el tercero, para que nadie "simplifique" moviendo la resincronizacion al controller de
     * "Actualizar", que es donde se descubrio el defecto.
     */
    public function test_guardar_el_carrito_entero_con_otra_cantidad_tambien_recalcula()
    {
        $cart_id = $this->carritoCon(10);

        $this->withSession(['carritos_propios' => [$cart_id]])
            ->putJson('/api/carts', [
                'id'                   => $cart_id,
                'articles'             => [$this->lineaDelPayload($this->articulo, 2)],
                'promociones_vinoteca' => [],
            ])
            ->assertStatus(200);

        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $this->articulo->id),
            'con 2 unidades no corresponde ningun tramo');
    }

    /**
     * 🔴 LA PRIMERA LINEA DE DEFENSA, MEDIDA APARTE: `get_price()` —el eslabon que resuelve el
     * precio al AGREGAR la linea— ya devuelve el precio del tramo por su cuenta.
     *
     * Por que tiene su propio caso en vez de confiar en los de arriba: se midio que sacandole a
     * `get_price()` la rama del tramo, NINGUN caso de esta clase se pone rojo. Es que
     * `set_total()` corre despues y la resincronizacion deja la fila igual — o sea que las dos
     * defensas se tapan una a la otra y ninguna prueba de punta a punta puede distinguirlas.
     *
     * Eso no es un defecto (que los dos caminos coincidan es justamente lo que se quiere), pero
     * deja la mitad del codigo sin red: si alguien saca esta rama "porque no la cubre ningun
     * test", el dia que la resincronizacion se saltee una linea —por el `catch`, o por el carril
     * de rangos por categoria— no queda nadie resolviendo el tramo. Este caso mide el eslabon
     * solo, con el mismo criterio que `PrecioDelCarritoTest` usa para la oferta personalizada.
     */
    public function test_get_price_resuelve_el_tramo_por_su_cuenta()
    {
        $linea = $this->lineaDelPayload($this->articulo, 10);

        $precio = \App\Http\Controllers\Helpers\CartHelper::get_price(
            [$linea],
            $linea,
            false,
            collect([]),
            $this->comercio->id
        );

        $this->assertSame(self::TRAMO_10, (float) $precio,
            'get_price tiene que devolver el tramo de 10 sin depender de la resincronizacion posterior');

        /* Y con una cantidad sin tramo devuelve el precio de siempre — el del payload, que es el
           agujero PREEXISTENTE de este repo y que esta mision no agranda ni arregla. */
        $sin_tramo = $this->lineaDelPayload($this->articulo, 2);

        $this->assertSame(self::PRECIO_NORMAL, (float) \App\Http\Controllers\Helpers\CartHelper::get_price(
            [$sin_tramo],
            $sin_tramo,
            false,
            collect([]),
            $this->comercio->id
        ));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El criterio 4, por el camino real
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 EL CRITERIO 4, DE PUNTA A PUNTA: el ganador sin precio usable NO le deja el lugar al de
     * abajo, y la linea se cobra al precio NORMAL.
     *
     * `MatcheoDeTramosTest` lo fija sobre el helper; este lo fija sobre la fila que se cobra, que
     * es donde el defecto se paga. El escenario es el que se midio el 16/9/2026: dos tramos
     * (`>=10` a $3.000 y `>=20` con `price` NULL) y un comprador llevando 25 unidades.
     *
     * Con el orden mal —filtrando los nulos antes de elegir— la tienda mostraba $3.000 y el
     * servidor cobraba $3.948. Este caso manda en el payload justamente los $3.000 que mostraba la
     * pantalla rota, y exige que lo guardado sea $3.948: el tramo de 20 gana, se queda sin precio,
     * y no hay descuento.
     */
    public function test_el_tramo_ganador_sin_precio_no_deja_competir_al_de_abajo_y_se_cobra_el_precio_normal()
    {
        $articulo = $this->articuloPublicado($this->comercio, ['name' => 'Articulo Tramo Sin Precio']);

        $this->tramo($articulo, ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, 3000);
        $this->tramo($articulo, ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 20, null);

        $respuesta = $this->crearCarrito($this->comercio, [
            /* El `final_price` del payload es el numero que mostraba la pantalla rota. */
            'articles' => [$this->lineaDelPayload($articulo, 25, ['final_price' => 3000])],
        ]);

        $respuesta->assertStatus(201);
        $cart_id = (int) $respuesta->json('cart.id');

        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $articulo->id),
            'gana el tramo de 20, se queda sin precio usable y la linea cae al precio normal');

        $this->assertSame(self::PRECIO_NORMAL * 25, $this->totalGuardado($cart_id));
    }

    /**
     * La contraprueba del mismo articulo: con 15 unidades el ganador es el de 10, que SI tiene
     * precio, y ahi el descuento corresponde.
     *
     * Sin esto, el caso de arriba daria verde contra un helper que no aplicara nunca ningun tramo.
     */
    public function test_por_debajo_del_tramo_sin_precio_el_tramo_de_abajo_si_cobra()
    {
        $articulo = $this->articuloPublicado($this->comercio, ['name' => 'Articulo Tramo Sin Precio 2']);

        $this->tramo($articulo, ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, 3000);
        $this->tramo($articulo, ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 20, null);

        $respuesta = $this->crearCarrito($this->comercio, [
            'articles' => [$this->lineaDelPayload($articulo, 15)],
        ]);

        $respuesta->assertStatus(201);

        $this->assertSame(3000.00, $this->precioGuardado((int) $respuesta->json('cart.id'), $articulo->id));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | La OFERTA POR CANTIDAD EN PORCENTAJE, cobrada de punta a punta
    | (mision oferta-por-cantidad-porcentaje, 24/9/2026)
    |
    | `MatcheoDeTramosTest` fija el criterio sobre arrays; estos casos lo fijan sobre la fila de
    | `article_cart` que se cobra al confirmar el pedido, que es donde el defecto se paga.
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Un articulo con `Mayor o igual 10 -> 15%` y un comprador llevando 10: el pivote guarda
     * $3.355,80, que es $3.948 menos el 15%.
     *
     * Es el pedido literal de Lucas ("a partir de 10 unidades, 15% de descuento") medido donde
     * importa: en la plata.
     */
    public function test_un_tramo_por_porcentaje_se_cobra_de_punta_a_punta()
    {
        $articulo = $this->articuloConPorcentaje('Articulo Porcentaje');

        $cart_id = $this->carritoDe($articulo, 10);

        $this->assertSame(self::PRECIO_CON_PORCENTAJE, $this->precioGuardado($cart_id, $articulo->id),
            '3948 menos el 15% son 3355,80');

        $this->assertSame(round(self::PRECIO_CON_PORCENTAJE * 10, 2), $this->totalGuardado($cart_id));
    }

    /**
     * Y una unidad menos no alcanza: con 9 no matchea el tramo y la linea sale al precio normal.
     *
     * Sin este caso, un helper que descontara siempre daria verde arriba.
     */
    public function test_por_debajo_del_tramo_el_porcentaje_no_muerde()
    {
        $articulo = $this->articuloConPorcentaje('Articulo Porcentaje Borde');

        $cart_id = $this->carritoDe($articulo, 9);

        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $articulo->id));
    }

    /**
     * 🔴 EL BOTON "ACTUALIZAR" CON UN TRAMO POR PORCENTAJE: bajar la cantidad devuelve el precio,
     * y volver a subirla lo vuelve a descontar.
     *
     * Es el mismo hueco que la mision del 16/9 tapo para el precio fijo, por el mismo camino
     * (`update_article_amount()` no vuelve a pasar por `get_price()`), y hay que probarlo aparte
     * porque la rama del porcentaje es codigo nuevo: la resincronizacion tiene que resolverle el
     * PRECIO BASE ademas del tramo, y ahi es donde se puede equivocar de escala sin que nada
     * avise.
     */
    public function test_el_boton_actualizar_baja_y_sube_el_precio_con_el_porcentaje()
    {
        $articulo = $this->articuloConPorcentaje('Articulo Porcentaje Actualizar');

        $cart_id = $this->carritoDe($articulo, 10);

        $this->assertSame(self::PRECIO_CON_PORCENTAJE, $this->precioGuardado($cart_id, $articulo->id),
            'el escenario arranca con el descuento puesto: si no, el caso seria vacuo');

        /* Bajar a 1: el descuento ya no corresponde. */
        $this->actualizarCantidad($cart_id, ['id' => $articulo->id, 'amount' => 1])
            ->assertStatus(200);

        $this->assertSame(1.0, $this->cantidadGuardada($cart_id, $articulo->id));
        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $articulo->id),
            'con 1 unidad no hay oferta por cantidad: el 15% no se puede conseguir apretando un boton');
        $this->assertSame(self::PRECIO_NORMAL, $this->totalGuardado($cart_id));

        /* Y volver a subir: el descuento vuelve. */
        $this->actualizarCantidad($cart_id, ['id' => $articulo->id, 'amount' => 12])
            ->assertStatus(200);

        $this->assertSame(self::PRECIO_CON_PORCENTAJE, $this->precioGuardado($cart_id, $articulo->id));

        /* `round()` en el numero ESPERADO y no en el medido: `3355.80 * 12` da
           40269.600000000006 en un float de PHP, mientras que `carts.total` es una columna
           decimal y guarda 40269.60. Lo que se afloja es la aritmetica del test, no la asercion:
           el valor exigido sigue siendo el centavo exacto. */
        $this->assertSame(round(self::PRECIO_CON_PORCENTAJE * 12, 2), $this->totalGuardado($cart_id));
    }

    /**
     * 🔴 EL PORCENTAJE MUERDE EL PRECIO DE LA BASE, NO EL DEL PAYLOAD.
     *
     * Es la unica punta de esta funcionalidad donde el agujero preexistente de "el cliente fija el
     * precio base" se podia AGRANDAR: si el descuento se calculara sobre el `final_price` que
     * manda el navegador y ese numero fuera lo unico que decide, cualquiera podria mandarse un
     * precio de $1 y llevarse el articulo. La resincronizacion relee el articulo de la base y
     * reescribe la fila, asi que lo que queda guardado es el 15% sobre los $3.948 de verdad.
     *
     * ⚠️ Lo que este caso NO dice es que el agujero este cerrado para un articulo SIN tramos: ese
     * sigue saliendo al precio del payload, igual que en master, y arreglarlo es otra mision (ver
     * `test_un_articulo_sin_tramos_no_lo_toca_nadie`).
     */
    public function test_el_porcentaje_se_calcula_sobre_el_precio_de_la_base_y_no_sobre_el_del_payload()
    {
        $articulo = $this->articuloConPorcentaje('Articulo Porcentaje Payload');

        $respuesta = $this->crearCarrito($this->comercio, [
            'articles' => [$this->lineaDelPayload($articulo, 10, ['final_price' => 1])],
        ]);

        $respuesta->assertStatus(201);

        $this->assertSame(self::PRECIO_CON_PORCENTAJE, $this->precioGuardado((int) $respuesta->json('cart.id'), $articulo->id),
            'el 15% sale de los $3.948 de la base, no del $1 que mando el navegador');
    }

    /**
     * Con `price` y `porcentaje` cargados en la MISMA fila gana el precio fijo, tambien por el
     * camino real.
     *
     * Es compatibilidad hacia atras: `price` es lo unico que existia, y una fila vieja no puede
     * cambiar de precio porque alguien le agregue un porcentaje despues.
     */
    public function test_con_los_dos_valores_cargados_el_carrito_cobra_el_precio_fijo()
    {
        $articulo = $this->articuloPublicado($this->comercio, ['name' => 'Articulo Los Dos']);

        $this->tramoConPorcentaje($articulo, ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, 90, self::TRAMO_10);

        $cart_id = $this->carritoDe($articulo, 10);

        $this->assertSame(self::TRAMO_10, $this->precioGuardado($cart_id, $articulo->id),
            'gana el precio fijo de $3.000, no los $394,80 que daria el 90%');
    }

    /**
     * Un porcentaje de 100 NO regala el articulo: el tramo no aplica y se cobra el precio normal.
     *
     * Por el camino real importa mas que en el helper, porque el final de esta cadena es una fila
     * de `article_cart` con `price` NOT NULL: un $0 guardado ahi es un pedido confirmado a cero
     * contra la cuenta corriente de una persona.
     */
    public function test_un_porcentaje_de_cien_no_regala_el_articulo_en_el_carrito()
    {
        $articulo = $this->articuloPublicado($this->comercio, ['name' => 'Articulo Porcentaje Cien']);

        $this->tramoConPorcentaje($articulo, ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, 100);

        $cart_id = $this->carritoDe($articulo, 10);

        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $articulo->id),
            'el 100% no aplica: la linea sale al precio normal, nunca a $0');
        $this->assertSame(self::PRECIO_NORMAL * 10, $this->totalGuardado($cart_id));
    }

    /**
     * Y el eslabon solo: `get_price()` resuelve el porcentaje por su cuenta, sin depender de la
     * resincronizacion posterior.
     *
     * Mismo motivo que `test_get_price_resuelve_el_tramo_por_su_cuenta` para el precio fijo: las
     * dos defensas se tapan una a la otra y ninguna prueba de punta a punta las distingue.
     */
    public function test_get_price_resuelve_el_porcentaje_por_su_cuenta()
    {
        $articulo = $this->articuloConPorcentaje('Articulo Porcentaje get_price');

        $linea = $this->lineaDelPayload($articulo, 10);

        $this->assertSame(self::PRECIO_CON_PORCENTAJE, (float) \App\Http\Controllers\Helpers\CartHelper::get_price(
            [$linea],
            $linea,
            false,
            collect([]),
            $this->comercio->id
        ), 'get_price aplica el 15% sobre el final_price de la linea, sin esperar a set_total');

        /* Y con una cantidad sin tramo devuelve el precio de siempre. */
        $sin_tramo = $this->lineaDelPayload($articulo, 2);

        $this->assertSame(self::PRECIO_NORMAL, (float) \App\Http\Controllers\Helpers\CartHelper::get_price(
            [$sin_tramo],
            $sin_tramo,
            false,
            collect([]),
            $this->comercio->id
        ));
    }

    /**
     * Un articulo con la escala de precios fijos de siempre MAS un tramo por porcentaje mas
     * profundo: cada cantidad toma lo suyo.
     *
     * Los dos modos conviven en el mismo articulo porque la exclusion es POR FILA, no por
     * articulo, y quien elige sigue siendo el `amount`.
     */
    public function test_los_dos_modos_conviven_en_el_mismo_articulo_y_manda_la_cantidad()
    {
        $articulo = $this->conLaEscalaDeLaReproduccion(
            $this->articuloPublicado($this->comercio, ['name' => 'Articulo Escala Mixta'])
        );

        $this->tramoConPorcentaje($articulo, ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 20, 15);

        $this->assertSame(self::TRAMO_5, $this->precioGuardado($this->carritoDe($articulo, 5), $articulo->id),
            'con 5 gana el tramo de 5, que es por precio fijo');

        $this->assertSame(self::TRAMO_10, $this->precioGuardado($this->carritoDe($articulo, 10), $articulo->id),
            'con 10 gana el de 10, tambien por precio fijo');

        $this->assertSame(self::PRECIO_CON_PORCENTAJE, $this->precioGuardado($this->carritoDe($articulo, 20), $articulo->id),
            'con 20 gana el de 20, que es por porcentaje');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Lo que esta funcionalidad NO puede cambiarle a nadie
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Un articulo SIN tramos se comporta exactamente como en master, y el boton "Actualizar" no le
     * toca el precio.
     *
     * `resincronizar_precios_por_rango()` filtra con `whereHas('article_price_ranges')` justo para
     * esto: una funcionalidad nueva no puede cambiarle el precio a un carrito de una tienda que no
     * la usa. Y como "el cliente fija el precio base" es un agujero PREEXISTENTE de este repo, la
     * linea se guarda con el `final_price` del payload — lo que se fija acá es que eso siga igual,
     * ni mejor ni peor, para el que no tiene tramos.
     */
    public function test_un_articulo_sin_tramos_no_lo_toca_nadie()
    {
        $sin_tramos = $this->articuloPublicado($this->comercio, ['name' => 'Articulo Sin Tramos']);

        $respuesta = $this->crearCarrito($this->comercio, [
            'articles' => [$this->lineaDelPayload($sin_tramos, 10)],
        ]);

        $respuesta->assertStatus(201);
        $cart_id = (int) $respuesta->json('cart.id');

        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $sin_tramos->id));

        $this->actualizarCantidad($cart_id, ['id' => $sin_tramos->id, 'amount' => 1])
            ->assertStatus(200);

        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $sin_tramos->id),
            'sin tramos, el precio guardado no lo toca la resincronizacion');
    }

    /**
     * Dos lineas en el mismo carrito, cada una con SU cantidad y SU tramo.
     *
     * El tramo se elige con la cantidad de ESA linea y no con el total del carrito — que es
     * exactamente al reves de como funciona el otro carril de rangos, el de CATEGORIA
     * (`check_article_price_type_group` suma las lineas del mismo grupo). Confundir los dos
     * cobraria mal en los dos.
     */
    public function test_cada_linea_elige_su_tramo_con_su_propia_cantidad()
    {
        $segundo = $this->conLaEscalaDeLaReproduccion(
            $this->articuloPublicado($this->comercio, ['name' => 'Articulo Escala 2'])
        );

        $respuesta = $this->crearCarrito($this->comercio, [
            'articles' => [
                $this->lineaDelPayload($this->articulo, 10),
                $this->lineaDelPayload($segundo, 1),
            ],
        ]);

        $respuesta->assertStatus(201);
        $cart_id = (int) $respuesta->json('cart.id');

        $this->assertSame(self::TRAMO_10, $this->precioGuardado($cart_id, $this->articulo->id),
            'la linea de 10 cobra su tramo');
        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $segundo->id),
            'la de 1 no: las cantidades no se suman entre articulos distintos');

        $this->assertSame(self::TRAMO_10 * 10 + self::PRECIO_NORMAL, $this->totalGuardado($cart_id));
    }
}
