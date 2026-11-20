<?php

namespace Tests\Feature;

use App\Buyer;
use App\Cart;
use App\Http\Controllers\Helpers\OrderMailDeliveryHelper as Deliver;
use App\Jobs\SendOrderEmails;
use App\Mail\NuevoPedidoComercio;
use App\Mail\PedidoRecibidoComprador;
use App\OnlineConfiguration;
use App\Order;
use App\OrderCommunicationDelivery as Delivery;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Compatibilidad de los correos del pedido con un empresa-api VIEJO, que todavía no creó la tabla
 * `order_communication_deliveries`. La tienda se despliega antes que el release de empresa, así que
 * "sin tabla" es el estado normal durante días: sin el registro durable el envío tiene que seguir siendo
 * el de antes (comercio y comprador, sin idempotencia), no quedar apagado por un 1146 relanzado.
 *
 * DDL fuera de transacción: la tabla se renombra y SIEMPRE se restaura en tearDown antes de limpiar datos.
 * Mismo camino real que OrderEmailsTest (endpoint, Job, helper, Mailable, ArrayTransport); sin SMTP real.
 */
class OrderMailSinTablaTest extends TestCase
{
    const TABLA = 'order_communication_deliveries';
    const RESPALDO = 'order_comm_deliveries_sintabla_bak';

