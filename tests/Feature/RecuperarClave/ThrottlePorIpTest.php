<?php

namespace Tests\Feature\RecuperarClave;

use App\Buyer;
use App\Mail\PasswordReset;
use App\Providers\RouteServiceProvider;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Throttle POR IP de las tres rutas públicas de "Olvidé mi contraseña" (misión
 * throttle-recuperar-clave-por-ip, 20/10/2026).
 *
 * Lo que se prueba, con el middleware de throttle ACTIVO (los tests de la capa por cuenta lo apagan):
 *   - cada paso responde 429 al pasar su límite por minuto y su límite por hora;
 *   - la ventana por hora cuenta APARTE de la del minuto (claves distintas 'm:' / 'h:'): si
 *     compartieran contador, la de la hora nunca llegaría a su tope porque la del minuto vence antes;
 *   - los tres pasos tienen cubos independientes entre sí;
 *   - otra IP no se ve afectada;
 *   - el cuerpo y los headers del 429, y que NO revela si el correo existe;
 *   - pasado el tiempo (el reloj de Carbon mueve el caché de los tests, que es `array`) se libera.
 *
 * Los pedidos de relleno usan un correo que no existe (no mandan mail) o una clave corta (422 sin tocar
 * nada): lo que cuenta para el throttle es el pedido, no lo que responda el controlador.
 */
class ThrottlePorIpTest extends TestCase
{
    use DatabaseTransactions;

    const ENVIAR = '/password-reset/send-verification-code';
    const VERIFICAR = '/password-reset/check-verification-code';
    const CAMBIAR = '/password-reset/update-password';

    /** Mensaje del 429, tal cual lo ve el comprador. */
    const MENSAJE = 'Hiciste demasiados intentos. Esperá unos minutos y volvé a probar.';

    /** @var \App\User */
    private $comercio;

    /** @var \App\Buyer */
    private $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        // El RateLimiter usa el caché por defecto (`array` en los tests, ver phpunit.xml): se vacía por las dudas.
        Cache::flush();

        // Reloj congelado: así la hora no corre sola entre pedidos y el test avanza el tiempo a mano.
        Carbon::setTestNow(Carbon::now());

        $this->comercio = User::create([
            'name'         => 'Comercio Throttle Test',
            'company_name' => 'Comercio Throttle Test',
            'email'        => 'comercio-throttle-'.Str::random(10).'@test.local',
            'password'     => bcrypt('secreto'),
            'status'       => 'commerce',
        ]);

        $this->comprador = Buyer::create([
            'name'     => 'Comprador Throttle Test',
            'email'    => 'comprador-throttle-'.Str::random(10).'@test.local',
            'password' => bcrypt('vieja-clave'),
            'user_id'  => $this->comercio->id,
        ]);

