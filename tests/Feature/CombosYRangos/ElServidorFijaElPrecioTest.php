<?php

namespace Tests\Feature\CombosYRangos;

use App\Http\Controllers\Helpers\ArticlePriceRangeHelper;
use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EL SERVIDOR MANDA, EL NAVEGADOR NO (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * ── Lo que esta clase fija, y lo que a proposito NO ───────────────────────────────────────────
 *
 * "El cliente fija el precio base" es un agujero PREEXISTENTE de este repo: `CartHelper::get_price()`
 * recibe el articulo tal cual lo manda el navegador y su `final_price` es texto que escribio el
 * cliente. Arreglar eso es otra mision, y esta clase no lo prueba resuelto porque no lo esta.
 *
 * Lo que SI fija es que las dos colecciones NUEVAS no nacieron con el agujero adentro:
 *
 *   1. Los TRAMOS por cantidad se leen de la base, nunca del payload. Sin esto, cualquiera se
 *      inventa un tramo de "a partir de 1 unidad, $1" y lo cobra.
 *   2. El precio de un COMBO sale de `combos.price`. El molde de ese metodo
 *      (`attach_promociones_vinoteca`) usa `$promo['final_price']`, o sea el numero del navegador;
 *      los combos no lo copiaron.
 *   3. Con el mismo `where` que resuelve el precio se cierran dos cosas mas: un combo SIN PUBLICAR
 *      y un combo de OTRO COMERCIO no entran al carrito. El comercio sale del CARRITO
 *      (`$cart->user_id`, que lo escribio el servidor), no del payload.
 *
 * Y un cuarto, que es de otra familia pero del mismo lado del mostrador: el COSTO del combo no
 * viaja al navegador. La tienda es publica; cualquier visitante con las devtools abiertas veria el
 * margen de cada combo.
 *
 * ⚠️ Base real del slot con `DatabaseTransactions`, sin migraciones propias (el esquema lo gobierna
 * `empresa-api`).
 */
class ElServidorFijaElPrecioTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConCombosYRangos;

    /** Lo que el payload adulterado pretende cobrar. Si aparece guardado, hay agujero. */
    const PRECIO_TRUCHO = 1.00;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article */
    private $articulo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();

        $this->assertTrue(ArticlePriceRangeHelper::hay_tabla(),
            'La base del slot tiene que tener article_price_ranges.');
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
     * Un tramo inventado por el navegador: "a partir de 1 unidad, $1".
     *
     * @return array
     */
    private function tramoInventado()
    {
        return [
            [
                'id'         => 999999,
                'article_id' => $this->articulo->id,
                'modo'       => ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL,
                'amount'     => 1,
                'price'      => self::PRECIO_TRUCHO,
            ],
        ];
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Los tramos por cantidad
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 Un tramo inventado en el payload no cobra nada: el precio es el del tramo de LA BASE.
     *
     * El payload de este caso viene adulterado por las dos puntas —un tramo de $1 desde la primera
     * unidad Y un `final_price` de $1—, que es todo lo que un atacante controla. Con 10 unidades
     * la fila tiene que quedar en los $3.000 del tramo real.
     *
     * `ArticlePriceRangeHelper::precio_de_articulo()` existe exactamente para esto: resuelve el
     * tramo con las filas LEIDAS DE LA BASE y ni siquiera mira las que vienen en el articulo.
     */
    public function test_un_tramo_inventado_en_el_payload_no_cambia_el_precio_guardado()
    {
        $respuesta = $this->crearCarrito($this->comercio, [
            'articles' => [$this->lineaDelPayload($this->articulo, 10, [
                'final_price'          => self::PRECIO_TRUCHO,
                'article_price_ranges' => $this->tramoInventado(),
            ])],
        ]);

        $respuesta->assertStatus(201);
        $cart_id = (int) $respuesta->json('cart.id');

        $this->assertSame(self::TRAMO_10, $this->precioGuardado($cart_id, $this->articulo->id),
            'el precio sale de article_price_ranges de la base, no del que viaja en el articulo');

        $this->assertSame(self::TRAMO_10 * 10, $this->totalGuardado($cart_id),
            'y el total tambien: el payload no fija el total');
    }

    /**
     * Y con una cantidad para la que NO hay tramo, un `final_price` trucho tampoco se cobra: la
     * resincronizacion lo corrige hacia arriba.
     *
     * ⚠️ Ojo con lo que este caso prueba y lo que no. La correccion la hace
     * `resincronizar_precios_por_rango()`, que solo mira los articulos CON tramos — asi que esto
     * vale para los articulos de esta funcionalidad y no para el resto del catalogo, donde el
     * agujero preexistente sigue abierto (ver `PrecioDelCarritoPorCantidadTest::test_un_articulo_sin_tramos_no_lo_toca_nadie`).
     * Se fija igual porque es la garantia concreta de que la funcionalidad NUEVA no lo agranda.
     */
    public function test_sin_tramo_que_matchee_un_final_price_trucho_lo_corrige_la_resincronizacion()
    {
        $respuesta = $this->crearCarrito($this->comercio, [
            'articles' => [$this->lineaDelPayload($this->articulo, 2, [
                'final_price' => self::PRECIO_TRUCHO,
            ])],
        ]);

        $respuesta->assertStatus(201);
        $cart_id = (int) $respuesta->json('cart.id');

        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $this->articulo->id),
            'el precio de un articulo con tramos se reconstruye del lado del servidor');
    }

    /**
     * 🔴 Y el eslabon que resuelve el precio AL AGREGAR ya ignora el tramo del payload por su
     * cuenta, medido aparte.
     *
     * Por que no alcanza con el caso de arriba: se midio que haciendo que `get_price()` resuelva
     * el tramo con `$article['article_price_ranges']` —o sea, con lo que manda el navegador—,
     * NINGUN caso de punta a punta se pone rojo. Es que `set_total()` corre despues y
     * `resincronizar_precios_por_rango()` reescribe la fila con el precio de la base.
     *
     * O sea que la defensa que hoy ataja el payload adulterado es la SEGUNDA, y la primera no
     * tiene quien la cuide. Eso importa porque la segunda tiene salidas: se saltea las lineas del
     * carril de ofertas, se saltea el comercio con rangos por categoria cuando no matchea ningun
     * tramo, y tiene un `catch (\Throwable)` que deja el precio que ya estaba. En cualquiera de
     * esos caminos, el numero que queda es el que resolvio `get_price()`.
     *
     * `ArticlePriceRangeHelper::precio_de_articulo()` existe exactamente para eso: lee los tramos
     * de la base y ni siquiera mira los del articulo que le pasan.
     */
    public function test_get_price_ignora_los_tramos_que_vienen_en_el_payload()
    {
        $linea = $this->lineaDelPayload($this->articulo, 10, [
            'final_price'          => self::PRECIO_TRUCHO,
            'article_price_ranges' => $this->tramoInventado(),
        ]);

        $precio = \App\Http\Controllers\Helpers\CartHelper::get_price(
            [$linea],
            $linea,
            false,
            collect([]),
            $this->comercio->id
        );

        $this->assertSame(self::TRAMO_10, (float) $precio,
            'get_price tiene que resolver el tramo contra la base, no contra los tramos del payload');
    }

    /**
     * El tramo del payload tampoco se cuela por el camino de "Actualizar": ahi ni siquiera hay
     * payload de articulo, y la fila tiene que quedar con el precio de la base.
     */
    public function test_el_tramo_del_payload_tampoco_se_cuela_al_cambiar_la_cantidad()
    {
        $respuesta = $this->crearCarrito($this->comercio, [
            'articles' => [$this->lineaDelPayload($this->articulo, 1)],
        ]);

        $cart_id = (int) $respuesta->json('cart.id');

        $this->actualizarCantidad($cart_id, [
            'id'     => $this->articulo->id,
            'amount' => 10,
        ])->assertStatus(200);

        $this->assertSame(self::TRAMO_10, $this->precioGuardado($cart_id, $this->articulo->id));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El precio del combo
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 El precio y el costo de un combo salen de `combos`, no del payload.
     *
     * El molde de `attach_combos()` es `attach_promociones_vinoteca()`, que guarda
     * `$promo['final_price']` — el numero del navegador. Una coleccion nueva no tiene por que
     * nacer con el agujero adentro: `combos.price` es un precio fijo, igual para todos los
     * compradores, sin listas ni recargos de por medio, asi que resolverlo del lado del servidor
     * cuesta UNA query para todo el carrito.
     */
    public function test_el_precio_de_un_combo_sale_de_la_base_y_no_del_payload()
    {
        $combo = $this->combo($this->comercio);

        $respuesta = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($combo, 2, [
                'price'       => self::PRECIO_TRUCHO,
                'final_price' => self::PRECIO_TRUCHO,
                'cost'        => self::PRECIO_TRUCHO,
            ])],
        ]);

        $respuesta->assertStatus(201);
        $cart_id = (int) $respuesta->json('cart.id');

        $filas = $this->combosGuardados($cart_id);

        $this->assertCount(1, $filas, 'el combo publicado del comercio si tiene que entrar');
        $this->assertSame(self::PRECIO_COMBO, (float) $filas[0]->price,
            'el precio guardado es combos.price, no el del payload');
        $this->assertSame(self::COSTO_COMBO, (float) $filas[0]->cost,
            'el costo tambien sale de la base: es lo que despues alimenta el margen del pedido');
        $this->assertSame(2.0, (float) $filas[0]->amount);

        $this->assertSame(self::PRECIO_COMBO * 2, $this->totalGuardado($cart_id),
            'y el total se arma con el precio de la base');
    }

    /**
     * 🔴 Un combo con "Mostrar en la tienda" APAGADO no entra al carrito.
     *
     * `combos.online` tiene default 0 y es el check del ABM de empresa: el comerciante puede tener
     * combos armados para el mostrador que no quiere publicar, con precios que no son los de la
     * tienda. Que la home no los liste no alcanza — el id es enumerable y el carrito es publico.
     *
     * El descarte es en silencio, igual que hace `attachArticles` con las lineas que no le
     * corresponden: el carrito se guarda, sin esa linea.
     */
    public function test_un_combo_sin_publicar_no_se_puede_agregar_al_carrito()
    {
        $escondido = $this->combo($this->comercio, ['name' => 'Combo Sin Publicar', 'online' => 0]);

        $respuesta = $this->crearCarrito($this->comercio, [
            'articles' => [$this->lineaDelPayload($this->articulo, 1)],
            'combos'   => [$this->lineaDeComboDelPayload($escondido, 1)],
        ]);

        $respuesta->assertStatus(201);
        $cart_id = (int) $respuesta->json('cart.id');

        $this->assertCount(0, $this->combosGuardados($cart_id),
            'un combo con online = 0 no entra al carrito aunque su id venga en el payload');

        $this->assertSame(self::PRECIO_NORMAL, $this->totalGuardado($cart_id),
            'y el total no lo suma: es solo el articulo');
    }

    /**
     * 🔴 Un combo de OTRO comercio tampoco.
     *
     * El comercio contra el que se busca sale de `$cart->user_id` —que lo escribio el servidor al
     * crear el carrito— y no del payload. Si saliera del payload, el atacante elegiria contra que
     * comercio se resuelve: es el mismo filtro de aislamiento que ya tiene `attachArticles` para
     * la oferta personalizada.
     *
     * Y esta base es COMPARTIDA entre el ERP y la tienda del mismo cliente, asi que "otro
     * comercio" no es hipotetico: en las bases compartidas viejas hay decenas de comercios adentro
     * de la misma base.
     */
    public function test_un_combo_de_otro_comercio_no_se_puede_agregar_al_carrito()
    {
        $otro_comercio = $this->comercioConTienda();
        $ajeno = $this->combo($otro_comercio, ['name' => 'Combo Ajeno', 'price' => 1.00]);

        $respuesta = $this->crearCarrito($this->comercio, [
            'articles' => [$this->lineaDelPayload($this->articulo, 1)],
            'combos'   => [$this->lineaDeComboDelPayload($ajeno, 1)],
        ]);

        $respuesta->assertStatus(201);
        $cart_id = (int) $respuesta->json('cart.id');

        $this->assertCount(0, $this->combosGuardados($cart_id),
            'el combo de otro comercio no entra: el comercio sale del carrito, no del payload');

        $this->assertSame(self::PRECIO_NORMAL, $this->totalGuardado($cart_id));
    }

    /**
     * La contraprueba de los dos de arriba, y sin ella serian adornos: el combo publicado DEL
     * comercio del carrito si entra.
     *
     * Si `attach_combos()` dejara de colgar cualquier cosa —un `where` de mas, un `keyBy` mal—,
     * los dos casos anteriores seguirian verdes sin probar nada. Este se pone rojo primero.
     */
    public function test_el_combo_publicado_del_comercio_si_entra()
    {
        $combo = $this->combo($this->comercio);

        $respuesta = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($combo, 1)],
        ]);

        $respuesta->assertStatus(201);

        $this->assertCount(1, $this->combosGuardados((int) $respuesta->json('cart.id')));
    }

    /**
     * Y el mismo payload con los TRES casos juntos deja entrar solo al que corresponde.
     *
     * Es el caso que prueba que el descarte es por linea y no aborta el resto del carrito: un
     * combo ajeno en el payload no puede dejar afuera al combo legitimo que viene al lado.
     */
    public function test_en_un_payload_mezclado_entra_solo_el_combo_que_corresponde()
    {
        $bueno     = $this->combo($this->comercio, ['name' => 'Combo Bueno']);
        $escondido = $this->combo($this->comercio, ['name' => 'Combo Escondido', 'online' => 0]);
        $ajeno     = $this->combo($this->comercioConTienda(), ['name' => 'Combo Ajeno']);

        $respuesta = $this->crearCarrito($this->comercio, [
            'combos' => [
                $this->lineaDeComboDelPayload($escondido, 1),
                $this->lineaDeComboDelPayload($bueno, 1),
                $this->lineaDeComboDelPayload($ajeno, 1),
            ],
        ]);

        $respuesta->assertStatus(201);

        $filas = $this->combosGuardados((int) $respuesta->json('cart.id'));

        $this->assertCount(1, $filas);
        $this->assertSame($bueno->id, (int) $filas[0]->combo_id);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Lo que NO viaja al navegador
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 El COSTO del combo no sale en ninguna respuesta publica.
     *
     * `cost` es lo que al comerciante le cuesta el combo, y la tienda es publica: cualquier
     * visitante que abra las devtools en la home veria el margen de cada uno. La tienda ya no
     * publica el costo de un articulo (`Cart::articles()` no trae `cost` en el pivot), asi que
     * esto es mantener el mismo criterio en una coleccion nueva — con `$hidden` en el modelo, que
     * cubre los dos lugares por los que un combo sale: la home y el carrito.
     *
     * ⚠️ `$hidden` toca la serializacion, no la lectura: el caso de arriba ya fijo que
     * `attach_combos()` sigue guardando el costo en la fila, que es lo que despues alimenta el
     * margen del pedido.
     */
    public function test_el_costo_del_combo_no_viaja_al_navegador()
    {
        $combo = $this->combo($this->comercio);

        /* 1. Por la home, que es publica y anonima. */
        $de_la_home = $this->json('GET', '/api/articles/featured-last-uploads/'.$this->comercio->id.'?page=1')
                            ->assertStatus(200)
                            ->json('combos');

        $this->assertCount(1, $de_la_home, 'el combo publicado tiene que estar en la home');
        $this->assertArrayNotHasKey('cost', $de_la_home[0],
            'el costo del combo no puede viajar en la home: se lee con las devtools');
        $this->assertEquals(self::PRECIO_COMBO, (float) $de_la_home[0]['final_price'],
            'y el precio si viaja, con el nombre que el SPA usa para todo lo comprable');

        /* 2. Y por el carrito. */
        $respuesta = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($combo, 1)],
        ]);

        $respuesta->assertStatus(201);

        $del_carrito = $respuesta->json('cart.combos');

        $this->assertCount(1, $del_carrito);
        $this->assertArrayNotHasKey('cost', $del_carrito[0],
            'tampoco en el carrito');
        $this->assertArrayNotHasKey('cost', $del_carrito[0]['pivot'],
            'ni en el pivot: Cart::combos() no lo declara en withPivot');

        /* Y la contraprueba de que el costo SI esta en la base: sin esto, el caso daria verde
           contra un `attach_combos` que no guardara nada. */
        $this->assertSame(self::COSTO_COMBO,
            (float) DB::table('cart_combo')->where('cart_id', $respuesta->json('cart.id'))->value('cost'));
    }
}
