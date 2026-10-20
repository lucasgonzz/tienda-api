<?php

namespace Tests\Feature\RecuperarClave;

use App\Buyer;
use App\Mail\PasswordReset;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Olvidé mi contraseña" con código de 4 dígitos (misión recuperar-clave-codigo-4-digitos, 20/10/2026).
 *
 * Lo que se prueba:
 *   - el largo del código es opt-in: `longitud_del_codigo` = 4 da 4 dígitos; sin el campo (la pantalla
 *     vieja) siguen siendo 6;
 *   - los topes que hacen seguro un código de 4 dígitos (9.000 combinaciones): 5 intentos fallidos por
 *     código, 5 códigos por hora por comprador (o sea, 25 códigos probados por hora como máximo) y 30
 *     minutos de vida;
 *   - el código de un comercio no sirve en otro;
 *   - la recuperación y el registro del comprador (que comparten la columna `buyers.verification_code`
 *     en el resto del sistema) no se pisan;
 *   - el camino completo: pedir, verificar, cambiar la clave y entrar con la clave nueva.
 *
 * El caché de los tests es el de `.env.testing` (array): se reinicia con cada test, así que ningún
 * contador de un test le llega al siguiente. Igual, `setUp` lo vacía a mano por si alguien cambia el driver.
 */
class CodigoDeCuatroDigitosTest extends TestCase
{
    use DatabaseTransactions;

    const ENVIAR = '/password-reset/send-verification-code';
    const VERIFICAR = '/password-reset/check-verification-code';
    const CAMBIAR = '/password-reset/update-password';

    /** @var \App\User */
    private $comercio;

    /** @var \App\Buyer */
    private $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->comercio = $this->crear_comercio('Comercio Cuatro Digitos');

