<?php
namespace Tests\Feature\Buyer;

use App\Buyer;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegistroPublicoTest extends TestCase
{
    use DatabaseTransactions;

    protected function comercio()
    {
        return User::create(['name' => 'Comercio prueba registro', 'email' => Str::random(18).'@test.local', 'password' => bcrypt('prueba-local'), 'status' => 'commerce']);
    }

    protected function datos($comercio)
    {
        return ['commerce_id' => $comercio->id, 'name' => 'Persona de Prueba', 'phone' => '3519876543', 'email' => Str::random(18).'@test.local', 'password' => 'prueba-local'];
    }

    public function test_alta_publica_crea_una_cuenta_y_sesion_sin_exponer_clave()
    {
        $comercio = $this->comercio();
        $datos = $this->datos($comercio);
        $respuesta = $this->postJson('/register', $datos)->assertStatus(201);
        $comprador = Buyer::findOrFail($respuesta->json('buyer.id'));
        $this->assertSame((int) $comercio->id, (int) $comprador->user_id);
        $this->assertTrue(Hash::check($datos['password'], $comprador->password));
        $this->assertAuthenticatedAs($comprador, 'buyer');
        $respuesta->assertJsonMissingPath('buyer.password');
        $this->assertSame(1, Buyer::where('user_id', $comercio->id)->count());
    }

    public function test_correo_invalido_no_crea_cuenta()
    {
        $comercio = $this->comercio();
        $datos = $this->datos($comercio); $datos['email'] = 'correo-invalido';
        $this->postJson('/register', $datos)->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame(0, Buyer::where('user_id', $comercio->id)->count());
        $this->assertGuest('buyer');
    }

    public function test_comercio_inexistente_es_validacion()
    {
        $datos = $this->datos((object) ['id' => 0]);
        $this->postJson('/register', $datos)->assertStatus(422)->assertJsonValidationErrors('commerce_id');
    }

    public function test_mismo_correo_no_duplica_ni_cambia_la_cuenta()
    {
        $comercio = $this->comercio(); $datos = $this->datos($comercio);
        $primera = $this->postJson('/register', $datos)->assertStatus(201);
        $original = Buyer::findOrFail($primera->json('buyer.id'))->getAttributes();
        $this->app['auth']->guard('buyer')->logout();
        $datos['phone'] = '3515555555'; $datos['password'] = 'otra-clave';
        $this->postJson('/register', $datos)->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame($original, Buyer::findOrFail($original['id'])->getAttributes());
        $this->assertSame(1, Buyer::where('user_id', $comercio->id)->count());
        $this->assertGuest('buyer');
    }

    public function test_telefono_compartido_en_mismo_comercio_no_reutiliza_cuenta()
    {
        $comercio = $this->comercio(); $datos = $this->datos($comercio);
        $primero = Buyer::create(['name' => 'Cuenta anterior', 'phone' => '+549'.$datos['phone'], 'email' => 'anterior-'.Str::random(10).'@test.local', 'password' => bcrypt('clave-anterior'), 'user_id' => $comercio->id, 'verification_code' => '123456']);
        $original = $primero->fresh()->getAttributes();
        $respuesta = $this->postJson('/register', $datos)->assertStatus(201);
        $nuevo = Buyer::findOrFail($respuesta->json('buyer.id'));
        $this->assertNotSame($primero->id, $nuevo->id);
        $this->assertSame($original, $primero->fresh()->getAttributes());
        $this->assertSame(2, Buyer::where('user_id', $comercio->id)->count());
        $this->assertNull($nuevo->comercio_city_client_id);
        $this->assertAuthenticatedAs($nuevo, 'buyer');
    }

    public function test_mismo_telefono_y_correo_en_otro_comercio_no_toca_cuenta_ajena()
    {
        $comercio = $this->comercio(); $otro = $this->comercio(); $datos = $this->datos($comercio);
        $previo = Buyer::create(['name' => 'Otra cuenta', 'phone' => '+549'.$datos['phone'], 'email' => $datos['email'], 'password' => bcrypt('clave-anterior'), 'user_id' => $otro->id]);
        $original = $previo->fresh()->getAttributes();
        $respuesta = $this->postJson('/register', $datos)->assertStatus(201);
        $this->assertSame($original, $previo->fresh()->getAttributes());
        $this->assertNotSame($previo->id, $respuesta->json('buyer.id'));
        $this->assertSame(1, Buyer::where('user_id', $otro->id)->count());
    }

    public function test_normaliza_correo_y_rechaza_duplicado_con_mayusculas()
    {
        $comercio = $this->comercio(); $datos = $this->datos($comercio);
        $correo = strtolower($datos['email']); $datos['email'] = '  '.strtoupper($correo).'  ';
        $this->postJson('/register', $datos)->assertStatus(201)->assertJsonPath('buyer.email', $correo);
        $datos['email'] = $correo;
        $this->postJson('/register', $datos)->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame(1, Buyer::where('user_id', $comercio->id)->count());
    }

    public function test_limites_del_esquema_productivo_no_terminan_en_500()
    {
        $comercio = $this->comercio(); $datos = $this->datos($comercio);
        $datos['email'] = str_repeat('a', 60).'@'.str_repeat('b', 45).'.com';
        $datos['address'] = str_repeat('c', 128);
        $this->assertSame(110, strlen($datos['email']));
        $this->postJson('/register', $datos)->assertStatus(201);
        $datos['email'] = 'a'.$datos['email'];
        $datos['address'] .= 'd';
        $this->postJson('/register', $datos)->assertStatus(422)->assertJsonValidationErrors(['email', 'address']);
    }

    public function test_telefono_normalizado_respeta_largo_de_columna()
    {
        $comercio = $this->comercio(); $datos = $this->datos($comercio);
        $datos['phone'] = str_repeat('1', 124);
        $this->postJson('/register', $datos)->assertStatus(201)->assertJsonPath('buyer.phone', '+549'.$datos['phone']);
        $datos['email'] = 'otro-'.$datos['email']; $datos['phone'] .= '1';
        $this->postJson('/register', $datos)->assertStatus(422)->assertJsonValidationErrors('phone');
    }
}
