<?php

namespace Tests\Feature\RecuperarClave;

use App\Buyer;
use App\Mail\PasswordReset;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Olvidé mi contraseña", segundo y tercer paso: `POST /password-reset/check-verification-code` y
 * `POST /password-reset/update-password` (misión tiendas-demo-y-limpieza-tienda, 14/10/2026).
 *
 * La contraseña nueva se guarda solo con el código vigente de ese comprador, el mismo que valida el
 * segundo paso. La pantalla siempre mandó el código junto con la contraseña (también la versión 1.1.0),
 * así que exigirlo no cambia nada para quien sigue los tres pasos.
 *
 * Desde el 20/10/2026 (misión recuperar-clave-codigo-4-digitos) el código vive en CACHÉ y no en
 * `buyers.verification_code` (esa columna es del registro del comprador; ver el docblock de
 * PasswordResetController). Por eso los tests ya no lo siembran en la columna: lo piden por el mismo
 * camino que la pantalla (`send-verification-code`) y lo leen del mail. Lo que verifican no cambió.
 */
class CambioDeClaveTest extends TestCase
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
            'name'         => 'Comercio Cambio Clave Test',
            'company_name' => 'Comercio Cambio Clave Test',
            'email'        => 'cambio-clave-'.Str::random(10).'@test.local',
            'password'     => bcrypt('secreto'),
            'status'       => 'commerce',
        ]);

        $this->comprador = Buyer::create([
            'name'     => 'Comprador Cambio Clave Test',
            'email'    => 'comprador-cambio-'.Str::random(10).'@test.local',
            'password' => bcrypt('vieja-clave'),
            'user_id'  => $this->comercio->id,
        ]);
    }

    /**
     * Pide el código por el camino real (`send-verification-code`, mail falso) y devuelve el que salió en el mail.
     *
     * @param  array  $extra  Campos extra del pedido, p. ej. `longitud_del_codigo`.
     * @return string
     */
    private function pedir_codigo(array $extra = [])
    {
        Mail::fake();

        $this->postJson('/password-reset/send-verification-code', array_merge([
            'email'       => $this->comprador->email,
            'commerce_id' => $this->comercio->id,
        ], $extra))->assertStatus(200)->assertJson(['email_send' => true]);

        return (string) Mail::sent(PasswordReset::class)->last()->code;
    }

    /** Un código que seguro NO es el dado (para probar el caso del código equivocado). */
    private function codigo_distinto_de($codigo)
    {
        return $codigo === '111111' ? '222222' : '111111';
    }

    /** El cuerpo que manda la pantalla en el tercer paso. */
    private function pedido_de_cambio($codigo, $clave = 'nueva-clave-123')
    {
        return [
            'email'             => $this->comprador->email,
            'commerce_id'       => $this->comercio->id,
            'verification_code' => $codigo,
            'password'          => $clave,
        ];
    }

    public function test_con_el_codigo_vigente_cambia_la_clave_y_borra_el_codigo()
    {
        $codigo = $this->pedir_codigo();

        $respuesta = $this->postJson('/password-reset/update-password', $this->pedido_de_cambio($codigo));

        $respuesta->assertStatus(200)->assertJson(['password_updated' => true]);
        $this->comprador->refresh();
        $this->assertTrue(Hash::check('nueva-clave-123', $this->comprador->password));

        // El código se borró: no se puede reusar para cambiar la clave otra vez.
        $this->postJson('/password-reset/update-password', $this->pedido_de_cambio($codigo, 'otra-clave-456'))
            ->assertStatus(422);
        $this->assertTrue(Hash::check('nueva-clave-123', $this->comprador->fresh()->password));
    }

    public function test_sin_codigo_no_cambia_la_clave()
    {
        $codigo = $this->pedir_codigo();

        $respuesta = $this->postJson('/password-reset/update-password', $this->pedido_de_cambio(null));

        $respuesta->assertStatus(422)->assertJson(['password_updated' => false]);
        $this->comprador->refresh();
        $this->assertTrue(Hash::check('vieja-clave', $this->comprador->password));

        // El código pendiente sigue sirviendo: un pedido sin código no lo rompe.
        $this->postJson('/password-reset/check-verification-code', [
            'email'             => $this->comprador->email,
            'commerce_id'       => $this->comercio->id,
            'verification_code' => $codigo,
        ])->assertJson(['verified' => true]);
    }

    public function test_con_un_codigo_que_no_es_no_cambia_la_clave()
    {
        $codigo = $this->pedir_codigo();

        $respuesta = $this->postJson('/password-reset/update-password', $this->pedido_de_cambio($this->codigo_distinto_de($codigo)));

        $respuesta->assertStatus(422);
        $this->assertTrue(Hash::check('vieja-clave', $this->comprador->fresh()->password));
    }

    public function test_si_nunca_se_pidio_un_codigo_no_cambia_la_clave()
    {
        $respuesta = $this->postJson('/password-reset/update-password', $this->pedido_de_cambio(''));

        $respuesta->assertStatus(422);
        $this->assertTrue(Hash::check('vieja-clave', $this->comprador->fresh()->password));
    }

    public function test_el_segundo_paso_con_un_email_desconocido_dice_que_no_sin_explotar()
    {
        $respuesta = $this->postJson('/password-reset/check-verification-code', [
            'email'             => 'nadie-'.Str::random(8).'@test.local',
            'commerce_id'       => $this->comercio->id,
            'verification_code' => '482913',
        ]);

        $respuesta->assertStatus(200)->assertJson(['verified' => false]);
    }

    public function test_el_segundo_paso_acepta_el_codigo_vigente()
    {
        $codigo = $this->pedir_codigo();

        $respuesta = $this->postJson('/password-reset/check-verification-code', [
            'email'             => $this->comprador->email,
            'commerce_id'       => $this->comercio->id,
            'verification_code' => $codigo,
        ]);

        $respuesta->assertStatus(200)->assertJson(['verified' => true]);
    }
}
