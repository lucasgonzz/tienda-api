<?php

namespace Tests\Feature\TiendaActualizando;

use App\Buyer;
use App\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión aviso-actualizacion-sistema (CA13): mientras empresa-api actualiza el sistema de un cliente, la
 * tienda online de ese comercio contesta la "marca" (HTTP 503 con `error: sistema_actualizando`) y
 * tienda-spa muestra "Estamos actualizando la tienda".
 *
 * El estado vive en `actualizaciones_del_sistema`, tabla que crea SÓLO empresa-api (tienda-api no tiene
 * migraciones y comparte la base). La tienda únicamente la LEE: bloquea si y sólo si
 * `estado = 'actualizando' AND vence_at > NOW()` para `owner_id = commerce_id`.
 *
 * Qué fija este test:
 *  - fila `actualizando` vigente del comercio C: toda ruta del grupo `api` de C contesta la marca
 *    (comercio por parámetro de ruta `commerce_id`, por input `commerce_id` o por el comprador autenticado);
 *  - otro comercio D, fila vencida, estado distinto de `actualizando`: no bloquea;
 *  - exceptuadas: webhook de Mercado Pago (no puede perder avisos) y `seo/*` (crawlers);
 *  - tabla inexistente (empresa-api viejo, la tienda se despliega antes): no bloquea;
 *  - el estado se cachea 10 segundos por comercio.
 *
 * 🔴 DDL y transacciones: `CREATE TABLE` / `DROP TABLE` hacen commit implícito en MariaDB y romperían
 * `DatabaseTransactions`. Por eso este test NO usa transacciones: crea la tabla en `setUp()` sólo si la base
 * de prueba no la trae, la borra en `tearDown()` sólo si la creó él, y borra lo que insertó (filas, usuarios
 * y comprador) por id. No deja nada en la base.
 */
class TiendaActualizandoTest extends TestCase
{
    const TABLA = 'actualizaciones_del_sistema';

    /** Comercio que se está actualizando. */
    protected $comercio;

    /** Otro comercio de la misma base, que no se actualiza. */
    protected $otro_comercio;

    /** Ids de los usuarios (comercios) creados por el test, para borrarlos en tearDown. */
    protected $usuarios_creados = [];

    /** Ids de los compradores creados por el test, para borrarlos en tearDown. */
    protected $compradores_creados = [];

    /** true si el test creó la tabla (y por lo tanto la tiene que borrar). */
    protected $tabla_creada_por_el_test = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertStringContainsString('testing', DB::connection()->getDatabaseName());

        // Una corrida anterior no tiene que dejar el estado cacheado de un comercio.
        Cache::flush();

        if (! Schema::hasTable(self::TABLA)) {
            $this->crear_tabla();
            $this->tabla_creada_por_el_test = true;
        }

