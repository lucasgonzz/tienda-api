<?php

namespace Tests\Feature;

use App\Jobs\BroadcastOrderCreated;
use App\Notifications\OrderCreated;
use App\Order;
use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Aviso por broadcast de pedido nuevo (tarea 42).
 *
 * Sobre el alcance de estas pruebas, para que nadie lo lea de mas: `tienda-api` NO tiene carpeta
 * `database/migrations` (el esquema vive solo en las bases reales) y el slot no tiene base de
 * testing asignada, asi que NO se puede pegarle al endpoint POST /order con la base de por medio.
 * Lo que se prueba aca es el mecanismo completo del aviso —el Job real, la notificacion real, el
 * despacho real con dispatchAfterResponse() y el terminate() real del contenedor— sobre las dos
 * unicas tablas que ese camino toca, creadas a mano. Lo que queda sin cubrir esta declarado en el
 * INFORME.md de la tarea.
 */
class BroadcastOrderCreatedTest extends TestCase
{

    /**
     * Esquema minimo: las unicas tablas que toca el camino del aviso.
     * `messages` se crea solo para poder afirmar que sigue vacia.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('num')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('buyer_id')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->integer('buyer_id')->nullable();
            $table->text('text')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Comercio duenio de los pedidos de las pruebas.
     */
    private function comercio()
    {
        return User::create([
            'name'  => 'Comercio de prueba',
            'email' => 'comercio@test.local',
        ]);
    }

    /**
     * Criterio 1: crear un pedido emite el aviso al canal del comercio duenio, UNA sola vez,
     * con el payload esperado.
     */
    public function test_emite_una_sola_vez_al_canal_del_comercio_duenio()
    {
        Notification::fake();

        $comercio = $this->comercio();

        (new BroadcastOrderCreated(77, 1234, $comercio->id))->handle();

        Notification::assertSentToTimes($comercio, OrderCreated::class, 1);

        Notification::assertSentTo($comercio, OrderCreated::class, function ($notification) use ($comercio) {
            $canales = $notification->broadcastOn();
            $this->assertCount(1, $canales);
            $this->assertSame('order.created.'.$comercio->id, $canales[0]->name);

            $payload = $notification->toBroadcast($comercio)->data;
            $this->assertSame(['order_id' => 77, 'order_num' => 1234], $payload);

            return true;
        });
    }

    /**
     * Criterio 2: via() devuelve SOLO broadcast. Sin mail, sin database, sin nada mas.
     */
    public function test_via_devuelve_solo_broadcast()
    {
        $comercio = $this->comercio();
        $order    = $this->pedidoEnMemoria(77, 1234, $comercio->id);

        $notification = new OrderCreated($order);

        $this->assertSame(['broadcast'], $notification->via($comercio));
    }

