<?php

namespace Tests\Feature\RecuperarClave;

use App\Buyer;
use App\Http\Controllers\PasswordResetController;
use App\Mail\PasswordReset;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * "Olvidé mi contraseña": `POST /password-reset/send-verification-code` (mision
 * tiendas-demo-y-limpieza-tienda, 14/10/2026).
 *
 * Hasta hoy, si el servidor de correo rechazaba el envio, la ruta respondia 500. Medido en la
 * tienda de demo: `UnexpectedResponseException` "554 5.7.1 Recipient domain is reserved" (una
 * casilla @example.com) y, antes, `TransportException` 535 (la clave del SMTP vacia). Ahora:
 *
 *   - un fallo del transporte responde 422 con `message` para el comprador, deja el error en el
 *     log y no deja puesto el codigo que nadie recibio;
 *   - el envio que anda y el email desconocido responden igual que siempre.
 *
 * El fallo se fuerza con un transporte de Symfony Mailer que tira la excepcion: el mail recorre
 * el camino real (el Mailable se arma y se manda), y lo unico que cambia es que el "servidor"
 * lo rechaza.
 */
class EnvioDelCodigoTest extends TestCase
{
    use DatabaseTransactions;

    const RUTA = '/password-reset/send-verification-code';

    /** @var \App\User */
    private $comercio;

    /** @var \App\Buyer */
    private $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'         => 'Comercio Clave Test',
            'company_name' => 'Comercio Clave Test',
            'email'        => 'clave-'.Str::random(10).'@test.local',
            'password'     => bcrypt('secreto'),
            'status'       => 'commerce',
        ]);

        $this->comprador = Buyer::create([
            'name'     => 'Comprador Clave Test',
            'email'    => 'comprador-clave-'.Str::random(10).'@test.local',
            'password' => bcrypt('vieja-clave'),
            'user_id'  => $this->comercio->id,
        ]);
    }

    /**
     * Manda el mail por un transporte que rechaza con la excepcion dada.
     *
     * @param  \Throwable  $excepcion
     * @return void
     */
    private function servidorDeCorreoQueRechaza(\Throwable $excepcion)
    {
        Mail::extend('rechaza_en_tests', function () use ($excepcion) {
            return new class($excepcion) extends AbstractTransport {
                private $excepcion;

                public function __construct(\Throwable $excepcion)
                {
                    parent::__construct();
                    $this->excepcion = $excepcion;
                }

                protected function doSend(SentMessage $message): void
                {
                    throw $this->excepcion;
                }

                public function __toString(): string
                {
                    return 'rechaza-en-tests://';
                }
            };
        });

        config([
            'mail.mailers.rechaza_en_tests' => ['transport' => 'rechaza_en_tests'],
            'mail.default'                  => 'rechaza_en_tests',
        ]);
    }

    private function pedirCodigo($email)
    {
        return $this->postJson(self::RUTA, [
            'email'       => $email,
            'commerce_id' => $this->comercio->id,
        ]);
    }

    /** El caso de la demo: el SMTP rechaza el destinatario (554). */
    public function test_si_el_servidor_rechaza_el_destinatario_responde_422_con_un_mensaje()
    {
        Log::spy();
        $this->servidorDeCorreoQueRechaza(new UnexpectedResponseException(
            'Expected response code "250/251/252" but got code "554", with message "554 5.7.1 Recipient domain is reserved".'
        ));

        $respuesta = $this->pedirCodigo($this->comprador->email);

        $respuesta->assertStatus(422);
        $respuesta->assertJson([
            'email_send' => false,
            'message'    => PasswordResetController::MENSAJE_ENVIO_FALLIDO,
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($mensaje, $contexto) {
                return strpos($mensaje, 'Recuperar clave') === 0
                    && $contexto['buyer_id'] == $this->comprador->id
                    && $contexto['excepcion'] === UnexpectedResponseException::class
                    && strpos($contexto['mensaje'], '554 5.7.1') !== false;
            })
            ->once();

        $this->assertNull($this->comprador->fresh()->verification_code,
            'el codigo que no salio no queda puesto');
    }

    /** Cualquier otro fallo del transporte (la clave del SMTP vacia daba 535) va por el mismo camino. */
    public function test_si_el_servidor_no_acepta_la_autenticacion_tambien_responde_422()
    {
        $this->servidorDeCorreoQueRechaza(new TransportException(
            'Failed to authenticate on SMTP server with username "contacto@comerciocity.com" using the following authenticators: "LOGIN", "PLAIN". 535 5.7.8 Error: authentication failed'
        ));

        $respuesta = $this->pedirCodigo($this->comprador->email);

        $respuesta->assertStatus(422);
        $this->assertSame(PasswordResetController::MENSAJE_ENVIO_FALLIDO, $respuesta->json('message'));
        $this->assertStringNotContainsString('535', $respuesta->getContent(), 'el detalle del SMTP no llega al comprador');
    }

    /** Un codigo pedido antes (que si se mando) no se pierde porque el reenvio fallo. */
    public function test_un_fallo_deja_el_codigo_que_el_comprador_ya_tenia()
    {
        $this->comprador->verification_code = 123456;
        $this->comprador->save();

        $this->servidorDeCorreoQueRechaza(new TransportException('Connection could not be established with host "smtp.test:465"'));

        $this->pedirCodigo($this->comprador->email)->assertStatus(422);

        $this->assertEquals(123456, $this->comprador->fresh()->verification_code);
    }

    /** El caso que anda no cambia: 200, `email_send` true, el mail sale al comprador con el codigo guardado. */
    public function test_el_envio_que_anda_responde_igual_que_siempre()
    {
        Mail::fake();

        $respuesta = $this->pedirCodigo($this->comprador->email);

        $respuesta->assertStatus(200);
        $this->assertSame(['email_send' => true], $respuesta->json());

        $codigo = $this->comprador->fresh()->verification_code;
        $this->assertNotNull($codigo);

        Mail::assertSent(PasswordReset::class, function ($mail) use ($codigo) {
            return $mail->hasTo($this->comprador->email) && (string) $mail->code === (string) $codigo;
        });
    }

    /** Un email que no es de un comprador de la tienda tampoco cambia: 200 y `email_send` false, sin mandar nada. */
    public function test_un_email_desconocido_responde_igual_que_siempre()
    {
        Mail::fake();

        $respuesta = $this->pedirCodigo('nadie-'.Str::random(8).'@test.local');

        $respuesta->assertStatus(200);
        $this->assertSame(['email_send' => false], $respuesta->json());
        Mail::assertNothingSent();
    }
}
