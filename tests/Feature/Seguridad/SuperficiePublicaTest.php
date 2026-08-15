<?php

namespace Tests\Feature\Seguridad;

use App\Buyer;
use App\Cart;
use App\Order;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Superficie publica de la API de la tienda (mision seguridad-api-publica-tienda, 15/8/2026).
 *
 * ── Que modos de falla cubre esta clase ───────────────────────────────────────────────────────
 *
 *   - que una ruta de la cuenta del comprador vuelva a quedar accesible sin sesion;
 *   - que alguien se convierta en otro comprador sabiendo su email (POST /api/buyer era una toma
 *     de cuenta);
 *   - que se pueda leer, modificar o BORRAR el carrito de otro por id;
 *   - que un secreto vuelva a viajar en una respuesta;
 *   - y —lo mas importante, porque es lo que mas facil se rompe arreglando lo de arriba— que el
 *     flujo de compra de invitado siga funcionando.
 *
 * ⚠️ Sobre la base: `tienda-api` no tiene database/migrations (el esquema lo gobierna
 * `empresa-api`), asi que aca NO se usa RefreshDatabase ni migrate. Se corre contra la base real
 * del slot con DatabaseTransactions.
 *
 * ⚠️ Sobre la sesion: phpunit.xml fija SESSION_DRIVER=array, que no persiste entre requests. Los
 * tests que necesitan una sesion con estado la siembran con withSession(). Eso NO es hacer trampa:
 * lo que se esta probando es la decision del controller dado un estado de sesion, no que Laravel
 * sepa persistir sesiones. Lo otro —que la sesion de verdad sobreviva entre requests en un
 * cliente real— no se puede probar aca y se verifico con curl contra el servidor levantado; esta
 * anotado en el informe de la mision.
 */
