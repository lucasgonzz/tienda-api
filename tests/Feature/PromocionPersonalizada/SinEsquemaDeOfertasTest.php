<?php

namespace Tests\Feature\PromocionPersonalizada;

use App\Article;
use App\Buyer;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ClientOfferHelper;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El contrato con `empresa-api`: la tienda desplegada contra una base que todavia NO tiene
 * `client_offers` ni `client_offer_ranges` (mision promocion-personalizada-tienda).
 *
 * 🔴 Este es el escenario NORMAL, no el borde, y por eso tiene su propia clase.
 *
 * El esquema lo crea `empresa-api` sobre la rama `motor-de-ofertas-por-cliente`, y no llega a la
 * base de un cliente hasta que eso se mergee y salga por release. La tienda, en cambio, la
 * despliega Lucas a mano cuando quiere. O sea que la tienda va a estar desplegada DIAS O SEMANAS
 * contra una base sin estas tablas. Si eso no esta resuelto, CADA pagina que muestre un precio
 * —cada listado, cada ficha, cada carrito— tira una excepcion, porque el enganche esta en
 * ArticleHelper::checkPriceTypes(), que es el embudo de precios de toda la tienda.
 *
 * ⚠️ Por que esta clase NO usa DatabaseTransactions: el unico modo honesto de probar "las tablas
 * no estan" es que no esten, y eso se hace con un RENAME TABLE. RENAME es DDL, y MySQL le hace
 * commit implicito a la transaccion abierta, asi que el rollback del trait no revertiria nada.
 * Esta clase limpia lo suyo a mano. Mismo criterio que ContratoConEmpresaApiTest.
 *
 * ⚠️ Tampoco usa el trait CreaElEsquemaDeOfertas, obviamente: lo que prueba es la ausencia.
 */
class SinEsquemaDeOfertasTest extends TestCase
{
    const ESCONDIDA_OFERTAS = 'client_offers_escondida_test';
    const ESCONDIDA_RANGOS  = 'client_offer_ranges_escondida_test';

    /** Id del cliente del ERP del comprador. No hace falta que exista en `clients`: la query del
     *  contrato compara `client_offers.client_id` con ese numero y no hay foreign key fisica. */
    const CLIENT_ID = 987654;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article */
    private $articulo;

    /** @var \App\Buyer|null */
    private $comprador = null;

    protected function setUp(): void
    {
        parent::setUp();

        /* La suite corre todos los casos en el mismo proceso y el helper memoiza en statics. */
        ClientOfferHelper::olvidarMemoria();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        $this->articulo = Article::where('user_id', $this->comercio->id)->first();
        $this->assertNotNull($this->articulo, 'La base del slot tiene que tener al menos un articulo del comercio.');

        $this->comprador = Buyer::create([
            'name'                    => 'Comprador Sin Esquema',
            'email'                   => 'sin-esquema-'.Str::random(10).'@test.local',
            'comercio_city_client_id' => self::CLIENT_ID,
            'user_id'                 => $this->comercio->id,
        ]);
    }

    /**
     * Deja la base como estaba, pase lo que pase en el caso.
     *
     * El restore de las tablas va PRIMERO y afuera de cualquier condicion: si quedaran
     * renombradas, se llevarian puesta la suite entera y todo lo que corra despues en este slot.
     */
    protected function tearDown(): void
    {
        $this->restaurarLasTablas();

        if (!is_null($this->comprador)) {
            DB::table('buyers')->where('id', $this->comprador->id)->delete();
        }

        ClientOfferHelper::olvidarMemoria();

        parent::tearDown();
    }

