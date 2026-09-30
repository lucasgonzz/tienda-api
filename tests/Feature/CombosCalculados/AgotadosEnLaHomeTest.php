<?php

namespace Tests\Feature\CombosCalculados;

use App\Http\Controllers\Helpers\HomeHelper;
use App\OnlineConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El filtro de combos agotados de la home (mision combos-calculados, 30/9/2026).
 *
 * Es el espejo de `Article::scopeCheckStock()`: la tienda decide si oculta lo agotado con
 * `show_articles_without_stock` (y `ignorar_stock` lo apaga todo). Para un combo "agotado" es
 * `stock_disponible === 0`; un `null` (los componentes no llevan stock) NO esta agotado.
 *
 * ⚠️ `ignorar_stock` NO existe en la base de testing de este slot (la columna la crea una
 * migracion de `empresa-api` que llega con su release): ese borde se prueba en
 * `SinEsquemaDeCombosCalculadosTest`, que puede agregar y sacar la columna porque no usa
 * transacciones. Aca se fija lo que si se puede medir con `DatabaseTransactions`: que sin la
 * columna la tienda se comporta por `show_articles_without_stock` y no revienta.
 */
class AgotadosEnLaHomeTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCombosCalculados;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Combo */
    private $con_stock;

    /** @var \App\Combo */
    private $agotado;

    /** @var \App\Combo */
    private $sin_control;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarTodo();

        $this->comercio = $this->comercioConTienda();

        $this->con_stock = $this->combo($this->comercio, ['name' => 'Con stock']);
        $this->componente($this->con_stock, $this->articuloConStock($this->comercio, 10), 2);

        $this->agotado = $this->combo($this->comercio, ['name' => 'Agotado']);
        $this->componente($this->agotado, $this->articuloConStock($this->comercio, 1), 2);

        $this->sin_control = $this->combo($this->comercio, ['name' => 'Sin control']);
        $this->componente($this->sin_control, $this->articuloConStock($this->comercio, null), 1);
    }

    protected function tearDown(): void
    {
        $this->olvidarTodo();

        parent::tearDown();
    }

    /** Los ids que la home lista, ordenados, para comparar sin depender del orden de la pagina. */
    private function idsEnLaHome()
    {
        $ids = array_column($this->combosDeLaHome($this->comercio), 'id');
        sort($ids);

        return $ids;
    }

    private function configuracion($show_articles_without_stock)
    {
        OnlineConfiguration::where('user_id', $this->comercio->id)
                            ->update(['show_articles_without_stock' => $show_articles_without_stock]);
    }

    private function idsOrdenados(array $combos)
    {
        $ids = array_map(function ($combo) {
            return $combo->id;
        }, $combos);
        sort($ids);

        return $ids;
    }

    /**
     * Con la tienda configurada para MOSTRAR lo sin stock, el combo agotado se lista (con
     * `stock_disponible = 0` para que la tarjeta ponga el cartel "Agotado").
     */
    public function test_mostrando_los_sin_stock_el_agotado_se_lista()
    {
        $this->configuracion(1);

        $this->assertSame(
            $this->idsOrdenados([$this->con_stock, $this->agotado, $this->sin_control]),
            $this->idsEnLaHome()
        );
    }

    /**
     * 🔴 Con la tienda configurada para OCULTAR lo sin stock, el combo agotado no se lista, y los
     * otros dos si: el que tiene stock y el que no lleva control (null).
     */
    public function test_ocultando_los_sin_stock_el_agotado_desaparece()
    {
        $this->configuracion(0);

        $this->assertSame(
            $this->idsOrdenados([$this->con_stock, $this->sin_control]),
            $this->idsEnLaHome(),
            'el agotado sale; el "sin control" (null) NO esta agotado y se queda'
        );
    }

    /** `show_articles_without_stock` en NULL (la columna lo admite) se lee como apagado, igual que el articulo. */
    public function test_el_valor_null_de_la_configuracion_oculta_como_el_articulo()
    {
        $this->configuracion(null);

        $this->assertNotContains($this->agotado->id, $this->idsEnLaHome());
    }

    /** Cuando el agotado se oculta, el resto del combo (precio, foto) sigue viajando para los otros. */
    public function test_ocultar_el_agotado_no_toca_a_los_demas()
    {
        $this->configuracion(0);

        $combo = $this->comboEnLaHome($this->comercio, $this->con_stock);

        $this->assertSame(5, $combo['stock_disponible'], '10 / 2 = 5');
        $this->assertTrue($combo['is_combo']);
        $this->assertEquals(self::PRECIO_COMBO, $combo['final_price']);
    }

    /** La regla vive en un metodo publico que el resto del codigo puede consultar. */
    public function test_un_comercio_sin_configuracion_online_no_oculta_nada()
    {
        OnlineConfiguration::where('user_id', $this->comercio->id)->delete();

        $this->assertFalse(HomeHelper::ocultar_combos_agotados($this->comercio->id));
        $this->assertFalse(HomeHelper::ocultar_combos_agotados(0), 'ni un comercio que no existe');
    }

    /** Sin la columna `ignorar_stock` (esta base) la decision la toma `show_articles_without_stock`. */
    public function test_sin_la_columna_ignorar_stock_decide_show_articles_without_stock()
    {
        $this->configuracion(1);
        $this->assertFalse(HomeHelper::ocultar_combos_agotados($this->comercio->id));

        $this->configuracion(0);
        $this->assertTrue(HomeHelper::ocultar_combos_agotados($this->comercio->id));
    }
}
