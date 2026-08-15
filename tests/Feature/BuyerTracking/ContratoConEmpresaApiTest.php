<?php

namespace Tests\Feature\BuyerTracking;

use App\Http\Controllers\Helpers\BuyerTrackingHelper;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El contrato con `empresa-api`: la tienda desplegada contra una base que todavia NO tiene las
 * tablas de tracking (mision tracking-buyers-tienda).
 *
 * 🔴 Este es el escenario NORMAL, no el borde, y por eso tiene su propia clase de test.
 *
 * El esquema lo crea `empresa-api` sobre la rama `refractor`, y no llega a la base de un cliente
 * hasta que eso se mergee a `develop` y salga por release. La tienda, en cambio, la despliega
 * Lucas a mano cuando quiere. O sea que la tienda va a estar desplegada durante DIAS O SEMANAS
 * contra una base sin estas tablas. Si eso no esta resuelto, cada visita de producto de cada
 * comprador tira una excepcion.
 *
 * ⚠️ Por que esta clase NO usa DatabaseTransactions, a diferencia de IngestaDeEventosTest:
 * el unico modo honesto de probar "la tabla no esta" es que la tabla no este, y eso se hace con
 * un RENAME TABLE. RENAME es DDL, y MySQL le hace un commit implicito a la transaccion abierta,
 * asi que el rollback del trait no revertiria nada y daria una falsa sensacion de limpieza. Esta
 * clase limpia lo suyo a mano, explicitamente.
 */
class ContratoConEmpresaApiTest extends TestCase
{
    const TABLA          = 'buyer_tracking_events';
    const TABLA_ESCONDIDA = 'buyer_tracking_events_escondida_test';
    const RUTA           = '/api/buyer-tracking/events';

    /** @var \App\User|null */
    private $comercio = null;