        $this->comprador = $this->crear_comprador($this->comercio, 'comprador-4d-'.Str::random(10).'@test.local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Ayudas
    // ------------------------------------------------------------------------------------------

    private function crear_comercio($nombre)
    {
        return User::create([
            'name'         => $nombre,
            'company_name' => $nombre,
            'email'        => 'comercio-4d-'.Str::random(10).'@test.local',
            'password'     => bcrypt('secreto'),
            'status'       => 'commerce',
        ]);
    }

    private function crear_comprador(User $comercio, $email, array $extra = [])
    {
        return Buyer::create(array_merge([
            'name'     => 'Comprador Cuatro Digitos',
            'email'    => $email,
            'password' => bcrypt('vieja-clave'),
            'user_id'  => $comercio->id,
        ], $extra));
    }

    /** El cuerpo que manda la pantalla nueva en el paso 1. */
    private function pedido(Buyer $comprador, array $extra = [])
    {
        return array_merge([
            'email'               => $comprador->email,
            'commerce_id'         => $comprador->user_id,
            'longitud_del_codigo' => 4,
        ], $extra);
    }

    /**
     * Pide un código y devuelve el que salió en el mail (el último de este comprador).
     *
     * @return string
     */
    private function pedir_codigo(Buyer $comprador = null, array $extra = [])
    {
        $comprador = $comprador ?: $this->comprador;

        $this->postJson(self::ENVIAR, $this->pedido($comprador, $extra))
            ->assertStatus(200)
            ->assertJson(['email_send' => true]);

        return (string) Mail::sent(PasswordReset::class, function ($mail) use ($comprador) {
            return $mail->hasTo($comprador->email);
        })->last()->code;
    }

    /** Verifica un código (paso 2) y devuelve el booleano de `verified`. */
    private function verificar($codigo, Buyer $comprador = null, $commerce_id = null)
    {
        $comprador = $comprador ?: $this->comprador;

        return $this->postJson(self::VERIFICAR, [
            'email'             => $comprador->email,
            'commerce_id'       => is_null($commerce_id) ? $comprador->user_id : $commerce_id,
            'verification_code' => $codigo,
        ])->assertStatus(200)->json('verified');
    }

    /** Intenta cambiar la clave (paso 3) y devuelve la respuesta. */
    private function cambiar($codigo, $clave = 'clave-nueva-123', Buyer $comprador = null)
    {
        $comprador = $comprador ?: $this->comprador;

        return $this->postJson(self::CAMBIAR, [
            'email'             => $comprador->email,
            'commerce_id'       => $comprador->user_id,
            'verification_code' => $codigo,
            'password'          => $clave,
        ]);
    }

    /** Un código de 4 dígitos que seguro NO es el dado. */
    private function equivocado_distinto_de($codigo, $desplazamiento = 1)
    {
        $otro = (((int) $codigo - 1000 + $desplazamiento) % 9000) + 1000;

        return (string) $otro;
    }

    // ------------------------------------------------------------------------------------------
    // El largo del código es opt-in
    // ------------------------------------------------------------------------------------------

    public function test_con_longitud_del_codigo_4_el_codigo_tiene_cuatro_digitos()
    {
        Mail::fake();

        $codigo = $this->pedir_codigo();

        $this->assertMatchesRegularExpression('/^[1-9]\d{3}$/', $codigo, 'cuatro dígitos, 1000-9999');
    }

    public function test_longitud_del_codigo_llegando_como_texto_tambien_cuenta()
    {
        Mail::fake();

        $codigo = $this->pedir_codigo(null, ['longitud_del_codigo' => '4']);

        $this->assertMatchesRegularExpression('/^\d{4}$/', $codigo);
    }

    /** La pantalla vieja no manda el campo: tiene que seguir recibiendo 6 dígitos. */
    public function test_sin_longitud_del_codigo_sigue_siendo_de_seis_digitos()
    {
        Mail::fake();

        $this->postJson(self::ENVIAR, [
            'email'       => $this->comprador->email,
            'commerce_id' => $this->comercio->id,
        ])->assertStatus(200)->assertExactJson(['email_send' => true]);

        $codigo = (string) Mail::sent(PasswordReset::class)->last()->code;

        $this->assertMatchesRegularExpression('/^[1-9]\d{5}$/', $codigo, 'seis dígitos, 100000-999999');
    }

    /** Cualquier valor que no sea 4 no cambia nada: el largo no es un parámetro libre. */
    public function test_cualquier_otro_valor_de_longitud_del_codigo_da_seis_digitos()
    {
        foreach ([2, 3, 5, 8, 'abc', '', [4], 0, null] as $valor) {
            Cache::flush();
            Mail::fake();

            $this->postJson(self::ENVIAR, $this->pedido($this->comprador, ['longitud_del_codigo' => $valor]))
                ->assertStatus(200);

            $codigo = (string) Mail::sent(PasswordReset::class)->last()->code;
            $this->assertMatchesRegularExpression('/^\d{6}$/', $codigo, 'con longitud_del_codigo='.json_encode($valor));
        }
    }

    public function test_los_codigos_de_cuatro_digitos_no_empiezan_con_cero()
    {
        // 1000-9999: la pantalla puede mandar el código como número sin perder un cero inicial.
        foreach (range(1, 4) as $i) {
            Mail::fake();
            $codigo = $this->pedir_codigo();
            $this->assertGreaterThanOrEqual(1000, (int) $codigo);
            $this->assertLessThanOrEqual(9999, (int) $codigo);
        }
    }

    // ------------------------------------------------------------------------------------------
    // El camino completo
    // ------------------------------------------------------------------------------------------

    public function test_de_punta_a_punta_pide_verifica_cambia_la_clave_y_entra_con_la_nueva()
    {
        Mail::fake();

        $codigo = $this->pedir_codigo();
        $this->assertTrue($this->verificar($codigo));

        $this->cambiar($codigo, 'clave-nueva-123')
            ->assertStatus(200)
            ->assertJson(['password_updated' => true]);

        $comprador = $this->comprador->fresh();
        $this->assertTrue(Hash::check('clave-nueva-123', $comprador->password));
        $this->assertFalse(Hash::check('vieja-clave', $comprador->password), 'la clave vieja deja de servir');

        // Entra con la clave nueva por el login real de la tienda; con la vieja, no.
        $this->postJson('/login', [
            'email'       => $comprador->email,
            'commerce_id' => $this->comercio->id,
            'password'    => 'clave-nueva-123',
        ])->assertStatus(200);

        $this->app['auth']->guard('buyer')->logout();

        $this->postJson('/login', [
            'email'       => $comprador->email,
            'commerce_id' => $this->comercio->id,
            'password'    => 'vieja-clave',
        ])->assertStatus(403);
    }

    public function test_el_cambio_de_clave_deja_al_comprador_con_la_sesion_iniciada()
    {
        Mail::fake();

        $codigo = $this->pedir_codigo();
        $this->cambiar($codigo)->assertStatus(200);

        $this->assertAuthenticatedAs($this->comprador->fresh(), 'buyer');
    }

    public function test_una_clave_nueva_demasiado_corta_se_rechaza_y_no_gasta_un_intento()
    {
        Mail::fake();

        $codigo = $this->pedir_codigo();

        foreach (range(1, 8) as $i) {
            $this->cambiar($codigo, '12345')
                ->assertStatus(422)
                ->assertJson(['password_updated' => false]);
        }

        $this->assertTrue(Hash::check('vieja-clave', $this->comprador->fresh()->password));
        // Ni una sola de esas 8 le sumó un intento al código: sigue vivo.
        $this->assertTrue($this->verificar($codigo));
        $this->cambiar($codigo, '123456')->assertStatus(200);
    }

    public function test_pedir_otro_codigo_invalida_el_anterior()
    {
        Mail::fake();

        $primero = $this->pedir_codigo();
        $segundo = $this->pedir_codigo();

        if ($primero !== $segundo) {
            $this->assertFalse($this->verificar($primero), 'el código viejo ya no sirve');
        }
        $this->assertTrue($this->verificar($segundo));
    }

    // ------------------------------------------------------------------------------------------
    // Topes: 5 intentos por código, 5 códigos por hora, 30 minutos
    // ------------------------------------------------------------------------------------------

    /** El 5.º error mata el código: el correcto ya no sirve ni para verificar ni para cambiar la clave. */
    public function test_a_los_cinco_intentos_fallidos_el_codigo_muere()
    {
        Mail::fake();

        $codigo = $this->pedir_codigo();

        foreach (range(1, 5) as $i) {
            $this->assertFalse($this->verificar($this->equivocado_distinto_de($codigo, $i)), "intento $i");
        }

        $this->assertFalse($this->verificar($codigo), 'el código bueno ya no sirve');
        $this->cambiar($codigo)->assertStatus(422);
        $this->assertTrue(Hash::check('vieja-clave', $this->comprador->fresh()->password));
    }

    /** Con cuatro errores el código sigue vivo: el tope es 5, no 4. */
    public function test_con_cuatro_intentos_fallidos_el_codigo_bueno_todavia_sirve()
    {
        Mail::fake();

        $codigo = $this->pedir_codigo();

        foreach (range(1, 4) as $i) {
            $this->assertFalse($this->verificar($this->equivocado_distinto_de($codigo, $i)));
        }

        $this->assertTrue($this->verificar($codigo));
        $this->cambiar($codigo)->assertStatus(200);
    }

    /** Los intentos del paso 2 y los del paso 3 salen de la misma bolsa. */
    public function test_los_intentos_de_verificar_y_de_cambiar_la_clave_suman_juntos()
    {
        Mail::fake();

        $codigo = $this->pedir_codigo();

        $this->assertFalse($this->verificar($this->equivocado_distinto_de($codigo, 1)));
        $this->assertFalse($this->verificar($this->equivocado_distinto_de($codigo, 2)));
        $this->cambiar($this->equivocado_distinto_de($codigo, 3))->assertStatus(422);
        $this->cambiar($this->equivocado_distinto_de($codigo, 4))->assertStatus(422);
        $this->cambiar($this->equivocado_distinto_de($codigo, 5))->assertStatus(422);

        $this->assertFalse($this->verificar($codigo), 'a la 5.ª equivocación, muerto');
    }

    /**
     * Por emisión se prueban como mucho 5 códigos: el correcto, puesto en el lugar N de la fila de
     * intentos, entra si N <= 5 y no entra si N >= 6, porque los 5 errores anteriores ya mataron el código.
     */
    public function test_no_se_pueden_probar_mas_de_cinco_codigos_por_emision()
    {
        Mail::fake();

        foreach (range(1, 8) as $lugar) {
            Cache::flush();
            $codigo = $this->pedir_codigo();

            foreach (range(1, $lugar - 1) as $i) {
                if ($lugar === 1) {
                    break;
                }
                $this->verificar($this->equivocado_distinto_de($codigo, $i));
            }

            $this->assertSame($lugar <= 5, $this->verificar($codigo), "el código correcto en el lugar $lugar");
        }
    }

    /** 5 códigos por hora: el sexto pedido se rechaza y no manda mail. */
    public function test_no_se_pueden_pedir_mas_de_cinco_codigos_por_hora()
    {
        // Throttle por IP (mision throttle-recuperar-clave-por-ip): este test hace muchos pedidos desde
        // 127.0.0.1 y lo que prueba es el tope POR CUENTA; la capa por IP se prueba en ThrottlePorIpTest.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Mail::fake();

        foreach (range(1, 5) as $i) {
            $this->pedir_codigo();
        }

        $this->postJson(self::ENVIAR, $this->pedido($this->comprador))
            ->assertStatus(422)
            ->assertJson([
                'email_send' => false,
                'message'    => \App\Http\Controllers\PasswordResetController::MENSAJE_DEMASIADOS_PEDIDOS,
            ]);

        Mail::assertSent(PasswordReset::class, 5);
    }

    /**
     * El techo del que depende la seguridad del código de 4 dígitos: 5 emisiones x 5 intentos = 25
     * códigos probados por hora, y no uno más. Se agotan los 5 intentos de cada emisión, se pide el
     * siguiente, y en la sexta ya no hay código nuevo que probar.
     */
    public function test_no_se_pueden_probar_mas_de_veinticinco_codigos_por_hora()
    {
        // Throttle por IP (mision throttle-recuperar-clave-por-ip): este test hace muchos pedidos desde
        // 127.0.0.1 y lo que prueba es el tope POR CUENTA; la capa por IP se prueba en ThrottlePorIpTest.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Mail::fake();

        $probados = 0;

        for ($emision = 1; $emision <= 6; $emision++) {
            $respuesta = $this->postJson(self::ENVIAR, $this->pedido($this->comprador));

            if ($respuesta->status() !== 200) {
                $this->assertSame(6, $emision, 'solo la sexta emisión se rechaza');
                break;
            }

            $codigo = (string) Mail::sent(PasswordReset::class)->last()->code;

            // Se prueban códigos hasta que el código muera.
            for ($i = 1; $i <= 50; $i++) {
                if ($this->verificar($this->equivocado_distinto_de($codigo, $i))) {
                    $this->fail('un código equivocado salió verificado');
                }
                $probados++;

                if (!$this->verificar($codigo)) {
                    break;
                }
            }
        }

        // Cada emisión admite 5 intentos fallidos: 5 emisiones x 5 = 25. (La comprobación de "sigue
        // vivo" se hace con el código bueno y no suma intentos.)
        $this->assertSame(25, $probados, 'exactamente 25 códigos probados por hora');
        Mail::assertSent(PasswordReset::class, 5);
    }

    public function test_pasada_la_hora_se_pueden_pedir_codigos_otra_vez()
    {
        // Throttle por IP (mision throttle-recuperar-clave-por-ip): este test hace muchos pedidos desde
        // 127.0.0.1 y lo que prueba es el tope POR CUENTA; la capa por IP se prueba en ThrottlePorIpTest.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Mail::fake();

        foreach (range(1, 5) as $i) {
            $this->pedir_codigo();
        }
        $this->postJson(self::ENVIAR, $this->pedido($this->comprador))->assertStatus(422);

        Carbon::setTestNow(Carbon::now()->addMinutes(61));

        $this->pedir_codigo();
    }

    /** El tope por hora es por comprador: a otro comprador no le gasta pedidos. */
    public function test_el_tope_por_hora_es_por_comprador()
    {
        // Throttle por IP (mision throttle-recuperar-clave-por-ip): este test hace muchos pedidos desde
        // 127.0.0.1 y lo que prueba es el tope POR CUENTA; la capa por IP se prueba en ThrottlePorIpTest.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Mail::fake();

        $otro = $this->crear_comprador($this->comercio, 'otro-4d-'.Str::random(8).'@test.local');

        foreach (range(1, 5) as $i) {
            $this->pedir_codigo();
        }
        $this->postJson(self::ENVIAR, $this->pedido($this->comprador))->assertStatus(422);

        $this->pedir_codigo($otro);
    }

    /** Un problema del servidor de correo no le gasta al comprador los pedidos de la hora. */
    public function test_un_mail_que_no_salio_no_cuenta_para_el_tope_por_hora()
    {
        // Throttle por IP (mision throttle-recuperar-clave-por-ip): este test hace muchos pedidos desde
        // 127.0.0.1 y lo que prueba es el tope POR CUENTA; la capa por IP se prueba en ThrottlePorIpTest.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->times(6)->andThrow(
            new \Symfony\Component\Mailer\Exception\TransportException('sin conexión')
        );

        foreach (range(1, 6) as $i) {
            $this->postJson(self::ENVIAR, $this->pedido($this->comprador))
                ->assertStatus(422)
                ->assertJson(['message' => \App\Http\Controllers\PasswordResetController::MENSAJE_ENVIO_FALLIDO]);
        }
    }