    /**
     * El canal tiene que ser PUBLICO: el autorizador de Echo del sistema apunta a empresa-api y
     * este evento sale de tienda-api, asi que un canal privado no lo autoriza nadie.
     */
    public function test_el_canal_es_publico()
    {
        $comercio = $this->comercio();
        $order    = $this->pedidoEnMemoria(77, 1234, $comercio->id);

        $canales = (new OrderCreated($order))->broadcastOn();

        $this->assertInstanceOf(\Illuminate\Broadcasting\Channel::class, $canales[0]);
        $this->assertNotInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class, $canales[0]);
    }

    /**
     * Lo que de verdad viaja por el cable no es toBroadcast(): Laravel envuelve la notificacion en
     * BroadcastNotificationCreated, y su broadcastWith() hace array_merge(datos, ['id' => uuid,
     * 'type' => clase]). Este test fija que el id del pedido sobrevive a ese merge — si alguien
     * renombra la clave a `id`, el uuid de la notificacion la pisa y empresa-spa recibe un uuid
     * donde espera el numero, sin ningun error en ningun lado.
     */
    public function test_el_id_del_pedido_sobrevive_al_sobre_de_laravel()
    {
        $comercio = $this->comercio();
        $order    = $this->pedidoEnMemoria(77, 1234, $comercio->id);

        $notification     = new OrderCreated($order);
        $notification->id = 'uuid-de-la-notificacion';

        $evento  = new BroadcastNotificationCreated($comercio, $notification, $notification->toBroadcast($comercio)->data);
        $payload = $evento->broadcastWith();

        $this->assertSame(77, $payload['order_id']);
        $this->assertSame(1234, $payload['order_num']);
        // Y se deja a la vista que `id` es del sobre, no del pedido:
        $this->assertSame('uuid-de-la-notificacion', $payload['id']);
    }

    /**
     * Criterio 3, primera mitad: una falla REAL del aviso no sale del Job.
     * No se mockea nada: se rompe la configuracion de broadcasting igual que si en produccion
     * faltaran las credenciales de Pusher, y se ejecuta el Job de verdad.
     */
    public function test_una_falla_del_aviso_no_escapa_del_job()
    {
        $comercio = $this->comercio();

        config(['broadcasting.default' => 'driver-que-no-existe']);

        // Si el Job dejara escapar la excepcion, esta llamada tira y el test falla.
        (new BroadcastOrderCreated(77, 1234, $comercio->id))->handle();

        $this->assertTrue(true, 'el Job se trago la falla del aviso, como corresponde');
    }

    /**
     * El mismo criterio, pero con el caso realista: Pusher configurado y sin credenciales, que es
     * lo que pasa si el .env de produccion no las tiene.
     *
     * 🔴 Este caso NO tira Exception: tira TypeError (Pusher\Pusher::__construct() recibe null
     * donde espera string). Por eso el catch del Job es de \Throwable y no de \Exception. Si
     * alguien lo "alinea" con el de SendOrderEmails, este test se pone en rojo — que es
     * exactamente para lo que esta.
     */
    public function test_una_falla_de_tipo_error_tampoco_escapa_del_job()
    {
        $comercio = $this->comercio();

        config([
            'broadcasting.default'                   => 'pusher',
            'broadcasting.connections.pusher.key'    => null,
            'broadcasting.connections.pusher.secret' => null,
            'broadcasting.connections.pusher.app_id' => null,
        ]);

        (new BroadcastOrderCreated(77, 1234, $comercio->id))->handle();

        $this->assertTrue(true, 'un TypeError del broadcaster tampoco puede salir del Job');
    }

    /**
     * Criterio 3, segunda mitad y la que importa: el pedido ya guardado sigue guardado aunque el
     * aviso reviente, despachandolo por el mismo camino que usa el controlador
     * (dispatchAfterResponse + terminate del contenedor).
     */
    public function test_el_pedido_sobrevive_a_una_falla_del_aviso()
    {
        $comercio = $this->comercio();

        $order = Order::create([
            'num'      => 1234,
            'user_id'  => $comercio->id,
            'buyer_id' => 9,
        ]);

        config(['broadcasting.default' => 'driver-que-no-existe']);

        BroadcastOrderCreated::dispatchAfterResponse($order->id, $order->num, $order->user_id);

        // Es lo que hace el kernel HTTP despues de mandarle la respuesta al comprador.
        $this->app->terminate();

        $this->assertNotNull(Order::find($order->id), 'el pedido tiene que seguir en la base');
        $this->assertSame(1234, (int) Order::find($order->id)->num);
    }

    /**
     * Criterio 5: este camino no escribe ninguna fila en `messages` (eso lo hacia el helper viejo,
     * que sigue comentado).
     */
    public function test_no_escribe_en_messages()
    {
        $comercio = $this->comercio();

        (new BroadcastOrderCreated(77, 1234, $comercio->id))->handle();

        $this->assertSame(0, DB::table('messages')->count());
    }

    /**
     * Si el comercio no existe, se registra y no se emite nada — pero tampoco se rompe.
     */
    public function test_sin_comercio_no_emite_y_no_rompe()
    {
        Notification::fake();

        (new BroadcastOrderCreated(77, 1234, 999999))->handle();

        Notification::assertNothingSent();
    }

    /**
     * Pedido armado en memoria, sin tocar la base: la notificacion solo usa id, num y user_id.
     */
    private function pedidoEnMemoria($id, $num, $user_id)
    {
        $order      = new Order(['num' => $num, 'user_id' => $user_id]);
        $order->id  = $id;

        return $order;
    }
}
