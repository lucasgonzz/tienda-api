<?php

namespace Tests\Feature\CuotasMercadoPago;

use App\Cuota;
use App\Http\Controllers\Helpers\CuotasMercadoPagoHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El calculo de las cuotas y las guardas de esquema (mision `tienda-cuotas-mercado-pago`,
 * 15/10/2026). No toca la base: lo que se prueba son las funciones puras y la logica de las cuatro
 * guardas, con el `Schema` falseado (nada de DDL).
 *
 * ── El calculo (plan §4) ──────────────────────────────────────────────────────────────────────
 *
 *     base           = suma de final_price * amount de los items de la preferencia
 *     recargo_monto  = round(base * neto / 100, 2)
 *     total_cobrado  = base + recargo_monto
 *     cuota_mostrada = round(total_cobrado / N, 2)
 *
 * Y las restricciones de medios de la preferencia, que son una funcion PURA.
 */
class CalculoYGuardasDeCuotasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CuotasMercadoPagoHelper::olvidar();
    }

    protected function tearDown(): void
    {
        CuotasMercadoPagoHelper::olvidar();

        parent::tearDown();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El calculo
    |---------------------------------------------------------------------------------------------
    */

    public function test_la_base_es_la_suma_de_precio_por_cantidad_de_todos_los_items()
    {
        $items = [
            ['name' => 'A', 'amount' => 2, 'final_price' => 1000],
            ['name' => 'B', 'amount' => 1, 'final_price' => 500.5],
            ['name' => 'Envio', 'amount' => 1, 'final_price' => 500],
        ];

        $this->assertSame(3000.5, CuotasMercadoPagoHelper::base_a_cobrar($items));
        $this->assertSame(0.0, CuotasMercadoPagoHelper::base_a_cobrar([]));
    }

    public function test_el_recargo_en_plata_se_redondea_a_dos_decimales()
    {
        $this->assertSame(200.0, CuotasMercadoPagoHelper::recargo_en_plata(2000, 10));
        $this->assertSame(66.6, CuotasMercadoPagoHelper::recargo_en_plata(2000, 3.33));
        $this->assertSame(0.33, CuotasMercadoPagoHelper::recargo_en_plata(10, 3.333));
        $this->assertSame(0.0, CuotasMercadoPagoHelper::recargo_en_plata(2000, 0));
    }

    public function test_el_item_de_recargo_tiene_el_nombre_la_cantidad_y_el_precio_en_plata()
    {
        $this->assertSame(
            ['name' => 'Recargo por pago en 6 cuotas', 'amount' => 1, 'final_price' => 400.0],
            CuotasMercadoPagoHelper::item_de_recargo(6, 400)
        );
    }

    public function test_no_hay_item_si_el_recargo_en_plata_es_cero()
    {
        $this->assertNull(CuotasMercadoPagoHelper::item_de_recargo(3, 0));
        $this->assertNull(CuotasMercadoPagoHelper::item_de_recargo(3, 0.004), 'Redondeado a centavos es cero.');
    }

    public function test_el_valor_de_la_cuota_es_el_total_cobrado_dividido_en_n()
    {
        $this->assertSame(733.33, CuotasMercadoPagoHelper::valor_de_cuota(2200, 3));
        $this->assertSame(2200.0, CuotasMercadoPagoHelper::valor_de_cuota(2200, 1));
    }

    public function test_las_restricciones_de_contado_excluyen_solo_la_tarjeta_de_credito()
    {
        $this->assertSame(
            ['excluded_payment_types' => [['id' => 'credit_card']]],
            CuotasMercadoPagoHelper::restricciones_de_medios(CuotasMercadoPagoHelper::MODO_CONTADO)
        );
    }

    public function test_las_restricciones_de_cuotas_ponen_el_tope_y_excluyen_todo_lo_que_no_es_credito_menos_el_dinero_en_cuenta()
    {
        $restricciones = CuotasMercadoPagoHelper::restricciones_de_medios(CuotasMercadoPagoHelper::MODO_CUOTAS, 6);

        $this->assertSame(6, $restricciones['installments']);
        $this->assertSame(
            ['debit_card', 'prepaid_card', 'ticket', 'atm', 'bank_transfer'],
            array_column($restricciones['excluded_payment_types'], 'id')
        );
        $this->assertNotContains('account_money', array_column($restricciones['excluded_payment_types'], 'id'), 'Mercado Pago no deja excluir el dinero en cuenta.');
        $this->assertNotContains('credit_card', array_column($restricciones['excluded_payment_types'], 'id'));
    }

    public function test_sin_modo_o_sin_cantidad_no_hay_restricciones()
    {
        $this->assertSame([], CuotasMercadoPagoHelper::restricciones_de_medios(null));
        $this->assertSame([], CuotasMercadoPagoHelper::restricciones_de_medios('otra cosa'));
        $this->assertSame([], CuotasMercadoPagoHelper::restricciones_de_medios(CuotasMercadoPagoHelper::MODO_CUOTAS));
    }

    #[DataProvider('cantidadesDeCuotas')]
    public function test_la_cantidad_de_cuotas_que_manda_el_comprador_se_normaliza($valor, $esperado)
    {
        $this->assertSame($esperado, CuotasMercadoPagoHelper::entero_de_cuotas($valor));
    }

    public static function cantidadesDeCuotas()
    {
        return [
            'entero'                  => [3, 3],
            'texto numerico'          => ['6', 6],
            'texto con espacios'      => [' 12 ', 12],
            'decimal entero'          => [3.0, 3],
            'uno'                     => [1, 1],
            'cero'                    => [0, null],
            'negativo'                => [-3, null],
            'texto negativo'          => ['-3', null],
            'decimal con fraccion'    => [2.5, null],
            'texto no numerico'       => ['abc', null],
            'texto vacio'             => ['', null],
            'null'                    => [null, null],
            'booleano'                => [true, null],
            'lista'                   => [[3], null],
        ];
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Lo que el webhook anota
    |---------------------------------------------------------------------------------------------
    */

    public function test_lo_que_se_anota_del_cobro_sale_del_pago()
    {
        CuotasMercadoPagoHelper::forzar_esquema(['cobro' => true]);

        $this->assertSame(
            ['cuotas_cobradas' => 3, 'medio_de_pago_cobrado' => 'credit_card'],
            CuotasMercadoPagoHelper::atributos_de_cobro(['installments' => '3', 'payment_type_id' => 'credit_card'])
        );

        $this->assertSame([], CuotasMercadoPagoHelper::atributos_de_cobro([]));
        $this->assertSame(['cuotas_cobradas' => 1], CuotasMercadoPagoHelper::atributos_de_cobro(['installments' => 1]));
    }

    public function test_sin_la_guarda_del_cobro_no_se_anota_nada()
    {
        CuotasMercadoPagoHelper::forzar_esquema(['cobro' => false]);

        $this->assertSame([], CuotasMercadoPagoHelper::atributos_de_cobro(['installments' => 3, 'payment_type_id' => 'credit_card']));
    }

    public function test_el_desajuste_se_detecta_por_cantidad_o_por_medio_y_solo_si_el_comprador_eligio_cuotas()
    {
        $tarjeta_en_3 = ['installments' => 3, 'payment_type_id' => 'credit_card'];

        $this->assertFalse(CuotasMercadoPagoHelper::el_cobro_no_coincide(3, $tarjeta_en_3));
        $this->assertFalse(CuotasMercadoPagoHelper::el_cobro_no_coincide('3', $tarjeta_en_3), 'Un entero que viene como texto de la base.');
        $this->assertTrue(CuotasMercadoPagoHelper::el_cobro_no_coincide(6, $tarjeta_en_3));
        $this->assertTrue(CuotasMercadoPagoHelper::el_cobro_no_coincide(3, ['installments' => 3, 'payment_type_id' => 'debit_card']));
        $this->assertTrue(CuotasMercadoPagoHelper::el_cobro_no_coincide(3, ['installments' => 1, 'payment_type_id' => 'account_money']));
        $this->assertTrue(CuotasMercadoPagoHelper::el_cobro_no_coincide(3, []), 'Un pago que no dice nada de las cuotas no coincide con lo elegido.');

        $this->assertFalse(CuotasMercadoPagoHelper::el_cobro_no_coincide(null, ['installments' => 1, 'payment_type_id' => 'account_money']), 'Sin cuotas elegidas (contado) no hay nada que comparar.');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Las guardas de esquema
    |---------------------------------------------------------------------------------------------
    */

    public function test_cada_guarda_se_apaga_sola_sin_llevarse_a_las_otras()
    {
        foreach (['vinculo', 'carrito', 'pedido', 'cobro'] as $apagada) {
            CuotasMercadoPagoHelper::olvidar();
            CuotasMercadoPagoHelper::forzar_esquema(array_fill_keys(['vinculo', 'carrito', 'pedido', 'cobro'], true));
            CuotasMercadoPagoHelper::forzar_esquema([$apagada => false]);

            $resultado = [
                'vinculo' => CuotasMercadoPagoHelper::vinculo_disponible(),
                'carrito' => CuotasMercadoPagoHelper::carrito_disponible(),
                'pedido'  => CuotasMercadoPagoHelper::pedido_disponible(),
                'cobro'   => CuotasMercadoPagoHelper::cobro_disponible(),
            ];

            foreach ($resultado as $guarda => $disponible) {
                $this->assertSame($guarda !== $apagada, $disponible, 'Con "'.$apagada.'" apagada, la guarda "'.$guarda.'" no tenia que cambiar.');
            }
        }
    }

    public function test_olvidar_descarta_lo_forzado_y_vuelve_a_medir_la_base_real()
    {
        CuotasMercadoPagoHelper::forzar_esquema(['carrito' => false]);
        $this->assertFalse(CuotasMercadoPagoHelper::carrito_disponible());

        CuotasMercadoPagoHelper::olvidar();

        $this->assertTrue(CuotasMercadoPagoHelper::carrito_disponible(), 'La base de testing tiene carts.cuotas.');
    }

    public function test_un_esquema_a_medio_aplicar_apaga_solo_la_guarda_que_le_falta()
    {
        // Falta UNA de las dos columnas de `orders` que se escriben juntas en el `Order::create`.
        Schema::shouldReceive('hasColumn')->andReturnUsing(function ($tabla, $columna) {
            return !($tabla === 'orders' && $columna === 'cuotas_recargo');
        });

        $this->assertFalse(CuotasMercadoPagoHelper::pedido_disponible(), 'Una sola columna de las dos no alcanza: el INSERT reventaria.');
        $this->assertTrue(CuotasMercadoPagoHelper::cobro_disponible());
        $this->assertTrue(CuotasMercadoPagoHelper::carrito_disponible());
        $this->assertTrue(CuotasMercadoPagoHelper::vinculo_disponible());
    }

    public function test_el_vinculo_pide_tambien_la_columna_de_cuotas_que_se_consulta()
    {
        Schema::shouldReceive('hasColumn')->andReturnUsing(function ($tabla, $columna) {
            return !($tabla === 'cuotas' && $columna === 'payment_method_id');
        });

        $this->assertFalse(CuotasMercadoPagoHelper::vinculo_disponible(), 'Sin cuotas.payment_method_id la consulta de cuotas reventaria en la ruta publica.');
    }

    public function test_si_no_se_puede_leer_el_esquema_la_guarda_da_false_y_deja_un_aviso()
    {
        Log::spy();

        Schema::shouldReceive('hasColumn')->andThrow(new \RuntimeException('la base no contesta'));

        $this->assertFalse(CuotasMercadoPagoHelper::carrito_disponible());

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_cada_guarda_se_mide_una_sola_vez_por_proceso()
    {
        // `vinculo` mira dos columnas: dos consultas la primera vez y ninguna la segunda.
        Schema::shouldReceive('hasColumn')->twice()->andReturn(true);

        $this->assertTrue(CuotasMercadoPagoHelper::vinculo_disponible());
        $this->assertTrue(CuotasMercadoPagoHelper::vinculo_disponible());
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El modelo de lectura
    |---------------------------------------------------------------------------------------------
    */

    public function test_el_modelo_cuota_es_de_solo_lectura()
    {
        $cuota = new Cuota();

        try {
            $cuota->save();
            $this->fail('Cuota::save() tendria que cortar.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('solo lectura', $e->getMessage());
        }

        try {
            $cuota->delete();
            $this->fail('Cuota::delete() tendria que cortar.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('solo lectura', $e->getMessage());
        }

        $this->assertSame('cuotas', $cuota->getTable());
    }
}
