<?php

namespace Tests\Feature\CombosCalculados;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El collage de un combo SIN foto propia, con el caso medido en la tienda de demo el 14/10/2026
 * (mision tiendas-demo-y-limpieza-tienda): "KIT TALLER DEMO" = 2 x LLAVE T 10MM GARDEX (con foto)
 * + 1 x ESPATULA 70mm (con foto), creado por `POST combo` en empresa sin imagen. En la portada se
 * vio con UNA sola foto, la de la espatula.
 *
 * Lo que fija este caso es la punta de la API del collage: con esa receta, la home manda
 * `images` VACIO (no hay foto propia, asi que la tarjeta no puede tomar nada como tal) y los DOS
 * componentes, cada uno con su foto y su cantidad. Con ese payload, `imagenes_de_tarjeta()` de
 * tienda-spa (`src/helpers/combo.js`) arma dos teselas, una por componente distinto (no por
 * unidad), y la tarjeta las dibuja lado a lado (`combo-card__imagenes--2`): verificado con la SPA
 * compilada a 1366, 820 y 375 px.
 *
 * O sea: si una tarjeta muestra UNA sola foto para esta receta, el payload que le llego no es
 * este. Lo unico que lo explica es una fila en `images` con `imageable_type = 'combo'` para ese
 * combo (la tarjeta la toma, bien, como foto propia: `test_la_home_trae_la_foto_propia_del_combo`
 * en ImagenesDelComboTest), o que el componente no venga en `article_combo`. Los dos son datos
 * de la base, no codigo.
 */
class CollageDelComboTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCombosCalculados;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarTodo();
    }

    protected function tearDown(): void
    {
        $this->olvidarTodo();

        parent::tearDown();
    }

    /** 2 x un articulo con foto + 1 x otro con foto, sin foto propia: viajan las dos fotos. */
    public function test_un_combo_de_dos_componentes_con_foto_trae_las_dos_fotos_y_ninguna_propia()
    {
        $comercio = $this->comercioConTienda();

        $llave = $this->articuloConStock($comercio, 50, ['name' => 'LLAVE T 10MM GARDEX']);
        $espatula = $this->articuloConStock($comercio, 50, ['name' => 'ESPATULA 70mm REMACHADO M/MADERA BIASSONI']);
        $this->fotoDelArticulo($llave, 'https://cdn.test/llave-t-10mm.webp');
        $this->fotoDelArticulo($espatula, 'https://cdn.test/espatula-70mm.webp');

        $combo = $this->combo($comercio);
        $this->componente($combo, $llave, 2);
        $this->componente($combo, $espatula, 1);

        $en_la_home = $this->comboEnLaHome($comercio, $combo);

        $this->assertSame([], $en_la_home['images'], 'sin foto propia la tarjeta tiene que armar el collage');

        $this->assertCount(2, $en_la_home['articles'], 'un componente por articulo distinto, no por unidad');

        $por_nombre = [];
        foreach ($en_la_home['articles'] as $componente) {
            $por_nombre[$componente['name']] = $componente;
        }

        $this->assertSame(2, (int) $por_nombre['LLAVE T 10MM GARDEX']['pivot']['amount']);
        $this->assertSame(1, (int) $por_nombre['ESPATULA 70mm REMACHADO M/MADERA BIASSONI']['pivot']['amount']);

        $this->assertSame('https://cdn.test/llave-t-10mm.webp',
            $por_nombre['LLAVE T 10MM GARDEX']['images'][0]['hosting_url'],
            'el componente que va DOS veces trae su foto igual que el otro');
        $this->assertSame('https://cdn.test/espatula-70mm.webp',
            $por_nombre['ESPATULA 70mm REMACHADO M/MADERA BIASSONI']['images'][0]['hosting_url']);
    }

    /**
     * La foto de un componente no se convierte en foto propia del combo: aunque las dos esten en
     * la misma tabla `images`, la del articulo es `imageable_type = 'article'`.
     */
    public function test_la_foto_de_un_componente_no_viaja_como_foto_propia()
    {
        $comercio = $this->comercioConTienda();

        $espatula = $this->articuloConStock($comercio, 50, ['name' => 'ESPATULA']);
        $this->fotoDelArticulo($espatula, 'https://cdn.test/espatula.webp');

        $combo = $this->combo($comercio);
        $this->componente($combo, $espatula, 1);

        $this->assertSame([], $this->comboEnLaHome($comercio, $combo)['images']);
    }
}
