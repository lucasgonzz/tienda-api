<?php

namespace Tests\Feature\CuentaCorriente;

use App\Buyer;
use App\CreditAccount;
use App\CurrentAcount;
use App\Http\Controllers\CurrentAcountController;
use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\MySqlBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El token de los links de PDF de cuenta corriente que abre el comprador de la tienda (mision
 * pdf-de-venta-publico, 10/10/2026).
 *
 * ── Que se prueba ─────────────────────────────────────────────────────────────────────────────
 *
 * empresa-api deja de servir los PDF sin sesion ni token cuando se cierra la ventana de 60 dias.
 * El comprador no tiene sesion de empresa, asi que la tienda le emite el token insertando (o
 * reusando) la fila de `pdf_links` en la base compartida. Los dos endpoints:
 *
 *   GET /api/current-acount/pdf-token/credit-account/{id}  → tipo `credit_account` (estado de cuenta)
 *   GET /api/current-acount/pdf-token/movement/{id}        → tipo `current_acount` (pago / NC)
 *
 * Los modos de falla que cubre: que un comprador saque el token del PDF de OTRO (404, y sin
 * escribir nada); que cada click cree una fila nueva en vez de reusar la vigente; que una fila
 * REVOCADA se reuse; que la fila no respete el contrato con empresa-api (tipo, user_id del dueño,
 * largo del token, revoked_at); y que la tienda nueva contra una base SIN la tabla (empresa sin
 * actualizar) rompa en vez de devolver {token: null}.
 *
 * ── La tabla `pdf_links` en la base del slot ──────────────────────────────────────────────────
 *
 * La crea la migracion de `empresa-api`; `tienda-api` no tiene database/migrations y la base del
 * slot (`tienda_testing_s12`) no la tenia al 10/10/2026. crearLaTablaDelContratoSiFalta() la crea
 * con el esquema EXACTO del plan (tipos, largo de las columnas y nombres de indice), con el
 * Blueprint de Laravel —el mismo generador que va a correr la migracion de verdad—. Idempotente:
 * si ya esta, no se toca. Y NO se dropea al terminar: es esquema que empresa-api va a crear igual
 * (mismo criterio que CreaElEsquemaDeOfertas).
 *
 * ⚠️ Por que esta clase NO usa DatabaseTransactions: el CREATE TABLE es DDL y MySQL le hace commit
 * implicito a la transaccion abierta. El trait abre la suya en setUpTraits(), ANTES del setUp() de
 * la clase, asi que un CREATE ahi adentro la cerraria sin que Laravel se entere: los inserts del
 * caso quedarian commiteados y el rollback del final no revertiria nada. Por eso el orden es a
 * mano: primero el esquema (fuera de toda transaccion), DESPUES DB::beginTransaction(), y el
 * rollBack() en el tearDown. Todo lo que el caso inserta (comprador, cuentas, movimientos, filas de
 * `pdf_links`) se revierte.
 *
 * ── El caso "sin la tabla" ────────────────────────────────────────────────────────────────────
 *
 * NO se esconde ni se borra la tabla real (un RENAME/DROP que muere a mitad deja la base rota para
 * todo lo que corra despues). Se reemplaza la fachada `Schema` por un builder que, para
 * `pdf_links` y solo para ella, dice que no existe — y la tabla real SIGUE ahi durante el caso. Eso
 * es lo que vuelve honesto al test: si el controlador dejara de consultar la guarda (o la
 * consultara por otro camino que la fachada), encontraria la tabla, insertaria y devolveria un
 * token, y el caso se pondria rojo.
 *
 * ⚠️ Sobre los clientes: `credit_accounts.model_id` se compara con `buyers.comercio_city_client_id`
 * y no hay foreign key fisica, asi que no hace falta una fila en `clients` (que es del ERP y tiene
 * decenas de columnas obligatorias). Mismo criterio que SinEsquemaDeOfertasTest.
 */
class TokenDePdfDeCuentaCorrienteTest extends TestCase
{
    const TABLA = 'pdf_links';

    const RUTA_CUENTA = '/api/current-acount/pdf-token/credit-account/';
    const RUTA_MOVIMIENTO = '/api/current-acount/pdf-token/movement/';

    /** La ruta vecina contra la que se compara el "sin sesion". */
    const RUTA_VECINA = '/api/current-acount/sale-pdf-token/';

    /** Clientes del ERP de los dos compradores. Lejos de los ids sembrados en la base del slot. */
    const CLIENTE_DEL_COMPRADOR = 987101;
    const CLIENTE_DEL_AJENO = 987102;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Buyer  dueño de $cuenta y de sus movimientos */
    private $comprador;

