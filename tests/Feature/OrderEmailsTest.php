<?php

namespace Tests\Feature;

use App\Buyer;
use App\Cart;
use App\Jobs\SendOrderEmails;
use App\OnlineConfiguration;
use App\Order;
use App\OrderCommunicationDelivery as Delivery;
use App\User;
use App\Http\Controllers\Helpers\OrderMailDeliveryHelper as Deliver;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Fakes\EntornoSinCredenciales;
use Tests\TestCase;

/** DB sintética compartida con ERP; no DatabaseTransactions: hay que observar commits reales. */
abstract class OrderMailFixture extends TestCase
{
    protected $owner;
    protected $buyer;
    protected $configuration;
    protected $seen = [];
    protected $failure;
    protected $owners = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertStringContainsString('testing', DB::connection()->getDatabaseName());
        $this->owner = User::create(['name' => 'U10', 'company_name' => 'U10',
            'email' => uniqid('owner-').'@example.test', 'status' => 'commerce', 'password' => bcrypt('synthetic-only')]);
        $this->owners[] = $this->owner->id;
        $this->buyer = Buyer::create(['name' => 'Comprador sintético', 'email' => 'buyer@example.test',
            'user_id' => $this->owner->id]);
        $this->configuration = OnlineConfiguration::create(['user_id' => $this->owner->id,
            'notificar_pedido_al_negocio' => 1, 'notificar_pedido_al_cliente' => 1,
            'mail_notificacion_pedidos' => 'A@example.test; a@EXAMPLE.test, invalido, b@example.test']);
        DB::table('order_statuses')->updateOrInsert(['name' => 'Sin confirmar'], ['updated_at' => now()]);
        config(['mail.default' => 'array']);
        app('events')->listen(MessageSending::class, function ($event) {
            $recipient = $event->message->getTo()[0]->getAddress();
            $this->seen[] = [$recipient, config('mail.from.address'), config('mail.mailers.smtp.host')];
            // Este mismo callback es previo al ArrayTransport real, no un camino alternativo del Job.
            $this->assertSame(0, DB::transactionLevel());
            $pdo = new \PDO('mysql:unix_socket='.config('database.connections.mysql.unix_socket')
                .';dbname='.DB::connection()->getDatabaseName(), config('database.connections.mysql.username'), config('database.connections.mysql.password'));
            $status = $pdo->query('SELECT status FROM order_communication_deliveries WHERE recipient_hash = '. $pdo->quote(hash('sha256', $recipient)).' ORDER BY id DESC LIMIT 1')->fetchColumn();
            $this->assertSame('attempting', $status, 'claim durable visible desde otra conexión antes del transporte');
            if ($this->failure) {
                ($this->failure)($recipient);
            }
        });
    }

    protected function tearDown(): void
    {
        try {
            // Se borran exclusivamente las identidades creadas por esta fixture, no el esquema.
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

    protected function checkout()
    {
        $cart = Cart::create(['user_id' => $this->owner->id, 'total' => 100, 'deliver' => 0]);
        $response = $this->withSession(['carritos_propios' => [$cart->id], 'checkout_buyer_id' => $this->buyer->id])
            ->postJson('/api/orders', ['cart_id' => $cart->id, 'commerce_id' => $this->owner->id,
                'address' => 'Dirección sintética']);
        $response->assertCreated();
        return Order::findOrFail($response->json('order_id'));
    }

    protected function row($order, $type = 'new_order_merchant', $recipient = 'a@example.test')
    {
        return Delivery::where(['owner_id' => $order->user_id, 'order_id' => $order->id,
            'channel' => 'email', 'communication_type' => $type,
            'recipient_hash' => hash('sha256', $recipient)])->firstOrFail();
    }
}

class OrderEmailsTest extends OrderMailFixture
{
    public function test_endpoint_transporte_y_dedupe_durable()
    {
        $order = $this->checkout();
        $this->assertSame(['a@example.test', 'b@example.test', 'buyer@example.test'], array_column($this->seen, 0));
        (new SendOrderEmails($order->id))->handle();
        $this->assertCount(3, $this->seen);
        $this->assertSame('transport_accepted', $this->row($order)->status);
        $this->assertNotNull($this->row($order)->accepted_at);
        $this->assertSame(1, $this->row($order)->attempt_count);
    }

    public function test_rechazo_independiente_reintenta_misma_fila_y_no_los_aceptados()
    {
        $this->failure = function ($recipient) {
            if ($recipient === 'a@example.test') {
                throw new UnexpectedResponseException('NO PERSISTIR correo ni respuesta', 550);
            }
        };
        $order = $this->checkout();
        $failed = $this->row($order);
        $this->assertSame('failed', $failed->status);
        $this->assertSame('smtp_rejected', $failed->error_code);
        $this->assertNotNull($failed->failed_at);
        $this->assertSame('transport_accepted', $this->row($order, 'order_received', 'buyer@example.test')->status);
        $this->assertNotNull(Order::find($order->id));
        $this->failure = null;
        (new SendOrderEmails($order->id))->handle();
        $this->assertSame($failed->id, $this->row($order)->id);
        $this->assertSame(2, $this->row($order)->attempt_count);
        $this->assertSame('transport_accepted', $this->row($order)->status);
        $this->assertCount(4, $this->seen);
    }

    public function test_timeout_incierto_y_nunca_reenvia()
    {
        $this->failure = function ($recipient) {
            if ($recipient === 'a@example.test') {
                throw new \RuntimeException('Timeout privado');
            }
        };
        $order = $this->checkout();
        $this->assertSame('uncertain', $this->row($order)->status);
        $this->assertNull($this->row($order)->accepted_at);
        $this->assertNull($this->row($order)->failed_at);
        $this->failure = null;
        (new SendOrderEmails($order->id))->handle();
        $this->assertCount(3, $this->seen);
    }

    public function test_endpoint_no_despacha_si_rollback_y_solo_envia_al_commit()
    {
        DB::beginTransaction();
        $order = $this->checkout();
        $this->assertCount(0, $this->seen);
        DB::rollBack();
        $this->assertNull(Order::find($order->id));
        $this->assertCount(0, $this->seen);
        DB::beginTransaction();
        $confirmed = $this->checkout();
        $this->assertCount(0, $this->seen);
        DB::commit();
        $this->app->terminate();
        $this->assertNotNull(Order::find($confirmed->id));
        $this->assertCount(3, $this->seen);
    }

    public function test_concurrente_claim_en_dos_procesos_y_huerfano_no_reintentable()
    {
        $this->configuration->update(['notificar_pedido_al_negocio' => 0, 'notificar_pedido_al_cliente' => 0]);
        $order = $this->checkout();
        $identity = ['owner_id' => $order->user_id, 'order_id' => $order->id, 'channel' => 'email',
            'communication_type' => 'new_order_merchant', 'recipient_hash' => hash('sha256', 'race@example.test')];
        $this->assertTrue(function_exists('proc_open'), 'Se requiere runner con procesos reales');
        // Dos intérpretes PHP, dos conexiones reales; barrera stdin antes del claim.
        $processes = [];
        // Entorno armado a mano: el del proceso de prueba más las credenciales de mentira, para que el hijo
        // no lea las reales del .env.testing (proc_open descarta los valores vacíos de un entorno propio).
        $child_env = array_merge(getenv(), EntornoSinCredenciales::para_procesos_hijos());
        $code = 'require "vendor/autoload.php"; $app=require "bootstrap/app.php";'
            .'$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            .'fread(STDIN,1); $order=App\\Order::findOrFail('.(int) $order->id.');'
            .'App\\Http\\Controllers\\Helpers\\OrderMailDeliveryHelper::deliver('
            .'$order,"new_order_merchant","race@example.test",function(){usleep(150000);'
            .'return Illuminate\\Support\\Facades\\Mail::raw("Sintético",function($m){$m->to("race@example.test");});});';
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $child_env);
                $this->assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            foreach ($processes as [$process, $pipes]) { fwrite($pipes[0], 'x'); fclose($pipes[0]); }
            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $output);
            }
        } finally {
            foreach ($processes as [$process, $pipes]) {
                if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            }
        }
        DB::purge();
        $row = Delivery::where($identity)->firstOrFail();
        $this->assertSame('transport_accepted', $row->status);
        $this->assertSame(1, $row->attempt_count);
        $this->assertSame(1, Delivery::where($identity)->count());
        $row->update(['status' => 'attempting', 'last_attempt_at' => now()->subHour(), 'accepted_at' => null]);
        Deliver::deliver($order, 'new_order_merchant', 'race@example.test', function () {
            $this->fail('Un intento huérfano jamás se reenvía automáticamente');
        });
        $this->assertSame('uncertain', $row->fresh()->status);
        $this->assertSame('orphaned_attempt', $row->fresh()->error_code);
    }

    public function test_prepared_sin_recibo_es_incierto_y_no_reenvia()
    {
        $this->configuration->update(['notificar_pedido_al_negocio' => 0, 'notificar_pedido_al_cliente' => 0]);
        $order = $this->checkout();
        $row = Delivery::create(['owner_id' => $order->user_id, 'order_id' => $order->id,
            'channel' => 'email', 'communication_type' => 'order_received',
            'recipient_hash' => hash('sha256', 'no-receipt@example.test')]);
        $this->assertSame('prepared', $row->fresh()->status);
        $this->assertSame(0, $row->fresh()->attempt_count);
        $calls = 0;
        $send = function () use (&$calls) { $calls++; return null; };
        Deliver::deliver($order, 'order_received', 'no-receipt@example.test', $send);
        Deliver::deliver($order, 'order_received', 'no-receipt@example.test', $send);
        $this->assertSame(1, $calls);
        $this->assertSame('uncertain', $row->fresh()->status);
        $this->assertSame('transport_no_receipt', $row->fresh()->error_code);
        $this->assertNotNull($row->fresh()->last_attempt_at);
        $this->assertNull($row->fresh()->accepted_at);
    }

    public function test_identidad_ajena_y_config_desactivada_no_envian()
    {
        $this->configuration->update(['notificar_pedido_al_negocio' => 0, 'notificar_pedido_al_cliente' => 0]);
        $order = $this->checkout();
        $this->assertCount(0, $this->seen);
        $order->user_id++;
        $this->expectException(\LogicException::class);
        Deliver::deliver($order, 'order_received', 'buyer@example.test', function () {
            $this->fail('Owner falsificado');
        });
    }
}
