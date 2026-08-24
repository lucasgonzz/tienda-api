<?php

namespace Tests\Feature\Home;

use App\Article;
use App\StockMovement;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La seccion Novedades de la home (tanda-correctivos-2408, item 19).
 *
 * ── El bug que esta clase clava ───────────────────────────────────────────────────────────────
 *
 * HomeHelper::getNovedades() buscaba cada articulo con whereNotNull('deleted_at'). Article usa
 * SoftDeletes, cuyo global scope agrega deleted_at IS NULL a toda query: las dos condiciones
 * juntas son imposibles y la seccion llegaba SIEMPRE vacia a la tienda, hubiera o no ingresos
 * de stock. El primer test de esta clase esta rojo sobre ese codigo.
 *
 * Novedades = articulos no borrados con movimiento de stock reciente (stock_resultante > 0),
 * los mas nuevos primero segun la fecha del movimiento.
 *
 * ⚠️ Sobre la base: tienda-api no tiene database/migrations (el esquema lo gobierna
 * empresa-api), asi que aca NO se usa RefreshDatabase ni migrate. Se corre contra la base real
 * del slot con DatabaseTransactions, igual que el resto de la suite.
 */
class NovedadesDeLaHomeTest extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\User */
    private $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        /* Que checkOnline() no exija imagenes: los articulos de este test se crean pelados,
           y lo que se prueba es el filtro de borrados, no el de imagenes. */
        $this->comercio->online_configuration()->update(['show_articles_without_images' => 1]);
    }

    /**
     * Un articulo online, activo y con stock, listo para ser novedad.
     *
     * @param  string  $nombre
     * @return \App\Article
     */
    private function articulo($nombre)
    {
        return Article::create([
            'name'    => $nombre,
            'slug'    => 'novedad-test-'.uniqid(),
            'user_id' => $this->comercio->id,
            'status'  => 'active',
            'online'  => 1,
            'stock'   => 10,
        ]);
    }

    /**
     * Registra un ingreso de stock del articulo. Los segundos en el futuro garantizan que el
     * movimiento sea mas nuevo que cualquier cosa sembrada en la base del slot, y ordenan a
     * los movimientos de un mismo test entre si.
     *
     * @param  \App\Article  $articulo
     * @param  int  $segundos_en_el_futuro
     * @return \App\StockMovement
     */
    private function ingresoDeStock($articulo, $segundos_en_el_futuro)
    {
        $movimiento = new StockMovement;
        $movimiento->article_id       = $articulo->id;
        $movimiento->user_id          = $this->comercio->id;
        $movimiento->amount           = 5;
        $movimiento->stock_resultante = 10;
        $movimiento->created_at       = Carbon::now()->addSeconds($segundos_en_el_futuro);
        $movimiento->save();

        return $movimiento;
    }

    /**
     * Las novedades que devuelve la home, tal como las recibe el SPA.
     *
     * @return array
     */
    private function novedades()
    {
        return $this->json('GET', '/api/articles/featured-last-uploads/'.$this->comercio->id.'?page=1')
            ->assertStatus(200)
            ->json('novedades');
    }

    /**
     * 🔴 La regresion en si: un articulo vivo con ingreso de stock TIENE que aparecer.
     *
     * Sobre el codigo viejo este test esta rojo con novedades vacias, que es exactamente lo
     * que veia cualquier tienda en produccion.
     */
    public function test_un_articulo_con_ingreso_de_stock_aparece_en_novedades()
    {
        $articulo = $this->articulo('Novedad Viva Test');
        $this->ingresoDeStock($articulo, 60);

        $ids = array_column($this->novedades(), 'id');

        $this->assertContains($articulo->id, $ids,
            'Un articulo no borrado con movimiento de stock reciente tiene que estar en novedades.');
    }

    /** Y el borrado con soft delete no: novedades es de articulos vivos. */
    public function test_un_articulo_borrado_no_aparece_en_novedades()
    {
        $borrado = $this->articulo('Novedad Borrada Test');
        $this->ingresoDeStock($borrado, 60);

        /* Como lo borra el ERP: escribiendo deleted_at en la base compartida. No se usa
           $borrado->delete() porque dispararia los hooks de ESTE repo (el paquete Likeable
           quiere limpiar una tabla likeable_likes que no existe en el esquema del ERP), y el
           camino real del borrado es empresa-api, que no los tiene. */
        Article::where('id', $borrado->id)->update(['deleted_at' => Carbon::now()]);

        $ids = array_column($this->novedades(), 'id');

        $this->assertNotContains($borrado->id, $ids,
            'Un articulo borrado no puede aparecer en novedades aunque tenga movimientos recientes.');
    }

    /** Mas nuevos primero: el orden lo da la fecha del movimiento, no el id del articulo. */
    public function test_las_novedades_vienen_de_la_mas_nueva_a_la_mas_vieja()
    {
        $viejo = $this->articulo('Novedad Vieja Test');
        $nuevo = $this->articulo('Novedad Nueva Test');

        $this->ingresoDeStock($viejo, 60);
        $this->ingresoDeStock($nuevo, 120);

        $ids = array_column($this->novedades(), 'id');

        $posicion_nuevo = array_search($nuevo->id, $ids);
        $posicion_viejo = array_search($viejo->id, $ids);

        $this->assertNotFalse($posicion_nuevo);
        $this->assertNotFalse($posicion_viejo);
        $this->assertLessThan($posicion_viejo, $posicion_nuevo,
            'El articulo con el ingreso de stock mas nuevo tiene que venir antes.');
    }

    /** Un articulo con varios ingresos recientes es UNA novedad, no una por movimiento. */
    public function test_un_articulo_con_varios_movimientos_aparece_una_sola_vez()
    {
        $articulo = $this->articulo('Novedad Repetida Test');
        $this->ingresoDeStock($articulo, 60);
        $this->ingresoDeStock($articulo, 90);
        $this->ingresoDeStock($articulo, 120);

        $ids = array_column($this->novedades(), 'id');

        $this->assertSame(1, count(array_keys($ids, $articulo->id)),
            'El mismo articulo no puede repetirse en novedades por tener varios movimientos.');
    }
}