    /** @var \App\Buyer  otro comprador del mismo comercio, con su propio cliente del ERP */
    private $ajeno;

    /** @var \App\CreditAccount  cuenta corriente de $comprador */
    private $cuenta;

    /** @var \App\CurrentAcount  pago de $comprador, con user_id */
    private $pago;

    /** @var \App\CurrentAcount  nota de credito de $comprador, con user_id NULL */
    private $nota_credito_sin_user;

    protected function setUp(): void
    {
        parent::setUp();

        // 1) El esquema del contrato, FUERA de toda transaccion (ver el docblock de la clase).
        $this->crearLaTablaDelContratoSiFalta();

        // 2) Recien ahora la transaccion del caso.
        DB::beginTransaction();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        $this->comprador = Buyer::create([
            'name'                    => 'Comprador PDF CC Test',
            'email'                   => 'pdf-cc-'.Str::random(10).'@test.local',
            'comercio_city_client_id' => self::CLIENTE_DEL_COMPRADOR,
            'user_id'                 => $this->comercio->id,
        ]);

        $this->ajeno = Buyer::create([
            'name'                    => 'Ajeno PDF CC Test',
            'email'                   => 'pdf-cc-ajeno-'.Str::random(10).'@test.local',
            'comercio_city_client_id' => self::CLIENTE_DEL_AJENO,
            'user_id'                 => $this->comercio->id,
        ]);

        $this->cuenta = $this->cuentaDe(self::CLIENTE_DEL_COMPRADOR);

        $this->pago = CurrentAcount::create([
            'credit_account_id' => $this->cuenta->id,
            'client_id'         => self::CLIENTE_DEL_COMPRADOR,
            'user_id'           => $this->comercio->id,
            'detalle'           => 'Pago N°1 test',
            'haber'             => 1000,
            'saldo'             => -1000,
            'status'            => 'pago_from_client',
        ]);

        $this->nota_credito_sin_user = CurrentAcount::create([
            'credit_account_id' => $this->cuenta->id,
            'client_id'         => self::CLIENTE_DEL_COMPRADOR,
            'user_id'           => null,
            'detalle'           => 'Nota de credito test',
            'haber'             => 500,
            'saldo'             => -1500,
            'status'            => 'nota_credito',
        ]);
    }

    protected function tearDown(): void
    {
        // El rollback va PRIMERO, mientras la app (y su conexion) siguen vivas.
        if ($this->app && DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        // El caso "sin la tabla" reemplaza la fachada: que no se filtre al caso siguiente.
        if ($this->app) {
            $this->restaurarLaFachadaSchema();
        }

        parent::tearDown();
    }

    /**
     * El dueño de la cuenta recibe un token del largo del contrato, y el SEGUNDO pedido le
     * devuelve el MISMO (reuso de la fila vigente), sin crear otra fila.
     */
    public function test_el_dueno_de_la_cuenta_recibe_un_token_y_el_mismo_en_el_segundo_pedido()
    {
        $primero = $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_CUENTA.$this->cuenta->id)
            ->assertStatus(200)
            ->json('token');

        $this->assertIsString($primero);
        $this->assertSame(48, strlen($primero), 'El token es Str::random(48): 48 caracteres.');

        $segundo = $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_CUENTA.$this->cuenta->id)
            ->assertStatus(200)
            ->json('token');