    /** @var int|null Extension creada por este test, si la creo el (para poder borrarla). */
    private $extencion_creada = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable(self::TABLA)) {
            $this->markTestSkipped(
                'Falta la tabla '.self::TABLA.'. Se crea con las migraciones de empresa-api '
                .'(2026_08_15_140000) apuntadas a esta base.'
            );
        }

        BuyerTrackingHelper::olvidarMemoria();

        $this->comercio = User::create([
            'name'     => 'Comercio de prueba contrato',
            'email'    => 'contrato-'.Str::random(10).'@test.local',
            'password' => bcrypt('secreto'),
            'status'   => 'commerce',
        ]);
    }

    /**
     * Deja la base como estaba, pase lo que pase en el caso.
     *
     * El restore de la tabla va PRIMERO y afuera de cualquier condicion: si quedara renombrada,
     * se llevaria puesta la suite entera y todo lo que corra despues en este slot.
     */
    protected function tearDown(): void
    {
        if (Schema::hasTable(self::TABLA_ESCONDIDA)) {
            DB::statement('RENAME TABLE '.self::TABLA_ESCONDIDA.' TO '.self::TABLA);
        }

        if (!is_null($this->comercio)) {
            DB::table(self::TABLA)->where('user_id', $this->comercio->id)->delete();
            DB::table('extencion_empresa_user')->where('user_id', $this->comercio->id)->delete();
            DB::table('users')->where('id', $this->comercio->id)->delete();
        }

        if (!is_null($this->extencion_creada)) {
            DB::table('extencion_empresas')->where('id', $this->extencion_creada)->delete();
        }

        BuyerTrackingHelper::olvidarMemoria();

        parent::tearDown();
    }

    /**
     * Con la tabla ausente: 204, cero escrituras y —lo que de verdad importa— cero ruido en el log.
     *
     * 🔴 La tercera asercion es la unica que prueba algo. Sin ella este test daria verde igual
     * aunque la guarda Schema::hasTable no existiera: el insert tiraria, el try/catch del helper
     * lo atraparia y la respuesta seria 204 lo mismo. El comportamiento observable por el SPA es
     * identico en los dos casos.
     *
     * Lo que los distingue es el log. Con la guarda no se registra nada, porque no pasa nada
     * anormal: la tienda esta desplegada antes que el esquema, que es lo previsto. Sin la guarda
     * se registra un warning POR LOTE durante todas las semanas que dure esa ventana — miles por
     * dia en un comercio con trafico — y ese ruido termina tapando las fallas de verdad.
     */
    public function test_con_la_tabla_ausente_responde_204_sin_escribir_ni_ensuciar_el_log()
    {
        $this->prenderLaExtencion();

        Log::spy();

        DB::statement('RENAME TABLE '.self::TABLA.' TO '.self::TABLA_ESCONDIDA);

        try {
            $respuesta = $this->postJson(self::RUTA, [
                'commerce_id' => $this->comercio->id,
                'events'      => [
                    [
                        'event_type' => 'product_view',
                        'visitor_id' => (string) Str::uuid(),
                        'session_id' => (string) Str::uuid(),
                        'article_id' => 77,
                    ],
                ],
            ]);

            $respuesta->assertStatus(204);

            $this->assertFalse(
                Schema::hasTable(self::TABLA),
                'el escenario no se ejercito: la tabla seguia estando'
            );
        } finally {
            DB::statement('RENAME TABLE '.self::TABLA_ESCONDIDA.' TO '.self::TABLA);
        }

        $this->assertSame(0, DB::table(self::TABLA)->where('user_id', $this->comercio->id)->count());

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');

        /*
         * Pero mudo del todo tampoco: queda UNA linea en nivel info, una sola por proceso.
         *
         * Es la otra mitad del mismo razonamiento. Sin ninguna señal, "la tienda esta esperando
         * el esquema" y "hace tres semanas que no se captura nada y nadie sabe por que" se ven
         * exactamente igual desde afuera. Con nivel info y una vez por proceso, el operador lo
         * puede ver sin que se le llene el log — que es justo lo que la asercion de arriba
         * protege.
         */
        Log::shouldHaveReceived('info')
            ->withArgs(function ($mensaje) {
                return is_string($mensaje) && strpos($mensaje, 'no existe todavia en esta base') !== false;
            })
            ->once();
    }

    /**
     * La contracara, para que el test de arriba no pueda dar verde por el motivo equivocado:
     * con la tabla puesta y todo lo demas igual, la misma llamada SI escribe.
     *
     * Sin este caso, un helper que no escribiera nunca nada pasaria el test anterior.
     */
    public function test_con_la_tabla_presente_la_misma_llamada_si_escribe()
    {
        $this->prenderLaExtencion();

        $respuesta = $this->postJson(self::RUTA, [
            'commerce_id' => $this->comercio->id,
            'events'      => [
                [
                    'event_type' => 'product_view',
                    'visitor_id' => (string) Str::uuid(),
                    'session_id' => (string) Str::uuid(),
                    'article_id' => 77,
                ],
            ],
        ]);

        $respuesta->assertStatus(204);

        $this->assertSame(1, DB::table(self::TABLA)->where('user_id', $this->comercio->id)->count());
    }

    /**
     * Prende la extension para el comercio del caso, recordando si tuvo que crear la fila de
     * catalogo para poder borrarla despues (esta clase no tiene transaccion que la revierta).
     *
     * @return void
     */
    private function prenderLaExtencion()
    {
        $extencion_id = DB::table('extencion_empresas')
            ->where('slug', BuyerTrackingHelper::SLUG_EXTENCION)
            ->value('id');

        if (is_null($extencion_id)) {
            $extencion_id = DB::table('extencion_empresas')->insertGetId([
                'name'       => 'Seguimiento de compradores en la tienda',
                'slug'       => BuyerTrackingHelper::SLUG_EXTENCION,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $this->extencion_creada = $extencion_id;
        }

        DB::table('extencion_empresa_user')->insert([
            'extencion_empresa_id' => $extencion_id,
            'user_id'              => $this->comercio->id,
            'created_at'           => Carbon::now(),
            'updated_at'           => Carbon::now(),
        ]);
    }
}
