<?php

namespace Tests\Feature;

require_once __DIR__.'/OrderEmailsTest.php';

use App\Buyer;
use App\Jobs\SendOrderEmails;
use App\OnlineConfiguration;
use App\User;
use App\Http\Controllers\Helpers\ClientMailConfigHelper;
use Illuminate\Support\Facades\Mail;

class OrderMailIsolationTest extends OrderMailFixture
{
    public function test_dos_comercios_mismo_proceso_restaura_config_y_mailer_cacheado_incluso_con_falla()
    {
        config(['mail.from.address' => 'system@example.test', 'mail.mailers.smtp.host' => 'system.test']);
        $manager = Mail::getFacadeRoot();
        $mailer = $manager->mailer('array');
        $baseline = config('mail');
        $this->configuration->update(['mail_enabled' => 1, 'mail_host' => 'owner-one.test',
            'mail_port' => 2525, 'mail_encryption' => null,
            'mail_username' => 'owner-one@example.test', 'mail_password' => 'synthetic-only',
            'mail_from_address' => 'owner-one@example.test']);
        $this->failure = function ($recipient) {
            if ($recipient === 'a@example.test') {
                throw new \RuntimeException('fallo sintético sin exponer');
            }
        };
        $first = $this->checkout();
        $this->assertSame('uncertain', $this->row($first)->status);
        $this->assertSame($baseline, config('mail'));
        $this->assertSame($manager, Mail::getFacadeRoot());
        $this->assertSame($mailer, Mail::mailer('array'));

        $this->owner = User::create(['name' => 'Otro comercio', 'company_name' => 'Dos',
            'email' => uniqid('two-').'@example.test', 'status' => 'commerce', 'password' => bcrypt('synthetic-only')]);
        $this->owners[] = $this->owner->id;
        $this->buyer = Buyer::create(['name' => 'Dos', 'email' => 'second@example.test', 'user_id' => $this->owner->id]);
        $this->configuration = OnlineConfiguration::create(['user_id' => $this->owner->id,
            'mail_enabled' => 0, 'notificar_pedido_al_negocio' => 1, 'notificar_pedido_al_cliente' => 1,
            'mail_notificacion_pedidos' => 'second-merchant@example.test']);
        $this->failure = null;
        $second = $this->checkout();
        $this->assertSame(['a@example.test', 'owner-one@example.test', 'owner-one.test'], $this->seen[0]);
        $this->assertSame(['second-merchant@example.test', 'system@example.test', 'system.test'], $this->seen[3]);
        $this->assertSame('transport_accepted', $this->row($second, 'order_received', 'second@example.test')->status);
        $this->assertSame($baseline, config('mail'));
        $this->assertSame($manager, Mail::getFacadeRoot());
        $this->assertSame($mailer, Mail::mailer('array'));
        (new SendOrderEmails($first->id))->handle();
        $this->assertCount(5, $this->seen);
    }

    public function test_finally_restaura_tambien_si_callback_tira_error_y_config_incompleta_no_hereda()
    {
        $baseline = config('mail');
        $manager = Mail::getFacadeRoot();
        $this->configuration->update(['mail_enabled' => 1, 'mail_host' => 'incomplete.test']);
        try {
            ClientMailConfigHelper::isolated($this->owner->id, function () use ($baseline) {
                $this->assertSame($baseline, config('mail'));
                throw new \Error('callback sintético');
            });
            $this->fail('El callback debe lanzar');
        } catch (\Error $expected) {
            $this->assertSame('callback sintético', $expected->getMessage());
        }
        $this->assertSame($baseline, config('mail'));
        $this->assertSame($manager, Mail::getFacadeRoot());
    }
}
