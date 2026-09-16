<?php

namespace Tests\Feature\Buyer;

use App\Buyer;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El contrato con `empresa-api`: un cliente cuya base TODAVÍA no tiene
 * `buyers.envio_zipcode/envio_city/envio_state` (esa migración es de `empresa-api`, y el deploy
 * de la tienda no corre `migrate` — `arquitectura_tecnica.md:470`). `PUT /api/buyer/envio-zipcode`
 * tiene que responder 204 sin escribir nada, nunca un 500.
 *
 * ⚠️ Mismo mecanismo que `SinEsquemaDeEnviosTest`: DDL (`RENAME COLUMN`) hace commit implícito en
 * MySQL, así que no hay transacción que revertir sola. El restore va en el `finally`, el
 * `tearDown` Y el `setUp`, por si una corrida anterior murió a mitad.
 */
class ActualizaEnvioZipcodeSinEsquemaTest extends TestCase
{
    const RUTA = '/api/buyer/envio-zipcode';
    const SUFIJO = '_escondida_test';
    const COLUMNAS = ['envio_zipcode', 'envio_city', 'envio_state'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurarElEsquema();
        $this->assertTrue(Schema::hasColumn('buyers', 'envio_zipcode'), 'la base del slot tiene que arrancar con la columna.');
    }

    protected function tearDown(): void
    {
        $this->restaurarElEsquema();
        parent::tearDown();
    }

    public function test_sin_las_columnas_responde_204_y_no_escribe_nada()
    {
        $this->esconderElEsquema();

        try {
            $this->assertFalse(Schema::hasColumn('buyers', 'envio_zipcode'));

            DB::beginTransaction();
            try {
                $comercio = User::create([
                    'name'     => 'Comercio Sin Esquema Test',
                    'email'    => 'sin-esquema-'.Str::random(10).'@test.local',
                    'password' => bcrypt('secreto'),
                    'status'   => 'commerce',
                ]);
                $comprador = Buyer::create([
                    'name'    => 'Comprador Sin Esquema Test',
                    'email'   => 'comprador-sin-esquema-'.Str::random(8).'@test.local',
                    'phone'   => '3511234567',
                    'user_id' => $comercio->id,
                ]);

                $this->actingAs($comprador, 'buyer')
                    ->putJson(self::RUTA, ['zipcode' => 'S2000', 'city' => 'Rosario', 'state' => 'Santa Fe'])
                    ->assertStatus(204);
            } finally {
                DB::rollBack();
            }
        } finally {
            $this->restaurarElEsquema();
        }

        $this->assertTrue(Schema::hasColumn('buyers', 'envio_zipcode'), 'la base volvió a tener la columna.');
    }

    private function esconderElEsquema()
    {
        foreach (self::COLUMNAS as $columna) {
            if (Schema::hasColumn('buyers', $columna)) {
                DB::statement('ALTER TABLE `buyers` RENAME COLUMN `'.$columna.'` TO `'.$columna.self::SUFIJO.'`');
            }
        }
    }

    private function restaurarElEsquema()
    {
        foreach (self::COLUMNAS as $columna) {
            if (Schema::hasColumn('buyers', $columna.self::SUFIJO)) {
                DB::statement('ALTER TABLE `buyers` RENAME COLUMN `'.$columna.self::SUFIJO.'` TO `'.$columna.'`');
            }
        }
    }
}
