<?php

namespace Tests\Feature\RecuperarClave;

use App\Buyer;
use App\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PersistenciaEntreSolicitudesTest extends TestCase
{
    use \Tests\Concerns\ConCachePersistenteDeRecuperacion;

    private $comercio;
    private $comprador;
    private $ahora;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparar_cache_recuperacion();
        $this->ahora = time();
        $this->comercio = User::create([
            'name' => 'Prueba persistencia recuperación', 'company_name' => 'Prueba persistencia',
            'email' => Str::random(16).'@test.local', 'password' => bcrypt(Str::random(25)), 'status' => 'commerce',
        ]);
        $this->comprador = Buyer::create([
            'name' => 'Prueba persistencia', 'email' => Str::random(16).'@test.local',
            'password' => bcrypt(Str::random(25)), 'user_id' => $this->comercio->id,
        ]);
        // No hay transacción compartida: cada proceso debe ver la cuenta y los cambios reales.
        $this->beforeApplicationDestroyed(function () {
            $this->comprador->delete();
            $this->comercio->delete();
        });
    }

    private function proceso($paso, array $extra = [], $ip = '10.20.30.40', $bloquear_smtp = false)
    {
        $entrada = [
            'database' => config('database.connections.mysql.database'),
            'cache_path' => $this->ruta_cache_recuperacion,
            'app_key' => config('app.key'),
            'ahora' => $this->ahora,
            'ip' => $ip,
            'paso' => $paso,
            'bloquear_smtp' => $bloquear_smtp,
            'payload' => array_merge([
                'email' => $this->comprador->email, 'commerce_id' => $this->comercio->id,
                'longitud_del_codigo' => 4,
            ], $extra),
        ];
        $p = new Process([PHP_BINARY, base_path('tests/Fixtures/solicitud_recuperacion.php')], base_path());
        $p->setInput(json_encode($entrada));
        $p->setTimeout(30);
        return $p;
    }

    private function resultado(Process $p)
    {
        $p->wait();
        $this->assertTrue($p->isSuccessful(), 'El proceso independiente debe finalizar sin error.');
        $salida = json_decode($p->getOutput(), true);
        $this->assertIsArray($salida, 'El proceso debe entregar una respuesta JSON.');
        return $salida;
    }

    private function solicitar($paso, array $extra = [], $ip = '10.20.30.40')
    {
        $p = $this->proceso($paso, $extra, $ip);
        $p->start();
        return $this->resultado($p);
    }

    private function emitir(array $extra = [])
    {
        $r = $this->solicitar('send-verification-code', $extra);
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['body']['email_send'] ?? false);
        $this->assertTrue(is_string($r['codigo_para_otro_proceso']));
        return $r;
    }

    public function test_emite_verifica_cambia_e_inicia_sesion_en_procesos_distintos_con_cache_general_array()
    {
        $emitido = $this->emitir();
        $codigo = $emitido['codigo_para_otro_proceso'];
        $verificado = $this->solicitar('check-verification-code', ['verification_code' => $codigo, 'email' => ' '.$this->comprador->email.' ']);
        $this->assertTrue($verificado['body']['verified'] ?? false);
        $this->assertNotSame($emitido['pid'], $verificado['pid']);
        $clave = Str::random(30);
        $cambiado = $this->solicitar('update-password', ['verification_code' => $codigo, 'password' => $clave]);
        $this->assertTrue($cambiado['body']['password_updated'] ?? false);
        $this->assertSame($this->comprador->id, $cambiado['sesion']);
        $this->assertTrue(Hash::check($clave, $this->comprador->fresh()->password));
        $consumido = $this->solicitar('check-verification-code', ['verification_code' => $codigo]);
        $this->assertFalse($consumido['body']['verified'] ?? true);
    }

    public function test_un_codigo_nuevo_invalida_el_anterior_y_vence_a_los_treinta_minutos()
    {
        $viejo = $this->emitir(['longitud_del_codigo' => 6])['codigo_para_otro_proceso'];
        $nuevo = $this->emitir()['codigo_para_otro_proceso'];
        $r = $this->solicitar('check-verification-code', ['verification_code' => $viejo]);
        $this->assertFalse($r['body']['verified'] ?? true);
        $this->ahora += 1799;
        $r = $this->solicitar('check-verification-code', ['verification_code' => $nuevo]);
        $this->assertTrue($r['body']['verified'] ?? false);
        $this->ahora++;
        $r = $this->solicitar('check-verification-code', ['verification_code' => $nuevo]);
        $this->assertFalse($r['body']['verified'] ?? true);
    }

    public function test_dos_cambios_paralelos_no_consumen_el_mismo_codigo_dos_veces()
    {
        $codigo = $this->emitir()['codigo_para_otro_proceso'];
        $a = $this->proceso('update-password', ['verification_code' => $codigo, 'password' => Str::random(30)]);
        $b = $this->proceso('update-password', ['verification_code' => $codigo, 'password' => Str::random(30)]);
        $a->start();
        $b->start();
        $resultados = [$this->resultado($a), $this->resultado($b)];
        $this->assertSame(1, count(array_filter($resultados, function ($r) { return $r['body']['password_updated'] ?? false; })));
    }

    public function test_revalida_despues_de_esperar_la_fila_aunque_venza_el_candado_de_cache()
    {
        $codigo = $this->emitir()['codigo_para_otro_proceso'];
        $clave_lock = 'tienda_password_reset:'.$this->comercio->id.':'.$this->comprador->id.':candado';
        DB::beginTransaction();
        try {
            Buyer::whereKey($this->comprador->id)->lockForUpdate()->first();
            $a = $this->proceso('update-password', ['verification_code' => $codigo, 'password' => Str::random(30)]);
            $a->start();
            $owner = $this->esperar_candado($clave_lock);
            // Simular TTL vencido sin dormir diez segundos; el segundo proceso puede entrar al
            // lock de caché mientras ambos esperan la misma fila bloqueada por esta conexión.
            $this->ahora += 11;
            $b = $this->proceso('update-password', ['verification_code' => $codigo, 'password' => Str::random(30)]);
            $b->start();
            $this->esperar_candado($clave_lock, $owner);
        } finally {
            DB::rollBack();
        }
        $resultados = [$this->resultado($a), $this->resultado($b)];
        $this->assertSame(1, count(array_filter($resultados, function ($r) { return $r['body']['password_updated'] ?? false; })));
    }

    private function esperar_candado($clave, $anterior = null)
    {
        $limite = microtime(true) + 5;
        do {
            $owner = Cache::store('password_reset')->get($clave);
            if ($owner && $owner !== $anterior) {
                return $owner;
            }
            usleep(20000);
        } while (microtime(true) < $limite);
        $this->fail('El proceso debe adquirir el candado antes de liberar la fila.');
    }

    public function test_un_smtp_lento_que_falla_no_borra_el_codigo_de_una_emision_posterior()
    {
        $a = $this->proceso('send-verification-code', [], '10.20.30.40', true);
        $a->start();
        $limite = microtime(true) + 5;
        do {
            if (file_exists($this->ruta_cache_recuperacion.'/smtp-esperando')) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $limite);
        try {
            $this->assertFileExists($this->ruta_cache_recuperacion.'/smtp-esperando');
            $clave_lock = 'tienda_password_reset:'.$this->comercio->id.':'.$this->comprador->id.':candado';
            $owner = Cache::store('password_reset')->get($clave_lock);
            $this->ahora += 11;
            $b = $this->proceso('send-verification-code');
            $b->start();
            $this->esperar_candado($clave_lock, $owner);
        } finally {
            file_put_contents($this->ruta_cache_recuperacion.'/smtp-liberar', 'marca no secreta');
        }
        $fallida = $this->resultado($a);
        $posterior = $this->resultado($b);
        $this->assertSame(422, $fallida['status']);
        $this->assertTrue($posterior['body']['email_send'] ?? false);
        $verificado = $this->solicitar('check-verification-code', ['verification_code' => $posterior['codigo_para_otro_proceso']]);
        $this->assertTrue($verificado['body']['verified'] ?? false);
        foreach (['tienda_password_reset_pedidos:', 'tienda_password_reset_pedidos_dia:'] as $prefijo) {
            $contador = Cache::store('password_reset')->get($prefijo.$this->comercio->id.':'.$this->comprador->id);
            $this->assertSame(1, $contador['cantidad']);
        }
    }

    public function test_limites_por_ip_y_por_comprador_persisten_entre_procesos()
    {
        foreach (range(1, 5) as $i) {
            $this->emitir();
        }
        $ip = $this->solicitar('send-verification-code');
        $this->assertSame(429, $ip['status']);
        $cuenta = $this->solicitar('send-verification-code', [], '10.20.30.41');
        $this->assertSame(422, $cuenta['status']);
        $this->assertStringContainsString('Esperá una hora', $cuenta['body']['message']);
    }

    public function test_intentos_fallidos_se_acumulan_entre_solicitudes()
    {
        $codigo = $this->emitir()['codigo_para_otro_proceso'];
        foreach (range(1, 5) as $i) {
            $r = $this->solicitar('check-verification-code', ['verification_code' => '0000']);
            $this->assertFalse($r['body']['verified'] ?? true);
        }
        $r = $this->solicitar('check-verification-code', ['verification_code' => $codigo]);
        $this->assertFalse($r['body']['verified'] ?? true);
    }
}
