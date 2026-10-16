<?php

namespace Tests\Feature\CatalogoPorLista;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * (c) Categorias, subcategorias y marcas con lista restringida (mision catalogo-por-lista-tienda,
 * 5/10/2026).
 *
 * `HomeController::subCategories()` sigue con `whereHas('articles')` sin pasar por `checkOnline()`,
 * asi que solo se restringe con lista restringida. `categories()` (desde el 16/10/2026, mision
 * categorias-contador-publicado) cuenta y esconde con `checkOnline()` + `checkStock()`, que ya
 * incluyen la lista: el numero es el del listado, con o sin lista restringida (el detalle esta en
 * `Home\ContadorDeCategoriasTest`).
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
     * Sin lista restringida, los conteos cuentan todo lo publicado. Una categoria SIN articulos para
     * mostrar ya NO se devuelve (mision categorias-contador-publicado, decision de Lucas 16/10/2026:
     * antes se devolvia con 0; ahora es el mismo criterio que la rama con lista restringida).
     * Los articulos del fixture estan todos publicados, asi que los numeros son los de siempre.
     */
    public function test_el_visitante_ve_las_categorias_y_los_conteos_de_siempre()
    {
        $this->categoria($this->comercio, 'Vacia');

        $this->comoVisitante();

        $categorias = $this->categorias();

        $this->assertSame(['Herramientas', 'Solo Minorista'], array_keys($categorias));
        $this->assertSame(2, (int) $categorias['Herramientas']['articles_count']);
        $this->assertSame(1, (int) $categorias['Solo Minorista']['articles_count']);

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