class SuperficiePublicaTest extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Buyer  cuenta de verdad, con contraseña */
    private $victima;

    /** @var \App\Buyer  ficha creada por un checkout de invitado, sin credencial */
    private $ficha_invitado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        $this->victima = Buyer::create([
            'name'     => 'Victima Test',
            'email'    => 'victima-test-'.uniqid().'@example.com',
            'phone'    => '3440000001',
            'address'  => 'Calle Privada 742',
            'password' => bcrypt('una-contrasena-real'),
            'user_id'  => $this->comercio->id,
        ]);

        $this->ficha_invitado = Buyer::create([
            'name'    => 'Ficha Invitado Test',
            'email'   => 'ficha-test-'.uniqid().'@example.com',
            'phone'   => '3440000002',
            'user_id' => $this->comercio->id,
        ]);
    }

    /**
     * Las rutas de la cuenta responden 401 sin sesion.
     *
     * Es la lista completa de lo que quedo detras de auth:buyer. Si alguien saca una del grupo,
     * este test se pone rojo con el nombre de la ruta adentro del mensaje.
     */
    public function test_las_rutas_de_la_cuenta_piden_sesion()
    {
        $rutas = [
            ['GET',  '/api/user'],
            ['GET',  '/api/orders'],
            ['GET',  '/api/messages'],
            ['GET',  '/api/messages/set-read'],
            ['POST', '/api/messages'],
            ['GET',  '/api/last-searchs'],
            ['GET',  '/api/notifications'],
            ['GET',  '/api/notifications/unread'],
            ['POST', '/api/notifications/mark-as-read'],
            ['GET',  '/api/cupons'],
            ['GET',  '/api/cupons/set-read'],
            ['GET',  '/api/favorites'],
            ['GET',  '/api/questions'],
            ['POST', '/api/questions'],
            ['POST', '/api/addresses'],
            ['GET',  '/api/buyer-message'],
            ['POST', '/api/buyer-message'],
            ['PUT',  '/api/buyer'],
            ['PUT',  '/api/buyer/phone'],
            ['PUT',  '/api/buyer/password'],
            ['POST', '/api/payments'],
            ['GET',  '/api/calls/waiting-call'],
            ['POST', '/api/calls'],
            ['GET',  '/api/customers/cards/alguien@example.com'],
            ['GET',  '/api/buyer/search/algo/'.$this->comercio->id],
            ['GET',  '/api/carts/from-order/1'],
        ];

        foreach ($rutas as $ruta) {
            list($metodo, $url) = $ruta;

            $this->json($metodo, $url)->assertStatus(401, $metodo.' '.$url.' tiene que pedir sesion');
        }
    }

    /**
     * 🔴 El agujero mas grave que encontro la mision.
     *
     * POST /api/buyer buscaba el comprador por email + comercio y lo logueaba en el guard 'buyer'
     * sin pedir contraseña. Sabiendo el mail de alguien te convertias en esa persona.
     */
    public function test_no_se_puede_entrar_a_una_cuenta_ajena_mandando_solo_el_email()
    {
        $this->json('POST', '/api/buyer', [
            'email'       => $this->victima->email,
            'commerce_id' => $this->comercio->id,
        ])->assertStatus(200);

        // El checkout puede seguir, pero la cuenta NO se abrio.
        $this->json('GET', '/api/user')->assertStatus(401);
        $this->json('GET', '/api/orders')->assertStatus(401);
        $this->json('GET', '/api/messages')->assertStatus(401);
    }

    /**
     * La otra puerta a la misma casa: AuthController@social crea el comprador de Google SIN
     * contraseña. Si la regla mirara solo el password, esa cuenta seguiria siendo tomable.
     */
    public function test_tampoco_se_puede_entrar_a_una_cuenta_de_google_mandando_solo_el_email()
    {
        $de_google = Buyer::create([
            'name'        => 'Cuenta Google Test',
            'email'       => 'google-test-'.uniqid().'@example.com',
            'provider_id' => '11223344556677889900',
            'user_id'     => $this->comercio->id,
        ]);

        $this->json('POST', '/api/buyer', [
            'email'       => $de_google->email,
            'commerce_id' => $this->comercio->id,
        ])->assertStatus(200);

        $this->json('GET', '/api/user')->assertStatus(401);
    }

    /**
     * 🔴 Cerrar la toma de sesion no alcanzaba: POST /api/buyer seguia VOLCANDO el perfil entero
     * del comprador (via el scope withAll(): direcciones, el Client del ERP y todo su historial
     * de mensajes privados) a cualquiera que supiera el mail. Lo encontro la verificacion
     * independiente, despues del primer arreglo.
     */
    public function test_no_se_puede_leer_el_perfil_de_una_cuenta_ajena_mandando_solo_el_email()
    {
        $respuesta = $this->json('POST', '/api/buyer', [
            'email'       => $this->victima->email,
            'commerce_id' => $this->comercio->id,
        ])->assertStatus(200);

        $modelo = $respuesta->json('model');

        foreach (['name', 'surname', 'phone', 'email', 'messages', 'comercio_city_client'] as $clave) {
            $this->assertArrayNotHasKey($clave, $modelo,
                'POST /api/buyer no puede devolver '.$clave.' de una cuenta ajena: es una ruta publica resuelta por email.');
        }

        // `addresses` SI viaja, pero vacio: mixins/app.js::checkAddress() lee `.length` y una clave
        // ausente ahi es un TypeError. Lo que importa es que no traiga las direcciones de nadie.
        $this->assertSame([], $modelo['addresses'],
            'addresses tiene que llegar vacio: la clave existe por el SPA, no para mandar datos.');
    }

    /**
     * 🔴 Y tampoco puede ESCRIBIRLE. La ruta es publica y se resuelve por email: si actualizara la
     * direccion de cualquiera, se le podria reescribir la direccion de entrega a otra persona en
     * la base que la tienda comparte con el ERP.
     */
    public function test_no_se_puede_pisar_la_direccion_de_una_cuenta_ajena()
    {
        $original = $this->victima->address;

        $this->json('POST', '/api/buyer', [
            'email'       => $this->victima->email,
            'commerce_id' => $this->comercio->id,
            'address'     => 'Calle Del Atacante 999',
            'ciudad'      => 'Ciudad Falsa',
        ])->assertStatus(200);

        $this->victima->refresh();

        $this->assertSame($original, $this->victima->address,
            'La direccion de una cuenta con credencial no se puede cambiar desde una ruta publica.');
    }

    /**
     * La contracara: la ficha de un invitado no es una cuenta y tiene que seguir logueandose, o se
     * rompe el flujo mas usado de la tienda.
     */
    public function test_la_ficha_de_un_invitado_si_abre_sesion_como_antes()
    {
        $this->json('POST', '/api/buyer', [
            'email'       => $this->ficha_invitado->email,
            'commerce_id' => $this->comercio->id,
        ])->assertStatus(200);

        $this->json('GET', '/api/user')
            ->assertStatus(200)
            ->assertJsonPath('buyer.id', $this->ficha_invitado->id);
    }

    /**
     * 🔴 Medido antes de arreglar: un DELETE sin ninguna sesion borraba el carrito de otro
     * comprador, y la fila desaparecia de la base.
     */
    public function test_no_se_puede_borrar_el_carrito_de_otro()
    {
        $carrito = $this->carritoDe($this->victima);

        $this->json('DELETE', '/api/carts/'.$carrito->id)->assertStatus(403);

        $this->assertDatabaseHas('carts', ['id' => $carrito->id]);
    }

    /** Mismo IDOR, en las otras dos rutas que escriben. */
    public function test_no_se_puede_modificar_el_carrito_de_otro()
    {
        $carrito = $this->carritoDe($this->victima);

        $this->json('PUT', '/api/carts', [
            'id'                    => $carrito->id,
            'articles'              => [],
            'promociones_vinoteca'  => [],
        ])->assertStatus(403);

        $this->json('PUT', '/api/carts/update-article-amount/'.$carrito->id, [
            'id'     => 1,
            'amount' => 99,
        ])->assertStatus(403);

        // Y sigue igual: el PUT con arrays vacios, si hubiera pasado, lo habria borrado.
        $this->assertDatabaseHas('carts', ['id' => $carrito->id]);
    }

    /**
     * 🔴 La fuga por whereNull, que es la clase de error de casi toda esta mision.
     *
     * `carts.buyer_id` es NULLABLE, y Eloquent convierte where('buyer_id', null) en
     * whereNull('buyer_id'). O sea que lastCart le devolvia a un visitante el carrito del ultimo
     * invitado que hubiera pasado por esa tienda.
     */
    public function test_last_cart_no_devuelve_el_carrito_de_otro_invitado()
    {
        Cart::create([
            'buyer_id' => null,
            'user_id'  => $this->comercio->id,
            'total'    => 555.55,
        ]);

        $this->json('GET', '/api/carts/last-cart/'.$this->comercio->id)
            ->assertStatus(200)
            ->assertJsonPath('has_last_cart', false);
    }

    /**
     * Pero el invitado SI tiene que encontrar el suyo: es la pieza con la que el SPA reconstruye
     * el carrito al volver de Mercado Pago. Cortar el whereNull sin esto rompe el medio de pago
     * dominante.
     */
    public function test_el_invitado_si_encuentra_el_carrito_que_creo_su_sesion()
    {
        $carrito = Cart::create([
            'buyer_id' => null,
            'user_id'  => $this->comercio->id,
            'total'    => 100,
        ]);

        $this->withSession(['carritos_propios' => [$carrito->id]])
            ->json('GET', '/api/carts/last-cart/'.$this->comercio->id)
            ->assertStatus(200)
            ->assertJsonPath('has_last_cart', true)
            ->assertJsonPath('last_cart.id', $carrito->id);
    }

    /** Y puede modificarlo y borrarlo, que es lo que hace el checkout. */
    public function test_el_invitado_si_puede_borrar_el_carrito_que_creo_su_sesion()
    {
        $carrito = Cart::create([
            'buyer_id' => null,
            'user_id'  => $this->comercio->id,
            'total'    => 100,
        ]);

        $this->withSession(['carritos_propios' => [$carrito->id]])
            ->json('DELETE', '/api/carts/'.$carrito->id)
            ->assertStatus(200);

        $this->assertDatabaseMissing('carts', ['id' => $carrito->id]);
    }

    /**
     * 🔴 El invitado que arma el carrito y DESPUES se loguea con su cuenta tiene que poder comprar.
     *
     * Es una regresion que introdujo esta misma mision y que atrapo el revisor de merge midiendo
     * contra master: el borrado de `carritos_propios` en el login —puesto para proteger el
     * dispositivo compartido— dejaba al comprador sin poder tocar su propio carrito, porque el de
     * invitado tiene buyer_id NULL y nunca habia sido adoptado. 403 en PUT /carts y en POST /orders,
     * y mudo.
     *
     * Ningun otro test lo cubria: todos siembran la sesion a mano con withSession() y ninguno
     * ejercita el login DESPUES de un carrito de invitado.
     */
    public function test_el_invitado_que_se_loguea_no_pierde_su_carrito()
    {
        $carrito = Cart::create([
            'buyer_id' => null,
            'user_id'  => $this->comercio->id,
            'total'    => 100,
        ]);

        // La sesion del invitado: creo ese carrito con POST /api/carts.
        $this->withSession(['carritos_propios' => [$carrito->id]]);

        // Y ahora se loguea con su cuenta de verdad.
        $this->json('POST', '/login', [
            'email'       => $this->victima->email,
            'password'    => 'una-contrasena-real',
            'commerce_id' => $this->comercio->id,
        ])->assertStatus(200);

        // El carrito paso a ser suyo, no quedo huerfano.
        $carrito->refresh();
        $this->assertSame((int) $this->victima->id, (int) $carrito->buyer_id,
            'El carrito que el invitado creo antes de loguearse tiene que quedar atado a su cuenta.');

        // Y puede seguir operandolo: es el flujo "navego, cargo el carrito, me logueo para comprar".
        $this->actingAs($this->victima, 'buyer')
            ->json('PUT', '/api/carts/update-article-amount/'.$carrito->id, ['id' => 1, 'amount' => 3])
            ->assertStatus(200);
    }

    /**
     * La contracara, que es el motivo por el que existe el traspaso y no un "adoptar todo":
     * el carrito que YA es de otro comprador no se lo lleva el que entra.
     */
    public function test_el_que_se_loguea_no_se_lleva_el_carrito_de_otro_comprador()
    {
        $ajeno = $this->carritoDe($this->victima);

        $this->withSession(['carritos_propios' => [$ajeno->id]]);

        $this->json('POST', '/login', [
            'email'       => $this->ficha_invitado->email,
            'password'    => 'no-tiene',
            'commerce_id' => $this->comercio->id,
        ]);

        $ajeno->refresh();
        $this->assertSame((int) $this->victima->id, (int) $ajeno->buyer_id,
            'Un carrito que ya tiene dueño no cambia de dueño porque otro se loguee en el mismo navegador.');
    }

    /**
     * 🔴 IDOR enumerable por order_id: devolvia el carrito completo de otro comprador.
     *
     * No se cierra por sesion sino por orders.buyer_id, porque el SPA la llama desde un mensaje
     * viejo, en otra sesion (mixins/messages.js:59).
     */
    public function test_no_se_puede_ver_el_carrito_del_pedido_de_otro()
    {
        $pedido = Order::create([
            'num'        => 999999,
            'buyer_id'   => $this->victima->id,
            'user_id'    => $this->comercio->id,
            'total'      => 1234,
            // Las columnas NOT NULL sin default de `orders` en el esquema del ERP, medidas contra
            // information_schema: buyer_id, deliver, order_status_id, status y user_id.
            'deliver'         => 0,
            'order_status_id' => 1,
            'status'          => 'unconfirmed',
            'address_id'      => 0,
        ]);

        Cart::create([
            'buyer_id' => $this->victima->id,
            'user_id'  => $this->comercio->id,
            'order_id' => $pedido->id,
            'total'    => 1234,
        ]);

        // Sin sesion: la ruta esta detras de auth:buyer.
        $this->json('GET', '/api/carts/from-order/'.$pedido->id)->assertStatus(401);

        // Con la sesion de OTRO comprador: 403, no el carrito ajeno.
        $this->actingAs($this->ficha_invitado, 'buyer')
            ->json('GET', '/api/carts/from-order/'.$pedido->id)
            ->assertStatus(403);

        // Y el dueño si lo ve.
        $this->actingAs($this->victima, 'buyer')
            ->json('GET', '/api/carts/from-order/'.$pedido->id)
            ->assertStatus(200);
    }

    /**
     * El buscador de clientes del vendedor: pide sesion, pide seller_id, y no se lleva la base de
     * otro comercio cambiando el numero de la URL.
     */
    public function test_el_buscador_de_compradores_es_solo_para_vendedores()
    {
        $this->actingAs($this->ficha_invitado, 'buyer')
            ->json('GET', '/api/buyer/search/test/'.$this->comercio->id)
            ->assertStatus(403);

        $vendedor = Buyer::create([
            'name'      => 'Vendedor Test',
            'email'     => 'vendedor-test-'.uniqid().'@example.com',
            'seller_id' => 1,
            'user_id'   => $this->comercio->id,
        ]);

        $respuesta = $this->actingAs($vendedor, 'buyer')
            ->json('GET', '/api/buyer/search/test/'.$this->comercio->id)
            ->assertStatus(200);

        // Aunque no matchee nadie, lo que importa es que la forma no traiga credenciales.
        $cuerpo = $respuesta->json('buyers');
        foreach ($cuerpo as $comprador) {
            $this->assertArrayNotHasKey('password', $comprador);
            $this->assertArrayNotHasKey('remember_token', $comprador);
            $this->assertArrayNotHasKey('verification_code', $comprador);
        }
    }

    /**
     * Un vendedor de un comercio no se lleva la base de compradores de otro comercio cambiando el
     * commerce_id de la URL: el controller lo ignora y usa el del vendedor.
     */
    public function test_el_vendedor_no_puede_pedir_los_compradores_de_otro_comercio()
    {
        // No hace falta crear un segundo comercio en la tabla `users` (es del ERP y tiene decenas
        // de columnas obligatorias): alcanza con compradores atados a otro user_id, que es
        // exactamente el dato por el que filtra el controller.
        $otro_comercio_id = $this->comercio->id + 99999;

        $ajeno = Buyer::create([
            'name'                    => 'Zzz Ajeno Test',
            'email'                   => 'ajeno-test-'.uniqid().'@example.com',
            'comercio_city_client_id' => 1,
            'user_id'                 => $otro_comercio_id,
        ]);

        $vendedor = Buyer::create([
            'name'      => 'Vendedor Test',
            'email'     => 'vendedor2-test-'.uniqid().'@example.com',
            'seller_id' => 1,
            'user_id'   => $this->comercio->id,
        ]);

        // El vendedor pide explicitamente el comercio ajeno en la URL. El controller lo ignora.
        $ids = $this->actingAs($vendedor, 'buyer')
            ->json('GET', '/api/buyer/search/Ajeno/'.$otro_comercio_id)
            ->assertStatus(200)
            ->json('buyers.*.id');

        $this->assertNotContains($ajeno->id, $ids ? $ids : [],
            'El vendedor no puede llevarse la base de compradores de otro comercio cambiando el id de la URL.');
    }

    /**
     * El pedido se le atribuye a quien lo hace, no a quien diga el request.
     */
    public function test_no_se_puede_crear_un_pedido_a_nombre_de_otro()
    {
        $carrito = Cart::create([
            'buyer_id' => null,
            'user_id'  => $this->comercio->id,
            'total'    => 100,
        ]);

        $antes = Order::where('buyer_id', $this->victima->id)->count();

        $this->withSession(['carritos_propios' => [$carrito->id]])
            ->actingAs($this->ficha_invitado, 'buyer')
            ->json('POST', '/api/orders', [
                'commerce_id' => $this->comercio->id,
                'cart_id'     => $carrito->id,
                'buyer_id'    => $this->victima->id,
                'address'     => 'Calle Test 1',
            ]);

        $this->assertSame($antes, Order::where('buyer_id', $this->victima->id)->count(),
            'El buyer_id del request no puede atribuirle el pedido a otro comprador.');
    }

    /**
     * 🔴 Ser vendedor no alcanza para cargarle un pedido a cualquiera: tiene que ser un comprador
     * DE SU COMERCIO.
     *
     * Lo encontro la revision independiente del diff. El primer arreglo solo comprobaba
     * `seller_id`, asi que un vendedor del comercio A podia crear un pedido a nombre de un cliente
     * del comercio B — y como la tienda comparte base con el ERP, ese pedido confirmado termina en
     * una venta contra la cuenta corriente de esa persona.
     */
    public function test_un_vendedor_no_puede_cargar_un_pedido_a_un_comprador_de_otro_comercio()
    {
        $otro_comercio_id = $this->comercio->id + 99999;

        $ajeno = Buyer::create([
            'name'    => 'Comprador De Otro Comercio',
            'email'   => 'otro-comercio-'.uniqid().'@example.com',
            'user_id' => $otro_comercio_id,
        ]);

        $vendedor = Buyer::create([
            'name'      => 'Vendedor Test',
            'email'     => 'vendedor3-test-'.uniqid().'@example.com',
            'seller_id' => 1,
            'user_id'   => $this->comercio->id,
        ]);

        $carrito = Cart::create([
            'buyer_id' => null,
            'user_id'  => $this->comercio->id,
            'total'    => 100,
        ]);

        $antes = Order::where('buyer_id', $ajeno->id)->count();

        $this->withSession(['carritos_propios' => [$carrito->id]])
            ->actingAs($vendedor, 'buyer')
            ->json('POST', '/api/orders', [
                'commerce_id' => $this->comercio->id,
                'cart_id'     => $carrito->id,
                'buyer_id'    => $ajeno->id,
                'address'     => 'Calle Test 1',
            ]);

        $this->assertSame($antes, Order::where('buyer_id', $ajeno->id)->count(),
            'Un vendedor no puede atribuirle un pedido a un comprador de otro comercio.');
    }

    /**
     * Y sin `Accept: application/json` el 401 tiene que seguir siendo 401.
     *
     * 🔴 No es un detalle cosmetico. El handler de Laravel 10 hace
     * `redirect()->guest($exception->redirectTo() ?? route('login'))`, y en este repo no existe
     * ninguna ruta con nombre 'login': sin el override de Handler@unauthenticated eso es un 500
     * con la traza completa en el cuerpo. Y esta mision movio ~25 rutas detras de auth:buyer, o
     * sea que lo dispara cualquier curl o bot que pase por ahi.
     */
    public function test_sin_cabecera_accept_el_401_sigue_siendo_401_y_no_un_500()
    {
        $this->get('/api/orders', ['Accept' => 'text/html'])->assertStatus(401);
        $this->get('/api/user', ['Accept' => '*/*'])->assertStatus(401);
    }

    /** Y tampoco con el carrito de otro. */
    public function test_no_se_puede_crear_un_pedido_con_el_carrito_de_otro()
    {
        $ajeno = $this->carritoDe($this->victima);

        $this->actingAs($this->ficha_invitado, 'buyer')
            ->json('POST', '/api/orders', [
                'commerce_id' => $this->comercio->id,
                'cart_id'     => $ajeno->id,
                'address'     => 'Calle Test 1',
            ])
            ->assertStatus(403);
    }

    /**
     * Crea un carrito abierto de un comprador.
     *
     * @param  \App\Buyer  $buyer
     * @return \App\Cart
     */
    private function carritoDe($buyer)
    {
        return Cart::create([
            'buyer_id' => $buyer->id,
            'user_id'  => $this->comercio->id,
            'total'    => 999.99,
        ]);
    }
}
