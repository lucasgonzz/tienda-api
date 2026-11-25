<?php

namespace Tests\Feature\CarritosAjusteStock;

use App\Article;
use App\ArticleVariant;
use App\Http\Controllers\Helpers\CartStockNoticeHelper;
use App\Http\Controllers\Helpers\ConsultaStockHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CombosYRangos\ArmaComercioConCombosYRangos;
use Tests\TestCase;

/**
 * Mision carritos-ajuste-stock, criterio CA18: la matriz del tope de un renglon del carrito.
 *
 * `CartStockNoticeHelper::tope_de_linea()` es el espejo en tienda-api de `limit_for_line()` de
 * empresa-api (reglas 1 a 7 del plan, seccion 2.1). La tabla de casos es la MISMA que lleva el test
 * de empresa-api (`TopeDeLaTiendaTest::test_matriz_del_tope`, casos a-m) y los nombres de caso
 * coinciden a proposito: si una de las dos puntas cambia de criterio, el otro test lo delata.
 *
 * El caso `m` (comercio sin `online_configurations`) no aplica a tienda-api: sin configuracion no
 * hay tienda que atienda un carrito.
 *
 * Valor devuelto: `null` = sin tope; numero = cantidad maxima comprable (0 incluido).
 */
class TopeDeLineaTest extends TestCase
{
    use ArmaComercioConCombosYRangos, DatabaseTransactions;

    /** @var \App\User Comercio propio de cada caso. */
    private $comercio;

    /**
     * Comercio nuevo y memorias limpias para que un caso no herede el esquema del anterior.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->olvidarLasMemorias();
        $this->comercio = $this->comercioConTienda();
    }

    /**
     * Descarta las memorias estaticas para no filtrar estado a la clase siguiente.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();
        parent::tearDown();
    }

    /**
     * Los casos a-l de la matriz (mismos nombres que en empresa-api).
     *
     * Claves de cada caso:
     *  - `config`: columnas de `online_configurations` a pisar.
     *  - `stock`: stock del articulo (null permitido). `variante`: false = renglon sin variante;
     *    array con `stock` = renglon con esa variante.
     *  - `con_variantes_sin_elegir`: el articulo tiene variantes pero el renglon no trae ninguna.
     *  - `reserva`: depositos y reservas (`fisico`, `reservado`).
     *  - `esperado`: null (sin tope) o numero.
     *
     * @return array
     */
    public function casos_del_tope()
    {
        return [
            'a_ignorar_stock_sin_tope' => [['config' => ['ignorar_stock' => 1], 'stock' => 5], null],
            'b_stock_null_sin_equal_0_sin_tope' => [['config' => ['stock_null_equal_0' => 0], 'stock' => null], null],
            'c_stock_null_con_equal_0_sin_variante_tope_0' => [['config' => ['stock_null_equal_0' => 1], 'stock' => null], 0.0],
            'd_variante_con_stock_null_sin_tope' => [['config' => ['stock_null_equal_0' => 1], 'stock' => 7, 'variante' => ['stock' => null]], null],
            'e_consultas_activas_stock_0_sin_tope' => [['config' => ['permitir_pedidos_sin_stock' => 1], 'stock' => 0], null],
            'f_consultas_activas_stock_3_tope_3' => [['config' => ['permitir_pedidos_sin_stock' => 1], 'stock' => 3], 3.0],
            'g_consultas_activas_stock_negativo_tope_0' => [['config' => ['permitir_pedidos_sin_stock' => 1], 'stock' => -2], 0.0],
            'h_sin_opciones_stock_negativo_tope_0' => [['config' => [], 'stock' => -200], 0.0],
            'i_reserva_activa_fisico_10_reservado_6_tope_4' => [[
                'config' => ['reservar_stock_pedidos_web' => 1, 'reserva_stock_erp_protocolo' => 1, 'reserva_stock_tienda_protocolo' => 1],
                'stock' => 99, 'reserva' => ['fisico' => 10, 'reservado' => 6],
            ], 4.0],
            'j_reserva_activa_variante_deposito_5_reservado_2_tope_3' => [[
                'config' => ['reservar_stock_pedidos_web' => 1, 'reserva_stock_erp_protocolo' => 1, 'reserva_stock_tienda_protocolo' => 1],
                'stock' => 99, 'variante' => ['stock' => 99], 'reserva' => ['fisico' => 5, 'reservado' => 2],
            ], 3.0],
            'k_stock_fraccionario_tope_fraccionario' => [['config' => [], 'stock' => 2.5], 2.5],
            'l_articulo_con_variantes_y_renglon_sin_variante_sin_tope' => [['config' => [], 'stock' => 4, 'con_variantes_sin_elegir' => true], null],
        ];
    }

