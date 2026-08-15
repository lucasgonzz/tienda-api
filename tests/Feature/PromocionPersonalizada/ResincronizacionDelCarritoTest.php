<?php

namespace Tests\Feature\PromocionPersonalizada;

use App\Article;
use App\Buyer;
use App\Cart;
use App\Http\Controllers\Helpers\CartHelper;
use App\Http\Controllers\Helpers\ClientOfferHelper;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `CartHelper::resincronizar_precios_de_oferta()`, llamada desde `set_total()`: el precio
 * guardado de cada linea se vuelve a resolver contra la base antes de sumar el total
 * (mision promocion-personalizada-tienda).
 *
 * ── EL DEFECTO QUE FIJA, MEDIDO EN LA TIENDA CORRIENDO CON PLAYWRIGHT ─────────────────────────
 *
 * `CartController::update_article_amount()` —el boton "Actualizar" de la ficha— cambia el
 * `amount` del pivot con `updateExistingPivot` y NO vuelve a pasar por `get_price()`. Antes de
 * esta mision eso era inofensivo, porque el precio no dependia de la cantidad. Con una oferta por
 * tramos SI depende, y los numeros de esta clase son los de la reproduccion real:
 *
 *   Articulo de $3.948 con tramos 1-5 al 5%, 6-11 al 10% y 12+ al 18%.
 *   El comprador agrega 12  -> se guarda price = 3.237,36 (el tramo del 18%).
 *   Cambia la cantidad a 1 y aprieta Actualizar -> el pivot queda amount = 1 y price = 3.237,36.
 *   La pantalla mostraba $3.750,60 (el tramo del 5%, que es el que le corresponde) y el carrito
 *   guardaba $3.237,36.
 *
 * O sea que el descuento mas profundo se conseguia con CUALQUIER cantidad, sin manipular nada y
 * con un boton de la interfaz. Y pantalla y carrito decian numeros distintos, que es el peor
 * sintoma posible en el camino de la plata.
 *
 * 🔴 Por que ninguna de las clases que ya existian lo agarraba: PrecioDelCarritoTest prueba
 * `get_price()`, y el defecto esta justamente en el camino que NO pasa por `get_price()`. Un test
 * unitario de esa funcion no lo podia ver por definicion. Por eso el primer caso de esta clase le
 * pega al ENDPOINT real y no a un helper.
 *
 * ── La asimetria de la escritura, que es lo que hay que entender antes de tocar esto ──────────
 *
 *   - Linea CON oferta vigente: se escribe el precio del tramo, para arriba o para abajo. Es el
 *     precio que el comprador esta viendo en pantalla.
 *   - Linea SIN oferta: se escribe SOLO si el precio nuevo es MAYOR, o sea unicamente para
 *     deshacer un descuento que ya no corresponde. NUNCA para otorgar uno.
 *
 * "El cliente fija el precio base" es un agujero PREEXISTENTE de este repo; la asimetria es lo
 * que garantiza que esta funcionalidad no lo agrande. Los dos lados tienen su caso acá abajo.
 *
 * ⚠️ Sin DatabaseTransactions: el trait CreaElEsquemaDeOfertas hace CREATE TABLE (DDL con commit
 * implicito) y un caso esconde las tablas con RENAME. Se limpia a mano.
 */
class ResincronizacionDelCarritoTest extends TestCase
{
    use CreaElEsquemaDeOfertas;

    const CLIENT_ID = 987654;

    /** El precio de lista del articulo de la reproduccion. */
    const PRECIO_BASE = 3948.00;

    /** 3948 * 0.95 — el tramo 1-5. */
    const TRAMO_5 = 3750.60;

    /** 3948 * 0.90 — el tramo 6-11. */
    const TRAMO_10 = 3553.20;

    /** 3948 * 0.82 — el tramo 12+, con `max` NULL. */
    const TRAMO_18 = 3237.36;

    const ESCONDIDA_OFERTAS = 'client_offers_escondida_test';
    const ESCONDIDA_RANGOS  = 'client_offer_ranges_escondida_test';

    /** @var \App\User */
    private $comercio;