    public function test_el_codigo_vive_treinta_minutos()
    {
        Mail::fake();

        $codigo = $this->pedir_codigo();

        Carbon::setTestNow(Carbon::now()->addMinutes(29));
        $this->assertTrue($this->verificar($codigo), 'a los 29 minutos sirve');

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->assertFalse($this->verificar($codigo), 'a los 31 minutos venció');
        $this->cambiar($codigo)->assertStatus(422);
        $this->assertTrue(Hash::check('vieja-clave', $this->comprador->fresh()->password));
    }

    // ------------------------------------------------------------------------------------------
    // El código de un comercio no sirve en otro
    // ------------------------------------------------------------------------------------------

    /**
     * La misma persona (mismo mail) compra en dos tiendas: son dos compradores. El código pedido en
     * la tienda A no sirve en la B, ni para verificar ni para cambiar la clave de B.
     */
    public function test_el_codigo_de_un_comercio_no_sirve_en_otro()
    {
        Mail::fake();

        $comercio_b = $this->crear_comercio('Otro Comercio');
        $comprador_b = $this->crear_comprador($comercio_b, $this->comprador->email);

        $codigo_a = $this->pedir_codigo($this->comprador);

        // Con el código de A, contra B: no verifica y no cambia nada. (Y sin código pedido en B, no hay nada que probar.)
        $this->assertFalse($this->verificar($codigo_a, $comprador_b));
        $this->cambiar($codigo_a, 'clave-nueva-123', $comprador_b)->assertStatus(422);
        $this->assertTrue(Hash::check('vieja-clave', $comprador_b->fresh()->password));

        // Y si B también pidió el suyo, cada código sirve solo en su tienda.
        $codigo_b = $this->pedir_codigo($comprador_b);

        $this->assertTrue($this->verificar($codigo_b, $comprador_b));
        $this->assertTrue($this->verificar($codigo_a, $this->comprador));
        if ($codigo_a !== $codigo_b) {
            $this->assertFalse($this->verificar($codigo_a, $comprador_b));
        }
    }