    /**
     * CA18: para cada caso de la matriz, `tope_de_linea()` devuelve lo mismo que `limit_for_line()`.
     *
     * @dataProvider casos_del_tope
     * @param  array  $caso      Datos del caso (ver `casos_del_tope`).
     * @param  float|null  $esperado  Tope esperado (null = sin tope).
     * @return void
     */
    public function test_matriz_del_tope(array $caso, $esperado)
    {
        // Se aplica la configuracion del caso sobre la fila online del comercio de prueba.
        if (!empty($caso['config'])) {
            $this->comercio->online_configuration()->update($caso['config']);
        }
        $configuracion = $this->comercio->online_configuration()->first();

        // Articulo del caso; el stock puede ser null, fraccionario o negativo.
        $articulo = $this->articuloPublicado($this->comercio, ['stock' => $caso['stock']]);

        // Variante del renglon (o variante "de relleno" para el caso l: el renglon no la elige).
        $variante = null;
        if (isset($caso['variante'])) {
            $variante = $this->crear_variante($articulo, $caso['variante']['stock']);
        } elseif (!empty($caso['con_variantes_sin_elegir'])) {
            $this->crear_variante($articulo, 3);
        }

        // Deposito principal y reservas de otro pedido (casos i y j).
        if (isset($caso['reserva'])) {
            $this->armar_reserva($articulo, $variante, $caso['reserva']['fisico'], $caso['reserva']['reservado']);
        }

        // Se relee el articulo con sus variantes, como lo hace el carrito real.
        $articulo = Article::with('article_variants')->findOrFail($articulo->id);
        if ($variante) {
            $variante = ArticleVariant::findOrFail($variante->id);
        }

        $tope = CartStockNoticeHelper::tope_de_linea(
            $configuracion,
            $articulo,
            $variante,
            ConsultaStockHelper::activa($configuracion)
        );

        if ($esperado === null) {
            $this->assertNull($tope, 'Este caso no pone tope.');
        } else {
            $this->assertNotNull($tope, 'Este caso pone tope '.$esperado);
            $this->assertSame($esperado, (float) $tope);
        }
    }

    /**
     * Crea una variante del articulo con el stock indicado (puede ser null).
     *
     * @param  \App\Article  $articulo
     * @param  int|null  $stock
     * @return \App\ArticleVariant
     */
    private function crear_variante(Article $articulo, $stock)
    {
        $id = DB::table('article_variants')->insertGetId(['article_id' => $articulo->id, 'stock' => $stock]);
        return ArticleVariant::findOrFail($id);
    }

    /**
     * Arma el deposito principal con `$fisico` unidades y una reserva de otro pedido de
     * `$reservado` unidades sobre ese deposito. Con variante usa la tabla de depositos de variantes.
     *
     * @param  \App\Article  $articulo
     * @param  \App\ArticleVariant|null  $variante
     * @param  int  $fisico
     * @param  int  $reservado
     * @return void
     */
    private function armar_reserva(Article $articulo, $variante, $fisico, $reservado)
    {
        // Deposito principal del comercio (default_address) con el stock fisico de este recurso.
        $deposito = DB::table('addresses')->insertGetId([
            'user_id' => $this->comercio->id,
            'street' => 'Deposito principal de prueba',
            'default_address' => 1,
        ]);

        if ($variante) {
            DB::table('address_article_variant')->insert([
                'address_id' => $deposito, 'article_variant_id' => $variante->id, 'amount' => $fisico,
            ]);
        } else {
            DB::table('address_article')->insert([
                'address_id' => $deposito, 'article_id' => $articulo->id, 'amount' => $fisico,
            ]);
        }

        // Reserva de un pedido ajeno (order_id sin llave foranea en el esquema).
        DB::table('order_stock_reservations')->insert([
            'user_id' => $this->comercio->id,
            'order_id' => 987654,
            'article_id' => $articulo->id,
            'article_variant_id' => $variante ? $variante->id : null,
            'address_id' => $deposito,
            'amount' => $reservado,
        ]);
    }
}
