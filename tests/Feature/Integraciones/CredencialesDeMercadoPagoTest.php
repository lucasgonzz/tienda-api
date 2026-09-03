<?php

namespace Tests\Feature\Integraciones;

use App\Http\Controllers\Helpers\MercadoPagoCredentialsHelper;
use App\PaymentMethod;
use App\PaymentMethodType;
use App\Platform;
use App\PlatformConnector;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Con que credenciales de Mercado Pago cobra la tienda (mision `abm-integraciones-mp`).
 *
 * ── Que fija esta clase ───────────────────────────────────────────────────────────────────────
 *
 * `empresa` y `tienda` comparten la BASE, no el codigo: `MercadoPagoCredentialsHelper` esta
 * duplicado en los dos repos y el orden de resolucion tiene que ser el mismo en los dos. Si
 * divergiera, un comercio cobraria con una cuenta en el ERP y con otra en la tienda. Los cuatro
 * primeros tests son los mismos casos que fija
 * `empresa-api/tests/Feature/Integraciones/IntegracionesMercadoPagoTest.php`, con los mismos
 * valores centinela, justamente para que se puedan comparar de un vistazo.
 *
 * 🔴 Y el fallback a `payment_methods` no se saca: hay clientes en produccion cuya unica
 * credencial vive ahi, y `empresa` y `tienda` nunca llegan a produccion el mismo dia.
 *
 * ⚠️ Sobre la base: tienda-api no tiene database/migrations (el esquema lo gobierna
 * empresa-api), asi que aca NO se usa RefreshDatabase ni migrate. Se corre contra la base real
 * del slot con DatabaseTransactions, igual que el resto de la suite.
 */