        $this->assertSame($primero, $segundo, 'El segundo pedido tiene que reusar la fila vigente, no crear otra.');
        $this->assertSame(1, $this->filasDe(CurrentAcountController::TIPO_PDF_CREDIT_ACCOUNT, $this->cuenta->id)->count());
    }

    /** Lo mismo para el comprobante de un movimiento (pago / nota de credito). */
    public function test_el_dueno_del_movimiento_recibe_un_token_y_el_mismo_en_el_segundo_pedido()
    {
        $primero = $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_MOVIMIENTO.$this->pago->id)
            ->assertStatus(200)
            ->json('token');

        $this->assertIsString($primero);
        $this->assertSame(48, strlen($primero));

        $segundo = $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_MOVIMIENTO.$this->pago->id)
            ->assertStatus(200)
            ->json('token');

        $this->assertSame($primero, $segundo);
        $this->assertSame(1, $this->filasDe(CurrentAcountController::TIPO_PDF_CURRENT_ACOUNT, $this->pago->id)->count());

        // Y la NC (otro movimiento) tiene su propio token: el reuso es por tipo + model_id.
        $de_la_nc = $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_MOVIMIENTO.$this->nota_credito_sin_user->id)
            ->assertStatus(200)
            ->json('token');

        $this->assertNotSame($primero, $de_la_nc);
    }

    /**
     * La fila que lee empresa-api respeta el contrato al pie de la letra: tipo EXACTO, model_id del
     * recurso, user_id del dueño del recurso, token de 48 caracteres igual al devuelto, y
     * revoked_at null.
     */
    public function test_la_fila_creada_respeta_el_contrato()
    {
        $token_cuenta = $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_CUENTA.$this->cuenta->id)
            ->assertStatus(200)
            ->json('token');

        $token_pago = $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_MOVIMIENTO.$this->pago->id)
            ->assertStatus(200)
            ->json('token');

        $fila_cuenta = DB::table(self::TABLA)->where('token', $token_cuenta)->first();
        $this->assertNotNull($fila_cuenta);
        $this->assertSame('credit_account', $fila_cuenta->tipo, 'El tipo es uno de los TIPOS del contrato, exacto.');
        $this->assertSame((int) $this->cuenta->id, (int) $fila_cuenta->model_id);
        $this->assertSame((int) $this->comercio->id, (int) $fila_cuenta->user_id, 'user_id = el dueño del recurso (el user_id de la cuenta).');
        $this->assertSame(48, strlen($fila_cuenta->token));
        $this->assertNull($fila_cuenta->revoked_at);
        $this->assertNotNull($fila_cuenta->created_at);
        $this->assertNotNull($fila_cuenta->updated_at);

        $fila_pago = DB::table(self::TABLA)->where('token', $token_pago)->first();
        $this->assertNotNull($fila_pago);
        $this->assertSame('current_acount', $fila_pago->tipo, 'El tipo es uno de los TIPOS del contrato, exacto.');
        $this->assertSame((int) $this->pago->id, (int) $fila_pago->model_id);
        $this->assertSame((int) $this->comercio->id, (int) $fila_pago->user_id, 'user_id = el dueño del recurso (el user_id del movimiento).');
        $this->assertSame(48, strlen($fila_pago->token));
        $this->assertNull($fila_pago->revoked_at);
    }

    /**
     * `current_acounts.user_id` es nullable y el contrato no admite user_id null: el dueño de un
     * movimiento sin user_id es el de su cuenta corriente.
     */
    public function test_un_movimiento_sin_user_id_toma_el_dueno_de_su_cuenta()
    {
        $token = $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_MOVIMIENTO.$this->nota_credito_sin_user->id)
            ->assertStatus(200)
            ->json('token');

        $fila = DB::table(self::TABLA)->where('token', $token)->first();

        $this->assertNotNull($fila);
        $this->assertSame((int) $this->cuenta->user_id, (int) $fila->user_id);
    }

    /**
     * 🔴 Un comprador no saca el token del PDF de otro: 404 (no 403, no un oraculo) y no se escribe
     * NINGUNA fila. Mismo 404 para un id que no existe y para un comprador sin cliente del ERP.
     */
    public function test_un_comprador_ajeno_recibe_404_y_no_se_escribe_nada()
    {
        $this->actingAs($this->ajeno, 'buyer')
            ->getJson(self::RUTA_CUENTA.$this->cuenta->id)
            ->assertStatus(404);

        $this->actingAs($this->ajeno, 'buyer')
            ->getJson(self::RUTA_MOVIMIENTO.$this->pago->id)
            ->assertStatus(404);

        $this->actingAs($this->ajeno, 'buyer')
            ->getJson(self::RUTA_MOVIMIENTO.$this->nota_credito_sin_user->id)
            ->assertStatus(404);

        $this->assertSame(0, $this->filasDe(CurrentAcountController::TIPO_PDF_CREDIT_ACCOUNT, $this->cuenta->id)->count());
        $this->assertSame(0, $this->filasDe(CurrentAcountController::TIPO_PDF_CURRENT_ACOUNT, $this->pago->id)->count());
        $this->assertSame(0, $this->filasDe(CurrentAcountController::TIPO_PDF_CURRENT_ACOUNT, $this->nota_credito_sin_user->id)->count());

        // Un id que no existe: el mismo 404.
        $inexistente = (int) DB::table('credit_accounts')->max('id') + 1000;
        $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_CUENTA.$inexistente)
            ->assertStatus(404);

        // Un comprador sin cliente del ERP vinculado: nada es suyo, el mismo 404.
        $sin_cliente = Buyer::create([
            'name'    => 'Sin Cliente PDF CC Test',
            'email'   => 'pdf-cc-sin-cliente-'.Str::random(10).'@test.local',
            'user_id' => $this->comercio->id,
        ]);

        $this->actingAs($sin_cliente, 'buyer')
            ->getJson(self::RUTA_CUENTA.$this->cuenta->id)
            ->assertStatus(404);

        $this->actingAs($sin_cliente, 'buyer')
            ->getJson(self::RUTA_MOVIMIENTO.$this->pago->id)
            ->assertStatus(404);
    }

    /**
     * Un movimiento de OTRA cuenta (la del ajeno) tampoco: la pertenencia es por la cuenta del
     * movimiento, no por el id que manda la SPA.
     */
    public function test_el_movimiento_de_la_cuenta_de_otro_da_404()
    {
        $cuenta_ajena = $this->cuentaDe(self::CLIENTE_DEL_AJENO);

        $pago_ajeno = CurrentAcount::create([
            'credit_account_id' => $cuenta_ajena->id,
            'client_id'         => self::CLIENTE_DEL_AJENO,
            'user_id'           => $this->comercio->id,
            'detalle'           => 'Pago ajeno test',
            'haber'             => 10,
            'saldo'             => -10,
            'status'            => 'pago_from_client',
        ]);

        $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_MOVIMIENTO.$pago_ajeno->id)
            ->assertStatus(404);

        $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_CUENTA.$cuenta_ajena->id)
            ->assertStatus(404);

        $this->assertSame(0, $this->filasDe(CurrentAcountController::TIPO_PDF_CURRENT_ACOUNT, $pago_ajeno->id)->count());

        // Y su dueño si lo saca: el 404 de arriba es por pertenencia, no porque el dato este mal armado.
        $this->actingAs($this->ajeno, 'buyer')
            ->getJson(self::RUTA_MOVIMIENTO.$pago_ajeno->id)
            ->assertStatus(200);
    }

    /**
     * Sin sesion de comprador: lo mismo que la ruta vecina (salePdfToken), que tampoco esta en el
     * grupo auth:buyer. Se MIDE la vecina en el mismo caso en vez de suponer su respuesta.
     */
    public function test_sin_sesion_responde_lo_mismo_que_las_rutas_vecinas()
    {
        $vecina = $this->getJson(self::RUTA_VECINA.'1')->status();

        $this->assertSame(403, $vecina, 'La ruta vecina responde 403 sin comprador.');

        $this->getJson(self::RUTA_CUENTA.$this->cuenta->id)->assertStatus($vecina);
        $this->getJson(self::RUTA_MOVIMIENTO.$this->pago->id)->assertStatus($vecina);

        $this->assertSame(0, $this->filasDe(CurrentAcountController::TIPO_PDF_CREDIT_ACCOUNT, $this->cuenta->id)->count());
        $this->assertSame(0, $this->filasDe(CurrentAcountController::TIPO_PDF_CURRENT_ACOUNT, $this->pago->id)->count());
    }

    /**
     * Una fila REVOCADA no se reusa: revocado = no abre, asi que devolver su token le daria al
     * comprador un link muerto. Se crea otra.
     */
    public function test_una_fila_revocada_no_se_reusa()
    {
        $revocado = Str::random(48);

        DB::table(self::TABLA)->insert([
            'user_id'    => $this->comercio->id,
            'tipo'       => 'credit_account',
            'model_id'   => $this->cuenta->id,
            'token'      => $revocado,
            'revoked_at' => now()->subDay(),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDay(),
        ]);

        $token = $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_CUENTA.$this->cuenta->id)
            ->assertStatus(200)
            ->json('token');

        $this->assertNotSame($revocado, $token);
        $this->assertSame(2, $this->filasDe(CurrentAcountController::TIPO_PDF_CREDIT_ACCOUNT, $this->cuenta->id)->count());
        $this->assertNull(DB::table(self::TABLA)->where('token', $token)->value('revoked_at'));
    }

    /**
     * Base SIN `pdf_links` (cliente con empresa todavia sin actualizar): 200 con {token: null},
     * sin error y sin escribir nada. Con empresa vieja la ruta del PDF sigue publica y la SPA abre
     * el link de siempre.
     *
     * La tabla real sigue ahi durante todo el caso (ver el docblock de la clase): solo la fachada
     * dice que no esta. Si el controlador no consultara la guarda, este caso daria un token.
     */
    public function test_sin_la_tabla_devuelve_token_null_y_no_escribe_nada()
    {
        $this->assertTrue(Schema::hasTable(self::TABLA), 'Precondicion: la tabla real existe durante el caso.');

        $this->simularBaseSinLaTabla();

        $this->assertFalse(Schema::hasTable(self::TABLA), 'La fachada tiene que decir que la tabla no esta.');

        $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_CUENTA.$this->cuenta->id)
            ->assertStatus(200)
            ->assertExactJson(['token' => null]);

        $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA_MOVIMIENTO.$this->pago->id)
            ->assertStatus(200)
            ->assertExactJson(['token' => null]);

        // Sin la tabla, el ajeno sigue recibiendo 404: la pertenencia va antes que la guarda.
        $this->actingAs($this->ajeno, 'buyer')
            ->getJson(self::RUTA_CUENTA.$this->cuenta->id)
            ->assertStatus(404);

        // Nada escrito (se mide contra la tabla real, por la conexion, no por la fachada).
        $this->assertSame(0, $this->filasDe(CurrentAcountController::TIPO_PDF_CREDIT_ACCOUNT, $this->cuenta->id)->count());
        $this->assertSame(0, $this->filasDe(CurrentAcountController::TIPO_PDF_CURRENT_ACOUNT, $this->pago->id)->count());

        $this->restaurarLaFachadaSchema();
        $this->assertTrue(Schema::hasTable(self::TABLA), 'La tabla real no se toco.');
    }

    /**
     * Crea `pdf_links` con el esquema EXACTO del contrato si la base no la tiene. Idempotente.
     *
     * 🔴 Se llama ANTES de abrir la transaccion del caso: el CREATE TABLE hace commit implicito.
     *
     * Copia del plan de la mision ("Contrato empresa ↔ tienda"): id bigIncrements; user_id
     * unsignedBigInteger con indice; tipo string(40); model_id unsignedBigInteger; token string(64)
     * unico (`pdf_links_token_uq`); revoked_at timestamp nullable; timestamps; indice
     * `pdf_links_tipo_model_idx` (tipo, model_id); sin foreign keys.
     *
     * @return void
     */
    private function crearLaTablaDelContratoSiFalta()
    {
        if (Schema::hasTable(self::TABLA)) {
            return;
        }

        Schema::create(self::TABLA, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('tipo', 40);
            $table->unsignedBigInteger('model_id');
            $table->string('token', 64)->unique('pdf_links_token_uq');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tipo', 'model_id'], 'pdf_links_tipo_model_idx');
        });
    }

    /**
     * Reemplaza la fachada `Schema` por un builder de la MISMA conexion que, para `pdf_links` y
     * solo para ella, dice que no existe. Todo lo demas lo delega al builder de verdad.
     *
     * Se deshace con restaurarLaFachadaSchema() (tambien en el tearDown).
     *
     * @return void
     */
    private function simularBaseSinLaTabla()
    {
        $sin_la_tabla = new class(DB::connection()) extends MySqlBuilder {
            public function hasTable($table)
            {
                if ($table === TokenDePdfDeCuentaCorrienteTest::TABLA) {
                    return false;
                }

                return parent::hasTable($table);
            }
        };

        Schema::swap($sin_la_tabla);
    }

    /**
     * Deshace simularBaseSinLaTabla().
     *
     * Hacen falta las dos cosas: Schema::swap() deja el builder falso en la cache de la fachada Y
     * como instancia de `db.schema` en el contenedor. Vaciar solo la cache de la fachada no alcanza:
     * la fachada lo volveria a resolver del contenedor. Sin la instancia, `db.schema` vuelve a ser
     * el bind() de DatabaseServiceProvider (un builder nuevo de la conexion por defecto).
     *
     * @return void
     */
    private function restaurarLaFachadaSchema()
    {
        Schema::clearResolvedInstance('db.schema');
        $this->app->forgetInstance('db.schema');
    }

    /**
     * Crea una cuenta corriente del cliente del ERP indicado, del comercio del caso.
     *
     * @param int $client_id
     * @return \App\CreditAccount
     */
    private function cuentaDe($client_id)
    {
        return CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $client_id,
            'saldo'      => 0,
            'moneda_id'  => 1,
            'user_id'    => $this->comercio->id,
        ]);
    }

    /**
     * Las filas de `pdf_links` de un recurso, leidas por la conexion (no por la fachada).
     *
     * @param string $tipo
     * @param int $model_id
     * @return \Illuminate\Database\Query\Builder
     */
    private function filasDe($tipo, $model_id)
    {
        return DB::table(self::TABLA)->where('tipo', $tipo)->where('model_id', $model_id);
    }
}
