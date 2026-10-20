<?php

namespace Tests\Feature\RecuperarClave;

use App\Buyer;
use App\Mail\PasswordReset;
use App\OnlineConfiguration;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El mail del código de recuperación (misión recuperar-clave-codigo-4-digitos, 20/10/2026): mismo
 * diseño que los mails de pedido (`emails/orders/layout`), con el código en una caja destacada del color
 * de acento de la tienda, el aviso de que vence en 30 minutos y el "si no lo pediste, ignorá este mail".
 */
class MailDeRecuperacionTest extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Buyer */
    private $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'         => 'Comercio Cuatro Digitos',
            'company_name' => 'Comercio Cuatro Digitos',
            'email'        => 'comercio-mail-'.Str::random(10).'@test.local',
            'password'     => bcrypt('secreto'),
            'status'       => 'commerce',
        ]);

        $this->comprador = Buyer::create([
            'name'     => 'Comprador Mail',
            'email'    => 'comprador-mail-'.Str::random(10).'@test.local',
            'password' => bcrypt('vieja-clave'),
            'user_id'  => $this->comercio->id,
        ]);
    }

    /** Pide el código por el camino real (la API le manda el mail al comprador) y devuelve el que salió. */
    private function pedir_codigo()
    {
        $this->postJson('/password-reset/send-verification-code', [
            'email'               => $this->comprador->email,
            'commerce_id'         => $this->comercio->id,
            'longitud_del_codigo' => 4,
        ])->assertStatus(200);

        return (string) Mail::sent(PasswordReset::class)->last()->code;
    }


    public function test_el_mail_lleva_el_codigo_el_nombre_del_comercio_y_el_aviso_de_vencimiento()
    {
        Mail::fake();

        $codigo = $this->pedir_codigo();

        Mail::assertSent(PasswordReset::class, function ($mail) use ($codigo) {
            $html = $mail->render();

            return $mail->hasTo($this->comprador->email)
                && strpos($html, '>'.$codigo.'<') !== false
                && strpos($html, 'Comercio Cuatro Digitos') !== false
                && strpos($html, '30 minutos') !== false
                && strpos($html, 'ignorá este mail') !== false;
        });
    }

    public function test_el_mail_usa_el_color_de_acento_y_el_logo_de_la_tienda()
    {
        OnlineConfiguration::create([
            'user_id'       => $this->comercio->id,
            'primary_color' => '#0a7d4b',
            'logo_url'      => 'https://tienda.test/logo-comercio.png',
        ]);

        $html = (new PasswordReset('4821', $this->comercio->fresh()))->render();

        $this->assertStringContainsString('background-color:#0a7d4b', $html, 'la caja del código lleva el acento');
        $this->assertStringContainsString('src="https://tienda.test/logo-comercio.png"', $html);
        $this->assertStringContainsString('>4821<', $html);
    }

    /** Sin logo configurado, el mail muestra el nombre del comercio (nada de imagen rota). */
    public function test_el_mail_sin_logo_degrada_al_nombre_del_comercio()
    {
        $html = (new PasswordReset('4821', $this->comercio))->render();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Comercio Cuatro Digitos', $html);
    }

    /** Un acento claro lleva texto oscuro, uno oscuro texto blanco: el código siempre se lee. */
    public function test_el_texto_del_codigo_contrasta_con_el_color_de_acento()
    {
        foreach (['#fde047' => '#111827', '#1e3a8a' => '#ffffff', '#fff' => '#111827', '#000' => '#ffffff'] as $acento => $texto) {
            OnlineConfiguration::where('user_id', $this->comercio->id)->delete();
            OnlineConfiguration::create(['user_id' => $this->comercio->id, 'primary_color' => $acento]);

            $html = (new PasswordReset('4821', $this->comercio->fresh()))->render();

            $this->assertStringContainsString('letter-spacing:0.28em; padding-left:0.28em; color:'.$texto.';', $html, "acento $acento");
        }
    }

    /** Un color de acento que no es hexadecimal no se cuela en el estilo: cae al gris del sistema. */
    public function test_un_acento_que_no_es_hexadecimal_no_se_cuela_en_el_html()
    {
        OnlineConfiguration::create(['user_id' => $this->comercio->id, 'primary_color' => 'red;"><b>']);

        $html = (new PasswordReset('4821', $this->comercio->fresh()))->render();

        $this->assertStringNotContainsString('red;', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('background-color:#111827', $html);
    }

    public function test_el_mail_sale_aunque_el_comercio_no_exista()
    {
        $html = (new PasswordReset('4821', null))->render();

        $this->assertStringContainsString('>4821<', $html);
        $this->assertStringContainsString('La tienda', $html);
    }

    public function test_el_asunto_nombra_al_comercio()
    {
        $mail = new PasswordReset('4821', $this->comercio);
        $mail->build();

        $this->assertSame('Tu código para recuperar la contraseña - Comercio Cuatro Digitos', $mail->subject);
    }
}
