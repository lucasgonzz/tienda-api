<?php

namespace Tests\Feature\Buyer;

use App\Buyer;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `PUT /api/buyer/envio-zipcode`: guarda el último código postal con el que el buyer cotizó un
 * envío, para no volver a pedírselo la próxima vez (misión envio-cp-buyer-modal, 16/9/2026).
 *
 * Las columnas `envio_zipcode/envio_city/envio_state` las crea `empresa-api`; el caso donde
 * todavía no existen (cliente sin esa migración) va en `ActualizaEnvioZipcodeSinEsquemaTest`, con
 * el mismo mecanismo de `SinEsquemaDeEnviosTest` (DDL fuera de la transacción). Acá se asume que
 * `tienda_testing_s5` ya las tiene: se agregaron a mano con el mismo `ALTER TABLE` que la
 * migración de `empresa-api`, porque tienda-api no corre `migrate` contra su propia base de
 * testing para un esquema que no es suyo.
 */
class ActualizaEnvioZipcodeTest extends TestCase
{
    use DatabaseTransactions;

    const RUTA = '/api/buyer/envio-zipcode';

    protected function comercio()
    {
        return User::create([
            'name'     => 'Comercio Envios Test',
            'email'    => 'envios-zipcode-'.Str::random(10).'@test.local',
            'password' => bcrypt('secreto'),
            'status'   => 'commerce',
        ]);
    }

    protected function comprador(User $comercio)
    {
        return Buyer::create([
            'name'    => 'Comprador Envios Test',
            'email'   => 'comprador-zipcode-'.Str::random(8).'@test.local',
            'phone'   => '3511234567',
            'user_id' => $comercio->id,
        ]);
    }

    public function test_sin_sesion_devuelve_401()
    {
        $this->putJson(self::RUTA, ['zipcode' => 'S2000'])->assertStatus(401);
    }

    public function test_sin_codigo_postal_devuelve_422()
    {
        $comprador = $this->comprador($this->comercio());

        $this->actingAs($comprador, 'buyer')
            ->putJson(self::RUTA, ['city' => 'Rosario'])
            ->assertStatus(422);
    }

    public function test_guarda_los_tres_campos_del_buyer_autenticado()
    {
        $comprador = $this->comprador($this->comercio());

        $this->actingAs($comprador, 'buyer')
            ->putJson(self::RUTA, [
                'zipcode' => 'S2000',
                'city'    => 'Rosario',
                'state'   => 'Santa Fe',
            ])
            ->assertStatus(200);

        $comprador->refresh();
        $this->assertSame('S2000', $comprador->envio_zipcode);
        $this->assertSame('Rosario', $comprador->envio_city);
        $this->assertSame('Santa Fe', $comprador->envio_state);
    }

    public function test_guarda_el_codigo_postal_solo_sin_localidad_ni_provincia()
    {
        $comprador = $this->comprador($this->comercio());

        $this->actingAs($comprador, 'buyer')
            ->putJson(self::RUTA, ['zipcode' => 'C1425'])
            ->assertStatus(200);

        $comprador->refresh();
        $this->assertSame('C1425', $comprador->envio_zipcode);
        $this->assertNull($comprador->envio_city);
        $this->assertNull($comprador->envio_state);
    }

    public function test_solo_toca_al_buyer_autenticado_nunca_a_otro()
    {
        $comercio = $this->comercio();
        $comprador = $this->comprador($comercio);
        $otro = $this->comprador($comercio);
        $otro->envio_zipcode = 'X5000';
        $otro->save();

        $this->actingAs($comprador, 'buyer')
            ->putJson(self::RUTA, ['zipcode' => 'S2000'])
            ->assertStatus(200);

        $otro->refresh();
        $this->assertSame('X5000', $otro->envio_zipcode, 'guardar el propio no puede tocar el de otro buyer');
    }
}
