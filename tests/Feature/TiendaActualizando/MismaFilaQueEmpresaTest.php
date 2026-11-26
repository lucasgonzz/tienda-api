<?php

namespace Tests\Feature\TiendaActualizando;

use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión aviso-actualizacion-sistema: contrato de lectura de la fila de `actualizaciones_del_sistema`.
 *
 * empresa-api elige la fila de un dueño con `orderBy('id')` (la de id MÁS BAJO). Si alguna vez hubiera dos filas
 * del mismo dueño, tienda-api tiene que leer la misma que empresa, o las dos puntas discreparían sobre si el
 * sistema se está actualizando. Acá: la de id menor está `actualizando` vigente y la de id mayor `libre` ⇒ la
 * tienda bloquea.
 *
 * Mismo criterio de infraestructura que `TiendaActualizandoTest` (sin transacciones por el DDL; la tabla se crea
 * sólo si falta y se borra sólo si la creó el test; todo lo creado se borra por id).
 */
class MismaFilaQueEmpresaTest extends TestCase
{
    const TABLA = 'actualizaciones_del_sistema';

    protected $comercio;
    protected $tabla_creada_por_el_test = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertStringContainsString('testing', DB::connection()->getDatabaseName());

        Cache::flush();

        if (! Schema::hasTable(self::TABLA)) {
            Schema::create(self::TABLA, function ($table) {
                $table->id();
                $table->unsignedBigInteger('owner_id')->default(0);
                $table->string('estado', 20)->default('libre');
                $table->unsignedBigInteger('upgrade_id')->nullable();
                $table->string('version', 40)->nullable();
                $table->string('pedido_uuid', 36)->nullable();
                $table->timestamp('pedido_at')->nullable();
                $table->timestamp('vence_respuesta_at')->nullable();
                $table->timestamp('rechazado_at')->nullable();
                $table->timestamp('actualizando_desde')->nullable();
                $table->timestamp('vence_at')->nullable();
                $table->unsignedBigInteger('rechazado_por_user_id')->nullable();
                $table->timestamps();
                $table->index('owner_id', 'act_sis_owner_idx');
            });
            $this->tabla_creada_por_el_test = true;
        }

        $this->comercio = User::create([
            'name' => 'Comercio dos filas',
            'company_name' => 'Comercio dos filas',
            'email' => uniqid('comercio-').'@example.test',
            'status' => 'commerce',
            'password' => bcrypt('synthetic-only'),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if (Schema::hasTable(self::TABLA)) {
                if ($this->tabla_creada_por_el_test) {
                    Schema::drop(self::TABLA);
                } elseif ($this->comercio) {
                    DB::table(self::TABLA)->where('owner_id', $this->comercio->id)->delete();
                }
            }

            if ($this->comercio) {
                User::where('id', $this->comercio->id)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    /** Dos filas del mismo dueño: la de id menor `actualizando` vigente, la de id mayor `libre` ⇒ bloquea. */
    public function test_con_dos_filas_manda_la_de_id_menor_como_en_empresa(): void
    {
        $ahora = now();

        // Se insertan en orden: la primera tiene el id menor.
        DB::table(self::TABLA)->insert([
            'owner_id' => $this->comercio->id,
            'estado' => 'actualizando',
            'vence_at' => $ahora->copy()->addMinutes(60)->toDateTimeString(),
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        DB::table(self::TABLA)->insert([
            'owner_id' => $this->comercio->id,
            'estado' => 'libre',
            'vence_at' => null,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $respuesta = $this->getJson('/api/commerce/'.$this->comercio->id);

        $respuesta->assertStatus(503);
        $respuesta->assertJsonPath('error', 'sistema_actualizando');
    }
}
