<?php

namespace Tests\Feature;

require_once __DIR__.'/OrderEmailsTest.php';

use Illuminate\Log\Events\MessageLogged;

/**
 * Corrección A, punto 4 de Truvari: el aviso del pedido al comercio no puede quedar apagado en
 * silencio. Si el campo de correos no tiene ninguno válido se usa la cuenta, y cada decisión deja un
 * código en el log con el id del pedido (nunca direcciones).
 */
class OrderEmailsFallbackTest extends OrderMailFixture
{
    /** @var array Eventos de log capturados durante el test. */
    protected $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        app('events')->listen(MessageLogged::class, function ($event) {
            $this->logs[] = $event;
        });
    }

    /** Contextos de log con el error_code pedido. */
    protected function logs_with($code)
    {
        $found = [];
        foreach ($this->logs as $event) {
            if (($event->context['error_code'] ?? null) === $code) {
                $found[] = $event;
            }
        }
        return $found;
    }

    /** Destinatarios del comercio: todo lo enviado salvo el mail del comprador. */
    protected function merchant_recipients()
    {
        return array_values(array_diff(array_column($this->seen, 0), ['buyer@example.test']));
    }

    public function test_ca26_campo_invalido_cae_a_la_cuenta_y_lo_registra_sin_datos_personales()
    {
        $this->owner->update(['email' => 'dueno@example.test']);
        $this->configuration->update(['mail_notificacion_pedidos' => 'pedidos(arroba)truvari']);
        $order = $this->checkout();
        $this->assertSame(['dueno@example.test'], $this->merchant_recipients());
        $this->assertContains('buyer@example.test', array_column($this->seen, 0));
        $logs = $this->logs_with('merchant_recipients_invalid_using_account');
        $this->assertCount(1, $logs);
        $this->assertSame('warning', $logs[0]->level);
        $this->assertSame($order->id, (int) $logs[0]->context['order_id']);
        $encoded = json_encode($logs[0]->context);
        $this->assertStringNotContainsString('@', $encoded);
        $this->assertStringNotContainsString('truvari', $encoded);
    }

    public function test_ca27_mezcla_de_validos_e_invalidos_manda_a_los_validos_y_cuenta_los_descartados()
    {
        $this->configuration->update(['mail_notificacion_pedidos' => 'ok@example.test, malo']);
        $order = $this->checkout();
        $this->assertSame(['ok@example.test'], $this->merchant_recipients());
        $logs = $this->logs_with('merchant_recipient_discarded');
        $this->assertCount(1, $logs);
        $this->assertSame(1, (int) $logs[0]->context['discarded']);
        $this->assertSame($order->id, (int) $logs[0]->context['order_id']);
        $this->assertStringNotContainsString('malo', json_encode($logs[0]->context));
    }

    public function test_ca27_campo_vacio_va_a_la_cuenta_sin_avisos_de_invalidos()
    {
        $this->owner->update(['email' => 'dueno@example.test']);
        $this->configuration->update(['mail_notificacion_pedidos' => '']);
        $this->checkout();
        $this->assertSame(['dueno@example.test'], $this->merchant_recipients());
        $this->assertSame([], $this->logs_with('merchant_recipients_invalid_using_account'));
        $this->assertSame([], $this->logs_with('merchant_recipient_discarded'));
    }

    public function test_ca27_sin_ningun_correo_valido_no_se_manda_al_comercio_y_queda_registrado()
    {
        $this->owner->update(['email' => 'cuenta-sin-arroba']);
        $this->configuration->update(['mail_notificacion_pedidos' => 'tampoco-es-correo']);
        $order = $this->checkout();
        $this->assertSame([], $this->merchant_recipients());
        $this->assertSame(['buyer@example.test'], array_column($this->seen, 0));
        $logs = $this->logs_with('merchant_no_valid_recipient');
        $this->assertCount(1, $logs);
        $this->assertSame($order->id, (int) $logs[0]->context['order_id']);
    }

    public function test_ca27_aviso_apagado_no_manda_al_comercio_lo_registra_y_el_comprador_lo_recibe()
    {
        $this->configuration->update(['notificar_pedido_al_negocio' => 0]);
        $order = $this->checkout();
        $this->assertSame([], $this->merchant_recipients());
        $this->assertSame(['buyer@example.test'], array_column($this->seen, 0));
        $logs = $this->logs_with('merchant_notification_disabled');
        $this->assertCount(1, $logs);
        $this->assertSame($order->id, (int) $logs[0]->context['order_id']);
    }
}
