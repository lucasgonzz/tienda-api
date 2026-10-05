<?php

namespace Tests\Feature\CatalogoPorLista;

use App\Article;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CatalogoPorListaHelper;
use App\Http\Controllers\Helpers\ComboPrecioHelper;
use App\PriceType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (f) 🔴 LA CONSISTENCIA: la lista que elige `CatalogoPorListaHelper` es LA MISMA cuyo pivote aplica
 * `ArticleHelper::checkPriceTypes()` en sus casos 3 y 4 (mision catalogo-por-lista-tienda,
 * 5/10/2026).
 *
 * Es la garantia de "precio y catalogo salen de la misma funcion". Si alguien vuelve a copiar la
 * eleccion adentro de `checkPriceTypes()` y una de las dos copias cambia, el mayorista veria un
 * catalogo armado con una lista y los precios de otra — y esta clase se pone roja.
 *
 * ── El escenario: tres listas, y los bordes que hacen distinta la eleccion ───────────────────────
 *
 *   - Alta,  position 3, OCULTA AL PUBLICO: la mas alta, pero no para el visitante.
 *   - Media, position 2: la del visitante (la mas alta que el puede ver).
 *   - Baja,  position 1: la del cliente del ERP del caso "con lista propia".
 *
 * Un articulo con un precio distinto en cada lista (300 / 200 / 100), y el centinela 9999 en la
 * columna. Para cada comprador se mide, contra la MISMA lista:
 *   1. lo que devuelve el helper;
 *   2. el precio que deja `checkPriceTypes()` (tiene que ser el pivote de esa lista);
 *   3. el precio de la home por HTTP, que es lo que el comprador ve;
 *   4. que restringir ESA lista (y no otra) es lo que le esconde el articulo.
 *
 * Y de paso el espejo de los combos: `ComboPrecioHelper::lista_del_comprador()` elige la lista con
 * las mismas reglas (su docblock lo dice: "espejo de checkPriceTypes"). No se refactoriza en esta
 * mision, pero si se aleja de la eleccion de los articulos, se ve acá.
 */
class ConsistenciaDeLaEleccionTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCatalogoPorLista;

    /** @var \App\PriceType */
    private $alta;

    /** @var \App\PriceType */
    private $media;

    /** @var \App\PriceType */
    private $baja;

    /** @var \App\Article */
    private $articulo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();

        $this->comercio = $this->comercioConTienda();

        $this->alta  = $this->lista($this->comercio, 'Alta', 3, ['ocultar_al_publico' => 1]);
        $this->media = $this->lista($this->comercio, 'Media', 2);
        $this->baja  = $this->lista($this->comercio, 'Baja', 1);

        $this->articulo = $this->articulo($this->comercio, ['name' => 'Catalogo Consistencia']);

        $this->precioEnLista($this->articulo, $this->alta, 300);
        $this->precioEnLista($this->articulo, $this->media, 200);
        $this->precioEnLista($this->articulo, $this->baja, 100);
    }

    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /** Caso 4, visitante: la mas alta que NO esta oculta al publico (Media). */
    public function test_el_visitante_con_la_lista_mas_alta_oculta()
    {
        $this->comoVisitante();

        $this->assertLaMismaListaEnTodo($this->media, 200);
    }

    /** Caso 4, logueado sin cliente: la oculta SI cuenta para el logueado (Alta). */
    public function test_el_logueado_sin_cliente()
    {
        $this->comoComprador($this->compradorSinCliente($this->comercio));

        $this->assertLaMismaListaEnTodo($this->alta, 300);
    }

    /**
     * Caso 4, cliente del ERP con `price_type_id = 0`: es "sin lista" (la relacion da null), igual
     * que el logueado sin cliente.
     */
    public function test_el_cliente_con_price_type_id_cero()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, 0));

        $this->assertLaMismaListaEnTodo($this->alta, 300);
    }

    /** Caso 3, cliente del ERP con lista propia (Baja), aunque no sea la mas alta. */
    public function test_el_cliente_con_lista_propia()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->baja->id));

        $this->assertLaMismaListaEnTodo($this->baja, 100);
    }

    /**
     * Las cuatro mediciones del docblock de la clase contra la misma lista.
     *
     * @param  \App\PriceType  $esperada
     * @param  float  $precio_de_esa_lista
     * @return void
     */
    private function assertLaMismaListaEnTodo(PriceType $esperada, $precio_de_esa_lista)
    {
        /* 1. El helper. */
        CatalogoPorListaHelper::olvidar();
        $elegida = CatalogoPorListaHelper::lista_del_comprador($this->comercio->id);
        $this->assertNotNull($elegida, 'tiene que haber lista efectiva');
        $this->assertSame($esperada->id, (int) $elegida->id, 'la lista que elige el helper');

        /* 2. checkPriceTypes(), directo: el pivote que aplica es el de esa lista. */
        CatalogoPorListaHelper::olvidar();
        $resuelto = ArticleHelper::checkPriceTypes(Article::where('id', $this->articulo->id)->withAll()->get())->first();
        $this->assertEquals($precio_de_esa_lista, $resuelto->final_price, 'checkPriceTypes aplica el pivote de la misma lista');

        /* El espejo de los combos elige la misma. */
        $this->assertSame($esperada->id, (int) ComboPrecioHelper::lista_del_comprador($this->comercio->id),
            'ComboPrecioHelper (el espejo de los combos) elige la misma lista');

        /* 3. Lo que ve el comprador por HTTP. */
        $this->assertEquals($precio_de_esa_lista, $this->precioEnLaHome(), 'el precio de la home sale de la misma lista');

        /* 4. Restringir OTRA lista no le cambia nada... */
        foreach ([$this->alta, $this->media, $this->baja] as $otra) {
            if ($otra->id != $esperada->id) {
                $this->restringirLista($otra);
            }
        }

        $this->assertTrue($this->estaEnLaHome(), 'restringir las otras listas no le esconde el articulo');

        /* ...y restringir ESA lista, con el articulo sin habilitar, se lo esconde. */
        $this->restringirLista($esperada);

        $this->assertFalse($this->estaEnLaHome(), 'restringir su lista le esconde el articulo no habilitado');

        /* Habilitado para esa lista, vuelve, con el mismo precio. */
        DB::table('article_price_type')
            ->where('article_id', $this->articulo->id)
            ->where('price_type_id', $esperada->id)
            ->update(['visible_en_tienda' => 1]);

        $this->assertTrue($this->estaEnLaHome(), 'habilitado para su lista, vuelve');
        $this->assertEquals($precio_de_esa_lista, $this->precioEnLaHome(), 'y con el mismo precio');
    }

    /**
     * La home tal como la ve el comprador AHORA. Antes de cada request se recarga al comprador de la
     * sesion: el caso cambia la configuracion de las listas entre requests, y la instancia que deja
     * `actingAs()` conservaria la lista de antes del cambio (ver `refrescarLaSesion()`).
     *
     * @return array
     */
    private function articulosDeLaHome()
    {
        $this->refrescarLaSesion();

        return (array) $this->json('GET', '/api/articles/featured-last-uploads/'.$this->comercio->id.'?page=1')
                    ->assertStatus(200)
                    ->json('articles.data');
    }

    /** @return bool */
    private function estaEnLaHome()
    {
        return in_array($this->articulo->id, $this->idsDe($this->articulosDeLaHome()), true);
    }

    /** @return mixed */
    private function precioEnLaHome()
    {
        foreach ($this->articulosDeLaHome() as $articulo) {
            if ((int) $articulo['id'] === $this->articulo->id) {
                return $articulo['final_price'];
            }
        }

        $this->fail('el articulo no esta en la home');
    }
}
