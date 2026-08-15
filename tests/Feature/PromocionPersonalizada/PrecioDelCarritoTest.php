<?php

namespace Tests\Feature\PromocionPersonalizada;

use App\Article;
use App\Buyer;
use App\Http\Controllers\Helpers\CartHelper;
use App\Http\Controllers\Helpers\ClientOfferHelper;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El precio que se GUARDA en el carrito con una oferta personalizada de por medio
 * (mision promocion-personalizada-tienda).
 *
 * 🔴 Esta clase es la del camino de la plata. `CartHelper::get_price()` decide el
 * `cart_article.pivot.price`, que despues suma `set_total()` y copia
 * `OrderHelper::attachArticles()` al pedido — y en esta base un pedido confirmado termina en una
 * venta contra la cuenta corriente de una persona.
 *
 * ── Que modos de falla cubre ──────────────────────────────────────────────────────────────────
 *
 *   - que el DESCUENTO lo fije el navegador: el SPA reenvia el carrito entero, con el objeto
 *     `oferta_personalizada` adentro, asi que sin releer de la base cualquiera podria mandarse un
 *     90%, resucitar una oferta vencida o usar la de otro cliente;
 *   - el DOBLE DESCUENTO: para tipo 'unidad' el servidor ya piso `final_price` en
 *     checkPriceTypes(), asi que volver a aplicarle el porcentaje en el carrito cobraria dos
 *     veces el descuento. Un SPA viejo, o un payload guardado en localStorage antes del deploy,
 *     entran justo por ahi;
 *   - y la regresion mas cara de todas: que un articulo SIN oferta deje de cobrar lo que cobraba,
 *     que es lo que arriesga cualquier cambio en get_price().
 *
 * ⚠️ Sin DatabaseTransactions por el CREATE TABLE del trait (DDL con commit implicito).
 */
class PrecioDelCarritoTest extends TestCase
{
    use CreaElEsquemaDeOfertas;

    const CLIENT_ID   = 987654;
    const PRECIO_BASE = 1000.00;

    /** @var \App\User */
    private $comercio;

    /** @var int */
    private $article_id;

    /** @var \App\Buyer|null */
    private $comprador = null;

    protected function setUp(): void
    {
        parent::setUp();

        ClientOfferHelper::olvidarMemoria();

        $this->crearEsquemaDeOfertasSiFalta();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        $articulo = Article::where('user_id', $this->comercio->id)->first();
        $this->assertNotNull($articulo, 'La base del slot tiene que tener al menos un articulo del comercio.');

        $this->article_id = $articulo->id;

        $this->comprador = Buyer::create([
            'name'                    => 'Comprador Carrito',
            'email'                   => 'carrito-'.Str::random(10).'@test.local',
            'comercio_city_client_id' => self::CLIENT_ID,
            'user_id'                 => $this->comercio->id,
        ]);

        $this->limpiarOfertasDe($this->comercio->id);

        $this->actingAs($this->comprador, 'buyer');
    }

    protected function tearDown(): void
    {
        $this->limpiarOfertasDe($this->comercio->id);

        if (!is_null($this->comprador)) {
            DB::table('buyers')->where('id', $this->comprador->id)->delete();
        }

        ClientOfferHelper::olvidarMemoria();

        parent::tearDown();
    }