    /** Pedir el código de un comprador con el commerce_id de otra tienda no manda nada. */
    public function test_pedir_el_codigo_con_el_commerce_id_de_otra_tienda_no_manda_nada()
    {
        Mail::fake();

        $comercio_b = $this->crear_comercio('Otro Comercio Sin Compradores');

        $this->postJson(self::ENVIAR, [
            'email'               => $this->comprador->email,
            'commerce_id'         => $comercio_b->id,
            'longitud_del_codigo' => 4,
        ])->assertStatus(200)->assertExactJson(['email_send' => false]);

        Mail::assertNothingSent();
    }

    /** Cambiar la clave con el código de A y el commerce_id de B no toca a nadie. */
    public function test_los_intentos_de_un_comercio_no_le_gastan_el_codigo_al_otro()
    {
        // Throttle por IP (mision throttle-recuperar-clave-por-ip): este test hace muchos pedidos desde
        // 127.0.0.1 y lo que prueba es el tope POR CUENTA; la capa por IP se prueba en ThrottlePorIpTest.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Mail::fake();

        $comercio_b = $this->crear_comercio('Comercio B Intentos');
        $comprador_b = $this->crear_comprador($comercio_b, $this->comprador->email);

        $codigo_a = $this->pedir_codigo($this->comprador);

        // 10 errores contra la tienda B (donde no se pidió nada) no matan el código de la tienda A.
        foreach (range(1, 10) as $i) {
            $this->verificar($this->equivocado_distinto_de($codigo_a, $i), $comprador_b);
        }

        $this->assertTrue($this->verificar($codigo_a, $this->comprador));
    }

