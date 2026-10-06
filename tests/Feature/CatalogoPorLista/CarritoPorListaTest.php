<?php

namespace Tests\Feature\CatalogoPorLista;

use App\Article;
use App\PromocionVinoteca;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (d) El CARRITO no guarda lo que el comprador no puede ver por su lista (mision
 * catalogo-por-lista-tienda, 5/10/2026). Contrato C3 con tienda-spa:
 *
 *   - `POST /api/carts` y `PUT /api/carts` descartan esas lineas y suman a la respuesta
 *     `articulos_no_disponibles: [{id, name}]` SOLO cuando hubo alguna.
 *   - Sin descartes la respuesta es byte a byte la de hoy: la clave no aparece, ni vacia.
 *   - `PUT` decide el descarte antes de tocar el carrito, y si no queda ninguna linea se comporta
 *     como el carrito vacio de hoy (se borra y responde `cart: null`).
 *   - `POST` tambien: si la lista descarta TODO lo que se pedia y el payload no trae promociones de
 *     vinoteca ni combos, no crea el carrito y responde `200 {cart: null, articulos_no_disponibles}`
 *     (cambio de la revision independiente, B6: antes quedaba un carrito vivo, vacio y con total 0).
 *     Sin descartes, el POST de siempre (201, incluido el de un carrito sin lineas).
 *   - Los ids de las lineas se normalizan una sola vez, para decidir y para escribir: lo que no es un
 *     entero se descarta sin aviso (B5).
 *
 * El caso real que lo motiva: un visitante arma el carrito con la lista publica, se loguea como
 * mayorista, y el SPA vuelve a guardar el carrito con la sesion nueva.
 */
class CarritoPorListaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCatalogoPorLista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();
        $this->armarFerretotal();
    }

    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * 🔴 El mayorista guarda un carrito con los tres articulos: solo el habilitado queda, y los
     * otros dos vuelven en `articulos_no_disponibles`, con el nombre de la base y en el orden del
     * payload.
     */
    public function test_el_carrito_del_mayorista_descarta_lo_no_habilitado_y_lo_avisa()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $respuesta = $this->crearCarrito([
            $this->linea($this->habilitado, 2, 1000),
            $this->linea($this->sin_marcar, 1, 2000),
            $this->linea($this->deshabilitado, 1, 3000),
        ]);

        $respuesta->assertStatus(201);

        $this->assertSame([
            ['id' => $this->sin_marcar->id, 'name' => $this->sin_marcar->name],
            ['id' => $this->deshabilitado->id, 'name' => $this->deshabilitado->name],
        ], $respuesta->json('articulos_no_disponibles'));

        $cart_id = (int) $respuesta->json('cart.id');

        $this->assertSame([$this->habilitado->id], $this->idsGuardados($cart_id), 'solo se guarda el habilitado');
        $this->assertSame([$this->habilitado->id], $this->idsDe($respuesta->json('cart.articles')), 'y es lo unico que vuelve');
        $this->assertEquals(2000, DB::table('carts')->where('id', $cart_id)->value('total'),
            'el total es el del habilitado solo: lo descartado no se cobra');
    }

    /**
     * 🔴 Sin descartes la respuesta no trae la clave nueva, ni vacia: es la de hoy. Vale para el
     * visitante (lista sin restriccion) y para el mayorista que solo pide lo habilitado.
     */
    public function test_sin_descartes_la_respuesta_es_la_de_siempre()
    {
        $this->comoVisitante();

        $visitante = $this->crearCarrito([
            $this->linea($this->habilitado, 1, 1500),
            $this->linea($this->sin_marcar, 1, 2500),
            $this->linea($this->deshabilitado, 1, 3500),
        ]);

        $visitante->assertStatus(201);
        $this->assertSame(['cart'], array_keys($visitante->json()), 'visitante: la unica clave es cart');
        $this->assertSame($this->losTres(), $this->idsGuardados((int) $visitante->json('cart.id')));

        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $mayorista = $this->crearCarrito([$this->linea($this->habilitado, 1, 1000)]);

        $mayorista->assertStatus(201);
        $this->assertSame(['cart'], array_keys($mayorista->json()), 'mayorista sin descartes: la unica clave es cart');

        $actualizado = $this->putJson('/api/carts', [
            'id'                   => (int) $mayorista->json('cart.id'),
            'articles'             => [$this->linea($this->habilitado, 3, 1000)],
            'promociones_vinoteca' => [],
        ]);

        $actualizado->assertStatus(200);
        $this->assertSame(['cart'], array_keys($actualizado->json()), 'PUT sin descartes: la unica clave es cart');
    }

    /**
     * 🔴 Un POST con SOLO lineas no habilitadas (y sin promociones de vinoteca ni combos) NO crea el
     * carrito: responde `200 {cart: null, articulos_no_disponibles: [...]}`, igual que el PUT que se
     * queda sin lineas (que ya borraba el carrito y respondia `cart: null`).
     *
     * ── Por que cambio este caso (hallazgo B6 de la revision independiente) ─────────────────────────
     *
     * Este test fijaba lo contrario como "intencional": el POST creaba el carrito igual, sin lineas y con
     * total 0, y la respuesta traia ese carrito vacio mas el aviso. Era el diseño original de la mision,
     * y la revision lo marco como defecto: un carrito que el comprador nunca armo quedaba vivo —
     * `lastCart` lo devolvia con `has_last_cart: true` y cero articulos— y el propio comentario de
     * `update` llama defecto a ese mismo estado. Cambio la decision de diseño, no una aserción para que
     * pase: sin ninguna linea que guardar no hay carrito que crear, y el comprador recibe el aviso de lo
     * que no pudo agregar.
     */
    public function test_un_post_con_solo_no_habilitados_no_crea_el_carrito_y_lo_avisa()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $carritos_antes = DB::table('carts')->count();

        $respuesta = $this->crearCarrito([$this->linea($this->sin_marcar, 1, 2000)]);

        $respuesta->assertStatus(200);
        $this->assertSame([
            'cart'                     => null,
            'articulos_no_disponibles' => [['id' => $this->sin_marcar->id, 'name' => $this->sin_marcar->name]],
        ], $respuesta->json());

        $this->assertSame($carritos_antes, DB::table('carts')->count(), 'no se creo ningun carrito');

        /* Y no queda un "ultimo carrito" fantasma que el SPA reconstruya al volver. */
        $this->json('GET', '/api/carts/last-cart/'.$this->comercio->id)
            ->assertStatus(200)
            ->assertExactJson(['has_last_cart' => false]);
    }

    /**
     * Con una promocion de vinoteca en el payload el carrito SI se crea (le queda la promo, que no la
     * decide la lista) y el articulo se avisa: es el espejo del PUT.
     */
    public function test_un_post_con_una_promo_y_solo_no_habilitados_crea_el_carrito_con_la_promo()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $promo = PromocionVinoteca::create([
            'name'        => 'Promo Catalogo Test',
            'slug'        => 'promo-catalogo-test-'.Str::random(10),
            'user_id'     => $this->comercio->id,
            'online'      => 1,
            'stock'       => 10,
            'final_price' => 700,
            'cost'        => 0,
        ]);

        $respuesta = $this->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart'        => [
                'articles'             => [$this->linea($this->sin_marcar, 1, 2000)],
                'promociones_vinoteca' => [[
                    'id'          => $promo->id,
                    'user_id'     => $this->comercio->id,
                    'name'        => $promo->name,
                    'final_price' => 700,
                    'cost'        => 0,
                    'pivot'       => ['amount' => 1, 'notes' => null],
                ]],
            ],
        ]);

        $respuesta->assertStatus(201);
        $this->assertNotNull($respuesta->json('cart'), 'el carrito se crea: tiene la promo');
        $this->assertSame([['id' => $this->sin_marcar->id, 'name' => $this->sin_marcar->name]], $respuesta->json('articulos_no_disponibles'));

        $cart_id = (int) $respuesta->json('cart.id');
        $this->assertSame([], $this->idsGuardados($cart_id));
        $this->assertEquals(700, DB::table('carts')->where('id', $cart_id)->value('total'));
    }

    /**
     * Sin descartes, un POST de un carrito sin lineas sigue siendo el de siempre: se crea (vacio) con
     * 201 y sin la clave nueva. Lo que cambia con la lista es solo lo que se descarta.
     */
    public function test_un_post_sin_lineas_y_sin_descartes_sigue_creando_el_carrito_vacio()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $respuesta = $this->crearCarrito([]);

        $respuesta->assertStatus(201);
        $this->assertSame(['cart'], array_keys($respuesta->json()));
        $this->assertSame([], $this->idsGuardados((int) $respuesta->json('cart.id')));
    }

    /**
     * 🔴 Un PUT con SOLO lineas no habilitadas: el carrito se borra igual que con un payload vacio
     * —no queda vivo con total 0 y sin lineas, sin que el comprador pueda deshacerse de el— y la
     * respuesta es la del carrito vacio de hoy mas el aviso.
     */
    public function test_un_put_con_solo_no_habilitados_borra_el_carrito_como_el_carrito_vacio()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $cart_id = (int) $this->crearCarrito([$this->linea($this->habilitado, 1, 1000)])->json('cart.id');

        $respuesta = $this->putJson('/api/carts', [
            'id'                   => $cart_id,
            'articles'             => [$this->linea($this->sin_marcar, 1, 2000)],
            'promociones_vinoteca' => [],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame([
            'cart'                     => null,
            'articulos_no_disponibles' => [['id' => $this->sin_marcar->id, 'name' => $this->sin_marcar->name]],
        ], $respuesta->json());

        $this->assertFalse(DB::table('carts')->where('id', $cart_id)->exists(), 'el carrito se borro, como el vacio de hoy');
        $this->assertSame([], $this->idsGuardados($cart_id));
    }

    /** Un PUT mezclado: queda lo habilitado (con su cantidad) y se avisa lo otro. */
    public function test_un_put_mezclado_guarda_solo_lo_habilitado()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $cart_id = (int) $this->crearCarrito([$this->linea($this->habilitado, 1, 1000)])->json('cart.id');

        $respuesta = $this->putJson('/api/carts', [
            'id'                   => $cart_id,
            'articles'             => [
                $this->linea($this->deshabilitado, 4, 3000),
                $this->linea($this->habilitado, 3, 1000),
            ],
            'promociones_vinoteca' => [],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame([['id' => $this->deshabilitado->id, 'name' => $this->deshabilitado->name]], $respuesta->json('articulos_no_disponibles'));
        $this->assertSame([$this->habilitado->id], $this->idsGuardados($cart_id));
        $this->assertEquals(3, DB::table('article_cart')->where('cart_id', $cart_id)->value('amount'));
        $this->assertEquals(3000, DB::table('carts')->where('id', $cart_id)->value('total'));
    }

    /**
     * Un PUT con una promocion de vinoteca y solo articulos no habilitados: el carrito NO se borra
     * (le queda la promo, que no la decide la lista) y se avisa el articulo.
     */
    public function test_un_put_con_una_promo_y_solo_no_habilitados_conserva_la_promo()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $promo = PromocionVinoteca::create([
            'name'        => 'Promo Catalogo Test',
            'slug'        => 'promo-catalogo-test-'.Str::random(10),
            'user_id'     => $this->comercio->id,
            'online'      => 1,
            'stock'       => 10,
            'final_price' => 700,
            'cost'        => 0,
        ]);

        $cart_id = (int) $this->crearCarrito([$this->linea($this->habilitado, 1, 1000)])->json('cart.id');

        $respuesta = $this->putJson('/api/carts', [
            'id'                   => $cart_id,
            'articles'             => [$this->linea($this->sin_marcar, 1, 2000)],
            'promociones_vinoteca' => [[
                'id'          => $promo->id,
                'user_id'     => $this->comercio->id,
                'name'        => $promo->name,
                'final_price' => 700,
                'cost'        => 0,
                'pivot'       => ['amount' => 1, 'notes' => null],
            ]],
        ]);

        $respuesta->assertStatus(200);
        $this->assertNotNull($respuesta->json('cart'), 'el carrito sigue vivo: tiene la promo');
        $this->assertSame([['id' => $this->sin_marcar->id, 'name' => $this->sin_marcar->name]], $respuesta->json('articulos_no_disponibles'));
        $this->assertSame([], $this->idsGuardados($cart_id));
        $this->assertEquals(700, DB::table('carts')->where('id', $cart_id)->value('total'));
    }

    /**
     * El visitante que se loguea como mayorista: el carrito que armo con la lista publica se vuelve a
     * guardar con la sesion nueva (es lo que hace el SPA) y ahi se limpia.
     */
    public function test_el_visitante_que_se_loguea_como_mayorista_ve_su_carrito_limpio()
    {
        $this->comoVisitante();

        $cart_id = (int) $this->crearCarrito([
            $this->linea($this->habilitado, 1, 1500),
            $this->linea($this->sin_marcar, 1, 2500),
        ])->json('cart.id');

        $this->assertSame($this->ordenados([$this->habilitado->id, $this->sin_marcar->id]), $this->idsGuardados($cart_id));

        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $respuesta = $this->withSession(['carritos_propios' => [$cart_id]])->putJson('/api/carts', [
            'id'                   => $cart_id,
            'articles'             => [
                $this->linea($this->habilitado, 1, 1500),
                $this->linea($this->sin_marcar, 1, 2500),
            ],
            'promociones_vinoteca' => [],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame([['id' => $this->sin_marcar->id, 'name' => $this->sin_marcar->name]], $respuesta->json('articulos_no_disponibles'));
        $this->assertSame([$this->habilitado->id], $this->idsGuardados($cart_id));
    }

    /**
     * 🔴 Una linea con un id que NO es un entero no se guarda (hallazgo B5 de la revision independiente).
     *
     * El chequeo de la lista normalizaba los ids con `is_numeric` + `intval` y la escritura hacia
     * `attach($article['id'])` crudo: dos reglas para el mismo dato. `"id": true` no se chequeaba (no es
     * numerico) y se guardaba como `article_id = 1`; `"id": N.5` se chequeaba como N —el habilitado— y
     * MySQL guardaba otro (N+1: el no habilitado de al lado). Ahora el id se normaliza una sola vez, para
     * decidir y para escribir, y lo que no es un entero se descarta sin hacer ruido.
     *
     * Lo legitimo no cambia: la linea valida de adelante se guarda igual.
     */
    public function test_una_linea_con_un_id_que_no_es_un_entero_no_se_guarda()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $valida = $this->linea($this->habilitado, 1, 1000);

        $respuesta = $this->crearCarrito([
            $valida,
            array_merge($valida, ['id' => true]),
            array_merge($valida, ['id' => $this->habilitado->id + 0.5]),
            array_merge($valida, ['id' => (string) $this->habilitado->id.'abc']),
            array_merge($valida, ['id' => null]),
            array_diff_key($valida, ['id' => 1]),
        ]);

        $respuesta->assertStatus(201);
        $this->assertArrayNotHasKey('articulos_no_disponibles', $respuesta->json(), 'no hay nada reportable: no son articulos');

        $cart_id = (int) $respuesta->json('cart.id');

        $this->assertSame([$this->habilitado->id], $this->idsGuardados($cart_id), 'solo la linea valida');
        $this->assertEquals(1000, DB::table('carts')->where('id', $cart_id)->value('total'));

        /* Lo mismo en el PUT. */
        $actualizado = $this->putJson('/api/carts', [
            'id'                   => $cart_id,
            'articles'             => [
                $valida,
                array_merge($valida, ['id' => true]),
                array_merge($valida, ['id' => $this->habilitado->id + 0.5]),
            ],
            'promociones_vinoteca' => [],
        ]);

        $actualizado->assertStatus(200);
        $this->assertSame([$this->habilitado->id], $this->idsGuardados($cart_id), 'el PUT tambien');
    }

    /**
     * Un id numerico escrito como texto es el mismo id: se chequea y se guarda como entero. El no
     * habilitado que llega como `"2917"` se descarta igual que si llegara como `2917`, y vuelve en el
     * aviso con su id entero; el habilitado se guarda con su id entero.
     */
    public function test_un_id_numerico_como_texto_es_el_mismo_id()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $respuesta = $this->crearCarrito([
            array_merge($this->linea($this->habilitado, 1, 1000), ['id' => (string) $this->habilitado->id]),
            array_merge($this->linea($this->sin_marcar, 1, 2000), ['id' => (string) $this->sin_marcar->id]),
        ]);

        $respuesta->assertStatus(201);
        $this->assertSame(
            [['id' => $this->sin_marcar->id, 'name' => $this->sin_marcar->name]],
            $respuesta->json('articulos_no_disponibles')
        );
        $this->assertSame([$this->habilitado->id], $this->idsGuardados((int) $respuesta->json('cart.id')));
    }

    /**
     * Una linea tal cual la manda el SPA.
     *
     * @param  \App\Article  $articulo
     * @param  int  $cantidad
     * @param  float  $precio  El `final_price` que la API le mostro a ese comprador.
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

    /** @return \Illuminate\Testing\TestResponse */
    private function crearCarrito(array $articulos)
    {
        return $this->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart'        => [
                'articles'             => $articulos,
                'promociones_vinoteca' => [],
            ],
        ]);
    }

    /** @return array Los ids de articulo guardados en el carrito, ordenados. */
    private function idsGuardados($cart_id)
    {
        return $this->ordenados(DB::table('article_cart')->where('cart_id', $cart_id)->pluck('article_id')->all());
    }
}
