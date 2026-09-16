<?php

namespace Tests\Feature\CombosYRangos;

use App\Cart;
use App\Http\Controllers\Helpers\ArticlePriceRangeHelper;
use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use App\Http\Controllers\MercadoPagoController;
use App\PaymentMethod;
use App\PaymentMethodType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El contrato con `empresa-api`: la tienda NUEVA corriendo contra una base que todavia no tiene el
 * esquema de combos ni la tabla de tramos (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * 🔴 Este es el escenario NORMAL, no el borde. `combos.online`, `cart_combo`, `order_combo` y
 * `article_price_ranges` las crean las migraciones de `empresa-api`, y llegan a la base de un
 * cliente con el release del ERP. La tienda, en cambio, la despliega Lucas a mano, por cliente,
 * cuando quiere. O sea que va a haber clientes con la tienda nueva contra una base vieja durante
 * dias o semanas.
 *
 * Y sin guarda eso no es "una seccion que se ve vacia": es la tienda caida.
 *   - `where('online', 1)` sobre una columna que no existe es "Unknown column" -> la home en 500.
 *   - `with('combos')` sobre una tabla que no existe es "Base table or view not found" -> cada
 *     operacion del carrito en 500, o sea la compra entera.
 *   - Y `article_price_ranges` es peor todavia, porque su eager load vive en
 *     `Article::scopeWithAll()`, que esta en el camino de TODOS los listados de la tienda.
 *
 * Lo que se fija, con el esquema escondido: la home responde 200 con las secciones nuevas VACIAS,
 * el carrito se guarda igual aunque el payload traiga combos, y el precio es el normal de siempre.
 *
 * ── Por que esta clase NO usa DatabaseTransactions ────────────────────────────────────────────
 *
 * El unico modo honesto de probar "el esquema no esta" es que no este, y eso se hace con
 * `ALTER TABLE ... RENAME COLUMN` y `RENAME TABLE`. Son DDL, y MySQL les hace commit implicito a
 * la transaccion abierta, asi que el rollback del trait no revertiria nada. Acá el DDL va afuera y
 * lo que se inserta va adentro de una transaccion manual que se revierte en el `finally`. Mismo
 * criterio y mismo molde que `SinEsquemaDeEnviosTest` y `ActualizaEnvioZipcodeSinEsquemaTest`.
 *
 * 🔴 El restore va en el `finally`, en el `tearDown` Y en el `setUp` (por si una corrida anterior
 * murio a mitad). Esta base la comparten TODOS los tests de este slot: si las tablas quedaran
 * renombradas, se llevarian puesta la suite entera y todo lo que corra despues acá.
 */
class SinEsquemaDeCombosYRangosTest extends TestCase
{
    use ArmaComercioConCombosYRangos;

    /** Sufijo con el que se esconden la columna y las tablas. */
    const SUFIJO = '_escondida_test';

    /** Las tres tablas que se esconden, por nombre real. */
    const TABLAS = ['cart_combo', 'order_combo', 'article_price_ranges'];

    /**
     * @var array Ids de los comercios que este caso creo FUERA de transaccion. Se borran a mano en
     *            el `finally`: con el DDL de por medio no hay rollback que los limpie.
     */
    private $comercios_creados = [];

    /** @var array Ids de los carritos creados fuera de transaccion. */
    private $carritos_creados = [];

    /** @var array Ids de las promociones de vinoteca creadas fuera de transaccion. */
    private $promociones_creadas = [];

    /** @var array Ids de los medios de pago creados fuera de transaccion. */
    private $medios_de_pago_creados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercios_creados = [];
        $this->carritos_creados = [];
        $this->promociones_creadas = [];
        $this->medios_de_pago_creados = [];

        $this->olvidarLasMemorias();

        /* Por si una corrida anterior murio con el esquema escondido. */
        $this->restaurarElEsquema();

        $this->assertTrue(ComboEsquemaHelper::disponible(),
            'La base del slot tiene que ARRANCAR con el esquema de combos entero.');
        $this->assertTrue(ArticlePriceRangeHelper::hay_tabla(),
            'La base del slot tiene que ARRANCAR con article_price_ranges.');
    }

    /**
     * Deja la base como estaba, pase lo que pase: primero el esquema —sin condiciones, porque una
     * tabla escondida se lleva puesta la suite entera— y despues los datos.
     */
    protected function tearDown(): void
    {
        $this->restaurarElEsquema();
        $this->limpiarLoCreado();
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Los combos
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 Sin el esquema de combos, la tienda entera sigue andando y la seccion nueva viene vacia.
     *
     * La home y el carrito de punta a punta en un solo caso, porque el escenario es uno solo —el
     * cliente que actualizo la tienda antes que el ERP— y esconder el esquema cuesta tres DDL.
     *
     * El payload trae combos A PROPOSITO: un SPA nuevo contra una base vieja los va a mandar igual
     * (la clave `combos` la arma el front, no la base), y eso no puede reventar el checkout.
     */
    public function test_sin_el_esquema_de_combos_la_home_y_el_carrito_siguen_andando()
    {
        /* Los datos se crean ANTES de esconder nada y FUERA de transaccion: el DDL de mas abajo
           haria commit implicito igual, asi que se los borra a mano en el `finally`. */
        $comercio = $this->comercioCreado();
        $articulo = $this->articuloPublicado($comercio);
        $combo    = $this->combo($comercio);

        /* Con el esquema puesto, el combo SI esta: si esto no se verificara, el caso podria dar
           verde contra una home que nunca lista combos. */
        $this->assertCount(1, $this->combosDeLaHome($comercio->id),
            'el escenario arranca con el combo visible: si no, el caso seria vacuo');

        $this->esconderCombos();

        try {
            $this->olvidarLasMemorias();

            $this->assertFalse(ComboEsquemaHelper::disponible(),
                'con el esquema escondido la guarda tiene que dar false');

            /* 1. La home: 200 y la seccion vacia, no un 500. */
            $respuesta = $this->json('GET', '/api/articles/featured-last-uploads/'.$comercio->id.'?page=1');
            $respuesta->assertStatus(200);
            $this->assertSame([], $respuesta->json('combos'),
                'sin esquema la seccion de combos viene vacia, igual que si el comercio no tuviera ninguno');

            /* 2. El carrito, con combos en el payload: se guarda como siempre. */
            $creado = $this->crearCarrito($comercio, [
                'articles' => [$this->lineaDelPayload($articulo, 1)],
                'combos'   => [$this->lineaDeComboDelPayload($combo, 1)],
            ]);
            $creado->assertStatus(201);

            $cart_id = $this->carritoCreado($creado);

            $this->assertSame(self::PRECIO_NORMAL, $this->totalGuardado($cart_id),
                'el total es el del articulo solo: el combo del payload no se cuelga ni se suma');

            /* 3. Y los otros dos caminos que escriben el carrito, tambien. */
            $this->withSession(['carritos_propios' => [$cart_id]])
                ->putJson('/api/carts', [
                    'id'                   => $cart_id,
                    'articles'             => [$this->lineaDelPayload($articulo, 2)],
                    'promociones_vinoteca' => [],
                    'combos'               => [$this->lineaDeComboDelPayload($combo, 1)],
                ])
                ->assertStatus(200);

            $this->actualizarCantidad($cart_id, ['id' => $articulo->id, 'amount' => 3])
                ->assertStatus(200);

            /* 4. Y un payload que apunta al tercer pivote con `is_combo` contra una base sin
                  `cart_combo` tampoco puede reventar. */
            $this->actualizarCantidad($cart_id, [
                'id'       => $combo->id,
                'amount'   => 2,
                'is_combo' => true,
            ])->assertStatus(200);

            $this->assertSame(self::PRECIO_NORMAL * 3, $this->totalGuardado($cart_id));
        } finally {
            $this->restaurarCombos();
            $this->limpiarLoCreado();
        }

        $this->assertTrue(Schema::hasColumn('combos', 'online'), 'la base volvio a tener combos.online');
        $this->assertTrue(Schema::hasTable('cart_combo'), 'la base volvio a tener cart_combo');
        $this->assertTrue(Schema::hasTable('order_combo'), 'la base volvio a tener order_combo');
    }

    /**
     * 🔴 Un esquema A MEDIO APLICAR apaga los combos igual.
     *
     * `disponible()` exige LOS TRES objetos —la columna y las dos tablas— aunque lleguen todos en
     * la misma migracion. Un esquema a medias reventaria justo en el `POST /orders`, con el
     * comprador apretando "Confirmar compra", que es el peor lugar posible para enterarse.
     *
     * Es exactamente la razon por la que `ZipnovaEsquemaHelper::disponible()` mira `carts` Y
     * `orders` en vez de conformarse con una.
     */
    public function test_un_esquema_a_medio_aplicar_apaga_los_combos_igual()
    {
        /* Solo la columna: las dos tablas de pivote quedan puestas. */
        $this->esconderColumnaOnline();

        try {
            $this->olvidarLasMemorias();

            $this->assertTrue(Schema::hasTable('cart_combo'), 'el escenario es CON las tablas puestas');
            $this->assertTrue(Schema::hasTable('order_combo'));
            $this->assertFalse(Schema::hasColumn('combos', 'online'));

            $this->assertFalse(ComboEsquemaHelper::disponible(),
                'falta uno de los tres objetos: los combos quedan apagados enteros');
        } finally {
            $this->restaurarColumnaOnline();
        }

        $this->olvidarLasMemorias();

        $this->assertTrue(ComboEsquemaHelper::disponible(), 'la base volvio a estar completa');
    }

    /**
     * Y al reves: falta una tabla, sobra la columna. Mismo resultado.
     */
    public function test_con_la_columna_pero_sin_el_pivote_del_pedido_tambien_quedan_apagados()
    {
        $this->esconderTabla('order_combo');

        try {
            $this->olvidarLasMemorias();

            $this->assertTrue(Schema::hasColumn('combos', 'online'), 'el escenario es CON la columna puesta');
            $this->assertFalse(Schema::hasTable('order_combo'));

            $this->assertFalse(ComboEsquemaHelper::disponible(),
                'sin order_combo el pedido no podria copiar los combos: se apagan antes, no en el checkout');
        } finally {
            $this->restaurarTabla('order_combo');
        }

        $this->olvidarLasMemorias();

        $this->assertTrue(ComboEsquemaHelper::disponible());
    }

    /**
     * 🔴 SIN EL ESQUEMA DE COMBOS, EL COMPRADOR TIENE QUE PODER PAGAR IGUAL.
     *
     * Este caso es la guarda del arreglo de la fuga de plata de `articulos_a_cobrar()`
     * (`MercadoPagoController`): los combos eran una tercera colección del carrito que
     * `CartHelper::set_total()` sumaba y la preferencia de Mercado Pago NO cobraba. Al taparlo,
     * ese método pasó a leer `$cart->combos` — y `cart_combo` es justamente una de las tablas que
     * un cliente con la tienda nueva y el ERP viejo todavía no tiene.
     *
     * O sea: sin guarda, el arreglo de la fuga habría cambiado "cobrar de menos" por "no poder
     * cobrar nada", que es peor. Lo que se fija acá es que sin esquema el cobro queda exactamente
     * como en master: artículos y promociones de vinoteca, sin una excepción.
     *
     * ── Por qué este caso vive acá y no en `PrecioDeLaPreferenciaSaleDelCarritoTest` ──────────
     *
     * Sus tres hermanos —el invariante, el carrito de solo combos y el precio del pivote— sí están
     * allá, que es el molde del arreglo. Este no puede: probar "el esquema no está" exige DDL, y
     * MySQL le hace commit implícito a la transacción abierta, así que `DatabaseTransactions` —que
     * ese archivo usa— dejaría sembrado en la base del slot todo lo que el caso escribió. La
     * máquina de esconder/restaurar/limpiar a mano ya vive en esta clase y es la razón por la que
     * existe (ver su docblock).
     *
     * @return void
     */
    public function test_sin_el_esquema_de_combos_el_cobro_online_sigue_andando()
    {
        $comercio = $this->comercioCreado();
        $articulo = $this->articuloPublicado($comercio);
        $promo    = $this->promocionVinotecaCreada($comercio, 5000);
        $combo    = $this->combo($comercio);

        $cart = $this->carritoDeCobro($comercio, [
            'articulo' => [$articulo->id, 1000],
            'promo'    => [$promo->id, 5000],
            'combo'    => [$combo->id, 9000],
        ]);

        $payment_method = $this->medioDePagoCreado($comercio);

        /* Contraprueba: CON el esquema puesto, el combo SÍ entra en lo que se cobra. Sin esto, el
           caso de abajo daría verde incluso contra un `articulos_a_cobrar()` que nunca mire los
           combos — o sea, contra la fuga de plata sin arreglar. */
        $con_esquema = $this->articulosACobrar($comercio, $payment_method, $cart->fresh());

        $this->assertCount(3, $con_esquema,
            'el escenario arranca con las TRES colecciones en la preferencia: si no, el caso sería vacuo');
        $this->assertSame(15000.0, $this->sumaDeLosItems($con_esquema),
            '$1.000 de artículo + $5.000 de promoción + $9.000 de combo');

        $this->esconderCombos();

        try {
            $this->olvidarLasMemorias();

            $this->assertFalse(ComboEsquemaHelper::disponible(),
                'con el esquema escondido la guarda tiene que dar false');

            $items = $this->articulosACobrar($comercio, $payment_method, $cart->fresh());

            $this->assertCount(2, $items,
                'sin esquema se cobran las dos colecciones de siempre: el artículo y la promoción');
            $this->assertSame(6000.0, $this->sumaDeLosItems($items),
                'y por el importe de master, sin el combo que esta base no sabe que existe');
        } finally {
            $this->restaurarCombos();
            $this->limpiarLoCreado();
        }

        $this->assertTrue(ComboEsquemaHelper::disponible(), 'la base volvió a tener el esquema de combos');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Los tramos por cantidad
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 Sin `article_price_ranges`, la tienda sigue andando y los precios son los de siempre.
     *
     * Esta guarda es la mas critica de las dos: el eager load de los tramos vive en
     * `Article::scopeWithAll()`, que esta en el camino de TODOS los listados de la tienda. Sin la
     * guarda, una base sin esa tabla no perderia una seccion — perderia la tienda.
     *
     * El articulo del caso tiene tramos cargados ANTES de esconder la tabla, asi que lo que se
     * mide es la guarda y no la falta de datos.
     */
    public function test_sin_la_tabla_de_tramos_la_tienda_sigue_andando_y_cobra_el_precio_normal()
    {
        $comercio = $this->comercioCreado();
        $articulo = $this->conLaEscalaDeLaReproduccion($this->articuloPublicado($comercio));

        /* Con la tabla puesta, el tramo SI se cobra: la contraprueba que impide que el caso de
           abajo de verde contra un helper que no aplique nunca ningun tramo. */
        $con_tabla = $this->crearCarrito($comercio, [
            'articles' => [$this->lineaDelPayload($articulo, 10)],
        ]);
        $con_tabla->assertStatus(201);
        $this->assertSame(self::TRAMO_10, $this->precioGuardado($this->carritoCreado($con_tabla), $articulo->id),
            'el escenario arranca con el tramo funcionando: si no, el caso seria vacuo');

        $this->esconderTabla('article_price_ranges');

        try {
            $this->olvidarLasMemorias();

            $this->assertFalse(ArticlePriceRangeHelper::hay_tabla(),
                'con la tabla escondida la guarda tiene que dar false');

            /* 1. La home: 200 y la seccion nueva vacia. */
            $respuesta = $this->json('GET', '/api/articles/featured-last-uploads/'.$comercio->id.'?page=1');
            $respuesta->assertStatus(200);
            $this->assertSame([], $respuesta->json('articulos_con_rangos'),
                'sin tabla la seccion de "comprando mas, pagas menos" viene vacia');
            $this->assertNotEmpty($respuesta->json('articles'),
                'y el resto de la home sigue llegando: el eager load de scopeWithAll no puede tumbarla');

            /* 2. El detalle del articulo, que es el otro camino que pasa por scopeWithAll. */
            $this->json('GET', '/api/articles/'.$articulo->slug.'/'.$comercio->id)
                ->assertStatus(200);

            /* 3. El carrito: se guarda al precio normal, no al del tramo. */
            $creado = $this->crearCarrito($comercio, [
                'articles' => [$this->lineaDelPayload($articulo, 10)],
            ]);
            $creado->assertStatus(201);

            $cart_id = $this->carritoCreado($creado);

            $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($cart_id, $articulo->id),
                'sin la tabla no hay tramos: la linea sale al precio normal, como en master');

            /* 4. Y el boton "Actualizar" tampoco revienta. */
            $this->actualizarCantidad($cart_id, ['id' => $articulo->id, 'amount' => 1])
                ->assertStatus(200);
        } finally {
            $this->restaurarTabla('article_price_ranges');
            $this->limpiarLoCreado();
        }

        $this->assertTrue(Schema::hasTable('article_price_ranges'), 'la base volvio a tener article_price_ranges');
    }

    /**
     * 🔴 EL ULTIMO CASO, Y NO ES DECORATIVO: la base quedo exactamente como estaba.
     *
     * Los cuatro casos de arriba hacen DDL sobre la base que comparten TODOS los tests de este
     * slot. Si uno solo dejara una tabla renombrada, la suite entera se cae despues — y con un
     * error que no nombra la causa. Este caso lo denuncia acá y no alla.
     */
    public function test_la_base_quedo_como_estaba()
    {
        foreach (self::TABLAS as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), $tabla.' tiene que estar con su nombre real');
            $this->assertFalse(Schema::hasTable($tabla.self::SUFIJO), 'no puede quedar escondida '.$tabla);
        }

        $this->assertTrue(Schema::hasColumn('combos', 'online'));
        $this->assertFalse(Schema::hasColumn('combos', 'online'.self::SUFIJO));

        $this->olvidarLasMemorias();

        $this->assertTrue(ComboEsquemaHelper::disponible());
        $this->assertTrue(ArticlePriceRangeHelper::hay_tabla());
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Las fixtures y los ayudantes de lectura
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Los combos de la home de un comercio.
     *
     * @param  int  $commerce_id
     * @return array
     */
    private function combosDeLaHome($commerce_id)
    {
        return $this->json('GET', '/api/articles/featured-last-uploads/'.$commerce_id.'?page=1')
                    ->assertStatus(200)
                    ->json('combos');
    }

    /* ── Las fixtures del cobro online ────────────────────────────────────────────────────────
       Van acá y no en el trait porque solo las usa el caso del cobro. Todo lo que crean queda
       anotado para que `limpiarLoCreado()` lo borre: en esta clase no hay transacción que
       revierta. */

    /**
     * Una promoción de vinoteca del comercio, anotada para borrarla después.
     *
     * @param  \App\User  $comercio
     * @param  float  $final_price
     * @return \App\PromocionVinoteca
     */
    private function promocionVinotecaCreada($comercio, $final_price)
    {
        $promo = $this->promocionVinoteca($comercio, $final_price);

        $this->promociones_creadas[] = $promo->id;

        return $promo;
    }

    /**
     * El medio de pago con el que se arma la preferencia, anotado para borrarlo después.
     *
     * @param  \App\User  $comercio
     * @return \App\PaymentMethod
     */
    private function medioDePagoCreado($comercio)
    {
        $tipo = PaymentMethodType::where('name', 'MercadoPago')->first();

        if (!$tipo) {
            $tipo = new PaymentMethodType;
            $tipo->name = 'MercadoPago';
            $tipo->save();
        }

        $payment_method = new PaymentMethod;
        $payment_method->name                   = 'Mercado Pago';
        $payment_method->user_id                = $comercio->id;
        $payment_method->payment_method_type_id = $tipo->id;
        $payment_method->save();

        $this->medios_de_pago_creados[] = $payment_method->id;

        return $payment_method;
    }

    /**
     * Un carrito con las TRES colecciones colgadas a mano, con el precio congelado en cada pivote.
     *
     * Directo con `attach()` y no por el endpoint: lo que este caso mide es el cobro, no el
     * armado del carrito (eso ya lo cubre `test_sin_el_esquema_de_combos_la_home_y_el_carrito_siguen_andando`).
     *
     * @param  \App\User  $comercio
     * @param  array  $lineas  ['articulo' => [id, precio], 'promo' => [...], 'combo' => [...]]
     * @return \App\Cart
     */
    private function carritoDeCobro($comercio, array $lineas)
    {
        $cart = Cart::create(['user_id' => $comercio->id, 'buyer_id' => null]);

        $this->carritos_creados[] = $cart->id;

        $cart->articles()->attach($lineas['articulo'][0], ['amount' => 1, 'price' => $lineas['articulo'][1]]);
        $cart->promociones_vinoteca()->attach($lineas['promo'][0], ['amount' => 1, 'price' => $lineas['promo'][1]]);
        $cart->combos()->attach($lineas['combo'][0], ['amount' => 1, 'price' => $lineas['combo'][1], 'cost' => 0]);

        return $cart;
    }

    /**
     * Invoca `MercadoPagoController::articulos_a_cobrar()` por reflexión, igual que
     * `PrecioDeLaPreferenciaSaleDelCarritoTest`.
     *
     * @param  \App\User  $comercio
     * @param  \App\PaymentMethod  $payment_method
     * @param  \App\Cart  $cart
     * @return array
     */
    private function articulosACobrar($comercio, $payment_method, $cart)
    {
        $controller = new MercadoPagoController();
        $metodo = new ReflectionMethod($controller, 'articulos_a_cobrar');
        $metodo->setAccessible(true);

        return $metodo->invoke($controller, Request::create('/api/mercado-pago/preference', 'POST'), $comercio, $payment_method, $cart);
    }

    /**
     * Lo que Mercado Pago le va a cobrar al comprador.
     *
     * @param  array  $items
     * @return float
     */
    private function sumaDeLosItems(array $items)
    {
        return array_sum(array_map(function ($item) {
            return $item['final_price'] * $item['amount'];
        }, $items));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El esconder y el restaurar
    |---------------------------------------------------------------------------------------------
    */

    /** Esconde los tres objetos de los combos. */
    private function esconderCombos()
    {
        $this->esconderColumnaOnline();
        $this->esconderTabla('cart_combo');
        $this->esconderTabla('order_combo');
    }

    /** Y los devuelve. Idempotente. */
    private function restaurarCombos()
    {
        $this->restaurarColumnaOnline();
        $this->restaurarTabla('cart_combo');
        $this->restaurarTabla('order_combo');
    }

    /** Devuelve TODO lo que esta clase puede haber escondido. Idempotente. */
    private function restaurarElEsquema()
    {
        $this->restaurarColumnaOnline();

        foreach (self::TABLAS as $tabla) {
            $this->restaurarTabla($tabla);
        }
    }

    private function esconderColumnaOnline()
    {
        if (Schema::hasColumn('combos', 'online')) {
            DB::statement('ALTER TABLE `combos` RENAME COLUMN `online` TO `online'.self::SUFIJO.'`');
        }
    }

    private function restaurarColumnaOnline()
    {
        if (Schema::hasColumn('combos', 'online'.self::SUFIJO)) {
            DB::statement('ALTER TABLE `combos` RENAME COLUMN `online'.self::SUFIJO.'` TO `online`');
        }
    }

    private function esconderTabla($tabla)
    {
        if (Schema::hasTable($tabla)) {
            DB::statement('RENAME TABLE `'.$tabla.'` TO `'.$tabla.self::SUFIJO.'`');
        }
    }

    private function restaurarTabla($tabla)
    {
        if (Schema::hasTable($tabla.self::SUFIJO)) {
            DB::statement('RENAME TABLE `'.$tabla.self::SUFIJO.'` TO `'.$tabla.'`');
        }
    }

    /**
     * Un comercio de las fixtures, anotado para borrarlo despues.
     *
     * @return \App\User
     */
    private function comercioCreado()
    {
        $comercio = $this->comercioConTienda();

        $this->comercios_creados[] = $comercio->id;

        return $comercio;
    }

    /**
     * El id del carrito que devolvio el endpoint, anotado para borrarlo despues.
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return int
     */
    private function carritoCreado($respuesta)
    {
        $cart_id = (int) $respuesta->json('cart.id');

        $this->carritos_creados[] = $cart_id;

        return $cart_id;
    }

    /**
     * Borra lo que esta clase escribio.
     *
     * 🔴 Va por ids EXACTOS y nunca por un `like` sobre el mail de las fixtures: ese patron
     * tambien matchea los comercios que crean las otras clases de esta carpeta, y un borrado por
     * patron sobre la base compartida del slot es la clase de limpieza que un dia se lleva puesto
     * el fixture de otro test. Idempotente: se llama en el `finally` Y en el `tearDown`.
     *
     * @return void
     */
    private function limpiarLoCreado()
    {
        if (!empty($this->carritos_creados)) {
            DB::table('article_cart')->whereIn('cart_id', $this->carritos_creados)->delete();
            DB::table('cart_promocion_vinoteca')->whereIn('cart_id', $this->carritos_creados)->delete();

            if (Schema::hasTable('cart_combo')) {
                DB::table('cart_combo')->whereIn('cart_id', $this->carritos_creados)->delete();
            }

            DB::table('carts')->whereIn('id', $this->carritos_creados)->delete();

            $this->carritos_creados = [];
        }

        if (!empty($this->medios_de_pago_creados)) {
            DB::table('payment_methods')->whereIn('id', $this->medios_de_pago_creados)->delete();

            $this->medios_de_pago_creados = [];
        }

        if (!empty($this->promociones_creadas)) {
            DB::table('promocion_vinotecas')->whereIn('id', $this->promociones_creadas)->delete();

            $this->promociones_creadas = [];
        }

        if (empty($this->comercios_creados)) {
            return;
        }

        $articulos = DB::table('articles')->whereIn('user_id', $this->comercios_creados)->pluck('id')->all();

        if (!empty($articulos)) {
            if (Schema::hasTable('article_price_ranges')) {
                DB::table('article_price_ranges')->whereIn('article_id', $articulos)->delete();
            }

            DB::table('articles')->whereIn('id', $articulos)->delete();
        }

        DB::table('combos')->whereIn('user_id', $this->comercios_creados)->delete();
        DB::table('online_configurations')->whereIn('user_id', $this->comercios_creados)->delete();
        DB::table('users')->whereIn('id', $this->comercios_creados)->delete();

        $this->comercios_creados = [];
    }
}