    protected $owner;
    protected $buyer;
    protected $owners = [];
    /** Mailables realmente entregados al transporte, en orden: [destinatario, clase]. */
    protected $enviados = [];
    protected $tabla_oculta = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertStringContainsString('testing', DB::connection()->getDatabaseName());
        $this->assertTrue(Schema::hasTable(self::TABLA), 'La base de prueba trae el esquema nuevo de empresa.');
        $this->assertFalse(Schema::hasTable(self::RESPALDO), 'No pisar un respaldo de otra corrida.');
        $this->owner = User::create(['name' => 'SinTabla', 'company_name' => 'SinTabla',
            'email' => uniqid('owner-').'@example.test', 'status' => 'commerce', 'password' => bcrypt('synthetic-only')]);
        $this->owners[] = $this->owner->id;
        $this->buyer = Buyer::create(['name' => 'Comprador sintético', 'email' => 'buyer@example.test',
            'user_id' => $this->owner->id]);
        OnlineConfiguration::create(['user_id' => $this->owner->id,
            'notificar_pedido_al_negocio' => 1, 'notificar_pedido_al_cliente' => 1,
            'mail_notificacion_pedidos' => 'A@example.test; a@EXAMPLE.test, invalido, b@example.test']);
        DB::table('order_statuses')->updateOrInsert(['name' => 'Sin confirmar'], ['updated_at' => now()]);
        config(['mail.default' => 'array']);
        // El listener NO consulta la tabla de entregas: tiene que funcionar también cuando no existe.
        app('events')->listen(MessageSending::class, function ($event) {
            $this->assertSame(0, DB::transactionLevel());
            $this->enviados[] = [$event->message->getTo()[0]->getAddress(), $event->data['__laravel_mailable'] ?? null];
        });
    }

    protected function tearDown(): void
    {
        try {
            $this->restaurar_tabla();
            if ($this->owners) {
                Delivery::whereIn('owner_id', $this->owners)->delete();
                Order::whereIn('user_id', $this->owners)->delete();
                Cart::whereIn('user_id', $this->owners)->delete();
                Buyer::whereIn('user_id', $this->owners)->delete();
                OnlineConfiguration::whereIn('user_id', $this->owners)->delete();
                User::whereIn('id', $this->owners)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    /** Simula el esquema anterior del ERP: la tabla de entregas no existe. */
    protected function ocultar_tabla(): void
    {
        Schema::rename(self::TABLA, self::RESPALDO);
        $this->tabla_oculta = true;
    }

    protected function restaurar_tabla(): void
    {
        if ($this->tabla_oculta) {
            Schema::rename(self::RESPALDO, self::TABLA);
            $this->tabla_oculta = false;
        }
    }

    /** Crea el pedido por el endpoint real; el Job corre después del commit. */
    protected function checkout(): Order
    {
        $cart = Cart::create(['user_id' => $this->owner->id, 'total' => 100, 'deliver' => 0]);
        $response = $this->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $this->buyer->id])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->owner->id,
                'address' => 'Dirección sintética']);
        $response->assertCreated();
        return Order::findOrFail($response->json('order_id'));
    }

    public function test_sin_tabla_se_envia_al_comercio_y_al_comprador_como_antes()
    {
        $this->ocultar_tabla();
        $order = $this->checkout();
        $this->assertNotNull(Order::find($order->id), 'El pedido confirmado no depende del correo.');
        $this->assertSame([
            ['a@example.test', NuevoPedidoComercio::class],
            ['b@example.test', NuevoPedidoComercio::class],
            ['buyer@example.test', PedidoRecibidoComprador::class],
        ], $this->enviados);
        $this->assertFalse(Schema::hasTable(self::TABLA), 'El camino sin tabla no la crea ni la necesita.');
    }

    public function test_sin_tabla_el_job_directo_tambien_envia_comercio_y_comprador()
    {
        $order = $this->checkout();
        $this->enviados = [];
        $this->ocultar_tabla();
        (new SendOrderEmails($order->id))->handle();
        $this->assertSame(['a@example.test', 'b@example.test', 'buyer@example.test'], array_column($this->enviados, 0));
        $this->assertSame([NuevoPedidoComercio::class, NuevoPedidoComercio::class, PedidoRecibidoComprador::class],
            array_column($this->enviados, 1));
    }

    public function test_sin_tabla_se_conservan_las_validaciones_de_identidad_y_commit()
    {
        $order = $this->checkout();
        $this->ocultar_tabla();
        $nunca = function () {
            $this->fail('Una identidad inválida no puede llegar al transporte, con o sin tabla.');
        };
        $falsificado = clone $order;
        $falsificado->user_id++;
        foreach ([
            [\LogicException::class, $falsificado, 'order_received', 'buyer@example.test'],
            [\InvalidArgumentException::class, $order, 'tipo_desconocido', 'buyer@example.test'],
            [\InvalidArgumentException::class, $order, 'order_received', 'Buyer@Example.test'],
        ] as [$excepcion, $pedido, $tipo, $destinatario]) {
            try {
                Deliver::deliver($pedido, $tipo, $destinatario, $nunca);
                $this->fail('Se esperaba '.$excepcion);
            } catch (\Throwable $e) {
                $this->assertInstanceOf($excepcion, $e);
            }
        }
        DB::beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            Deliver::deliver($order, 'order_received', 'buyer@example.test', $nunca);
        } finally {
            DB::rollBack();
        }
    }

    public function test_sin_tabla_un_error_ajeno_del_transporte_no_se_traga()
    {
        $order = $this->checkout();
        $this->ocultar_tabla();
        // Deadlock (1213) del propio transporte/renderizado: debe salir tal cual, no confundirse con la ausencia de tabla.
        $ajeno = new QueryException('mysql', 'select * from otra_tabla', [], new \PDOException('otra'));
        $ajeno->errorInfo = ['40001', 1213, 'Deadlock found'];
        $llamado = false;
        try {
            Deliver::deliver($order, 'order_received', 'buyer@example.test', function () use ($ajeno, &$llamado) {
                $llamado = true;
                throw $ajeno;
            });
            $this->fail('El error ajeno debía propagarse.');
        } catch (QueryException $e) {
            $this->assertSame(1213, $e->errorInfo[1], 'Llegó el error del callback, no un 1146 de la tabla ausente.');
        }
        $this->assertTrue($llamado, 'Sin tabla el helper debe llamar al transporte.');
    }

    public function test_con_tabla_un_1146_en_el_registro_se_relanza_y_no_se_envia()
    {
        $order = $this->checkout();
        $this->enviados = [];
        // La tabla EXISTE: un 1146 al insertar no es "esquema viejo" y no debe enmascararse con un envío sin registro.
        $ajeno = new QueryException('mysql', 'insert into x', [], new \PDOException('x'));
        $ajeno->errorInfo = ['42S02', 1146, "Table 'x' doesn't exist"];
        Delivery::creating(function () use ($ajeno) {
            throw $ajeno;
        });
        try {
            Deliver::deliver($order, 'order_received', 'buyer@example.test', function () {
                $this->fail('Sin registro durable previo no hay transporte.');
            });
            $this->fail('El 1146 con la tabla presente debía propagarse.');
        } catch (QueryException $e) {
            $this->assertSame(1146, $e->errorInfo[1]);
        } finally {
            Delivery::flushEventListeners();
        }
        $this->assertSame([], $this->enviados);
    }

    public function test_con_tabla_el_doble_envio_no_duplica_aunque_antes_hubiera_corrido_sin_ella()
    {
        $this->ocultar_tabla();
        $this->checkout();
        $this->assertCount(3, $this->enviados, 'Sin tabla: envío previo.');
        $this->restaurar_tabla();
        $this->enviados = [];

        $order = $this->checkout();
        $this->assertSame(['a@example.test', 'b@example.test', 'buyer@example.test'], array_column($this->enviados, 0));
        (new SendOrderEmails($order->id))->handle();
        (new SendOrderEmails($order->id))->handle();
        $this->assertCount(3, $this->enviados, 'Con tabla, el reproceso del mismo pedido no vuelve a enviar.');
        $filas = Delivery::where('order_id', $order->id)->get();
        $this->assertCount(3, $filas);
        foreach ($filas as $fila) {
            $this->assertSame('transport_accepted', $fila->status);
            $this->assertSame(1, $fila->attempt_count);
        }
    }
}