        $this->desde_la_ip('10.1.1.1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Ayudas
    // ------------------------------------------------------------------------------------------

    /** Los pedidos siguientes salen desde esta IP. */
    private function desde_la_ip($ip)
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /** Adelanta el reloj (y con él, el caché `array` del RateLimiter). */
    private function adelantar_segundos($segundos)
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($segundos));
    }

    /**
     * El cuerpo de relleno de cada paso: un correo que no existe, o una clave corta. Cuentan para el
     * throttle igual que uno real, pero no mandan mail ni gastan intentos de ningún código.
     *
     * @param  string  $ruta
     * @return array
     */
    private function cuerpo_de_relleno($ruta)
    {
        $cuerpo = [
            'email'       => 'no-existe-'.Str::random(8).'@test.local',
            'commerce_id' => $this->comercio->id,
        ];

        if ($ruta === self::VERIFICAR) {
            $cuerpo['verification_code'] = '1234';
        }

        if ($ruta === self::CAMBIAR) {
            $cuerpo['verification_code'] = '1234';
            $cuerpo['password'] = '123';
        }

        return $cuerpo;
    }

    /**
     * Manda `$cantidad` pedidos de relleno a la ruta y devuelve la última respuesta.
     *
     * @param  string  $ruta
     * @param  int  $cantidad
     * @return \Illuminate\Testing\TestResponse
     */
    private function golpear($ruta, $cantidad)
    {
        $respuesta = null;

        for ($i = 0; $i < $cantidad; $i++) {
            $respuesta = $this->postJson($ruta, $this->cuerpo_de_relleno($ruta));
        }

        return $respuesta;
    }

    /**
     * Los límites esperados de cada paso: ruta, por minuto, por hora. Escritos a mano a propósito (no se
     * leen de las constantes del RouteServiceProvider) para que cambiar un límite sin querer rompa el test.
     *
     * @return array
     */
    public static function pasos()
    {
        return [
            'pedir el código'     => [self::ENVIAR, 5, 20],
            'verificar el código' => [self::VERIFICAR, 10, 60],
            'cambiar la clave'    => [self::CAMBIAR, 10, 30],
        ];
    }

    // ------------------------------------------------------------------------------------------
    // Límite por minuto
    // ------------------------------------------------------------------------------------------

    /**
     * @dataProvider pasos
     */
    public function test_pasado_el_limite_por_minuto_responde_429($ruta, $por_minuto, $por_hora)
    {
        $this->golpear($ruta, $por_minuto)->assertStatus(
            $ruta === self::CAMBIAR ? 422 : 200
        );

        $this->postJson($ruta, $this->cuerpo_de_relleno($ruta))->assertStatus(429);
    }

    /**
     * @dataProvider pasos
     */
    public function test_pasado_un_minuto_se_libera_el_limite_por_minuto($ruta, $por_minuto, $por_hora)
    {
        $this->golpear($ruta, $por_minuto + 1)->assertStatus(429);

        $this->adelantar_segundos(61);

        // Ya no es 429: el pedido vuelve a llegar al controlador.
        $this->assertNotSame(429, $this->postJson($ruta, $this->cuerpo_de_relleno($ruta))->status());
    }

    // ------------------------------------------------------------------------------------------
    // Límite por hora (cuenta aparte de la del minuto)
    // ------------------------------------------------------------------------------------------

    /**
     * Se mandan rondas del máximo por minuto, esperando 61 s entre una y otra: el límite del minuto
     * nunca se pasa, pero el de la hora sí. Si las dos ventanas compartieran contador, el de la hora
     * se reiniciaría con el del minuto y este 429 no llegaría nunca.
     *
     * @dataProvider pasos
     */
    public function test_pasado_el_limite_por_hora_responde_429_aunque_el_del_minuto_este_libre($ruta, $por_minuto, $por_hora)
    {
        $enviados = 0;

        while ($enviados < $por_hora) {
            $ronda = min($por_minuto, $por_hora - $enviados);
            $this->assertNotSame(429, $this->golpear($ruta, $ronda)->status(), "la ronda que llega a $enviados + $ronda no debería frenarse");
            $enviados += $ronda;
            $this->adelantar_segundos(61);
        }

        // El minuto está libre (pasaron 61 s desde la última ronda) pero la hora ya se agotó.
        $respuesta = $this->postJson($ruta, $this->cuerpo_de_relleno($ruta));
        $respuesta->assertStatus(429);

        // El 429 es el de la ventana de la HORA: el tope que informa es el de la hora y falta más de un minuto.
        $this->assertSame((string) $por_hora, $respuesta->headers->get('X-RateLimit-Limit'));
        $this->assertGreaterThan(60, (int) $respuesta->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(3600, (int) $respuesta->headers->get('Retry-After'));
    }

    /**
     * @dataProvider pasos
     */
    public function test_pasada_la_hora_se_libera_el_limite_por_hora($ruta, $por_minuto, $por_hora)
    {
        $enviados = 0;
        while ($enviados < $por_hora) {
            $ronda = min($por_minuto, $por_hora - $enviados);
            $this->golpear($ruta, $ronda);
            $enviados += $ronda;
            $this->adelantar_segundos(61);
        }
        $this->postJson($ruta, $this->cuerpo_de_relleno($ruta))->assertStatus(429);

        // La ventana de la hora corre desde el primer pedido: con 3.601 s más ya venció.
        $this->adelantar_segundos(3601);

        $this->assertNotSame(429, $this->postJson($ruta, $this->cuerpo_de_relleno($ruta))->status());
    }

    // ------------------------------------------------------------------------------------------
    // Independencia entre pasos y entre IPs
    // ------------------------------------------------------------------------------------------

    /**
     * Agotar un paso no frena a los otros dos: cada uno tiene su cubo.
     *
     * @dataProvider pasos
     */
    public function test_los_tres_pasos_tienen_cubos_independientes($ruta_agotada, $por_minuto, $por_hora)
    {
        $this->golpear($ruta_agotada, $por_minuto + 1)->assertStatus(429);

        foreach ([self::ENVIAR, self::VERIFICAR, self::CAMBIAR] as $otra) {
            if ($otra === $ruta_agotada) {
                continue;
            }
            $this->assertNotSame(
                429,
                $this->postJson($otra, $this->cuerpo_de_relleno($otra))->status(),
                "agotar $ruta_agotada no debería frenar a $otra"
            );
        }
    }

    /**
     * @dataProvider pasos
     */
    public function test_otra_ip_no_se_ve_afectada($ruta, $por_minuto, $por_hora)
    {
        $this->golpear($ruta, $por_minuto + 1)->assertStatus(429);

        $this->desde_la_ip('10.2.2.2');
        $this->assertNotSame(429, $this->postJson($ruta, $this->cuerpo_de_relleno($ruta))->status());

        // Y la primera IP sigue frenada.
        $this->desde_la_ip('10.1.1.1');
        $this->postJson($ruta, $this->cuerpo_de_relleno($ruta))->assertStatus(429);
    }

    // ------------------------------------------------------------------------------------------
    // La respuesta 429
    // ------------------------------------------------------------------------------------------

    /**
     * @dataProvider pasos
     */
    public function test_el_429_trae_el_mensaje_en_espanol_y_los_headers_de_limite($ruta, $por_minuto, $por_hora)
    {
        $respuesta = $this->golpear($ruta, $por_minuto + 1);

        $respuesta->assertStatus(429)
            ->assertExactJson([
                'message' => self::MENSAJE,
                'errors'  => ['email' => [self::MENSAJE]],
            ]);

        // Con el reloj congelado el minuto recién empezó: faltan 60 s para que se libere.
        $this->assertSame((string) $por_minuto, $respuesta->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $respuesta->headers->get('X-RateLimit-Remaining'));
        $this->assertSame('60', $respuesta->headers->get('Retry-After'));
        $this->assertNotNull($respuesta->headers->get('X-RateLimit-Reset'));
        $this->assertStringContainsString('application/json', $respuesta->headers->get('Content-Type'));
    }

    /**
     * El 429 no dice nada del correo: la misma respuesta para un comprador que existe y para uno que no.
     * Y el pedido frenado no llega al controlador: no manda ningún mail.
     */
    public function test_el_429_no_revela_si_el_correo_existe()
    {
        Mail::fake();

        $cuerpo_real = ['email' => $this->comprador->email, 'commerce_id' => $this->comercio->id];
        $cuerpo_inexistente = ['email' => 'no-existe-'.Str::random(8).'@test.local', 'commerce_id' => $this->comercio->id];

        // IP A: pide con el correo real hasta pasarse del minuto.
        $this->desde_la_ip('10.3.3.1');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::ENVIAR, $cuerpo_real);
        }
        $con_correo_real = $this->postJson(self::ENVIAR, $cuerpo_real);

        // IP B: lo mismo con un correo que no existe.
        $this->desde_la_ip('10.3.3.2');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::ENVIAR, $cuerpo_inexistente);
        }
        $con_correo_inexistente = $this->postJson(self::ENVIAR, $cuerpo_inexistente);

        $con_correo_real->assertStatus(429);
        $con_correo_inexistente->assertStatus(429);
        $this->assertSame($con_correo_inexistente->getContent(), $con_correo_real->getContent());
        $this->assertStringNotContainsString($this->comprador->email, $con_correo_real->getContent());

        // De los 5 pedidos de la IP A, los 5 dentro del límite mandaron mail (el tope por cuenta es de 5
        // por hora); el sexto, frenado por la IP, no mandó ninguno más.
        Mail::assertSent(PasswordReset::class, 5);
    }

    /**
     * Un pedido normal (una persona que se equivocó una vez) no se ve afectado y no cambia su respuesta:
     * el throttle es aditivo.
     */
    public function test_un_pedido_normal_responde_como_siempre()
    {
        Mail::fake();

        $this->postJson(self::ENVIAR, ['email' => $this->comprador->email, 'commerce_id' => $this->comercio->id])
            ->assertStatus(200)
            ->assertExactJson(['email_send' => true]);

        $this->postJson(self::ENVIAR, ['email' => 'no-existe-'.Str::random(8).'@test.local', 'commerce_id' => $this->comercio->id])
            ->assertStatus(200)
            ->assertExactJson(['email_send' => false]);
    }

    /**
     * Los límites del RouteServiceProvider son los del plan (red de seguridad contra un cambio sin querer
     * que el resto de los tests no notaría).
     */
    public function test_los_limites_declarados_son_los_acordados()
    {
        $this->assertSame([5, 20], [RouteServiceProvider::PEDIR_POR_MINUTO, RouteServiceProvider::PEDIR_POR_HORA]);
        $this->assertSame([10, 60], [RouteServiceProvider::VERIFICAR_POR_MINUTO, RouteServiceProvider::VERIFICAR_POR_HORA]);
        $this->assertSame([10, 30], [RouteServiceProvider::CAMBIAR_POR_MINUTO, RouteServiceProvider::CAMBIAR_POR_HORA]);
    }
}
