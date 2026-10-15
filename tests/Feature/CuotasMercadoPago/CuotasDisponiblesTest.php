<?php

namespace Tests\Feature\CuotasMercadoPago;

use App\Http\Controllers\Helpers\CuotasMercadoPagoHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Las cuotas que la tienda ofrece para un metodo de Mercado Pago (mision
 * `tienda-cuotas-mercado-pago`, 15/10/2026).
 *
 * ── Que fija esta clase ───────────────────────────────────────────────────────────────────────
 *
 * `CuotasMercadoPagoHelper::cuotas_disponibles()` —que es lo que viaja en el campo
 * `cuotas_disponibles` de `GET /api/payment-methods/{commerce_id}`— ofrece SOLO:
 *  - las filas de `cuotas` del comercio cargadas PARA el metodo de cuenta corriente al que esta
 *    vinculado el metodo de la tienda (no las genericas, no las de otro metodo, no las de otro
 *    comercio);
 *  - con recargo NETO (`recargo - descuento`, NULL cuenta como 0) mayor o igual a cero;
 *  - de a una por cantidad, ordenadas por cantidad;
 *  - y solo si el metodo es de tipo MercadoPago, esta vinculado y la base tiene la columna del
 *    vinculo. En cualquier otro caso `[]` y el checkout queda como siempre.
 *
 * La ruta es PUBLICA: tambien se fija que lo nuevo no filtre ninguna credencial.
 */
class CuotasDisponiblesTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCuotasMercadoPago;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armarCuotas();
    }

    protected function tearDown(): void
    {
        $this->olvidarCuotas();

        parent::tearDown();
    }

    public function test_solo_se_ofrecen_las_cuotas_del_metodo_vinculado()
    {
        $otro_metodo = $this->metodoDeCuentaCorriente('Otro metodo de cuenta corriente');
        $otro_comercio = $this->comercioConTienda();

        $this->cuota($this->comercio, $this->metodo_cc_id, 3, 10);
        $this->cuota($this->comercio, $this->metodo_cc_id, 6, 20);

        // Las que NO se ofrecen: de otro metodo de cuenta corriente, genericas (sin metodo) y de
        // otro comercio aunque apunten al mismo metodo vinculado.
        $this->cuota($this->comercio, $otro_metodo, 12, 30);
        $this->cuota($this->comercio, null, 9, 5);
        $this->cuota($otro_comercio, $this->metodo_cc_id, 2, 1);

        $this->assertSame(
            [
                ['cantidad_cuotas' => 3, 'recargo' => '10.00'],
                ['cantidad_cuotas' => 6, 'recargo' => '20.00'],
            ],
            CuotasMercadoPagoHelper::cuotas_disponibles($this->payment_method)
        );
    }

    public function test_el_recargo_que_se_ofrece_es_el_neto_del_descuento()
    {
        $this->cuota($this->comercio, $this->metodo_cc_id, 3, 15, 5);

        $this->assertSame(
            [['cantidad_cuotas' => 3, 'recargo' => '10.00']],
            CuotasMercadoPagoHelper::cuotas_disponibles($this->payment_method)
        );
    }

    public function test_un_recargo_vacio_cuenta_como_cero_y_se_ofrece_como_cuotas_sin_recargo()
    {
        $this->cuota($this->comercio, $this->metodo_cc_id, 3, null, null);
        $this->cuota($this->comercio, $this->metodo_cc_id, 6, 10, 10);

        $this->assertSame(
            [
                ['cantidad_cuotas' => 3, 'recargo' => '0.00'],
                ['cantidad_cuotas' => 6, 'recargo' => '0.00'],
            ],
            CuotasMercadoPagoHelper::cuotas_disponibles($this->payment_method),
            'Neto 0 = «N cuotas sin recargo»: se ofrece.'
        );
    }

    public function test_un_neto_negativo_no_se_ofrece()
    {
        $this->cuota($this->comercio, $this->metodo_cc_id, 3, 5, 15);   // -10
        $this->cuota($this->comercio, $this->metodo_cc_id, 6, null, 1); // -1
        $this->cuota($this->comercio, $this->metodo_cc_id, 12, 8);      // +8

        $this->assertSame(
            [['cantidad_cuotas' => 12, 'recargo' => '8.00']],
            CuotasMercadoPagoHelper::cuotas_disponibles($this->payment_method),
            'Un descuento por pagar en cuotas no tiene sentido y no se ofrece.'
        );
    }

    public function test_una_cantidad_menor_a_uno_no_se_ofrece()
    {
        $this->cuota($this->comercio, $this->metodo_cc_id, 0, 5);
        $this->cuota($this->comercio, $this->metodo_cc_id, -2, 5);
        $this->cuota($this->comercio, $this->metodo_cc_id, 1, 5);

        $this->assertSame(
            [['cantidad_cuotas' => 1, 'recargo' => '5.00']],
            CuotasMercadoPagoHelper::cuotas_disponibles($this->payment_method)
        );
    }

    public function test_vienen_ordenadas_por_cantidad_de_cuotas_y_no_por_orden_de_carga()
    {
        $this->cuotasDelMetodo([[12, 30], [3, 10], [6, 20]]);

        $this->assertSame(
            [3, 6, 12],
            array_column(CuotasMercadoPagoHelper::cuotas_disponibles($this->payment_method), 'cantidad_cuotas')
        );
    }

    public function test_si_una_cantidad_esta_cargada_dos_veces_se_ofrece_una_sola_la_mas_vieja()
    {
        $this->cuota($this->comercio, $this->metodo_cc_id, 3, 10);
        $this->cuota($this->comercio, $this->metodo_cc_id, 3, 99);

        $this->assertSame(
            [['cantidad_cuotas' => 3, 'recargo' => '10.00']],
            CuotasMercadoPagoHelper::cuotas_disponibles($this->payment_method)
        );
    }

    public function test_el_neto_negativo_no_tapa_a_la_fila_valida_de_la_misma_cantidad()
    {
        $this->cuota($this->comercio, $this->metodo_cc_id, 3, 0, 5);   // -5: no se ofrece
        $this->cuota($this->comercio, $this->metodo_cc_id, 3, 10);

        $this->assertSame(
            [['cantidad_cuotas' => 3, 'recargo' => '10.00']],
            CuotasMercadoPagoHelper::cuotas_disponibles($this->payment_method)
        );
    }

    public function test_el_recargo_viaja_como_string_con_dos_decimales()
    {
        $this->cuota($this->comercio, $this->metodo_cc_id, 3, 10.5);
        $this->cuota($this->comercio, $this->metodo_cc_id, 6, 7);

        $this->assertSame(
            ['10.50', '7.00'],
            array_column(CuotasMercadoPagoHelper::cuotas_disponibles($this->payment_method), 'recargo')
        );
    }

    public function test_sin_vinculo_no_hay_cuotas_aunque_haya_filas_cargadas()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        $sin_vinculo = $this->metodoMercadoPago($this->comercio, null);

        $this->assertSame([], CuotasMercadoPagoHelper::cuotas_disponibles($sin_vinculo), 'No se matchea por nombre: sin vinculo explicito no hay cuotas.');
    }

    public function test_un_metodo_vinculado_pero_sin_cuotas_cargadas_no_ofrece_nada()
    {
        $this->assertSame([], CuotasMercadoPagoHelper::cuotas_disponibles($this->payment_method));
    }

    public function test_un_metodo_que_no_es_mercado_pago_no_ofrece_cuotas_aunque_tenga_vinculo()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        $efectivo = $this->payment_method->replicate();
        $efectivo->payment_method_type_id = $this->tipoDePago('Efectivo de prueba cuotas')->id;
        $efectivo->save();

        $this->assertSame([], CuotasMercadoPagoHelper::cuotas_disponibles($efectivo->fresh()));
    }

    public function test_sin_la_columna_del_vinculo_no_se_ofrece_nada()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        CuotasMercadoPagoHelper::forzar_esquema(['vinculo' => false]);

        $this->assertSame([], CuotasMercadoPagoHelper::cuotas_disponibles($this->payment_method));
    }

    public function test_un_metodo_nulo_no_rompe()
    {
        $this->assertSame([], CuotasMercadoPagoHelper::cuotas_disponibles(null));
        $this->assertNull(CuotasMercadoPagoHelper::recargo_neto(null, 3));
    }

    /**
     * 🔴 La ruta publica: el metodo de Mercado Pago gana `cuotas_disponibles`, los otros metodos NO
     * ganan el campo, y la respuesta no lleva ninguna credencial.
     */
    public function test_el_endpoint_publico_trae_cuotas_disponibles_solo_en_los_de_mercado_pago()
    {
        $this->cuotasDelMetodo([[6, 20], [3, 10]]);

        $sin_vinculo = $this->metodoMercadoPago($this->comercio, null);
        $sin_vinculo->name = 'Mercado Pago sin vincular';
        $sin_vinculo->save();

        $efectivo = $this->payment_method->replicate();
        $efectivo->name = 'Efectivo';
        $efectivo->payment_method_type_id = $this->tipoDePago('Efectivo de prueba cuotas')->id;
        $efectivo->save();

        $respuesta = $this->getJson('/api/payment-methods/'.$this->comercio->id);

        $respuesta->assertStatus(200);

        $metodos = collect($respuesta->json('payment_methods'))->keyBy('name');

        $this->assertSame(
            [
                ['cantidad_cuotas' => 3, 'recargo' => '10.00'],
                ['cantidad_cuotas' => 6, 'recargo' => '20.00'],
            ],
            $metodos['Mercado Pago']['cuotas_disponibles']
        );

        $this->assertSame([], $metodos['Mercado Pago sin vincular']['cuotas_disponibles'], 'Un Mercado Pago sin vinculo viaja con la lista vacia.');
        $this->assertArrayNotHasKey('cuotas_disponibles', $metodos['Efectivo'], 'Los demas metodos no cambian.');

        $this->assertStringNotContainsString('TOKEN-DE-PRUEBA', $respuesta->getContent(), 'La ruta es publica: el access_token no puede viajar.');
        $this->assertArrayNotHasKey('access_token', $metodos['Mercado Pago']);
    }

    public function test_el_endpoint_publico_con_la_columna_del_vinculo_ausente_viaja_con_la_lista_vacia()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        CuotasMercadoPagoHelper::forzar_esquema(['vinculo' => false]);

        $respuesta = $this->getJson('/api/payment-methods/'.$this->comercio->id);

        $respuesta->assertStatus(200);
        $this->assertSame([], $respuesta->json('payment_methods.0.cuotas_disponibles'));
    }

    public function test_recargo_neto_resuelve_de_la_base_y_devuelve_null_si_la_cantidad_no_se_ofrece()
    {
        $this->cuotasDelMetodo([[3, 10], [6, 20]]);

        $this->assertSame(10.0, CuotasMercadoPagoHelper::recargo_neto($this->payment_method, 3));
        $this->assertSame(20.0, CuotasMercadoPagoHelper::recargo_neto($this->payment_method, 6));
        $this->assertNull(CuotasMercadoPagoHelper::recargo_neto($this->payment_method, 4));
    }
}