    /**
     * TEST A — con las tablas ausentes la tienda funciona igual, no toca el precio y no ensucia
     * el log.
     *
     * 🔴 La ultima asercion es la unica que prueba algo de verdad.
     *
     * Sin ella este test daria verde igual aunque la guarda `Schema::hasTable` no existiera: la
     * query tiraria, el try/catch de aplicar() la atraparia y devolveria los mismos articulos, y
     * el resultado observable por el SPA seria identico. Lo que distingue los dos mundos es el
     * log: con la guarda queda UNA linea de nivel `info` por proceso, porque no pasa nada
     * anormal —la tienda esta desplegada antes que el esquema, que es lo previsto—; sin la
     * guarda hay un `warning` con excepcion POR PAGINA durante todas las semanas que dure esa
     * ventana, y ese ruido termina tapando las fallas de verdad.
     */
    public function test_con_las_tablas_ausentes_la_tienda_sigue_andando_y_no_ensucia_el_log()
    {
        $this->actingAs($this->comprador, 'buyer');

        $articulo = $this->articuloConPrecio(1234.56);

        Log::spy();

        $this->esconderLasTablas();

        try {
            $devueltos = ArticleHelper::checkPriceTypes([$articulo]);

            $this->assertFalse(
                Schema::hasTable(ClientOfferHelper::TABLA),
                'el escenario no se ejercito: '.ClientOfferHelper::TABLA.' seguia estando'
            );
        } finally {
            $this->restaurarLasTablas();
        }

        /* El articulo vuelve intacto: mismo objeto, mismo precio, y sin ninguno de los dos
           campos nuevos del contrato. */
        $this->assertCount(1, $devueltos);
        $this->assertSame(1234.56, (float) $devueltos[0]->final_price,
            'sin esquema el precio no se puede tocar');

        $atributos = $devueltos[0]->getAttributes();
        $this->assertArrayNotHasKey('oferta_personalizada', $atributos);
        $this->assertArrayNotHasKey('precio_sin_oferta', $atributos);

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');

        /*
         * Pero mudo del todo tampoco: UNA linea en nivel info, una sola por proceso.
         *
         * Es la otra mitad del mismo razonamiento. Sin ninguna señal, "la tienda esta esperando
         * el esquema" y "hace tres semanas que no se aplica ninguna oferta y nadie sabe por que"
         * se ven exactamente igual desde afuera.
         */
        Log::shouldHaveReceived('info')
            ->withArgs(function ($mensaje) {
                return is_string($mensaje) && strpos($mensaje, 'no existe todavia en esta base') !== false;
            })
            ->once();
    }

    /**
     * TEST B — el endpoint con las tablas ausentes devuelve 200 con la lista vacia, no un 500.
     *
     * Para el SPA "todavia no llego el esquema" y "este comprador no tiene ofertas" tienen que
     * ser lo mismo: el overlay simplemente no aparece. Un 500 acá le rompe la carga del SPA a
     * todo comprador con cliente del ERP durante semanas.
     */
    public function test_el_endpoint_con_las_tablas_ausentes_devuelve_200_con_la_lista_vacia()
    {
        $this->esconderLasTablas();

        try {
            $this->actingAs($this->comprador, 'buyer')
                ->getJson('/api/client-offers/'.$this->comercio->id)
                ->assertStatus(200)
                ->assertExactJson(['articles' => []]);

            $this->assertFalse(
                Schema::hasTable(ClientOfferHelper::TABLA),
                'el escenario no se ejercito: '.ClientOfferHelper::TABLA.' seguia estando'
            );
        } finally {
            $this->restaurarLasTablas();
        }
    }

    /**
     * Una copia en memoria del articulo del comercio, con el precio fijado.
     *
     * Se usa una fila REAL (y no un modelo inventado) porque `checkPriceTypes()` la recorre
     * entera antes de llegar al enganche; y el precio se fija a mano porque lo que este test
     * mide es que ese numero NO cambie.
     *
     * @param float $final_price
     * @return \App\Article
     */
    private function articuloConPrecio($final_price)
    {
        $articulo = Article::find($this->articulo->id);
        $articulo->final_price = $final_price;

        return $articulo;
    }

    /**
     * Esconde las dos tablas del contrato, si estan.
     *
     * Se esconden LAS DOS aunque a la guarda le alcance con que falte una: una base con
     * `client_offers` y sin `client_offer_ranges` es un esquema a medio llegar, que no es el
     * estado que este test dice medir.
     *
     * @return void
     */
    private function esconderLasTablas()
    {
        if (Schema::hasTable(ClientOfferHelper::TABLA)) {
            DB::statement('RENAME TABLE '.ClientOfferHelper::TABLA.' TO '.self::ESCONDIDA_OFERTAS);
        }

        if (Schema::hasTable(ClientOfferHelper::TABLA_RANGOS)) {
            DB::statement('RENAME TABLE '.ClientOfferHelper::TABLA_RANGOS.' TO '.self::ESCONDIDA_RANGOS);
        }
    }

    /**
     * Devuelve las tablas a su nombre. Idempotente, y se llama en el `finally` Y en el tearDown.
     *
     * @return void
     */
    private function restaurarLasTablas()
    {
        if (Schema::hasTable(self::ESCONDIDA_OFERTAS)) {
            DB::statement('RENAME TABLE '.self::ESCONDIDA_OFERTAS.' TO '.ClientOfferHelper::TABLA);
        }

        if (Schema::hasTable(self::ESCONDIDA_RANGOS)) {
            DB::statement('RENAME TABLE '.self::ESCONDIDA_RANGOS.' TO '.ClientOfferHelper::TABLA_RANGOS);
        }
    }
}