    /** @var \App\Buyer|null */
    private $comprador = null;

    /** @var int */
    private $article_id;

    /** @var array */
    private $articulos_creados = [];

    /** @var array */
    private $carritos_creados = [];

    protected function setUp(): void
    {
        parent::setUp();

        ClientOfferHelper::olvidarMemoria();

        $this->crearEsquemaDeOfertasSiFalta();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        $this->comprador = Buyer::create([
            'name'                    => 'Comprador Resincronizacion',
            'email'                   => 'resync-'.Str::random(10).'@test.local',
            'comercio_city_client_id' => self::CLIENT_ID,
            'user_id'                 => $this->comercio->id,
        ]);

        /* El articulo de la reproduccion: precio de lista propio, para que el numero contra el que
           se mide no dependa de lo que tenga sembrado el slot. */
        $this->article_id = $this->crearArticulo();

        $this->limpiarOfertasDe($this->comercio->id);
    }

    /**
     * Deja la base como estaba. El restore de las tablas va PRIMERO y sin condiciones: si
     * quedaran escondidas se llevarian puesta la suite entera.
     */
    protected function tearDown(): void
    {
        $this->restaurarLasTablas();

        $this->limpiarOfertasDe($this->comercio->id);

        if (!empty($this->carritos_creados)) {
            DB::table('article_cart')->whereIn('cart_id', $this->carritos_creados)->delete();
            DB::table('carts')->whereIn('id', $this->carritos_creados)->delete();
        }

        if (!empty($this->articulos_creados)) {
            DB::table('articles')->whereIn('id', $this->articulos_creados)->delete();
        }

        if (!is_null($this->comprador)) {
            DB::table('buyers')->where('id', $this->comprador->id)->delete();
        }

        ClientOfferHelper::olvidarMemoria();

        parent::tearDown();
    }

    /**
     * 🔴 EL CASO QUE REPRODUCE EL DEFECTO, y le pega al endpoint de verdad.
     *
     * `PUT /api/carts/update-article-amount/{cart_id}` es literalmente el boton "Actualizar".
     * Antes del arreglo, bajar de 12 a 1 dejaba el pivot en `amount = 1` con el `price` del tramo
     * del 18%: el comprador se llevaba el descuento por volumen comprando una unidad.
     */
    public function test_bajar_la_cantidad_desde_actualizar_recalcula_el_tramo()
    {
        $this->ofertaPorTramos($this->article_id);

        /* El carrito tal como quedo despues de agregar 12 unidades: el precio del tramo del 18%. */
        $carrito = $this->carritoCon($this->article_id, 12, self::TRAMO_18);

        $this->actingAs($this->comprador, 'buyer')
            ->json('PUT', '/api/carts/update-article-amount/'.$carrito->id, [
                'id'     => $this->article_id,
                'amount' => 1,
            ])
            ->assertStatus(200);

        $this->assertSame(1.0, $this->cantidadGuardada($carrito->id),
            'el endpoint tiene que haber bajado la cantidad: si no, el caso no se ejercito');

        $this->assertSame(self::TRAMO_5, $this->precioGuardado($carrito->id),
            'con 1 unidad corresponde el tramo 1-5 (5%), no el del 18% que quedo de cuando eran 12');

        $this->assertSame(self::TRAMO_5, (float) Cart::find($carrito->id)->total,
            'el total tiene que salir del precio corregido, no del viejo');
    }

    /**
     * Y hacia abajo tambien: subir la cantidad tiene que hacer valer el tramo mas profundo.
     *
     * Sin este caso el arreglo podria ser un "nunca bajes el precio", que le cobraria de mas al
     * comprador que compra mas — que es exactamente al que la promocion quiere premiar.
     */
    public function test_subir_la_cantidad_hace_valer_el_tramo_mas_profundo()
    {
        $this->actingAs($this->comprador, 'buyer');

        $this->ofertaPorTramos($this->article_id);

        $carrito = $this->carritoCon($this->article_id, 1, self::TRAMO_5);

        DB::table('article_cart')->where('cart_id', $carrito->id)->update(['amount' => 12]);

        CartHelper::set_total($carrito);

        $this->assertSame(self::TRAMO_18, $this->precioGuardado($carrito->id),
            'con 12 unidades corresponde el ultimo tramo, el de max NULL');
    }

