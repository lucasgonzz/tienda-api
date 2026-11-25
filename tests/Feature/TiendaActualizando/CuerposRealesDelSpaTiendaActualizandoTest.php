<?php

namespace Tests\Feature\TiendaActualizando;

use App\Cart;
use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión aviso-actualizacion-sistema, ronda 2 (hallazgo A-2 de la revisión): un comprador INVITADO podía
 * pagar con Mercado Pago mientras la tienda se actualizaba, porque las requests que manda el SPA real NO
 * traen `commerce_id`:
 *
 *  - `POST mercado-pago/preference` (tienda-spa `BtnSave.vue`): `payment_method` (con `user_id` = comercio),
 *    `delivery_zone`, `articles` y `cart_id`;
 *  - `PUT /api/carts`: el carrito entero que devolvió el servidor (`id`, `user_id`, ...), sin `commerce_id`;
 *  - `PUT carts/update-article-amount/{cart_id}`, `PATCH carts/{cart_id}/items`, `DELETE carts/{cart_id}/items`
 *    y `DELETE carts/{cart_id}`: el comercio sólo se ve a través del carrito de la URL;
 *  - `POST /api/orders` y `POST /api/mercado-pago/tarjeta/montos`: viajan con `cart_id`.
 *
 * Evidencia de que `carts.user_id` y `payment_method.user_id` son el comercio: `CartController@store` guarda
 * `'user_id' => $request->commerce_id`, `lastCart` filtra `Cart::where('user_id', $commerce_id)` y
 * `MercadoPagoController` usa `User::find($request->payment_method['user_id'])` como el comercio que cobra.
 *
 * Mismo criterio de infraestructura que `TiendaActualizandoTest` (sin transacciones por el DDL, tabla creada y
 * borrada por el test, todo lo creado se borra por id). Este archivo no toca los tests de 3dafefe.
 */
class CuerposRealesDelSpaTiendaActualizandoTest extends TestCase
{
    const TABLA = 'actualizaciones_del_sistema';

    /** Comercio que se está actualizando. */
    protected $comercio;

    /** Otro comercio de la misma base, que no se actualiza. */
    protected $otro_comercio;

    /** Carrito (de invitado, sin comprador) del comercio que se actualiza. */
    protected $carrito;

    /** Carrito (de invitado) del otro comercio. */
    protected $carrito_del_otro;

    protected $usuarios_creados = [];
    protected $carritos_creados = [];
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

        $this->comercio = $this->crear_comercio('Comercio actualizando');
        $this->otro_comercio = $this->crear_comercio('Comercio sin actualizar');

        $this->carrito = $this->crear_carrito($this->comercio->id);
        $this->carrito_del_otro = $this->crear_carrito($this->otro_comercio->id);