    // ------------------------------------------------------------------------------------------
    // Entradas raras: nunca un 500
    // ------------------------------------------------------------------------------------------

    public function test_sin_email_o_con_basura_no_explota()
    {
        Mail::fake();

        foreach ([
            [],
            ['email' => null, 'commerce_id' => $this->comercio->id],
            ['email' => ['x'], 'commerce_id' => $this->comercio->id],
            ['email' => $this->comprador->email],
            ['email' => $this->comprador->email, 'commerce_id' => ['x']],
        ] as $cuerpo) {
            $this->postJson(self::ENVIAR, $cuerpo)->assertStatus(200)->assertExactJson(['email_send' => false]);
            $this->postJson(self::VERIFICAR, $cuerpo + ['verification_code' => '1234'])->assertStatus(200)->assertJson(['verified' => false]);
            $this->postJson(self::CAMBIAR, $cuerpo + ['verification_code' => '1234', 'password' => 'clave-123'])->assertStatus(422);
        }

        Mail::assertNothingSent();
    }

    /** Un código que llega como número (no como texto) se acepta igual: es lo que mandaba la pantalla vieja. */
    public function test_el_codigo_como_numero_se_acepta()
    {
        Mail::fake();

        $codigo = $this->pedir_codigo();

        $this->postJson(self::VERIFICAR, [
            'email'             => $this->comprador->email,
            'commerce_id'       => $this->comercio->id,
            'verification_code' => (int) $codigo,
        ])->assertJson(['verified' => true]);
    }

