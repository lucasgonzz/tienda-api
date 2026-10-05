<?php

namespace Tests\Feature\CatalogoPorLista;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * (c) Categorias, subcategorias y marcas con lista restringida (mision catalogo-por-lista-tienda,
 * 5/10/2026).
 *
 * `HomeController::categories()` y `subCategories()` cuentan con `withCount('articles')` /
 * `whereHas('articles')` SIN pasar por `checkOnline()`: hoy cuentan todo. Con lista restringida eso
 * seria un defecto doble —categorias que al abrirlas estan vacias, y conteos que le dicen al
 * mayorista cuantos articulos existen que no le muestran—, asi que SOLO en ese caso se restringen.
 * Sin lista restringida la consulta queda identica, incluido que una categoria vacia se sigue
 * devolviendo: eso es lo que esta clase fija en la otra mitad.
 *
 * Las marcas: el `whereHas` ya pasaba por `checkOnline()`; el conteo se restringe con el mismo
 * criterio que el de las categorias.
 */
class CategoriasYMarcasTest extends TestCase
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
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * 🔴 El mayorista no ve la categoria que solo tiene articulos no habilitados, y los conteos
     * cuentan solo lo habilitado, en la categoria y en sus subcategorias.
     */
    public function test_el_mayorista_no_ve_categorias_vacias_y_los_conteos_son_de_lo_habilitado()
    {
        $this->categoria($this->comercio, 'Vacia');

        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $categorias = $this->categorias();

        $this->assertSame(['Herramientas'], array_keys($categorias),
            'ni "Solo Minorista" (todo no habilitado) ni "Vacia" (sin articulos)');
        $this->assertSame(1, (int) $categorias['Herramientas']['articles_count']);

        $subs = $this->porNombre($categorias['Herramientas']['sub_categories']);
        $this->assertSame(['Taladros'], array_keys($subs), 'Martillos solo tiene un articulo sin marcar');
        $this->assertSame(1, (int) $subs['Taladros']['articles_count']);
    }

    /**
     * Sin lista restringida, todo como hoy: los conteos cuentan todo, y una categoria SIN articulos
     * se sigue devolviendo (con 0). Si este caso se pone rojo, se cambio el comportamiento del 100%
     * de los clientes.
     */
    public function test_el_visitante_ve_las_categorias_y_los_conteos_de_siempre()
    {
        $this->categoria($this->comercio, 'Vacia');

        $this->comoVisitante();

        $categorias = $this->categorias();

        $this->assertSame(['Herramientas', 'Solo Minorista', 'Vacia'], array_keys($categorias));
        $this->assertSame(2, (int) $categorias['Herramientas']['articles_count']);
        $this->assertSame(1, (int) $categorias['Solo Minorista']['articles_count']);
        $this->assertSame(0, (int) $categorias['Vacia']['articles_count']);

        $subs = $this->porNombre($categorias['Herramientas']['sub_categories']);
        $this->assertSame(['Martillos', 'Taladros'], array_keys($subs));
    }

    /** `GET /sub-categories/{category_id}`: la ruta sin comercio, con el mismo criterio. */
    public function test_las_subcategorias_respetan_la_lista()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $this->assertSame(['Taladros'], $this->nombresDeSubcategorias($this->herramientas->id));
        $this->assertSame([], $this->nombresDeSubcategorias($this->solo_minorista->id));

        $this->comoVisitante();

        $this->assertSame(['Martillos', 'Taladros'], $this->nombresDeSubcategorias($this->herramientas->id));
        $this->assertSame(['Pinturas'], $this->nombresDeSubcategorias($this->solo_minorista->id));
    }

    /**
     * Las marcas: la del mayorista sin habilitados no aparece, y el conteo cuenta solo los
     * habilitados. El visitante, como siempre.
     */
    public function test_las_marcas_y_sus_conteos_respetan_la_lista()
    {
        $this->comoComprador($this->compradorConLista($this->comercio, $this->mayorista->id));

        $marcas = $this->marcas();
        $this->assertSame(['Habilitada'], array_keys($marcas));
        $this->assertSame(1, (int) $marcas['Habilitada']['articles_count']);

        $this->comoVisitante();

        $marcas = $this->marcas();
        $this->assertSame(['Habilitada', 'Solo Minorista'], array_keys($marcas));
        $this->assertSame(2, (int) $marcas['Habilitada']['articles_count']);
        $this->assertSame(1, (int) $marcas['Solo Minorista']['articles_count']);
    }

    /** @return array Las categorias de la respuesta, por nombre, en orden alfabetico. */
    private function categorias()
    {
        return $this->porNombre($this->json('GET', '/api/categories/'.$this->comercio->id)->assertStatus(200)->json('categories'));
    }

    /** @return array Las marcas de la respuesta, por nombre, en orden alfabetico. */
    private function marcas()
    {
        return $this->porNombre($this->json('GET', '/api/brands/'.$this->comercio->id)->assertStatus(200)->json('brands'));
    }

    /** @return array */
    private function nombresDeSubcategorias($category_id)
    {
        return array_keys($this->porNombre($this->json('GET', '/api/sub-categories/'.$category_id)->assertStatus(200)->json('sub_categories')));
    }

    /**
     * @param  array|null  $modelos
     * @return array
     */
    private function porNombre($modelos)
    {
        $por_nombre = [];

        foreach ((array) $modelos as $modelo) {
            $por_nombre[$modelo['name']] = $modelo;
        }

        ksort($por_nombre);

        return $por_nombre;
    }
}
