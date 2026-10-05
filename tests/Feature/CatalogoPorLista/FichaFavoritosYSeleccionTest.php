<?php

namespace Tests\Feature\CatalogoPorLista;

use App\Article;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (b) Los tres caminos que NO pasan por `checkOnline()` y tambien respetan la lista del comprador
 * (mision catalogo-por-lista-tienda, 5/10/2026): la ficha por slug, los favoritos y la seleccion
 * especial. Van aparte porque el enganche de `checkOnline()` no los cubre: si alguien saca el scope
 * de uno de estos, ningun otro test se entera.
 */
class FichaFavoritosYSeleccionTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCatalogoPorLista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();
        $this->armarFerretotal();
    }

    protected function tearDown(): void
    {
        /* Por si un caso de favoritos murio antes de su `finally`. Es TEMPORARY: no le hace commit a
           la transaccion del trait ni deja nada en la base compartida del slot. */
        DB::statement('DROP TEMPORARY TABLE IF EXISTS `likeable_likes`');

        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * 🔴 La ficha de un articulo que el mayorista no puede ver responde IGUAL que un slug que no
     * existe: `{"article": null}` con 200 (contrato C3). Ni 403 ni 404: un codigo propio le
     * confirmaria a quien prueba links que ese articulo existe.
     */
    public function test_la_ficha_de_un_no_habilitado_es_igual_a_la_de_un_slug_inexistente()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $no_habilitado = $this->json('GET', '/api/articles/'.$this->sin_marcar->slug.'/'.$this->comercio->id);
        $inexistente = $this->json('GET', '/api/articles/slug-que-no-existe-'.uniqid().'/'.$this->comercio->id);

        $no_habilitado->assertStatus(200);
        $inexistente->assertStatus(200);

        $this->assertSame(['article' => null], $no_habilitado->json());
        $this->assertSame($inexistente->json(), $no_habilitado->json(),
            'desde afuera no se puede distinguir un articulo no habilitado de uno que no existe');

        $this->json('GET', '/api/articles/'.$this->deshabilitado->slug.'/'.$this->comercio->id)
            ->assertStatus(200)
            ->assertExactJson(['article' => null]);

        /* Y el habilitado se abre, con el precio de su lista. */
        $habilitado = $this->json('GET', '/api/articles/'.$this->habilitado->slug.'/'.$this->comercio->id)->assertStatus(200);
        $this->assertSame($this->habilitado->id, (int) $habilitado->json('article.id'));
        $this->assertEquals(1000, $habilitado->json('article.final_price'));
    }

    /** El visitante abre cualquiera de los tres: su lista no es restringida. */
    public function test_el_visitante_abre_la_ficha_de_cualquiera()
    {
        $this->comoVisitante();

        foreach ([$this->habilitado, $this->sin_marcar, $this->deshabilitado] as $articulo) {
            $respuesta = $this->json('GET', '/api/articles/'.$articulo->slug.'/'.$this->comercio->id)->assertStatus(200);
            $this->assertSame($articulo->id, (int) $respuesta->json('article.id'), $articulo->name);
        }
    }

    /**
     * La seleccion especial (`/articles-seleccion-especial/A-B-C`) deja `null` en el lugar de lo que
     * el mayorista no puede ver, igual que con un id que no existe, y conserva el orden pedido. La
     * ruta no trae comercio: sale del articulo.
     */
    public function test_la_seleccion_especial_deja_null_lo_no_habilitado_y_conserva_el_orden()
    {
        $ids = $this->sin_marcar->id.'-'.$this->habilitado->id.'-'.$this->deshabilitado->id;

        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $modelos = $this->json('GET', '/api/articles-seleccion-especial/'.$ids)->assertStatus(200)->json('models');

        $this->assertCount(3, $modelos);
        $this->assertNull($modelos[0], 'sin_marcar: null en su lugar');
        $this->assertSame($this->habilitado->id, (int) $modelos[1]['id']);
        $this->assertNull($modelos[2], 'deshabilitado: null en su lugar');

        $this->comoVisitante();

        $modelos = $this->json('GET', '/api/articles-seleccion-especial/'.$ids)->assertStatus(200)->json('models');

        $this->assertSame(
            [$this->sin_marcar->id, $this->habilitado->id, $this->deshabilitado->id],
            array_map(function ($modelo) { return (int) $modelo['id']; }, $modelos),
            'el visitante ve los tres, en el orden pedido'
        );
    }

    /**
     * Los favoritos del mayorista no listan lo que su lista ya no le deja ver (un favorito marcado
     * antes de que el comerciante restringiera la lista, por ejemplo). Los del minorista, todos.
     *
     * ⚠️ La tabla `likeable_likes` (paquete rtconner/laravel-likeable) NO la crea ninguna migracion
     * de `empresa-api` y la base del slot no la tiene: hoy `GET /favorites` responde 500 en una base
     * asi (hallazgo aparte, va al informe). Para poder medir el scope, el caso la crea TEMPORARY con
     * la forma de la migracion del paquete: una tabla temporal vive solo en esta conexion, no le
     * hace commit implicito a la transaccion y no deja nada en la base compartida.
     */
    public function test_los_favoritos_del_mayorista_solo_traen_los_habilitados()
    {
        DB::statement('CREATE TEMPORARY TABLE `likeable_likes` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `likeable_id` varchar(36) NOT NULL,
            `likeable_type` varchar(255) NOT NULL,
            `user_id` varchar(36) NOT NULL,
            `created_at` timestamp NULL,
            `updated_at` timestamp NULL
        )');

        try {
            $mayorista = $this->compradorConLista($this->comercio, $this->mayorista->id);
            $minorista = $this->compradorConLista($this->comercio, $this->minorista->id);

            foreach ([$mayorista, $minorista] as $comprador) {
                foreach ([$this->habilitado, $this->sin_marcar, $this->deshabilitado] as $articulo) {
                    $this->favorito($comprador->id, $articulo);
                }
            }

            $this->comoComprador($mayorista);
            $this->assertSame(
                [$this->habilitado->id],
                $this->idsDe($this->json('GET', '/api/favorites')->assertStatus(200)->json('articles.data')),
                'mayorista: solo el habilitado'
            );

            $this->comoComprador($minorista);
            $this->assertSame(
                $this->losTres(),
                $this->idsDe($this->json('GET', '/api/favorites')->assertStatus(200)->json('articles.data')),
                'minorista: los tres'
            );
        } finally {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS `likeable_likes`');
        }
    }

    /**
     * Un favorito como lo guarda `Likeable::like()`.
     *
     * @param  int  $buyer_id
     * @param  \App\Article  $articulo
     * @return void
     */
    private function favorito($buyer_id, Article $articulo)
    {
        DB::table('likeable_likes')->insert([
            'likeable_id'   => (string) $articulo->id,
            'likeable_type' => $articulo->getMorphClass(),
            'user_id'       => (string) $buyer_id,
            'created_at'    => Carbon::now(),
            'updated_at'    => Carbon::now(),
        ]);
    }
}