        // Sólo el comercio principal se está actualizando.
        DB::table(self::TABLA)->insert([
            'owner_id' => $this->comercio->id,
            'estado' => 'actualizando',
            'vence_at' => now()->addMinutes(60)->toDateTimeString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if (Schema::hasTable(self::TABLA)) {
                if ($this->tabla_creada_por_el_test) {
                    Schema::drop(self::TABLA);
                } elseif ($this->usuarios_creados) {
                    DB::table(self::TABLA)->whereIn('owner_id', $this->usuarios_creados)->delete();
                }
            }

            if ($this->carritos_creados) {
                Cart::whereIn('id', $this->carritos_creados)->delete();
            }

            if ($this->usuarios_creados) {
                User::whereIn('id', $this->usuarios_creados)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    /** Crea un comercio sintético y anota su id para borrarlo. */
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

    /** Crea un carrito de invitado (sin comprador) del comercio y anota su id para borrarlo. */
    protected function crear_carrito(int $commerce_id): Cart
    {
        $carrito = Cart::create(['user_id' => $commerce_id, 'buyer_id' => null]);

        $this->carritos_creados[] = $carrito->id;

        return $carrito;
    }

    /** La marca de tienda, con el mismo contrato que `TiendaActualizandoTest`. */
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

    /** Que la respuesta NO sea la marca (lo que conteste la ruta real da igual). */
    protected function assert_no_es_la_marca($respuesta): void
    {
        $this->assertNotSame('sistema_actualizando', $respuesta->json('error'));
        $this->assertNotSame(503, $respuesta->getStatusCode());
    }

    /** Cuerpo de `POST mercado-pago/preference` como lo arma `BtnSave.vue` (sin `commerce_id`). */
    protected function cuerpo_de_preference(int $commerce_id, ?int $cart_id): array
    {
        return [
            'payment_method' => ['id' => 987654321, 'user_id' => $commerce_id, 'name' => 'Mercado Pago'],
            'delivery_zone' => null,
            'articles' => [],
            'cart_id' => $cart_id,
        ];
    }

    /** Invitado paga con Mercado Pago con carrito: el comercio sale del carrito. */
    public function test_preference_del_invitado_con_carrito_recibe_la_marca(): void
    {
        $this->assert_marca($this->postJson(
            '/api/mercado-pago/preference',
            $this->cuerpo_de_preference($this->comercio->id, $this->carrito->id)
        ));
    }

    /** Sin carrito (`cart_id: null`) el comercio sale de `payment_method.user_id`. */
    public function test_preference_sin_carrito_recibe_la_marca_por_el_medio_de_pago(): void
    {
        $this->assert_marca($this->postJson(
            '/api/mercado-pago/preference',
            $this->cuerpo_de_preference($this->comercio->id, null)
        ));
    }

    /** El comercio equivocado no se bloquea: preference de otro comercio, con su carrito y su medio de pago. */
    public function test_preference_de_otro_comercio_no_se_bloquea(): void
    {
        $this->assert_no_es_la_marca($this->postJson(
            '/api/mercado-pago/preference',
            $this->cuerpo_de_preference($this->otro_comercio->id, $this->carrito_del_otro->id)
        ));
    }

    /**
     * Manda el carrito (dato de la base) y no el `payment_method.user_id` que viene en el cuerpo: un cuerpo con
     * el medio de pago del comercio que se actualiza pero el carrito de otro no tiene que bloquear al otro... ni al
     * revés. Acá el carrito es de D (libre) y el medio de pago dice C (actualizando): no hay marca.
     */
    public function test_el_carrito_manda_sobre_el_medio_de_pago_del_cuerpo(): void
    {
        $this->assert_no_es_la_marca($this->postJson(
            '/api/mercado-pago/preference',
            $this->cuerpo_de_preference($this->comercio->id, $this->carrito_del_otro->id)
        ));
    }

    /** `PUT /api/carts` con el carrito que devolvió el servidor (`id` y `user_id`, sin `commerce_id`). */
    public function test_put_carts_recibe_la_marca_por_el_id_del_carrito(): void
    {
        $this->assert_marca($this->putJson('/api/carts', [
            'id' => $this->carrito->id,
            'user_id' => $this->comercio->id,
            'articles' => [],
            'total' => 0,
        ]));
    }

    /** `PUT /api/carts` de un carrito de otro comercio no se bloquea. */
    public function test_put_carts_de_otro_comercio_no_se_bloquea(): void
    {
        $this->assert_no_es_la_marca($this->putJson('/api/carts', [
            'id' => $this->carrito_del_otro->id,
            'user_id' => $this->otro_comercio->id,
            'articles' => [],
            'total' => 0,
        ]));
    }

    /** `PUT carts/update-article-amount/{cart_id}`: el comercio sólo se ve por el carrito de la URL. */
    public function test_update_article_amount_recibe_la_marca(): void
    {
        $this->assert_marca($this->putJson(
            '/api/carts/update-article-amount/'.$this->carrito->id,
            ['article_id' => 1, 'amount' => 2]
        ));
    }

    /** `PATCH carts/{cart_id}/items` (casilla "completar al reponer"). */
    public function test_patch_items_recibe_la_marca(): void
    {
        $this->assert_marca($this->patchJson(
            '/api/carts/'.$this->carrito->id.'/items',
            ['tipo' => 'articulo', 'id' => 1, 'completar_al_reponer' => true]
        ));
    }

    /** `DELETE carts/{cart_id}/items` y `DELETE carts/{cart_id}`. */
    public function test_delete_de_carrito_e_items_reciben_la_marca(): void
    {
        $this->assert_marca($this->deleteJson(
            '/api/carts/'.$this->carrito->id.'/items',
            ['tipo' => 'articulo', 'id' => 1]
        ));
        $this->assert_marca($this->deleteJson('/api/carts/'.$this->carrito->id));
    }

    /** Las rutas de carrito de otro comercio no se bloquean. */
    public function test_rutas_de_carrito_de_otro_comercio_no_se_bloquean(): void
    {
        // Control: el carrito del comercio que se actualiza sí recibe la marca.
        $this->assert_marca($this->patchJson(
            '/api/carts/'.$this->carrito->id.'/items',
            ['tipo' => 'articulo', 'id' => 1, 'completar_al_reponer' => true]
        ));

        $this->assert_no_es_la_marca($this->putJson(
            '/api/carts/update-article-amount/'.$this->carrito_del_otro->id,
            ['article_id' => 1, 'amount' => 2]
        ));
        $this->assert_no_es_la_marca($this->patchJson(
            '/api/carts/'.$this->carrito_del_otro->id.'/items',
            ['tipo' => 'articulo', 'id' => 1, 'completar_al_reponer' => true]
        ));
    }

    /** `POST /api/orders` y `POST mercado-pago/tarjeta/montos` viajan con `cart_id`. */
    public function test_pedido_y_montos_de_tarjeta_reciben_la_marca_por_cart_id(): void
    {
        $this->assert_marca($this->postJson('/api/orders', ['cart_id' => $this->carrito->id]));
        $this->assert_marca($this->postJson(
            '/api/mercado-pago/tarjeta/montos',
            ['cart_id' => $this->carrito->id, 'payment_method_id' => 987654321]
        ));
    }

    /** `POST /api/carts` (alta) lleva `commerce_id` en el cuerpo: el SPA sí lo manda ahí. */
    public function test_post_carts_con_commerce_id_recibe_la_marca(): void
    {
        $this->assert_marca($this->postJson('/api/carts', ['commerce_id' => $this->comercio->id, 'articles' => []]));
    }

    /** Un carrito inexistente o datos basura no identifican comercio: la request pasa (no se bloquea a ciegas). */
    public function test_carrito_inexistente_o_basura_no_bloquea(): void
    {
        $this->assert_no_es_la_marca($this->patchJson(
            '/api/carts/987654321/items',
            ['tipo' => 'articulo', 'id' => 1, 'completar_al_reponer' => true]
        ));
        $this->assert_no_es_la_marca($this->putJson('/api/carts', ['id' => 'abc', 'user_id' => 'x']));
        $this->assert_no_es_la_marca($this->postJson(
            '/api/mercado-pago/preference',
            ['payment_method' => 'basura', 'cart_id' => ['a'], 'articles' => 5]
        ));
    }
}
