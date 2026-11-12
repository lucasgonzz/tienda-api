<?php
namespace Tests\Feature\Buyer;

use App\Buyer;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegistroConIndiceLegacyTest extends TestCase
{
    public function test_indice_global_legacy_devuelve_422_sin_borrar_ni_iniciar_sesion()
    {
        $this->assertMatchesRegularExpression('/^tienda_testing_s[0-9]+$/', DB::connection()->getDatabaseName(), 'El DDL de compatibilidad solo corre en una base exclusiva del pool de testing.');
        $existia = collect(DB::select('SHOW INDEX FROM buyers'))->contains(function ($indice) {
            return $indice->Key_name === 'buyers_phone_unique';
        });
        $agregado = false;
        try {
            if (!$existia) {
                Schema::table('buyers', function ($tabla) { $tabla->unique('phone', 'buyers_phone_unique'); });
                $agregado = true;
            }
            DB::beginTransaction();
            $comercio = User::create(['name' => 'Comercio legacy', 'email' => Str::random(18).'@test.local', 'password' => bcrypt('prueba'), 'status' => 'commerce']);
            $otro = User::create(['name' => 'Otro comercio legacy', 'email' => Str::random(18).'@test.local', 'password' => bcrypt('prueba'), 'status' => 'commerce']);
            $previo = Buyer::create(['name' => 'Cuenta conservada', 'phone' => '+5493519876543', 'email' => Str::random(18).'@test.local', 'password' => bcrypt('anterior'), 'user_id' => $otro->id]);
            $original = $previo->fresh()->getAttributes();
            $this->postJson('/register', ['commerce_id' => $comercio->id, 'name' => 'Cuenta nueva', 'phone' => '3519876543', 'email' => Str::random(18).'@test.local', 'password' => 'prueba-local'])
                ->assertStatus(422)->assertJsonValidationErrors('phone');
            $this->assertSame($original, $previo->fresh()->getAttributes());
            $this->assertSame(0, Buyer::where('user_id', $comercio->id)->count());
            $this->assertGuest('buyer');
        } finally {
            while (DB::transactionLevel() > 0) { DB::rollBack(); }
            if ($agregado) {
                Schema::table('buyers', function ($tabla) { $tabla->dropUnique('buyers_phone_unique'); });
            }
        }
    }
}
