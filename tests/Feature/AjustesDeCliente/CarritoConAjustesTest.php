<?php

namespace Tests\Feature\AjustesDeCliente;

use App\Http\Controllers\Helpers\AjustesDeClienteHelper;
use App\Http\Controllers\Helpers\HomeHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PromocionPersonalizada\CreaElEsquemaDeOfertas;
use Tests\TestCase;

/**
 * El precio que el carrito GUARDA con los descuentos y recargos del cliente (mision
 * descuentos-recargos-por-cliente, 23/9/2026). Le pega a los endpoints de verdad.
 *
 * 🔴 La invariante que se fija: `article_cart.price` (y el de promos y combos) es el precio que
 * paga el comprador, con el factor aplicado UNA SOLA VEZ. El riesgo principal es el doble
 * factor: el SPA reenvia el articulo con `final_price` YA ajustado (945) y `get_price()` toma su
 * base del payload. Si el carrito volviera a multiplicar, guardaria 945 × 0,945 = 893,03.
 *
 * ⚠️ Sin DatabaseTransactions: el trait crea tablas (DDL, commit implicito). Se limpia a mano.
 */
class CarritoConAjustesTest extends TestCase
{
    use ArmaAjustesDeCliente;
    use CreaElEsquemaDeOfertas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armarAjustes();
    }

    protected function tearDown(): void
    {
        $this->limpiarAjustes();
        $this->limpiarOfertasDe(null);

        parent::tearDown();
    }

    /** 🔴 El caso del plan: 10% + 5% sobre 1000 -> la linea guarda 945, no 893,03. */
    public function test_el_carrito_guarda_945_y_no_893()
    {
        list($comprador, $articulo) = $this->compradorConDescuentoYRecargo(1000);

        $linea = $this->lineaDelSpa($articulo);

        /* Precondicion: el payload llega con el precio YA ajustado, que es lo que hace peligroso
           al carrito. Si esto no fuera asi, el caso no probaria el doble factor. */
        $this->assertSame(945.0, (float) $linea['final_price']);

        $cart_id = $this->guardarCarrito([$linea]);

        $this->assertSame(945.0, $this->precioDeLaLinea('article_cart', $cart_id));
        $this->assertSame(945.0, (float) DB::table('carts')->where('id', $cart_id)->value('total'));
    }

    /**
     * 🔴 Lo mismo, pero midiendo `get_price()` SOLO.
     *
     * Por que no alcanza con el caso de arriba: se midio que sacando el "desajuste" de get_price()
     * —o sea, con el doble factor adentro— el caso de punta a punta sigue verde, porque despues
     * corre set_total() y la resincronizacion de ajustes reescribe la linea con el precio de la
     * base. Pero hay caminos donde el numero que queda es el de get_price() (con la extension de
     * rangos por categoria la resincronizacion no toca articulos), asi que se fija aca.
     */
    public function test_get_price_aplica_el_factor_una_sola_vez()
    {
        list($comprador, $articulo) = $this->compradorConDescuentoYRecargo(1000);

        $linea = $this->lineaDelSpa($articulo);

        $this->olvidarMemorias();

        $precio = \App\Http\Controllers\Helpers\CartHelper::get_price([$linea], $linea, false, collect(), $this->comercio->id);

        $this->assertSame(945.0, (float) $precio, 'el factor va una sola vez: 945, no 893,03');
    }

    /**
     * Un SPA viejo (o un payload guardado de antes) manda el articulo sin la base: su
     * `final_price` es el de lista porque este servidor nunca lo ajusto. Se cobra 945 igual.
     */
    public function test_un_payload_sin_la_base_tambien_guarda_945()
    {
        list($comprador, $articulo) = $this->compradorConDescuentoYRecargo(1000);

        $linea = $this->lineaDelSpa($articulo);
        $linea['final_price'] = 1000;
        unset($linea['precio_sin_ajustes_de_cliente'], $linea['ajustes_de_cliente']);

        $cart_id = $this->guardarCarrito([$linea]);

        $this->assertSame(945.0, $this->precioDeLaLinea('article_cart', $cart_id));
    }

    /**
     * 🔴 Sin contrato, la linea que ESTE servidor ajusto antes vuelve a su base. El comprador
     * armo la linea logueado (945, base 1000) y despues cerro sesion: el SPA conserva el objeto y
     * lo manda como invitado. Sin cliente no hay factor, y cobrar 945 seria regalar el descuento.
     */
    public function test_sin_contrato_una_linea_ajustada_vuelve_a_su_base()
    {
        list($comprador, $articulo) = $this->compradorConDescuentoYRecargo(1000);

        $linea = $this->lineaDelSpa($articulo);
        $this->assertSame(945.0, (float) $linea['final_price']);

        $this->app['auth']->guard('buyer')->logout();

        $cart_id = $this->guardarCarrito([$linea]);

        $this->assertSame(1000.0, $this->precioDeLaLinea('article_cart', $cart_id));
    }

    /**
     * Los porcentajes salen de la BASE, no del payload: un payload con la lista de ajustes
     * inventada (un 90%) no cambia nada.
     */
    public function test_los_porcentajes_del_payload_no_se_leen()
    {
        list($comprador, $articulo) = $this->compradorConDescuentoYRecargo(1000);

        $linea = $this->lineaDelSpa($articulo);
        $linea['ajustes_de_cliente'] = [['id' => 1, 'tipo' => 'descuento', 'name' => 'x', 'percentage' => 90]];

        $cart_id = $this->guardarCarrito([$linea]);

        $this->assertSame(945.0, $this->precioDeLaLinea('article_cart', $cart_id));
    }

    /** Cambiar la cantidad con "Actualizar" mantiene el precio ajustado y el total lo sigue. */
    public function test_cambiar_la_cantidad_mantiene_945()
    {
        list($comprador, $articulo) = $this->compradorConDescuentoYRecargo(1000);

        $cart_id = $this->guardarCarrito([$this->lineaDelSpa($articulo)]);

        $this->actualizarCantidad($cart_id, $articulo->id, 3);

        $this->assertSame(945.0, $this->precioDeLaLinea('article_cart', $cart_id));
        $this->assertSame(2835.0, (float) DB::table('carts')->where('id', $cart_id)->value('total'));
    }

    /**
     * 🔴 El ERP desvincula el descuento con el carrito ya armado: al recalcular, la linea vuelve a
     * 1000 × 1,05 = 1050 (queda solo el recargo). Es el camino de "Actualizar", que no pasa por
     * get_price(): lo corrige la resincronizacion de set_total().
     */
    public function test_desvincular_el_descuento_con_el_carrito_armado_vuelve_a_1050()
    {
        $comprador = $this->compradorVinculado();
        $descuento = $this->crearDescuento(10);
        $this->vincular($comprador, [$descuento], [$this->crearRecargo(5)]);
        $articulo = $this->crearArticulo(1000);
        $this->actingAs($comprador, 'buyer');

        $cart_id = $this->guardarCarrito([$this->lineaDelSpa($articulo)]);
        $this->assertSame(945.0, $this->precioDeLaLinea('article_cart', $cart_id));

        $this->desvincularDescuento($comprador, $descuento);

        $this->actualizarCantidad($cart_id, $articulo->id, 1);

        $this->assertSame(1050.0, $this->precioDeLaLinea('article_cart', $cart_id));
        $this->assertSame(1050.0, (float) DB::table('carts')->where('id', $cart_id)->value('total'));
    }

    /** El ERP cambia el porcentaje del descuento: la linea se recalcula (1000 × 0,8 × 1,05). */
    public function test_cambiar_el_porcentaje_recalcula_la_linea()
    {
        $comprador = $this->compradorVinculado();
        $descuento = $this->crearDescuento(10);
        $this->vincular($comprador, [$descuento], [$this->crearRecargo(5)]);
        $articulo = $this->crearArticulo(1000);
        $this->actingAs($comprador, 'buyer');

        $cart_id = $this->guardarCarrito([$this->lineaDelSpa($articulo)]);

        DB::table('discounts')->where('id', $descuento)->update(['percentage' => 20]);
        $this->olvidarMemorias();

        $this->actualizarCantidad($cart_id, $articulo->id, 1);

        $this->assertSame(840.0, $this->precioDeLaLinea('article_cart', $cart_id));
    }

    /**
     * Guardar el carrito despues de desvincular, con el articulo que el SPA tenia en pantalla de
     * ANTES (final_price 945 y base 1000): el precio sale de la base y del ajuste de hoy, 1050.
     */
    public function test_guardar_con_un_payload_viejo_despues_de_desvincular_usa_los_ajustes_de_hoy()
    {
        $comprador = $this->compradorVinculado();
        $descuento = $this->crearDescuento(10);
        $this->vincular($comprador, [$descuento], [$this->crearRecargo(5)]);
        $articulo = $this->crearArticulo(1000);
        $this->actingAs($comprador, 'buyer');

        $linea_vieja = $this->lineaDelSpa($articulo);

        $this->desvincularDescuento($comprador, $descuento);

        $cart_id = $this->guardarCarrito([$linea_vieja]);

        $this->assertSame(1050.0, $this->precioDeLaLinea('article_cart', $cart_id));
    }

    /** Oferta 20% + descuento 10% en el carrito: 720, por la rama de la oferta de get_price(). */
    public function test_oferta_mas_descuento_en_el_carrito_da_720()
    {
        $this->crearEsquemaDeOfertasSiFalta();

        $comprador = $this->compradorVinculado();
        $this->vincular($comprador, [$this->crearDescuento(10)]);
        $articulo = $this->crearArticulo(1000);

        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => $comprador->comercio_city_client_id,
            'article_id' => $articulo->id,
            'porcentaje' => 20,
        ]);

        $this->actingAs($comprador, 'buyer');

        $cart_id = $this->guardarCarrito([$this->lineaDelSpa($articulo)]);

        $this->assertSame(720.0, $this->precioDeLaLinea('article_cart', $cart_id));

        /* Y "Actualizar" no lo mueve: la resincronizacion de ofertas tambien aplica el factor. */
        $this->actualizarCantidad($cart_id, $articulo->id, 2);

        $this->assertSame(720.0, $this->precioDeLaLinea('article_cart', $cart_id));
    }

    /**
     * Un tramo por cantidad del articulo (sale de la base, sin ajustar) tambien lleva el factor:
     * tramo de 800 desde 5 unidades -> 800 × 0,945 = 756.
     */
    public function test_el_tramo_por_articulo_lleva_el_factor()
    {
        list($comprador, $articulo) = $this->compradorConDescuentoYRecargo(1000);

        $tramo_id = DB::table('article_price_ranges')->insertGetId([
            'article_id' => $articulo->id,
            'modo'       => 'Mayor o igual',
            'amount'     => 5,
            'price'      => 800,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        $this->anotar('article_price_ranges', $tramo_id);

        $cart_id = $this->guardarCarrito([$this->lineaDelSpa($articulo, 5)]);

        $this->assertSame(756.0, $this->precioDeLaLinea('article_cart', $cart_id));

        /* Bajar a 1 sale del tramo: vuelve al precio normal ajustado, 945. */
        $this->actualizarCantidad($cart_id, $articulo->id, 1);

        $this->assertSame(945.0, $this->precioDeLaLinea('article_cart', $cart_id));
    }

    /**
     * 🔴 EL TRAMO POR PORCENTAJE MUERDE LA BASE CRUDA, Y RECIEN DESPUES VA EL FACTOR
     * (mision oferta-por-cantidad-porcentaje, 24/9/2026).
     *
     * Es el unico lugar donde la forma nueva de la oferta por cantidad puede equivocarse de
     * ESCALA, y el error seria invisible sin ajustes de cliente: sin contrato el precio crudo y el
     * ajustado son el mismo numero, asi que toda la carpeta `CombosYRangos` daria verde con la
     * cuenta hecha sobre el numero equivocado.
     *
     * Las dos cadenas posibles, con 20% sobre un articulo de $1.000 y un comprador con 10% de
     * descuento y 5% de recargo (factor 0,945):
     *
     *   · CORRECTA:   1000 × 0,80 = 800   ->  × 0,945 = 756,00
     *   · EQUIVOCADA: 945  × 0,80 = 756   ->  × 0,945 = 714,42   (el factor va dos veces)
     *
     * O sea el mismo defecto de doble factor que documenta el bloque largo de `get_price()`, por
     * un eslabon nuevo. Y el 756 no es casual: es exactamente lo que da el tramo de PRECIO FIJO de
     * $800 del caso de arriba, que es la comprobacion de que las dos formas de la oferta terminan
     * en la misma escala.
     */
    public function test_el_tramo_por_porcentaje_muerde_la_base_cruda_y_el_factor_va_una_sola_vez()
    {
        list($comprador, $articulo) = $this->compradorConDescuentoYRecargo(1000);

        $tramo_id = DB::table('article_price_ranges')->insertGetId([
            'article_id' => $articulo->id,
            'modo'       => 'Mayor o igual',
            'amount'     => 5,
            'price'      => null,
            'porcentaje' => 20,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        $this->anotar('article_price_ranges', $tramo_id);

        $cart_id = $this->guardarCarrito([$this->lineaDelSpa($articulo, 5)]);

        $this->assertSame(756.0, $this->precioDeLaLinea('article_cart', $cart_id),
            '1000 menos 20% son 800, y recien ahi el factor del cliente: 756. Nunca 714,42.');

        /* Y "Actualizar" no lo mueve: la resincronizacion resuelve la MISMA base cruda. */
        $this->actualizarCantidad($cart_id, $articulo->id, 6);

        $this->assertSame(756.0, $this->precioDeLaLinea('article_cart', $cart_id));

        /* Bajar a 1 sale del tramo: vuelve al precio normal ajustado, 945. */
        $this->actualizarCantidad($cart_id, $articulo->id, 1);

        $this->assertSame(945.0, $this->precioDeLaLinea('article_cart', $cart_id));
    }

    /** Decision 2: la promocion de vinoteca tambien lleva el factor, una vez. 2000 -> 1890. */
    public function test_la_promo_en_el_carrito_guarda_el_precio_ajustado()
    {
        list($comprador, $articulo) = $this->compradorConDescuentoYRecargo(1000);

        $promo_id = $this->crearPromo(2000);

        /* La promo como la manda el SPA: la que le dio la home, YA ajustada. */
        $promo = json_decode(json_encode(
            HomeHelper::get_promociones_vinoteca($this->comercio->id)->firstWhere('id', $promo_id)
        ), true);
        $this->assertSame(1890.0, (float) $promo['final_price']);
        $promo['pivot'] = ['amount' => 1, 'notes' => null];

        $cart_id = $this->guardarCarrito([], [$promo]);

        $this->assertSame(1890.0, $this->precioDeLaLinea('cart_promocion_vinoteca', $cart_id));
        $this->assertSame(1890.0, (float) DB::table('carts')->where('id', $cart_id)->value('total'));
    }

    /** Decision 2: el combo (precio de la base) lleva el factor. 3000 -> 2835. */
    public function test_el_combo_en_el_carrito_guarda_el_precio_ajustado()
    {
        $this->crearEsquemaDeCombosSiFalta();

        list($comprador, $articulo) = $this->compradorConDescuentoYRecargo(1000);

        $combo_id = DB::table('combos')->insertGetId([
            'num'        => 1,
            'name'       => 'Combo Ajustes Test',
            'price'      => 3000,
            'cost'       => 1000,
            'user_id'    => $this->comercio->id,
            'online'     => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        $this->anotar('combos', $combo_id);

        $cart_id = $this->guardarCarrito([], [], [['id' => $combo_id, 'pivot' => ['amount' => 2, 'notes' => null]]]);

        $this->assertSame(2835.0, $this->precioDeLaLinea('cart_combo', $cart_id));
        $this->assertSame(5670.0, (float) DB::table('carts')->where('id', $cart_id)->value('total'));

        /* Y el carrito que vuelve al SPA trae el combo ajustado, con su base. */
        $this->olvidarMemorias();
        $cart = \App\Http\Controllers\Helpers\CartHelper::getFullModel($cart_id);
        $combo = $cart->combos->first();
        $this->assertSame(2835.0, (float) $combo->final_price);
        $this->assertSame(3000.0, (float) $combo->precio_sin_ajustes_de_cliente);
    }

    /**
     * Cliente vinculado SIN ajustes: el carrito se comporta como en master.
     *
     * Se mide con una linea guardada por ENCIMA del precio de hoy (5000 contra 1000) porque es la
     * que distingue: master no la toca (lado B de la asimetria de ofertas, "nunca otorgar un
     * descuento"), y la resincronizacion nueva tampoco puede tocarla, porque sin ajustes vigentes
     * no gobierna ninguna linea. Una linea por DEBAJO no sirve para esto: con el contrato de
     * ofertas puesto, master ya la sube a la base (ResincronizacionDelCarritoTest).
     */
    public function test_sin_ajustes_el_carrito_se_comporta_como_master()
    {
        $comprador = $this->compradorVinculado();
        $articulo = $this->crearArticulo(1000);
        $this->actingAs($comprador, 'buyer');

        $linea = $this->lineaDelSpa($articulo);
        $this->assertFalse(isset($linea['precio_sin_ajustes_de_cliente']), 'sin ajustes el articulo no trae base');

        $linea['final_price'] = 5000;

        $cart_id = $this->guardarCarrito([$linea]);

        $this->assertSame(5000.0, $this->precioDeLaLinea('article_cart', $cart_id));

        $this->actualizarCantidad($cart_id, $articulo->id, 2);

        $this->assertSame(5000.0, $this->precioDeLaLinea('article_cart', $cart_id));
    }

    /**
     * 🔴 Tienda nueva contra una base de empresa vieja (sin las tablas): la tienda cobra el precio
     * de lista, sin ajustes, y no revienta. Es el escenario normal hasta que llega el release.
     */
    public function test_sin_las_tablas_el_carrito_cobra_el_precio_de_lista()
    {
        list($comprador, $articulo) = $this->compradorConDescuentoYRecargo(1000);

        $this->esconderTabla(AjustesDeClienteHelper::TABLA_RECARGOS);

        $linea = $this->lineaDelSpa($articulo);
        $this->assertSame(1000.0, (float) $linea['final_price'], 'sin tablas la tienda muestra el precio de lista');

        $cart_id = $this->guardarCarrito([$linea]);

        $this->assertSame(1000.0, $this->precioDeLaLinea('article_cart', $cart_id));

        $this->actualizarCantidad($cart_id, $articulo->id, 2);

        $this->assertSame(1000.0, $this->precioDeLaLinea('article_cart', $cart_id));
    }

    /**
     * Comprador vinculado con 10% de descuento y 5% de recargo, logueado, y un articulo.
     *
     * @param float $precio
     * @return array [buyer, article]
     */
    private function compradorConDescuentoYRecargo($precio)
    {
        $comprador = $this->compradorVinculado();
        $this->vincular($comprador, [$this->crearDescuento(10)], [$this->crearRecargo(5)]);
        $articulo = $this->crearArticulo($precio);

        $this->actingAs($comprador, 'buyer');

        return [$comprador, $articulo];
    }

    /**
     * Una promocion de vinoteca publicada del comercio.
     *
     * @param float $precio
     * @return int
     */
    private function crearPromo($precio)
    {
        $id = DB::table('promocion_vinotecas')->insertGetId([
            'name'        => 'Promo Carrito Ajustes Test',
            'slug'        => 'promo-carrito-ajustes-test-'.uniqid(),
            'final_price' => $precio,
            'cost'        => 100,
            'online'      => 1,
            'user_id'     => $this->comercio->id,
            'created_at'  => Carbon::now(),
            'updated_at'  => Carbon::now(),
        ]);

        $this->anotar('promocion_vinotecas', $id);

        return $id;
    }

    /**
     * POST /api/carts, como el SPA. Devuelve el id del carrito creado.
     *
     * @param array $articulos
     * @param array $promos
     * @param array $combos
     * @return int
     */
    private function guardarCarrito(array $articulos, array $promos = [], array $combos = [])
    {
        $this->olvidarMemorias();

        $respuesta = $this->postJson('/api/carts', [
            'commerce_id' => $this->comercio->id,
            'cart'        => [
                'articles'             => $articulos,
                'promociones_vinoteca' => $promos,
                'combos'               => $combos,
            ],
        ]);

        $respuesta->assertStatus(201);

        $cart_id = $respuesta->json('cart.id');
        $this->anotarCarrito($cart_id);

        return (int) $cart_id;
    }

    /**
     * PUT /api/carts/update-article-amount/{cart_id}: el boton "Actualizar".
     *
     * @param int $cart_id
     * @param int $article_id
     * @param float $cantidad
     * @return void
     */
    private function actualizarCantidad($cart_id, $article_id, $cantidad)
    {
        $this->olvidarMemorias();

        $this->putJson('/api/carts/update-article-amount/'.$cart_id, [
            'id'     => $article_id,
            'amount' => $cantidad,
        ])->assertStatus(200);
    }

    /**
     * El precio guardado de la (unica) linea de un pivot del carrito.
     *
     * @param string $tabla
     * @param int $cart_id
     * @return float
     */
    private function precioDeLaLinea($tabla, $cart_id)
    {
        return (float) DB::table($tabla)->where('cart_id', $cart_id)->value('price');
    }
}