class CredencialesDeMercadoPagoTest extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\User */
    private $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');
    }

    /**
     * Fila "MercadoPago" de `payment_method_types` (la siembra empresa-api; si la base del slot
     * no la tiene, se crea para el test y se va con la transaccion).
     *
     * @return \App\PaymentMethodType
     */
    private function tipoMercadoPago()
    {
        $tipo = PaymentMethodType::where('name', 'MercadoPago')->first();

        if (!$tipo) {
            $tipo = new PaymentMethodType;
            $tipo->name = 'MercadoPago';
            $tipo->save();
        }

        return $tipo;
    }

    /**
     * Metodo de pago de tipo MercadoPago del comercio, con las credenciales cargadas a mano.
     * Es lo unico con lo que la tienda cobraba antes de esta mision.
     *
     * @return \App\PaymentMethod
     */
    private function paymentMethodMp()
    {
        $payment_method = new PaymentMethod;
        $payment_method->name                   = 'Mercado Pago';
        $payment_method->user_id                = $this->comercio->id;
        $payment_method->payment_method_type_id = $this->tipoMercadoPago()->id;
        $payment_method->public_key             = 'PUBLIC-KEY-DE-PAYMENT-METHOD';
        $payment_method->access_token           = 'TOKEN-DE-PAYMENT-METHOD';
        $payment_method->save();

        return $payment_method;
    }

    /**
     * Conector de Mercado Pago del comercio, tal como lo deja el OAuth del ERP.
     *
     * @param array $atributos Overrides (por ejemplo `expires_at` para simular un token vencido).
     * @return \App\PlatformConnector
     */
    private function conectorMp($atributos = [])
    {
        $plataforma = Platform::firstOrCreate(
            ['slug' => Platform::SLUG_MERCADO_PAGO],
            ['name' => 'Mercado Pago']
        );

        $base = [
            'user_id'      => $this->comercio->id,
            'platform_id'  => $plataforma->id,
            'status'       => PlatformConnector::STATUS_CONECTADO,
            'access_token' => 'TOKEN-DEL-CONECTOR',
            'expires_at'   => null,
        ];

        /* `public_key` la agrega una migracion de empresa-api sobre la base compartida. Si en la
           base del slot todavia no esta, el conector se crea sin ella y el helper completa con la
           de `payment_methods`, que es exactamente lo que hace en produccion en esa ventana. */
        if (Schema::hasColumn('platform_connectors', 'public_key')) {
            $base['public_key'] = 'PUBLIC-KEY-DEL-CONECTOR';
        }

        return PlatformConnector::create(array_merge($base, $atributos));
    }

    /**
     * El listado publico de metodos de pago, tal como lo pide el SPA.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function listadoPublico()
    {
        return $this->getJson('/api/payment-methods/' . $this->comercio->id);
    }

    /**
     * El helper prefiere el conector conectado por sobre `payment_methods`.
     * Mismo caso que `test_el_helper_prefiere_el_conector_conectado` de empresa-api.
     *
     * @return void
     */
    public function test_el_helper_prefiere_el_conector_conectado()
    {
        $this->paymentMethodMp();
        $this->conectorMp();

        $credenciales = MercadoPagoCredentialsHelper::credentials($this->comercio->id);

        $this->assertSame('TOKEN-DEL-CONECTOR', $credenciales['access_token']);
        $this->assertSame('platform_connector', $credenciales['origen']);
    }

    /**
     * Sin conector, el helper cae a `payment_methods`: es exactamente lo que hace que un comercio
     * que nunca conecto por OAuth siga cobrando igual que antes de esta mision.
     *
     * @return void
     */
    public function test_el_helper_cae_a_payment_methods_cuando_no_hay_conector()
    {
        $this->paymentMethodMp();

        $credenciales = MercadoPagoCredentialsHelper::credentials($this->comercio->id);

        $this->assertSame('TOKEN-DE-PAYMENT-METHOD', $credenciales['access_token']);
        $this->assertSame('PUBLIC-KEY-DE-PAYMENT-METHOD', $credenciales['public_key']);
        $this->assertSame('payment_method', $credenciales['origen']);
    }

    /**
     * Un conector con el token vencido no vale: el helper cae a `payment_methods` igual que si no
     * existiera. Mismo criterio que `is_connected()`.
     *
     * @return void
     */
    public function test_el_helper_ignora_un_conector_vencido()
    {
        $this->paymentMethodMp();
        $this->conectorMp(['expires_at' => Carbon::now()->subDay()]);

        $credenciales = MercadoPagoCredentialsHelper::credentials($this->comercio->id);

        $this->assertSame('TOKEN-DE-PAYMENT-METHOD', $credenciales['access_token']);
        $this->assertSame('payment_method', $credenciales['origen']);
    }

    /**
     * Sin conector y sin `payment_methods`, el helper devuelve nulls y no explota: no poder
     * cobrar es un estado posible del sistema, no un error del programa.
     *
     * @return void
     */
    public function test_el_helper_devuelve_nulls_cuando_no_hay_con_que_cobrar()
    {
        $credenciales = MercadoPagoCredentialsHelper::credentials($this->comercio->id);

        $this->assertNull($credenciales['access_token']);
        $this->assertNull($credenciales['public_key']);
        $this->assertNull($credenciales['origen']);
    }

    /**
     * 🔴 EL CASO QUE JUSTIFICA EL try/catch. La APP_KEY de este repo y la de la empresa-api del
     * mismo cliente son la misma por decision de producto (el instalador la copia), pero un
     * cliente instalado antes de ese pipeline puede tener claves distintas: ahi el conector
     * existe, tiene token, y descifrarlo tira excepcion.
     *
     * Sin el catch, el checkout de ese comercio pasaria de "cobra por payment_methods" a
     * "revienta en 500". Con el catch, sigue cobrando como antes.
     *
     * Se escribe el token con el query builder para saltear el cast y dejar en la columna algo
     * que no es un ciphertext valido, que es lo mismo que ve el codigo cuando la clave no es la
     * que cifro esa fila.
     *
     * @return void
     */
    public function test_el_helper_cae_al_fallback_si_el_token_del_conector_no_se_puede_descifrar()
    {
        $this->paymentMethodMp();
        $conector = $this->conectorMp();

        DB::table('platform_connectors')
            ->where('id', $conector->id)
            ->update(['access_token' => 'esto-no-es-un-ciphertext-valido']);

        $credenciales = MercadoPagoCredentialsHelper::credentials($this->comercio->id);

        $this->assertSame(
            'TOKEN-DE-PAYMENT-METHOD',
            $credenciales['access_token'],
            'Un token que no se puede descifrar tiene que caer al fallback, no propagar la excepcion.'
        );
        $this->assertSame('payment_method', $credenciales['origen']);
    }

    /**
     * Con el comercio conectado por OAuth, el listado publico publica la public key del CONECTOR.
     *
     * Es lo que mantiene alineados el navegador y el backend: `MercadoPagoController@preference`
     * va a armar la preferencia con el access_token del conector, asi que el brick del comprador
     * tiene que tokenizar con la public key de esa misma cuenta. Y se resuelve del lado de la API
     * para que el contrato con el SPA no cambie: sigue leyendo `payment_method.public_key`.
     *
     * @return void
     */
    public function test_el_listado_publico_publica_la_public_key_del_conector()
    {
        if (!Schema::hasColumn('platform_connectors', 'public_key')) {
            $this->markTestSkipped('La base del slot todavia no tiene platform_connectors.public_key (la agrega una migracion de empresa-api).');
        }

        $payment_method = $this->paymentMethodMp();
        $this->conectorMp();

        $respuesta = $this->listadoPublico();
        $respuesta->assertStatus(200);

        $fila = collect($respuesta->json('payment_methods'))->firstWhere('id', $payment_method->id);

        $this->assertNotNull($fila, 'El metodo de pago de Mercado Pago tiene que seguir apareciendo en el listado.');
        $this->assertSame('PUBLIC-KEY-DEL-CONECTOR', $fila['public_key']);
    }

    /**
     * 🔴 Sin conector, la respuesta queda IDENTICA a la de antes de esta mision: cada fila con su
     * propia public key. Es el caso de todos los comercios que hoy cobran por `payment_methods`,
     * que son la mayoria mientras el ERP no este desplegado. Ninguno tiene que notar el cambio.
     *
     * @return void
     */
    public function test_el_listado_publico_no_cambia_cuando_no_hay_conector()
    {
        $payment_method = $this->paymentMethodMp();

        $respuesta = $this->listadoPublico();
        $respuesta->assertStatus(200);

        $fila = collect($respuesta->json('payment_methods'))->firstWhere('id', $payment_method->id);

        $this->assertNotNull($fila);
        $this->assertSame('PUBLIC-KEY-DE-PAYMENT-METHOD', $fila['public_key']);
    }

    /**
     * 🔴 EL LISTADO ES PUBLICO Y NO PUEDE LLEVAR NINGUN TOKEN.
     *
     * `GET /api/payment-methods/{commerce_id}` no tiene auth: cualquiera que abra la tienda lo
     * pide. Se afirma sobre el CUERPO CRUDO de la respuesta, no sobre el array parseado, para que
     * un token colado en cualquier nivel de anidamiento tambien lo haga fallar.
     *
     * @return void
     */
    public function test_el_listado_publico_no_serializa_ningun_access_token()
    {
        $this->paymentMethodMp();
        $this->conectorMp();

        $cuerpo = $this->listadoPublico()->getContent();

        $this->assertStringNotContainsString('TOKEN-DE-PAYMENT-METHOD', $cuerpo);
        $this->assertStringNotContainsString('TOKEN-DEL-CONECTOR', $cuerpo);
        $this->assertStringNotContainsString('access_token', $cuerpo);
        $this->assertStringNotContainsString('refresh_token', $cuerpo);
        $this->assertStringNotContainsString('client_secret', $cuerpo);
    }
}