    /**
     * TEST M — el porcentaje sale de la BASE, no del payload, y el `final_price` mentido no
     * cambia nada.
     *
     * El payload de este caso es exactamente lo que mandaria un atacante: un `final_price` de 1
     * peso y un `oferta_personalizada` inventado con 90%. Lo unico que el servidor le cree es
     * `precio_sin_oferta` —la base, que es lo que ya le cree hoy en `final_price`, un agujero
     * PREEXISTENTE que esta mision no toca— y el descuento lo relee de `client_offers`.
     */
    public function test_m_el_porcentaje_se_relee_de_la_base_y_no_del_payload()
    {
        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $this->article_id,
            'porcentaje' => 20,
        ]);

        $linea = $this->linea([
            'final_price'          => 1,
            'precio_sin_oferta'    => self::PRECIO_BASE,
            'oferta_personalizada' => [
                'id'              => 999999,
                'tipo_descuento'  => ClientOfferHelper::TIPO_UNIDAD,
                'porcentaje'      => 90,
                'precio_aplicado' => true,
                'rangos'          => [],
            ],
        ]);

        $precio = CartHelper::get_price([$linea], $linea, false, collect(), $this->comercio->id);

        $this->assertSame(800.00, (float) $precio,
            'el descuento es el 20% de la base, el que dice la base y no el 90% del payload');
    }

    /**
     * TEST N — sin `precio_sin_oferta` en el payload el precio queda IGUAL, sin doble descuento.
     *
     * 🔴 Es la guarda que hace que este cambio sea seguro de desplegar. Para tipo 'unidad' el
     * `final_price` que manda el SPA YA viene descontado (lo piso el servidor en
     * checkPriceTypes), asi que aplicarle el porcentaje otra vez cobraria 640 donde corresponden
     * 800. Un SPA viejo, o un carrito guardado en localStorage antes del deploy, mandan
     * exactamente este payload.
     *
     * 🔴 La asercion del log NO es decorativa, y esto se midio: sacandole la guarda al helper el
     * precio SIGUE dando 800, porque `$article['precio_sin_oferta']` sobre una clave ausente tira
     * un warning de PHP, Laravel lo convierte en ErrorException, el try/catch del helper se la
     * come y devuelve null igual. O sea que sin esta segunda asercion el test daba verde con la
     * guarda borrada: probaba el numero, no la guarda.
     *
     * Y la diferencia importa de verdad, no es una formalidad: el payload de un SPA viejo es un
     * estado PREVISTO del despliegue —los dos lados nunca llegan a produccion al mismo tiempo—,
     * asi que tiene que resolverse por la guarda y en silencio. Por el catch se resolveria con
     * una excepcion y un `warning` POR LINEA DE CARRITO, todo el dia, tapando las fallas de
     * verdad justo en el camino de la plata.
     */
    public function test_n_sin_precio_sin_oferta_no_hay_doble_descuento()
    {
        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $this->article_id,
            'porcentaje' => 20,
        ]);

        /* Lo que manda un SPA viejo: el final_price YA descontado por el servidor y ni rastro de
           precio_sin_oferta, que es un campo que ese SPA no conoce. */
        $linea = $this->linea(['final_price' => 800.00]);

        Log::spy();

        $precio = CartHelper::get_price([$linea], $linea, false, collect(), $this->comercio->id);

        $this->assertSame(800.00, (float) $precio,
            'sin la base explicita el precio es el del payload, tal cual');
        $this->assertNotSame(640.00, (float) $precio,
            'aplicar el 20% otra vez sobre un precio ya descontado seria cobrar el descuento dos veces');

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    /**
     * TEST O — un articulo SIN oferta cobra exactamente lo de master.
     *
     * Es la contracara y la que protege a los 99% de los carritos: si esta se rompe, se rompio el
     * precio de toda la tienda, no el de una promocion.
     *
     * 🔴 La linea NO lleva `precio_sin_oferta`, y eso es el test y no un descuido: un articulo
     * que nunca tuvo oferta no tiene ese campo, porque lo cuelga el servidor en checkPriceTypes
     * solo cuando aplico una. La version anterior de este test mandaba las dos cosas a la vez
     * —un payload que el sistema no produce— y con eso fijaba como correcto justo el defecto
     * que arreglo test_r (ver abajo).
     */
    public function test_o_un_articulo_sin_oferta_cobra_el_final_price_de_siempre()
    {
        $linea = $this->linea([
            'final_price' => 777.77,
        ]);

        $precio = CartHelper::get_price([$linea], $linea, false, collect(), $this->comercio->id);

        $this->assertSame(777.77, (float) $precio,
            'sin oferta vigente el carrito cobra el final_price, byte por byte igual a master');
    }

    /**
     * 🔴 TEST R — LA PROMOCION CANCELADA DEJA DE COBRARSE EN LA MISMA PESTAÑA.
     *
     * Este es el defecto de plata que encontro la verificacion independiente, y el unico test de
     * esta suite que se puso rojo con el codigo tal como se habia escrito primero.
     *
     * El escenario NO necesita que nadie manipule nada, y por eso importa:
     *   1. La oferta esta vigente. La API responde `final_price = 800` (ya descontado) y
     *      `precio_sin_oferta = 1000`. El SPA se guarda ese objeto en el carrito.
     *   2. El comerciante cancela la promocion desde el ERP, o pasa la medianoche del `hasta`.
     *      La pestaña del comprador sigue abierta con el objeto viejo en memoria.
     *   3. El comprador agrega cualquier otro articulo: eso reenvia TODO el carrito.
     *
     * Antes: `precioDeLinea` no encontraba oferta, devolvia null, y `get_price` caia al
     * `final_price` del payload — o sea a los 800 con el descuento de una promocion que ya no
     * existe. Con una recarga de pagina el mismo carrito cobraba 1000: la diferencia la decidia
     * si el navegador tenia el objeto viejo en memoria.
     *
     * Ahora el servidor decide con la base: 1000.
     */
    public function test_r_una_promocion_cancelada_deja_de_cobrarse_aunque_el_navegador_tenga_el_precio_viejo()
    {
        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $this->article_id,
            'porcentaje' => 20,
            'estado'     => 'cancelada',
        ]);

        /* Exactamente lo que quedo en el navegador cuando la oferta valia. */
        $linea = $this->linea([
            'final_price'       => 800.00,
            'precio_sin_oferta' => 1000.00,
        ]);

        $precio = CartHelper::get_price([$linea], $linea, false, collect(), $this->comercio->id);

        $this->assertSame(1000.00, (float) $precio,
            'una promocion cancelada no puede seguir cobrandose por el precio que quedo en el navegador');
    }

    /**
     * Y lo mismo cuando la oferta NUNCA existio para ese comprador pero el payload trae una base:
     * el servidor cobra la base, no el `final_price` que venga.
     */
    public function test_sin_oferta_en_la_base_pero_con_base_declarada_se_cobra_la_base()
    {
        $linea = $this->linea([
            'final_price'       => 800.00,
            'precio_sin_oferta' => 1000.00,
        ]);

        $precio = CartHelper::get_price([$linea], $linea, false, collect(), $this->comercio->id);

        $this->assertSame(1000.00, (float) $precio,
            'con base declarada y sin oferta vigente, el precio lo decide el servidor');
    }

    /**
     * TEST P — 'cantidad': el tramo lo elige el SERVIDOR desde `amount`.
     *
     * Los tramos se recorren ordenados por `min` y gana el ULTIMO que encaja, igual que
     * `get_price_range()`, que es el mecanismo que la tienda ya usa para lo mismo. El ultimo
     * tramo tiene `max` NULL = sin techo: con 40 unidades tiene que entrar ahi y no quedarse sin
     * descuento.
     */
    public function test_p_con_tipo_cantidad_el_tramo_lo_elige_el_servidor()
    {
        $offer_id = $this->insertarOferta([
            'user_id'        => $this->comercio->id,
            'client_id'      => self::CLIENT_ID,
            'article_id'     => $this->article_id,
            'tipo_descuento' => ClientOfferHelper::TIPO_CANTIDAD,
            'porcentaje'     => null,
        ]);

        $this->insertarRangos($offer_id, [
            [12, null, 18],
            [1, 5, 5],
            [6, 11, 10],
        ]);

        $tres = $this->linea([
            'final_price'       => self::PRECIO_BASE,
            'precio_sin_oferta' => self::PRECIO_BASE,
            'amount'            => 3,
        ]);

        $this->assertSame(950.00, (float) CartHelper::get_price([$tres], $tres, false, collect(), $this->comercio->id),
            'con 3 unidades entra el tramo 1-5, que descuenta 5%');

        $cuarenta = $this->linea([
            'final_price'       => self::PRECIO_BASE,
            'precio_sin_oferta' => self::PRECIO_BASE,
            'amount'            => 40,
        ]);

        $this->assertSame(820.00, (float) CartHelper::get_price([$cuarenta], $cuarenta, false, collect(), $this->comercio->id),
            'con 40 unidades entra el ultimo tramo, el de max NULL, que descuenta 18%');
    }

    /**
     * TEST Q — la VIGENCIA tambien la decide el servidor.
     *
     * La oferta esta vencida en la base y el navegador la reenvia igual, entera y en fecha. Es el
     * caso realista del overlay que quedo abierto cuando la oferta vencio a medianoche, y tambien
     * el del que edita el payload a mano. Las dos cosas terminan igual: se cobra el precio sin
     * descuento.
     */
    public function test_q_una_oferta_vencida_en_la_base_no_descuenta_aunque_el_payload_la_traiga()
    {
        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $this->article_id,
            'porcentaje' => 30,
            'desde'      => Carbon::today()->subDays(30)->toDateString(),
            'hasta'      => Carbon::yesterday()->toDateString(),
            'estado'     => ClientOfferHelper::ESTADO_ACTIVA,
        ]);

        /* 🔴 El `final_price` va con el precio YA DESCONTADO, que es el que de verdad quedo en
           el navegador cuando la oferta valia. Con PRECIO_BASE ahi el test pasaba sin probar
           nada: el fallback defectuoso devolvia justo la cifra esperada. */
        $linea = $this->linea([
            'final_price'          => 700.00,
            'precio_sin_oferta'    => self::PRECIO_BASE,
            'oferta_personalizada' => [
                'id'              => 1,
                'tipo_descuento'  => ClientOfferHelper::TIPO_UNIDAD,
                'porcentaje'      => 30,
                'desde'           => Carbon::today()->subDays(30)->toDateString(),
                'hasta'           => Carbon::today()->addDays(30)->toDateString(),
                'precio_aplicado' => true,
                'rangos'          => [],
            ],
        ]);

        $precio = CartHelper::get_price([$linea], $linea, false, collect(), $this->comercio->id);

        $this->assertSame(self::PRECIO_BASE, (float) $precio,
            'la vigencia la decide la base, no las fechas que mande el navegador');
    }

    /**
     * Y la oferta de OTRO cliente del ERP tampoco descuenta, aunque el payload venga perfecto.
     *
     * Es el mismo filtro que ContratoDeOfertasTest fija en el listado, pero acá vale plata: el
     * `client_id` sale de `buyers.comercio_city_client_id` de la SESION y nunca del payload.
     */
    public function test_la_oferta_de_otro_cliente_del_erp_no_descuenta_en_el_carrito()
    {
        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID + 1,
            'article_id' => $this->article_id,
            'porcentaje' => 50,
        ]);

        /* El final_price va descontado al 50%, como si el navegador lo hubiera traido de algun
           lado: si el servidor no decidiera, esa cifra seria la que se cobra. */
        $linea = $this->linea([
            'final_price'       => 500.00,
            'precio_sin_oferta' => self::PRECIO_BASE,
        ]);

        $this->assertSame(self::PRECIO_BASE, (float) CartHelper::get_price([$linea], $linea, false, collect(), $this->comercio->id));
    }

    /**
     * Una linea del carrito tal cual la manda el SPA: un array, no un modelo.
     *
     * @param array $atributos
     * @return array
     */
    private function linea(array $atributos = [])
    {
        return array_merge([
            'id'          => $this->article_id,
            'user_id'     => $this->comercio->id,
            'final_price' => self::PRECIO_BASE,
            'amount'      => 1,
            'pivot'       => ['amount' => 1, 'notes' => null],
        ], $atributos);
    }
}