    /**
     * El tramo del medio, para que el test de arriba no pueda dar verde por un "siempre el
     * ultimo tramo".
     */
    public function test_una_cantidad_del_tramo_del_medio_toma_el_tramo_del_medio()
    {
        $this->actingAs($this->comprador, 'buyer');

        $this->ofertaPorTramos($this->article_id);

        $carrito = $this->carritoCon($this->article_id, 1, self::TRAMO_5);

        DB::table('article_cart')->where('cart_id', $carrito->id)->update(['amount' => 8]);

        CartHelper::set_total($carrito);

        $this->assertSame(self::TRAMO_10, $this->precioGuardado($carrito->id),
            'con 8 unidades corresponde el tramo 6-11 (10%)');
    }

    /**
     * Promocion cancelada: la linea vuelve al precio de lista.
     *
     * Es el mismo defecto de plata que fija `PrecioDelCarritoTest::test_r`, pero por el otro
     * camino: alli el precio viejo venia en el payload, acá ya esta GUARDADO en la base. Un
     * carrito que quedo abierto desde antes de que el comerciante cancelara la promocion se
     * seguiria cobrando con el descuento al confirmarse el pedido.
     */
    public function test_una_promocion_cancelada_devuelve_la_linea_al_precio_de_lista()
    {
        $this->actingAs($this->comprador, 'buyer');

        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $this->article_id,
            'porcentaje' => 20,
            'estado'     => 'cancelada',
        ]);

        /* 3948 * 0.8: el precio con el que se guardo la linea cuando la promocion valia. */
        $carrito = $this->carritoCon($this->article_id, 1, 3158.40);

        CartHelper::set_total($carrito);

        $this->assertSame(self::PRECIO_BASE, $this->precioGuardado($carrito->id),
            'cancelada la promocion, la linea vuelve al precio de lista');
    }

    /**
     * Idem con una oferta pasada de fecha: la vigencia la dan las fechas, no `estado`.
     */
    public function test_una_oferta_vencida_devuelve_la_linea_al_precio_de_lista()
    {
        $this->actingAs($this->comprador, 'buyer');

        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $this->article_id,
            'porcentaje' => 20,
            'desde'      => Carbon::today()->subDays(30)->toDateString(),
            'hasta'      => Carbon::yesterday()->toDateString(),
            'estado'     => ClientOfferHelper::ESTADO_ACTIVA,
        ]);

        $carrito = $this->carritoCon($this->article_id, 1, 3158.40);

        CartHelper::set_total($carrito);

        $this->assertSame(self::PRECIO_BASE, $this->precioGuardado($carrito->id));
    }

    /**
     * 🔴 LA ASIMETRIA, LADO A: una linea SIN ninguna oferta guardada por DEBAJO de la base se
     * corrige HACIA ARRIBA.
     *
     * Es lo que deshace un descuento que ya no corresponde. La linea de este caso no tiene ni
     * tuvo oferta y esta guardada a 1.000 sobre una base de 3.948: se escribe la base.
     *
     * ⚠️ Necesita el comprador logueado, y no es un detalle del fixture: la resincronizacion
     * entera vive detras de `hayContrato()`, o sea que el carrito de un invitado no se toca ni
     * para esto. Lo fija el ultimo caso de la clase.
     */
    public function test_una_linea_sin_oferta_por_debajo_de_la_base_se_corrige_hacia_arriba()
    {
        $this->actingAs($this->comprador, 'buyer');

        $carrito = $this->carritoCon($this->article_id, 1, 1000.00);

        CartHelper::set_total($carrito);

        $this->assertSame(self::PRECIO_BASE, $this->precioGuardado($carrito->id),
            'un precio guardado por debajo de la base y sin oferta que lo justifique se corrige');
    }

    /**
     * 🔴 LA ASIMETRIA, LADO B: una linea SIN oferta guardada por ENCIMA de la base NO SE TOCA.
     *
     * Es el lado que hay que fijar con un test o se pierde en la primera "simplificacion". Sin
     * oferta, corregir hacia abajo seria OTORGAR un descuento que nadie autorizo, y encima
     * cambiaria el precio de carritos que hoy funcionan bien: hay razones legitimas por las que
     * una linea puede estar guardada por encima del `final_price` de hoy —el comerciante bajo el
     * precio de lista despues de que el carrito se armo, o la lista de precios del comprador
     * cambio—, y ese es el comportamiento de master, que esta mision no cambia.
     */
    public function test_una_linea_sin_oferta_por_encima_de_la_base_no_se_toca()
    {
        $this->actingAs($this->comprador, 'buyer');

        $carrito = $this->carritoCon($this->article_id, 1, 5000.00);

        CartHelper::set_total($carrito);

        $this->assertSame(5000.00, $this->precioGuardado($carrito->id),
            'sin oferta vigente NUNCA se baja el precio: eso seria otorgar un descuento que nadie autorizo');
    }

    /**
     * Y con oferta vigente si se escribe para arriba: el precio que manda es el del tramo, no el
     * que haya quedado guardado.
     *
     * Es la contracara del caso de arriba y lo que impide "arreglarlo" volviendo la escritura
     * simetrica en la direccion equivocada.
     */
    public function test_con_oferta_vigente_el_precio_del_tramo_manda_aunque_el_guardado_sea_menor()
    {
        $this->actingAs($this->comprador, 'buyer');

        $this->ofertaPorTramos($this->article_id);

        /* Guardada muy por debajo de cualquier tramo. */
        $carrito = $this->carritoCon($this->article_id, 1, 100.00);

        CartHelper::set_total($carrito);

        $this->assertSame(self::TRAMO_5, $this->precioGuardado($carrito->id),
            'con oferta vigente el precio es el del tramo, se escriba para arriba o para abajo');
    }

    /**
     * 🔴 COSTO: con las tablas del contrato ausentes —el estado NORMAL de hoy— `set_total()` no
     * carga los articulos del carrito ni consulta ofertas.
     *
     * `set_total()` corre en TODOS los caminos que escriben el carrito. Sin la guarda barata de
     * `hayContrato()`, cada uno de ellos pasaria a cargar los articulos con `withAll()` —que son
     * una docena larga de eager loads— para terminar descubriendo que no hay esquema. Y eso lo
     * pagarian todas las tiendas, todo el dia, durante las semanas que la mision de `empresa-api`
     * tarde en llegar a la base de cada cliente.
     */
    public function test_sin_las_tablas_del_contrato_set_total_no_carga_articulos_ni_consulta_ofertas()
    {
        $this->actingAs($this->comprador, 'buyer');

        $carrito = $this->carritoCon($this->article_id, 1, 1000.00);

        $this->esconderLasTablas();

        try {
            $queries = $this->queriesDurante(function () use ($carrito) {
                CartHelper::set_total($carrito);
            });

            $this->assertFalse(
                Schema::hasTable(ClientOfferHelper::TABLA),
                'el escenario no se ejercito: la tabla seguia estando'
            );
        } finally {
            $this->restaurarLasTablas();
        }

        $this->assertNoConsulto($queries);

        /* Y el precio guardado queda exactamente como estaba: sin esquema no hay nada que
           resincronizar, ni siquiera esa correccion hacia arriba. */
        $this->assertSame(1000.00, $this->precioGuardado($carrito->id),
            'sin las tablas del contrato el carrito se comporta byte por byte como master');
    }

    /**
     * Lo mismo sin comprador logueado, que es el caso mas frecuente de la tienda: se corta un
     * escalon antes todavia, sin tocar ni el information_schema.
     */
    public function test_sin_comprador_logueado_set_total_no_carga_articulos_ni_consulta_ofertas()
    {
        $this->ofertaPorTramos($this->article_id);

        $carrito = $this->carritoCon($this->article_id, 1, 1000.00);

        /* Sin actingAs: un invitado, que es como se compra en esta tienda la mayor parte del
           tiempo. */
        $queries = $this->queriesDurante(function () use ($carrito) {
            CartHelper::set_total($carrito);
        });

        $this->assertNoConsulto($queries);

        $this->assertSame(1000.00, $this->precioGuardado($carrito->id),
            'un invitado no tiene oferta personalizada: su carrito no se toca');
    }

    /**
     * 🔴 La contracara de los dos casos de costo, y sin ella los dos serian adornos.
     *
     * Los dos de arriba prueban que NO aparecen ciertas queries. Un marcador mal escrito —un
     * backtick de mas, un `select *` que Laravel compile distinto— no aparece nunca, y entonces
     * los dos dan verde para siempre sin mirar nada. Este caso mide con el MISMO metodo en el
     * escenario donde las queries SI tienen que estar, asi que si un marcador deja de matchear se
     * pone rojo acá y no en silencio allá.
     */
    public function test_con_el_contrato_puesto_la_resincronizacion_si_consulta()
    {
        $this->actingAs($this->comprador, 'buyer');

        $this->ofertaPorTramos($this->article_id);

        $carrito = $this->carritoCon($this->article_id, 12, self::TRAMO_5);

        $queries = $this->queriesDurante(function () use ($carrito) {
            CartHelper::set_total($carrito);
        });

        $de_ofertas = array_filter($queries, function ($sql) {
            return strpos($sql, 'client_offer') !== false && strpos($sql, 'information_schema') === false;
        });

        $de_lineas = array_filter($queries, function ($sql) {
            return strpos($sql, 'select * from `article_cart`') === 0;
        });

        $this->assertNotEmpty($de_ofertas,
            'el marcador de las queries del contrato dejo de matchear: los tests de costo quedaron vacuos');
        $this->assertNotEmpty($de_lineas,
            'el marcador de la lectura de lineas dejo de matchear: los tests de costo quedaron vacuos');

        /* Y de paso: la resincronizacion hizo lo suyo. */
        $this->assertSame(self::TRAMO_18, $this->precioGuardado($carrito->id));
    }

    /**
     * Ni una query a las tablas del contrato, ni una lectura de las lineas para resincronizar.
     *
     * Los dos marcadores son precisos y por eso se usan estos y no un conteo total:
     *   - `client_offer` en el SQL: las dos queries del contrato. El `Schema::hasTable` de la
     *     guarda pasa el nombre de la tabla como BINDING, no en el texto, asi que no se cuela.
     *   - `select * from `article_cart``: es la lectura de las lineas que hace
     *     resincronizar_precios_de_oferta(). El `$cart->load('articles')` del propio set_total
     *     compila distinto (`select `articles`.*, ... inner join `article_cart``), asi que este
     *     marcador solo lo enciende la funcion que se esta midiendo.
     *
     * @param array $queries
     * @return void
     */
    private function assertNoConsulto($queries)
    {
        $de_ofertas = array_values(array_filter($queries, function ($sql) {
            return strpos($sql, 'client_offer') !== false && strpos($sql, 'information_schema') === false;
        }));

        $de_lineas = array_values(array_filter($queries, function ($sql) {
            return strpos($sql, 'select * from `article_cart`') === 0;
        }));

        $this->assertSame([], $de_ofertas,
            'no se puede consultar client_offers: '.implode(' | ', $de_ofertas));

        $this->assertSame([], $de_lineas,
            'no se pueden leer las lineas para resincronizar: '.implode(' | ', $de_lineas));
    }

    /**
     * Todas las queries que dispara la accion.
     *
     * El listener lleva su propio interruptor porque en Laravel no se puede desregistrar: sin el,
     * una segunda medicion en el mismo caso le seguiria sumando a la primera.
     *
     * @param callable $accion
     * @return array
     */
    private function queriesDurante(callable $accion)
    {
        $queries  = [];
        $midiendo = true;

        DB::listen(function ($query) use (&$queries, &$midiendo) {
            if ($midiendo) {
                $queries[] = $query->sql;
            }
        });

        $accion();

        $midiendo = false;

        return $queries;
    }

    /**
     * La oferta por tramos de la reproduccion: 1-5 al 5%, 6-11 al 10% y 12+ al 18%.
     *
     * @param int $article_id
     * @return int
     */
    private function ofertaPorTramos($article_id)
    {
        $offer_id = $this->insertarOferta([
            'user_id'        => $this->comercio->id,
            'client_id'      => self::CLIENT_ID,
            'article_id'     => $article_id,
            'tipo_descuento' => ClientOfferHelper::TIPO_CANTIDAD,
            'porcentaje'     => null,
        ]);

        $this->insertarRangos($offer_id, [
            [12, null, 18],
            [1, 5, 5],
            [6, 11, 10],
        ]);

        return $offer_id;
    }

    /**
     * Un carrito del comprador con una sola linea, escrita a mano tal como la dejaria
     * `attachArticles()`.
     *
     * Se escribe la fila directo y no por el endpoint de guardado porque lo que estos casos
     * necesitan es un carrito YA GUARDADO con un precio determinado — que es el estado del que
     * parte el defecto: el precio quedo bien en su momento y despues dejo de corresponder.
     *
     * @param int $article_id
     * @param float $amount
     * @param float $price
     * @return \App\Cart
     */
    private function carritoCon($article_id, $amount, $price)
    {
        $carrito = Cart::create([
            'user_id'  => $this->comercio->id,
            'buyer_id' => $this->comprador->id,
            'total'    => $price * $amount,
        ]);

        $this->carritos_creados[] = $carrito->id;

        DB::table('article_cart')->insert([
            'cart_id'    => $carrito->id,
            'article_id' => $article_id,
            'amount'     => $amount,
            'price'      => $price,
            'cost'       => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $carrito;
    }

    /**
     * El precio guardado de la (unica) linea del carrito.
     *
     * @param int $cart_id
     * @return float
     */
    private function precioGuardado($cart_id)
    {
        return (float) DB::table('article_cart')->where('cart_id', $cart_id)->value('price');
    }

    /**
     * La cantidad guardada de la (unica) linea del carrito.
     *
     * @param int $cart_id
     * @return float
     */
    private function cantidadGuardada($cart_id)
    {
        return (float) DB::table('article_cart')->where('cart_id', $cart_id)->value('amount');
    }

    /**
     * Crea el articulo de la reproduccion y lo anota para borrarlo en el tearDown.
     *
     * @param array $atributos
     * @return int
     */
    private function crearArticulo(array $atributos = [])
    {
        $articulo = Article::create(array_merge([
            'user_id'     => $this->comercio->id,
            'name'        => 'Articulo Resync Test '.Str::random(8),
            'status'      => 'active',
            'online'      => 1,
            'stock'       => 100,
            'final_price' => self::PRECIO_BASE,
        ], $atributos));

        $this->articulos_creados[] = $articulo->id;

        return $articulo->id;
    }

    /**
     * Esconde las dos tablas del contrato, si estan.
     *
     * @return void
     */
    private function esconderLasTablas()
    {
        if (Schema::hasTable(ClientOfferHelper::TABLA)) {
            DB::statement('RENAME TABLE '.ClientOfferHelper::TABLA.' TO '.self::ESCONDIDA_OFERTAS);
        }

        if (Schema::hasTable(ClientOfferHelper::TABLA_RANGOS)) {
            DB::statement('RENAME TABLE '.ClientOfferHelper::TABLA_RANGOS.' TO '.self::ESCONDIDA_RANGOS);
        }
    }

    /**
     * Devuelve las tablas a su nombre. Idempotente, y se llama en el `finally` Y en el tearDown.
     *
     * @return void
     */
    private function restaurarLasTablas()
    {
        if (Schema::hasTable(self::ESCONDIDA_OFERTAS)) {
            DB::statement('RENAME TABLE '.self::ESCONDIDA_OFERTAS.' TO '.ClientOfferHelper::TABLA);
        }

        if (Schema::hasTable(self::ESCONDIDA_RANGOS)) {
            DB::statement('RENAME TABLE '.self::ESCONDIDA_RANGOS.' TO '.ClientOfferHelper::TABLA_RANGOS);
        }
    }
}
