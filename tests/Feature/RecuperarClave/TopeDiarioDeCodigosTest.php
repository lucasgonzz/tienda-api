<?php

namespace Tests\Feature\RecuperarClave;

use App\Buyer;
use App\Http\Controllers\PasswordResetController;
use App\Mail\PasswordReset;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Tope diario de códigos de recuperación por comprador (misión tope-diario-de-codigos-recuperar-clave,
 * 20/10/2026).
 *
 * Además de los 5 códigos por hora, cada comprador (comercio + comprador) puede recibir como mucho
 * `CODIGOS_POR_DIA_MAXIMOS` (10) en una ventana FIJA de 24 horas. Cuenta: 10 códigos x 5 intentos = 50
 * intentos por día contra 9.000 combinaciones de 4 dígitos (≈ 0,55 %), y no los ≈ 600 por día que dejaba
 * el tope por hora sostenido.
 *
 * Casos:
 *   (a) el 11.º código del día se rechaza: 422 con el mensaje del día, sin mail y sin tocar el pendiente;
 *   (b) 10 códigos repartidos en varias horas (respetando el tope por hora) y el 11.º rechazado;
 *   (c) pasadas 24 h del primer pedido vuelve a andar y el contador arranca de cero;
 *   (d) la ventana es fija: pedir cada 23 h no la estira;
 *   (e) un mail que no sale no consume ni la hora ni el día;
 *   (f) es por cuenta: otro comprador del mismo comercio no se ve afectado;
 *   (g) con la hora y el día llenos, el mensaje es el del día (y con solo la hora llena, el de la hora);
 *   (h) el código pendiente sigue sirviendo con el tope diario lleno;
 *   (i) de punta a punta: 10 códigos x 5 intentos = 50 probados por día, y no uno más;
 *   (j) el tope vale con y sin `longitud_del_codigo`, y cuentan juntos;
 *   (k) es por comercio + comprador: el mismo correo en otro comercio no se ve afectado.
 *
 * El caché de los tests es `array` y `Carbon::setTestNow` lo mueve: el reloj está congelado en `setUp`
 * y cada test lo adelanta a mano. Para emitir 10 códigos sin chocar con el tope de la hora (5), los
 * tests los reparten en dos tandas de 5 separadas por más de una hora.
 */
class TopeDiarioDeCodigosTest extends TestCase
{
    use DatabaseTransactions;

    const ENVIAR = '/password-reset/send-verification-code';
    const VERIFICAR = '/password-reset/check-verification-code';
    const CAMBIAR = '/password-reset/update-password';

    /** El texto del tope diario, escrito a mano a propósito: si cambia la constante, este test avisa. */
    const MENSAJE_DEL_DIA = 'Llegaste al máximo de códigos de recuperación por día. Probá de nuevo en 24 horas o pedile ayuda a soporte.';

    /** @var \App\User */
    private $comercio;

    /** @var \App\Buyer */
    private $comprador;

    /** @var \Illuminate\Support\Carbon El instante en que arranca el test (reloj congelado). */
    private $inicio;