        $this->comercio = $this->crear_comercio('Comercio actualizando');
        $this->otro_comercio = $this->crear_comercio('Comercio sin actualizar');
    }

    protected function tearDown(): void
    {
        try {
            Carbon::setTestNow();

            if (Schema::hasTable(self::TABLA)) {
                if ($this->tabla_creada_por_el_test) {
                    Schema::drop(self::TABLA);
                } elseif ($this->usuarios_creados) {
                    DB::table(self::TABLA)->whereIn('owner_id', $this->usuarios_creados)->delete();
                }
            }

            if ($this->compradores_creados) {
                Buyer::whereIn('id', $this->compradores_creados)->delete();
            }

            if ($this->usuarios_creados) {
                User::whereIn('id', $this->usuarios_creados)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Crea la tabla con las columnas EXACTAS que define empresa-api (plan §4.2). Sólo se usa cuando la base
     * de prueba todavía no la trae (la crea empresa-api en su migración).
     *
     * @return void
     */
    protected function crear_tabla(): void
    {
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
    }

    /**
     * Crea un comercio (User dueño) sintético y anota su id para borrarlo al terminar.
     *
     * @param string $nombre Nombre del comercio.
     * @return \App\User
     */
    protected function crear_comercio(string $nombre): User
    {
        $usuario = User::create([
            'name' => $nombre,
            'company_name' => $nombre,
            'email' => uniqid('comercio-').'@example.test',
            'status' => 'commerce',
            'password' => bcrypt('synthetic-only'),
        ]);

        $this->usuarios_creados[] = $usuario->id;

        return $usuario;
    }

    /**
     * Inserta la fila de estado de un comercio.
     *
     * @param int $owner_id Comercio dueño de la fila.
     * @param string $estado libre | pidiendo_ok | rechazado | actualizando.
     * @param string|null $vence_at Fecha (UTC de la base) en que vence el bloqueo; null = sin fecha.
     * @return void
     */
    protected function poner_estado(int $owner_id, string $estado, ?string $vence_at): void
    {
        DB::table(self::TABLA)->insert([
            'owner_id' => $owner_id,
            'estado' => $estado,
            'vence_at' => $vence_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * La marca de la tienda exacta del contrato (plan §2.4 / §4.4).
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta a verificar.
     * @return void
     */
    protected function assert_marca($respuesta): void
    {
        $respuesta->assertStatus(503);
        $respuesta->assertHeader('Retry-After', '30');
        $respuesta->assertExactJson([
            'error' => 'sistema_actualizando',
            'message' => 'Estamos actualizando la tienda. Volvé a intentar en unos minutos.',
            'reintentar_en_segundos' => 30,
        ]);
    }

    /**
     * Que la respuesta NO sea la marca (puede ser 200, 404, 422... lo que conteste la ruta real).
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta a verificar.
     * @return void
     */
    protected function assert_no_es_la_marca($respuesta): void
    {
        $this->assertNotSame('sistema_actualizando', $respuesta->json('error'));
        $this->assertNotSame(503, $respuesta->getStatusCode());
    }

    /** CA13: fila `actualizando` vigente ⇒ GET /api/commerce/C devuelve la marca con el texto de tienda. */
    public function test_comercio_actualizando_recibe_la_marca(): void
    {
        $this->poner_estado($this->comercio->id, 'actualizando', now()->addMinutes(60)->toDateTimeString());

        $this->assert_marca($this->getJson('/api/commerce/'.$this->comercio->id));
    }

    /** Otras rutas del grupo `api` con `commerce_id` en la URL también contestan la marca. */
    public function test_otras_rutas_del_comercio_tambien_reciben_la_marca(): void
    {
        $this->poner_estado($this->comercio->id, 'actualizando', now()->addMinutes(60)->toDateTimeString());

        $this->assert_marca($this->getJson('/api/titles/'.$this->comercio->id));
        $this->assert_marca($this->getJson('/api/categories/'.$this->comercio->id));
    }

    /** CA13: otro comercio de la misma base NO se bloquea. */
    public function test_otro_comercio_no_se_bloquea(): void
    {
        $this->poner_estado($this->comercio->id, 'actualizando', now()->addMinutes(60)->toDateTimeString());

        // Sin la marca para el comercio D (y sí para C, comprobado en el mismo test para no pasar en falso).
        $this->assert_marca($this->getJson('/api/commerce/'.$this->comercio->id));
        $this->assert_no_es_la_marca($this->getJson('/api/commerce/'.$this->otro_comercio->id));
    }

    /** CA13: `vence_at` pasado ⇒ no bloquea (un `actualizando` colgado no deja la tienda cerrada para siempre). */
    public function test_vence_at_pasado_no_bloquea(): void
    {
        $this->poner_estado($this->comercio->id, 'actualizando', now()->subMinutes(5)->toDateTimeString());
        $this->poner_estado($this->otro_comercio->id, 'actualizando', now()->addMinutes(60)->toDateTimeString());

        // El comercio D sí bloquea: prueba que el middleware está activo y que el vencido es el que pasa.
        $this->assert_marca($this->getJson('/api/commerce/'.$this->otro_comercio->id));
        $this->assert_no_es_la_marca($this->getJson('/api/commerce/'.$this->comercio->id));
    }

    /** Sólo `actualizando` bloquea: libre, pidiendo_ok y rechazado dejan pasar aunque `vence_at` sea futuro. */
    public function test_estados_distintos_de_actualizando_no_bloquean(): void
    {
        $this->poner_estado($this->comercio->id, 'pidiendo_ok', now()->addMinutes(60)->toDateTimeString());
        $this->poner_estado($this->otro_comercio->id, 'libre', now()->addMinutes(60)->toDateTimeString());

        $this->assert_no_es_la_marca($this->getJson('/api/commerce/'.$this->comercio->id));
        $this->assert_no_es_la_marca($this->getJson('/api/commerce/'.$this->otro_comercio->id));

        // Control: con `actualizando` el mismo comercio sí bloquea (el middleware está montado).
        Cache::flush();
        DB::table(self::TABLA)->where('owner_id', $this->comercio->id)->update(['estado' => 'actualizando']);
        $this->assert_marca($this->getJson('/api/commerce/'.$this->comercio->id));
    }

    /** El comercio también se resuelve por el input `commerce_id` (rutas sin el parámetro en la URL). */
    public function test_comercio_por_input_commerce_id(): void
    {
        $this->poner_estado($this->comercio->id, 'actualizando', now()->addMinutes(60)->toDateTimeString());

        $this->assert_marca($this->postJson('/api/mercado-pago/preference', ['commerce_id' => $this->comercio->id]));
    }

    /** Sin comercio identificable (ni URL, ni input, ni comprador) la request pasa. */
    public function test_sin_comercio_identificable_pasa(): void
    {
        $this->poner_estado($this->comercio->id, 'actualizando', now()->addMinutes(60)->toDateTimeString());

        // Control: el middleware está activo para el comercio.
        $this->assert_marca($this->getJson('/api/commerce/'.$this->comercio->id));

        // Un comprador anónimo en una ruta sin comercio: no hay a quién bloquear.
        $this->assert_no_es_la_marca($this->getJson('/api/user'));
    }

    /** Comprador autenticado: el comercio sale de `Buyer.user_id`. */
    public function test_comprador_autenticado_se_bloquea_por_su_comercio(): void
    {
        $this->poner_estado($this->comercio->id, 'actualizando', now()->addMinutes(60)->toDateTimeString());

        $comprador = Buyer::create([
            'name' => 'Comprador sintético',
            'email' => uniqid('buyer-').'@example.test',
            'user_id' => $this->comercio->id,
        ]);
        $this->compradores_creados[] = $comprador->id;

        $this->actingAs($comprador, 'buyer');

        $this->assert_marca($this->getJson('/api/user'));
    }

    /** CA13: el webhook de Mercado Pago NO se corta (MP no puede perder avisos de pago). */
    public function test_webhook_de_mercado_pago_exceptuado(): void
    {
        $this->poner_estado($this->comercio->id, 'actualizando', now()->addMinutes(60)->toDateTimeString());

        // Control: el comercio sí está bloqueado en una ruta común.
        $this->assert_marca($this->getJson('/api/commerce/'.$this->comercio->id));

        $this->assert_no_es_la_marca(
            $this->postJson('/api/mercado-pago/webhook?commerce_id='.$this->comercio->id, ['type' => 'otro'])
        );
    }

    /** CA13: `seo/*` NO se corta (los crawlers no tienen que ver un 503 por una actualización). */
    public function test_seo_exceptuado(): void
    {
        $this->poner_estado($this->comercio->id, 'actualizando', now()->addMinutes(60)->toDateTimeString());

        // Control: el comercio sí está bloqueado en una ruta común.
        $this->assert_marca($this->getJson('/api/commerce/'.$this->comercio->id));

        $this->assert_no_es_la_marca($this->getJson('/api/seo/pagina/'.$this->comercio->id));
        $this->assert_no_es_la_marca($this->getJson('/api/seo/sitemap/'.$this->comercio->id));
    }

    /**
     * CA13: tabla inexistente (empresa-api viejo, todavía sin la migración) ⇒ no bloquea. La tienda se
     * despliega ANTES que empresa, así que "sin tabla" es el estado normal durante días.
     */
    public function test_tabla_inexistente_no_bloquea(): void
    {
        // Control con la tabla presente: bloquea.
        $this->poner_estado($this->comercio->id, 'actualizando', now()->addMinutes(60)->toDateTimeString());
        $this->assert_marca($this->getJson('/api/commerce/'.$this->comercio->id));

        // Se borra la tabla (tearDown tolera que no exista) y se limpia el cache del estado.
        Schema::drop(self::TABLA);
        $this->tabla_creada_por_el_test = false;
        Cache::flush();

        $this->assert_no_es_la_marca($this->getJson('/api/commerce/'.$this->comercio->id));
        $this->assert_no_es_la_marca($this->getJson('/api/categories/'.$this->comercio->id));
    }

    /** El estado de un comercio se cachea 10 segundos: la tienda se entera en <= 10 s de que arrancó o terminó. */
    public function test_el_estado_se_cachea_diez_segundos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:00'));

        // Primera lectura: libre (queda cacheado).
        $this->assert_no_es_la_marca($this->getJson('/api/commerce/'.$this->comercio->id));

        // La fila pasa a `actualizando`: dentro de los 10 s todavía se ve el estado cacheado.
        $this->poner_estado($this->comercio->id, 'actualizando', now()->addMinutes(60)->toDateTimeString());
        Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:05'));
        $this->assert_no_es_la_marca($this->getJson('/api/commerce/'.$this->comercio->id));

        // Pasados los 10 s se vuelve a leer la base y la marca aparece.
        Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:11'));
        $this->assert_marca($this->getJson('/api/commerce/'.$this->comercio->id));
    }
}
