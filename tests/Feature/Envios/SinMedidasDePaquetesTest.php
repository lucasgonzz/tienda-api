<?php

namespace Tests\Feature\Envios;

use App\Combo;
use App\PromocionVinoteca;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** DDL fuera de transacción; los datos se revierten ANTES de restaurar la tabla en finally. */
class SinMedidasDePaquetesTest extends TestCase
{
    use ArmaComercioConZipnova;

    public function test_la_tienda_nueva_cotiza_promociones_y_combos_con_el_esquema_anterior_del_erp()
    {
        $tabla = 'medidas_envio_paquetes';
        $respaldo = 'medidas_envio_paquetes_respaldo_test';
        $this->assertFalse(Schema::hasTable($respaldo), 'No pisar un respaldo de otra corrida.');
        $this->assertTrue(Schema::hasTable($tabla));
        Schema::rename($tabla, $respaldo);
        try {
            DB::beginTransaction();
            try {
                $comercio = $this->comercioConTienda();
                $this->conectorZipnova($comercio);
                $this->zipnovaCotiza();
                $promo = PromocionVinoteca::create(['user_id' => $comercio->id, 'name' => 'Promo anterior', 'online' => 1, 'final_price' => 1000]);
                $combo = Combo::create(['user_id' => $comercio->id, 'num' => 1, 'name' => 'Combo anterior', 'online' => 1, 'price' => 2000]);
                $this->postJson('/api/envios/cotizar', ['commerce_id' => $comercio->id, 'zipcode' => '5000',
                    'promociones_vinoteca' => [['id' => $promo->id]], 'combos' => [['id' => $combo->id]],
                ])->assertOk();
                Http::assertSent(function ($request) {
                    $data = $request->data();
                    $this->assertCount(2, $data['items']);
                    $this->assertSame([500, 500], array_column($data['items'], 'weight'));
                    $this->assertSame([10, 10], array_column($data['items'], 'height'));
                    $this->assertEquals(3000, $data['declared_value']);
                    return true;
                });
            } finally {
                DB::rollBack();
            }
        } finally {
            Schema::rename($respaldo, $tabla);
        }
    }
}