    protected function setUp(): void
    {
        parent::setUp();

        // Throttle por IP (mision throttle-recuperar-clave-por-ip): estos tests hacen muchos pedidos desde
        // 127.0.0.1 y lo que prueban es el tope POR CUENTA; la capa por IP se prueba en ThrottlePorIpTest.
        $this->withoutMiddleware(ThrottleRequests::class);

        Cache::flush();

        // Reloj congelado: el tiempo solo corre cuando el test lo adelanta.
        $this->inicio = Carbon::now();
        Carbon::setTestNow($this->inicio);

        $this->comercio = $this->crear_comercio('Comercio Tope Diario');
        $this->comprador = $this->crear_comprador($this->comercio, 'comprador-dia-'.Str::random(10).'@test.local');
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
            'email'        => 'comercio-dia-'.Str::random(10).'@test.local',
            'password'     => bcrypt('secreto'),
            'status'       => 'commerce',
        ]);
    }

    private function crear_comprador(User $comercio, $email)
    {
        return Buyer::create([
            'name'     => 'Comprador Tope Diario',
            'email'    => $email,
            'password' => bcrypt('vieja-clave'),
            'user_id'  => $comercio->id,
        ]);
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

    /** Pide un código y devuelve el que salió en el mail (el último de este comprador). */
    private function pedir_codigo(?Buyer $comprador = null, array $extra = [])
    {
        $comprador = $comprador ?: $this->comprador;

        $this->postJson(self::ENVIAR, $this->pedido($comprador, $extra))
            ->assertStatus(200)
            ->assertExactJson(['email_send' => true]);

        return (string) Mail::sent(PasswordReset::class, function ($mail) use ($comprador) {
            return $mail->hasTo($comprador->email);
        })->last()->code;
    }

    /** Pide `$cantidad` códigos seguidos (sin mover el reloj) y devuelve el último. */
    private function pedir_codigos($cantidad, ?Buyer $comprador = null, array $extra = [])
    {
        $ultimo = null;

        for ($i = 0; $i < $cantidad; $i++) {
            $ultimo = $this->pedir_codigo($comprador, $extra);
        }

        return $ultimo;
    }

    /**
     * Emite los 10 códigos del día sin chocar con el tope de la hora: 5 ahora y 5 pasada la hora. Deja
     * el reloj 61 minutos después del inicio (la primera ventana de la hora ya venció, la segunda está
     * llena) y devuelve el último código.
     */
    private function llenar_el_dia(?Buyer $comprador = null, array $extra = [])
    {
        $this->pedir_codigos(5, $comprador, $extra);

        $this->avanzar_minutos(61);

        return $this->pedir_codigos(5, $comprador, $extra);
    }

    private function avanzar_minutos($minutos)
    {
        Carbon::setTestNow(Carbon::now()->addMinutes($minutos));
    }

    /** Intenta pedir un código y devuelve la respuesta, sin afirmar nada. */
    private function intentar_pedir(?Buyer $comprador = null, array $extra = [])
    {
        $comprador = $comprador ?: $this->comprador;

        return $this->postJson(self::ENVIAR, $this->pedido($comprador, $extra));
    }

    /** Afirma que el pedido fue rechazado por el tope diario, con la forma exacta del tope por hora. */
    private function assertRechazadoPorElDia($respuesta)
    {
        $respuesta->assertStatus(422)
            ->assertExactJson([
                'email_send' => false,
                'message'    => self::MENSAJE_DEL_DIA,
            ]);
    }

    /** Verifica un código (paso 2) y devuelve el booleano de `verified`. */
    private function verificar($codigo, ?Buyer $comprador = null)
    {
        $comprador = $comprador ?: $this->comprador;

        return $this->postJson(self::VERIFICAR, [
            'email'             => $comprador->email,
            'commerce_id'       => $comprador->user_id,
            'verification_code' => $codigo,
        ])->assertStatus(200)->json('verified');
    }

    /** Intenta cambiar la clave (paso 3) y devuelve la respuesta. */
    private function cambiar($codigo, $clave = 'clave-nueva-123', ?Buyer $comprador = null)
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
        return (string) ((((int) $codigo - 1000 + $desplazamiento) % 9000) + 1000);
    }

    // ------------------------------------------------------------------------------------------
    // El mensaje y la forma de la respuesta
    // ------------------------------------------------------------------------------------------

    /** El texto que ve el comprador es exactamente el pedido, y la constante lo dice. */
    public function test_el_mensaje_del_dia_es_el_acordado_y_distinto_al_de_la_hora()
    {
        $this->assertSame(self::MENSAJE_DEL_DIA, PasswordResetController::MENSAJE_DEMASIADOS_PEDIDOS_POR_DIA);
        $this->assertNotSame(PasswordResetController::MENSAJE_DEMASIADOS_PEDIDOS, PasswordResetController::MENSAJE_DEMASIADOS_PEDIDOS_POR_DIA);
        $this->assertSame(10, PasswordResetController::CODIGOS_POR_DIA_MAXIMOS);
        $this->assertSame(1440, PasswordResetController::VENTANA_DIARIA_EN_MINUTOS);
    }

    // ------------------------------------------------------------------------------------------
    // (a) El 11.º código del día se rechaza
    // ------------------------------------------------------------------------------------------

    public function test_a_el_codigo_once_del_dia_se_rechaza_sin_mail_y_con_el_mensaje_del_dia()
    {
        Mail::fake();

        $this->llenar_el_dia();
        Mail::assertSent(PasswordReset::class, 10);

        // Una hora y pico después del primero: la hora ya no molesta, el día sí.
        $this->avanzar_minutos(61);
        $this->assertRechazadoPorElDia($this->intentar_pedir());

        // Ni uno más: el rechazo no manda mail.
        Mail::assertSent(PasswordReset::class, 10);
    }

    public function test_a_el_rechazo_del_dia_se_repite_en_cada_pedido_siguiente()
    {
        Mail::fake();

        $this->llenar_el_dia();
        $this->avanzar_minutos(61);

        foreach (range(1, 4) as $i) {
            $this->assertRechazadoPorElDia($this->intentar_pedir());
        }

        Mail::assertSent(PasswordReset::class, 10);
    }

    // ------------------------------------------------------------------------------------------
    // (b) 10 códigos repartidos en varias horas
    // ------------------------------------------------------------------------------------------

    public function test_b_diez_codigos_repartidos_en_horas_entran_y_el_once_no()
    {
        Mail::fake();

        // Uno cada 90 minutos: el tope por hora nunca se acerca, así que lo único que frena es el del día.
        for ($i = 1; $i <= 10; $i++) {
            $this->pedir_codigo();
            $this->avanzar_minutos(90);
        }

        // 10 x 90 min = 15 horas desde el primero: sigue siendo el mismo día.
        $this->assertRechazadoPorElDia($this->intentar_pedir());
        Mail::assertSent(PasswordReset::class, 10);
    }

    public function test_b_el_codigo_diez_todavia_entra()
    {
        Mail::fake();

        for ($i = 1; $i <= 9; $i++) {
            $this->pedir_codigo();
            $this->avanzar_minutos(90);
        }

        // El décimo: no hay que rechazarlo (el tope es 10, no 9).
        $this->pedir_codigo();
        Mail::assertSent(PasswordReset::class, 10);
    }

    // ------------------------------------------------------------------------------------------
    // (c) Pasadas 24 h desde el primer pedido vuelve a andar
    // ------------------------------------------------------------------------------------------

    public function test_c_pasadas_veinticuatro_horas_del_primer_pedido_vuelve_a_andar_con_el_contador_en_cero()
    {
        Mail::fake();

        $this->llenar_el_dia();
        $this->avanzar_minutos(61);
        $this->assertRechazadoPorElDia($this->intentar_pedir());

        // Un segundo antes de cumplirse las 24 h del primer pedido: sigue cerrado.
        Carbon::setTestNow($this->inicio->copy()->addMinutes(1440)->subSecond());
        $this->assertRechazadoPorElDia($this->intentar_pedir());

        // A las 24 h justas la ventana venció: se puede pedir otra vez...
        Carbon::setTestNow($this->inicio->copy()->addMinutes(1440));
        $this->pedir_codigos(5);

        // ... y el contador arrancó de cero: entran 10 en total en la ventana nueva, no 1 ni 5.
        $this->avanzar_minutos(61);
        $this->pedir_codigos(5);
        $this->assertRechazadoPorElDia($this->intentar_pedir());

        Mail::assertSent(PasswordReset::class, 20);
    }

    // ------------------------------------------------------------------------------------------
    // (d) La ventana es fija: no se estira
    // ------------------------------------------------------------------------------------------

    public function test_d_pedir_cada_veintitres_horas_no_estira_la_ventana()
    {
        Mail::fake();

        // 9 códigos al arrancar la ventana (5 + 4, repartidos por el tope de la hora).
        $this->pedir_codigos(5);
        $this->avanzar_minutos(61);
        $this->pedir_codigos(4);

        // A las 23 h entra el décimo, dentro de la misma ventana...
        Carbon::setTestNow($this->inicio->copy()->addHours(23));
        $this->pedir_codigo();

        // ... y ese pedido NO movió el arranque de la ventana: sigue siendo el del primer código.
        $pedidos = Cache::get('tienda_password_reset_pedidos_dia:'.$this->comprador->user_id.':'.$this->comprador->id);
        $this->assertSame($this->inicio->getTimestamp(), (int) $pedidos['desde'], 'la ventana arranca con el primer pedido');
        $this->assertSame(10, (int) $pedidos['cantidad']);

        // A las 23 h todavía está llena...
        $this->assertRechazadoPorElDia($this->intentar_pedir());

        // ... y a las 24 h y 1 minuto del PRIMER pedido (una ventana que se estirara con el pedido de las
        // 23 h seguiría cerrada hasta las 47 h) vuelve a andar.
        Carbon::setTestNow($this->inicio->copy()->addMinutes(1441));
        $this->pedir_codigo();

        $pedidos = Cache::get('tienda_password_reset_pedidos_dia:'.$this->comprador->user_id.':'.$this->comprador->id);
        $this->assertSame(1, (int) $pedidos['cantidad'], 'ventana nueva, contador en 1');
    }

    // ------------------------------------------------------------------------------------------
    // (e) Un mail que no sale no consume ni la hora ni el día
    // ------------------------------------------------------------------------------------------

    public function test_e_un_mail_que_no_salio_no_consume_ni_la_hora_ni_el_dia()
    {
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->times(12)->andThrow(new TransportException('sin conexión'));

        // 12 pedidos fallidos: más que el tope de la hora (5) y que el del día (10). Si alguno se
        // contara, a la 6.ª o a la 11.ª vez saldría un mensaje de "demasiados" en lugar del del envío.
        foreach (range(1, 12) as $i) {
            $this->intentar_pedir()
                ->assertStatus(422)
                ->assertExactJson([
                    'email_send' => false,
                    'message'    => PasswordResetController::MENSAJE_ENVIO_FALLIDO,
                ]);
        }

        // Los dos contadores quedaron en cero.
        $dia = Cache::get('tienda_password_reset_pedidos_dia:'.$this->comprador->user_id.':'.$this->comprador->id);
        $hora = Cache::get('tienda_password_reset_pedidos:'.$this->comprador->user_id.':'.$this->comprador->id);
        $this->assertSame(0, (int) ($dia['cantidad'] ?? 0), 'el día no se gastó');
        $this->assertSame(0, (int) ($hora['cantidad'] ?? 0), 'la hora no se gastó');

        // Y el correo vuelve: el comprador conserva sus 10 códigos del día completos.
        Mail::fake();
        $this->llenar_el_dia();
        $this->avanzar_minutos(61);
        $this->assertRechazadoPorElDia($this->intentar_pedir());
        Mail::assertSent(PasswordReset::class, 10);
    }

    public function test_e_un_mail_que_falla_en_el_medio_devuelve_solo_su_pedido()
    {
        // 3 salen bien y el 4.º falla: el contador queda en 3 (no en 4, no en 0).
        Mail::fake();
        $this->pedir_codigos(3);

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new TransportException('sin conexión'));
        $this->intentar_pedir()->assertStatus(422)->assertJson(['message' => PasswordResetController::MENSAJE_ENVIO_FALLIDO]);

        $dia = Cache::get('tienda_password_reset_pedidos_dia:'.$this->comprador->user_id.':'.$this->comprador->id);
        $hora = Cache::get('tienda_password_reset_pedidos:'.$this->comprador->user_id.':'.$this->comprador->id);
        $this->assertSame(3, (int) $dia['cantidad']);
        $this->assertSame(3, (int) $hora['cantidad']);
    }

    // ------------------------------------------------------------------------------------------
    // (f) Es por cuenta
    // ------------------------------------------------------------------------------------------

    public function test_f_el_tope_diario_de_un_comprador_no_afecta_a_otro_del_mismo_comercio()
    {
        Mail::fake();

        $otro = $this->crear_comprador($this->comercio, 'otro-dia-'.Str::random(8).'@test.local');

        $this->llenar_el_dia();
        $this->avanzar_minutos(61);
        $this->assertRechazadoPorElDia($this->intentar_pedir());

        // El otro comprador del mismo comercio sigue con sus 10 códigos enteros.
        $this->pedir_codigos(5, $otro);
        $this->avanzar_minutos(61);
        $this->pedir_codigos(5, $otro);
        $this->assertRechazadoPorElDia($this->intentar_pedir($otro));
    }

    // ------------------------------------------------------------------------------------------
    // (g) Con la hora y el día llenos, el mensaje es el del día
    // ------------------------------------------------------------------------------------------

    public function test_g_con_la_hora_y_el_dia_llenos_el_mensaje_es_el_del_dia()
    {
        Mail::fake();

        $this->llenar_el_dia();

        // Sin mover el reloj: la hora (5 en la última hora) y el día (10) están llenos a la vez.
        $respuesta = $this->intentar_pedir();

        $this->assertRechazadoPorElDia($respuesta);
        $this->assertNotSame(PasswordResetController::MENSAJE_DEMASIADOS_PEDIDOS, $respuesta->json('message'));
    }

    public function test_g_con_solo_la_hora_llena_el_mensaje_sigue_siendo_el_de_la_hora()
    {
        Mail::fake();

        $this->pedir_codigos(5);

        $this->intentar_pedir()
            ->assertStatus(422)
            ->assertExactJson([
                'email_send' => false,
                'message'    => PasswordResetController::MENSAJE_DEMASIADOS_PEDIDOS,
            ]);
    }

    // ------------------------------------------------------------------------------------------
    // (h) El código pendiente sigue sirviendo con el tope diario lleno
    // ------------------------------------------------------------------------------------------

    public function test_h_el_codigo_pendiente_sigue_sirviendo_con_el_tope_diario_lleno()
    {
        Mail::fake();

        $ultimo = $this->llenar_el_dia();

        // Cuatro errores contra ese código: le queda un solo intento.
        foreach (range(1, 4) as $i) {
            $this->assertFalse($this->verificar($this->equivocado_distinto_de($ultimo, $i)));
        }

        // El pedido rechazado no genera código, no pisa el pendiente y no le devuelve intentos.
        $this->avanzar_minutos(5);
        $this->assertRechazadoPorElDia($this->intentar_pedir());
        Mail::assertSent(PasswordReset::class, 10);

        // El último código sigue vivo...
        $this->assertTrue($this->verificar($ultimo), 'el pendiente no se tocó');

        // ... con sus intentos en 4 (un error más lo mata)...
        $this->assertFalse($this->verificar($this->equivocado_distinto_de($ultimo, 9)));
        $this->assertFalse($this->verificar($ultimo), 'el 5.º error mató el código: no se le devolvieron intentos');
    }

    public function test_h_con_el_tope_diario_lleno_se_puede_cambiar_la_clave_con_el_codigo_pendiente()
    {
        Mail::fake();

        $ultimo = $this->llenar_el_dia();
        $this->assertRechazadoPorElDia($this->intentar_pedir());

        $this->cambiar($ultimo)
            ->assertStatus(200)
            ->assertJson(['password_updated' => true]);

        $this->assertTrue(Hash::check('clave-nueva-123', $this->comprador->fresh()->password));
    }

    // ------------------------------------------------------------------------------------------
    // (i) 10 códigos x 5 intentos = 50 intentos por día, y no uno más
    // ------------------------------------------------------------------------------------------

    public function test_i_no_se_pueden_probar_mas_de_cincuenta_codigos_por_dia()
    {
        Mail::fake();

        $probados = 0;
        $emisiones = 0;

        // Hasta 14 pedidos: el 11.º y siguientes tienen que ser rechazados.
        for ($pedido = 1; $pedido <= 14; $pedido++) {
            // Cada 5 emisiones se deja pasar la hora para no chocar con el tope de la hora.
            if ($emisiones > 0 && $emisiones % 5 === 0 && $pedido === $emisiones + 1) {
                $this->avanzar_minutos(61);
            }

            $respuesta = $this->intentar_pedir();

            if ($respuesta->status() !== 200) {
                $this->assertRechazadoPorElDia($respuesta);
                continue;
            }

            $emisiones++;
            $codigo = (string) Mail::sent(PasswordReset::class)->last()->code;

            // Se agotan los intentos del código: 5 errores y muere.
            for ($i = 1; $i <= 5; $i++) {
                $this->assertFalse($this->verificar($this->equivocado_distinto_de($codigo, $i)));
                $probados++;
            }
            $this->assertFalse($this->verificar($codigo), 'a los 5 errores el código bueno ya no sirve');
        }

        $this->assertSame(10, $emisiones, 'solo 10 códigos por día');
        $this->assertSame(50, $probados, '10 códigos x 5 intentos = 50 por día');
        Mail::assertSent(PasswordReset::class, 10);
    }

    // ------------------------------------------------------------------------------------------
    // (j) Con y sin longitud_del_codigo
    // ------------------------------------------------------------------------------------------

    public function test_j_el_tope_diario_vale_con_codigos_de_cuatro_digitos()
    {
        Mail::fake();

        $ultimo = $this->llenar_el_dia();
        $this->assertMatchesRegularExpression('/^[1-9]\d{3}$/', $ultimo);

        $this->avanzar_minutos(61);
        $this->assertRechazadoPorElDia($this->intentar_pedir());
    }

    public function test_j_el_tope_diario_vale_sin_longitud_del_codigo_la_pantalla_vieja()
    {
        Mail::fake();

        // La pantalla vieja no manda `longitud_del_codigo`: códigos de 6 dígitos, mismo tope.
        $cuerpo = ['email' => $this->comprador->email, 'commerce_id' => $this->comercio->id];

        foreach (range(1, 5) as $i) {
            $this->postJson(self::ENVIAR, $cuerpo)->assertStatus(200)->assertExactJson(['email_send' => true]);
        }
        $this->avanzar_minutos(61);
        foreach (range(1, 5) as $i) {
            $this->postJson(self::ENVIAR, $cuerpo)->assertStatus(200)->assertExactJson(['email_send' => true]);
        }

        $this->assertMatchesRegularExpression('/^[1-9]\d{5}$/', (string) Mail::sent(PasswordReset::class)->last()->code);

        $this->avanzar_minutos(61);
        $this->assertRechazadoPorElDia($this->postJson(self::ENVIAR, $cuerpo));
        Mail::assertSent(PasswordReset::class, 10);
    }

    public function test_j_los_pedidos_con_y_sin_longitud_del_codigo_cuentan_juntos()
    {
        Mail::fake();

        // 5 de 4 dígitos y 5 de 6 dígitos: entre los dos llenan el día.
        $this->pedir_codigos(5);
        $this->avanzar_minutos(61);
        foreach (range(1, 5) as $i) {
            $this->postJson(self::ENVIAR, [
                'email'       => $this->comprador->email,
                'commerce_id' => $this->comercio->id,
            ])->assertStatus(200);
        }

        $this->avanzar_minutos(61);

        // Se rechaza el pedido de 4 dígitos y también el de 6.
        $this->assertRechazadoPorElDia($this->intentar_pedir());
        $this->assertRechazadoPorElDia($this->postJson(self::ENVIAR, [
            'email'       => $this->comprador->email,
            'commerce_id' => $this->comercio->id,
        ]));
    }

    // ------------------------------------------------------------------------------------------
    // (k) Es por comercio + comprador
    // ------------------------------------------------------------------------------------------

    public function test_k_el_mismo_correo_en_otro_comercio_no_comparte_el_tope_diario()
    {
        Mail::fake();

        $comercio_b = $this->crear_comercio('Otro Comercio Tope Diario');
        $comprador_b = $this->crear_comprador($comercio_b, $this->comprador->email);

        $this->llenar_el_dia();
        $this->avanzar_minutos(61);
        $this->assertRechazadoPorElDia($this->intentar_pedir());

        // La misma persona (mismo mail) en otra tienda es otro comprador: sus 10 códigos están enteros.
        $this->pedir_codigos(5, $comprador_b);
        $this->avanzar_minutos(61);
        $this->pedir_codigos(5, $comprador_b);
        $this->assertRechazadoPorElDia($this->intentar_pedir($comprador_b));

        // Y se llenó el de B sin tocar el de A: A sigue cerrado por lo suyo (10 mails de A, 10 de B).
        Mail::assertSent(PasswordReset::class, 20);
    }
}
