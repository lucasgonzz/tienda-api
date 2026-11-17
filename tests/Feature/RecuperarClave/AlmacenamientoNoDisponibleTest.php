<?php

namespace Tests\Feature\RecuperarClave;

use App\Buyer;
use App\Exceptions\PasswordResetStorageException;
use App\Http\Middleware\ThrottlePasswordReset;
use App\Mail\PasswordReset;
use App\Services\PasswordResetCache;
use App\User;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlmacenamientoNoDisponibleTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Concerns\ConCachePersistenteDeRecuperacion;

    private $comercio;
    private $comprador;
    private $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparar_cache_recuperacion();
        $this->comercio = User::create([
            'name' => 'Prueba almacenamiento', 'company_name' => 'Prueba almacenamiento',
            'email' => Str::random(16).'@test.local', 'password' => bcrypt(Str::random(25)), 'status' => 'commerce',
        ]);
        $this->comprador = Buyer::create([
            'name' => 'Prueba almacenamiento', 'email' => Str::random(16).'@test.local',
            'password' => bcrypt('clave-anterior-prueba'), 'user_id' => $this->comercio->id,
        ]);
        Mail::fake();
    }

    private function solicitud($paso, array $extra = [])
    {
        return $this->postJson('/password-reset/'.$paso, array_merge([
            'email' => $this->comprador->email, 'commerce_id' => $this->comercio->id,
            'password' => 'clave-nueva-prueba', 'verification_code' => '0000',
        ], $extra));
    }

    private function exigir_error($respuesta, $campo)
    {
        $respuesta->assertStatus(422)->assertJson([
            $campo => false, 'message' => PasswordResetStorageException::MENSAJE,
            'error_code' => 'password_reset_storage_unavailable',
        ]);
        $this->assertStringNotContainsString('Server Error', $respuesta->getContent());
    }

    private function preparar_store_con_falla()
    {
        $this->store = new class(app('files'), $this->ruta_cache_recuperacion) extends FileStore {
            public $falla;

            public function put($key, $value, $seconds)
            {
                if (($this->falla === 'codigo' && str_starts_with($key, 'tienda_password_reset:'))
                    || ($this->falla === 'hora' && str_starts_with($key, 'tienda_password_reset_pedidos:'))
                    || ($this->falla === 'incremento_ip' && preg_match('/^[a-f0-9]{32}$/', $key))) {
                    return false;
                }
                return parent::put($key, $value, $seconds);
            }

            public function forget($key)
            {
                if ($this->falla === 'consumo' && str_starts_with($key, 'tienda_password_reset:')) {
                    return false;
                }
                return parent::forget($key);
            }
        };
        $store = $this->store;
        Cache::extend('file', function () use ($store) { return new Repository($store); });
        Cache::forgetDriver('password_reset');
    }

    public function test_una_configuracion_volatil_se_explica_en_los_tres_pasos_y_en_el_controlador_sin_middleware()
    {
        config(['cache.password_reset_store' => 'array']);
        foreach ([false, true] as $sin_middleware) {
            if ($sin_middleware) {
                $this->withoutMiddleware(ThrottlePasswordReset::class);
            }
            foreach (['send-verification-code' => 'email_send', 'check-verification-code' => 'verified', 'update-password' => 'password_updated'] as $paso => $campo) {
                $this->exigir_error($this->solicitud($paso), $campo);
            }
        }
        Mail::assertNothingSent();
        $this->assertTrue(Hash::check('clave-anterior-prueba', $this->comprador->fresh()->password));
    }

    public function test_un_directorio_inutilizable_no_devuelve_server_error_en_ningun_paso()
    {
        File::ensureDirectoryExists($this->ruta_cache_recuperacion);
        $archivo = $this->ruta_cache_recuperacion.'/es-un-archivo';
        File::put($archivo, 'marca no secreta');
        config(['cache.stores.password_reset.path' => $archivo]);
        Cache::forgetDriver('password_reset');
        foreach (['send-verification-code' => 'email_send', 'check-verification-code' => 'verified', 'update-password' => 'password_updated'] as $paso => $campo) {
            $this->exigir_error($this->solicitud($paso), $campo);
        }
        Mail::assertNothingSent();
    }

    public function test_no_manda_un_codigo_si_su_escritura_falla_despues_de_la_comprobacion()
    {
        $this->withoutMiddleware(ThrottlePasswordReset::class);
        $this->preparar_store_con_falla();
        $this->store->falla = 'codigo';
        $this->exigir_error($this->solicitud('send-verification-code'), 'email_send');
        Mail::assertNothingSent();
    }

    public function test_una_escritura_fallida_de_intento_se_explica_sin_perder_el_codigo_vigente()
    {
        $this->withoutMiddleware(ThrottlePasswordReset::class);
        $this->preparar_store_con_falla();
        $this->solicitud('send-verification-code')->assertStatus(200);
        $codigo = (string) Mail::sent(PasswordReset::class)->last()->code;
        $this->store->falla = 'codigo';
        $this->exigir_error($this->solicitud('check-verification-code'), 'verified');
        $this->store->falla = null;
        $this->solicitud('check-verification-code', ['verification_code' => $codigo])->assertJson(['verified' => true]);
    }

    public function test_un_fallo_parcial_no_gasta_el_cupo_de_la_otra_ventana()
    {
        $this->withoutMiddleware(ThrottlePasswordReset::class);
        $this->preparar_store_con_falla();
        $this->store->falla = 'hora';
        $this->exigir_error($this->solicitud('send-verification-code'), 'email_send');
        $clave = 'tienda_password_reset_pedidos_dia:'.$this->comercio->id.':'.$this->comprador->id;
        $this->assertNull(Cache::store('password_reset')->get($clave));
        Mail::assertNothingSent();
    }

    public function test_si_falla_el_consumo_no_cambia_la_contrasena_y_permite_reintentar()
    {
        $this->withoutMiddleware(ThrottlePasswordReset::class);
        $this->preparar_store_con_falla();
        $this->solicitud('send-verification-code')->assertStatus(200);
        $codigo = (string) Mail::sent(PasswordReset::class)->last()->code;
        $this->store->falla = 'consumo';
        $this->exigir_error($this->solicitud('update-password', ['verification_code' => $codigo]), 'password_updated');
        $this->assertTrue(Hash::check('clave-anterior-prueba', $this->comprador->fresh()->password));
        $this->store->falla = null;
        $this->solicitud('update-password', ['verification_code' => $codigo])->assertJson(['password_updated' => true]);
    }

    public function test_el_incremento_ip_no_se_confirma_si_filestore_devuelve_numero_sin_guardarlo()
    {
        $this->preparar_store_con_falla();
        $this->store->falla = 'incremento_ip';
        $this->exigir_error($this->solicitud('send-verification-code'), 'email_send');
        Mail::assertNothingSent();
    }

    public function test_comprobar_persistencia_no_crea_claves_ni_directorios_por_cada_instancia()
    {
        foreach (range(1, 25) as $i) {
            app(PasswordResetCache::class);
        }
        $this->assertCount(1, File::allFiles($this->ruta_cache_recuperacion));
        $this->assertCount(1, File::directories($this->ruta_cache_recuperacion));
    }
}