    // ------------------------------------------------------------------------------------------
    // No pisa el registro del comprador (misma columna `verification_code`, otro uso)
    // ------------------------------------------------------------------------------------------

    /**
     * La recuperación no lee ni escribe `buyers.verification_code`: lo que hubiera ahí (el código de un
     * registro que quedó a medias, o uno viejo de un reset) queda exactamente como estaba, en cada paso.
     */
    public function test_la_recuperacion_no_toca_la_columna_verification_code()
    {
        Mail::fake();

        $this->comprador->verification_code = 7788;
        $this->comprador->save();

        $codigo = $this->pedir_codigo();
        $this->assertEquals(7788, $this->comprador->fresh()->verification_code, 'tras pedir el código');

        $this->verificar($this->equivocado_distinto_de($codigo));
        $this->assertTrue($this->verificar($codigo));
        $this->assertEquals(7788, $this->comprador->fresh()->verification_code, 'tras verificar');

        $this->cambiar($codigo)->assertStatus(200);
        $this->assertEquals(7788, $this->comprador->fresh()->verification_code, 'tras cambiar la clave');
    }

    /** Y el código de la columna (el del registro) no sirve como código de recuperación. */
    public function test_el_codigo_pendiente_del_registro_no_sirve_para_recuperar_la_clave()
    {
        Mail::fake();

        $this->comprador->verification_code = 7788;
        $this->comprador->save();

        // Sin haber pedido un código de recuperación, el de la columna no abre nada.
        $this->assertFalse($this->verificar('7788'));
        $this->cambiar('7788')->assertStatus(422);
        $this->assertTrue(Hash::check('vieja-clave', $this->comprador->fresh()->password));
    }

    /** El registro sigue verificando su código como siempre, con una recuperación en curso. */
    public function test_el_registro_sigue_verificando_su_codigo_con_una_recuperacion_en_curso()
    {
        Mail::fake();

        $this->comprador->verification_code = 7788;
        $this->comprador->save();

        $this->pedir_codigo();

        $this->actingAs($this->comprador, 'buyer');
        $this->postJson('/register/verify-code', ['verification_code' => 7788])
            ->assertStatus(200)
            ->assertJson(['verified' => true]);
        $this->assertNull($this->comprador->fresh()->verification_code, 'el registro lo consume como siempre');
    }

    /**
     * El agujero que tenía guardar el código de recuperación en la columna: `register()` considera "no
     * registrado" a quien tiene `verification_code` y, con ese teléfono, BORRA al comprador
     * (`deleteIfExist`). Con la recuperación en caché, una recuperación pendiente no desregistra a nadie.
     */
    public function test_una_recuperacion_pendiente_no_deja_que_un_registro_borre_la_cuenta()
    {
        Mail::fake();

        $this->comprador->phone = '+5491155550000';
        $this->comprador->save();

        $this->pedir_codigo();

        // Alguien que conoce el teléfono intenta registrarse con él.
        $this->postJson('/register', [
            'name'        => 'Intruso',
            'email'       => 'intruso-'.Str::random(6).'@test.local',
            'password'    => 'clave-del-intruso',
            'phone'       => '1155550000',
            'commerce_id' => $this->comercio->id,
        ])->assertStatus(200);

        $this->assertNotNull(Buyer::find($this->comprador->id), 'la cuenta sigue existiendo');
        $this->assertSame($this->comprador->email, Buyer::find($this->comprador->id)->email);
    }
}
