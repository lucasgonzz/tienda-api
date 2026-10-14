<?php

namespace Tests\Feature\LoginConGoogle;

use App\OnlineConfiguration;
use App\User;
use App\UserConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La tienda ya no tiene login con Google (mision tiendas-demo-y-limpieza-tienda, 14/10/2026).
 *
 * Se saco `POST /sociallogin/{provider}/{commerce_id}` (AuthController@social) y GoogleLoginHelper.
 * Lo que se fija aca:
 *
 *   1. La ruta ya no existe: un SPA viejo que la llame recibe 404, no un login.
 *   2. `commerce.configuration.show_google_login` se sigue mandando —una tienda-spa vieja con
 *      `SocialLogin.vue` la lee— pero SIEMPRE en false, aunque el comercio tenga el login con
 *      Google prendido y las credenciales cargadas desde el ERP. Sin el override, la columna vieja
 *      de `user_configurations` vale 1 por defecto y le dibujaria el boton a todos.
 *   3. Las credenciales de Google siguen sin viajar en la respuesta publica: sacar el login no
 *      las vuelve publicas (las columnas siguen en la base).
 */
class SinLoginConGoogleTest extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\User */
    private $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Comercio Google Test',
            'email'    => 'google-'.Str::random(10).'@test.local',
            'password' => bcrypt('secreto'),
            'status'   => 'commerce',
        ]);

        /* El caso que antes prendia el boton: login habilitado y las dos credenciales cargadas. */
        OnlineConfiguration::create([
            'user_id'              => $this->comercio->id,
            'google_login_enabled' => 1,
            'google_client_id'     => 'client-id-centinela.apps.googleusercontent.com',
            'google_client_secret' => 'secreto-centinela-google',
        ]);

        /* `show_google_login` de user_configurations nace en 1 (default de la columna). */
        $configuracion = new UserConfiguration;
        $configuracion->user_id = $this->comercio->id;
        $configuracion->save();
    }

    /** La ruta del login social ya no existe, para ningun proveedor. */
    public function test_la_ruta_del_login_social_ya_no_existe()
    {
        foreach (['google', 'facebook'] as $proveedor) {
            $this->json('POST', '/sociallogin/'.$proveedor.'/'.$this->comercio->id, ['code' => 'cualquiera'])
                ->assertStatus(404);
        }
    }

    /** `show_google_login` viaja, y en false, aunque el comercio tenga todo cargado. */
    public function test_show_google_login_sale_siempre_en_false()
    {
        $this->assertSame(1, (int) UserConfiguration::where('user_id', $this->comercio->id)->value('show_google_login'),
            'el escenario arranca con la columna vieja prendida: si no, el caso seria vacuo');

        $respuesta = $this->getJson('/api/commerce/'.$this->comercio->id);
        $respuesta->assertStatus(200);

        $configuracion = $respuesta->json('commerce.configuration');
        $this->assertIsArray($configuracion);
        $this->assertArrayHasKey('show_google_login', $configuracion, 'una tienda vieja la sigue leyendo');
        $this->assertFalse($configuracion['show_google_login']);
    }

    /** Las credenciales de Google no viajan en la respuesta publica del comercio. */
    public function test_las_credenciales_de_google_siguen_sin_viajar()
    {
        $contenido = $this->getJson('/api/commerce/'.$this->comercio->id)->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('client-id-centinela', $contenido);
        $this->assertStringNotContainsString('secreto-centinela-google', $contenido);
    }
}
