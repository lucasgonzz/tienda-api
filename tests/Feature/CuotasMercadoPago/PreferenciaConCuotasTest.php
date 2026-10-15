<?php

namespace Tests\Feature\CuotasMercadoPago;

use App\DeliveryZone;
use App\Http\Controllers\Helpers\CuotasMercadoPagoHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La preferencia de Mercado Pago con cuotas (mision `tienda-cuotas-mercado-pago`, 15/10/2026).
 *
 * ── Como se prueba ────────────────────────────────────────────────────────────────────────────
 *
 * `POST /api/mercado-pago/preference` corre DE PUNTA A PUNTA (`TransporteDelSdkSinRed` reemplaza el
 * curl del SDK: nada sale a internet) y los tests leen el cuerpo EXACTO que el SDK le habria
 * mandado a `POST /checkout/preferences`. Eso fija dos cosas que por reflexion no se ven: la forma
 * real de `payment_methods` en el JSON (objeto, no lista) y que los 422 corten ANTES de crear la
 * preferencia.
 *
 * ── Que fija esta clase ───────────────────────────────────────────────────────────────────────
 *
 *  1. Sin `contado` ni `cuotas` la preferencia sale EXACTAMENTE como antes: sin `payment_methods`
 *     y con los mismos items. Es lo que sigue pasando con un SPA viejo.
 *  2. `contado: true` excluye la tarjeta de credito y no recarga nada.
 *  3. `cuotas: N` agrega el item «Recargo por pago en N cuotas» y restringe los medios.
 *  4. Dos opciones a la vez, o una cantidad que el servidor no ofrece, es 422 y NO se crea la
 *     preferencia.
 *  5. 🔴 El porcentaje sale SIEMPRE de la base: nada del body lo cambia.
 *  6. 🔴 El invariante de plata: la suma de los items de la preferencia es `cart.total` en el
 *     camino de siempre (lo clava `PrecioDeLaPreferenciaSaleDelCarritoTest`) y `cart.total` mas el
 *     recargo en el camino con cuotas.
 *  7. Un metodo que no ofrece cuotas (sin vinculo, sin filas, sin la columna) ignora los campos.
 */
class PreferenciaConCuotasTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCuotasMercadoPago;

    /** Los tipos que se excluyen cuando el comprador elige «N cuotas». `account_money` NO esta. */
    const EXCLUIDOS_EN_CUOTAS = [
        ['id' => 'debit_card'],
        ['id' => 'prepaid_card'],
        ['id' => 'ticket'],
        ['id' => 'atm'],
        ['id' => 'bank_transfer'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->armarCuotas(1000.0);
        TransporteDelSdkSinRed::instalar();
    }

    protected function tearDown(): void
    {
        TransporteDelSdkSinRed::desinstalar();
        $this->olvidarCuotas();

        parent::tearDown();
    }

    /**
     * El carrito de 2 unidades del articulo de $1.000: $2.000, que es la base de casi todos los
     * numeros de esta clase. Falla si el escenario no es el que los numeros suponen.
     *
     * @return \App\Cart
     */
    private function carritoDeDosMil()
    {
        $cart = $this->carritoPorElEndpoint(2);

        $this->assertSame(2000.0, (float) $cart->total, 'El escenario arranca con $2.000 en el carrito.');

        return $cart;
    }

    /**
     * La unica preferencia que el SDK creo en este test, ya decodificada.
     *
     * @return array
     */
    private function preferenciaEnviada()
    {
        $creadas = TransporteDelSdkSinRed::preferenciasCreadas();

        $this->assertCount(1, $creadas, 'El caso tenia que crear exactamente una preferencia.');

        return $creadas[0];
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 1. Sin los campos: como hoy
    |---------------------------------------------------------------------------------------------
    */

    public function test_sin_contado_ni_cuotas_la_preferencia_sale_como_hoy()
    {
        $this->cuotasDelMetodo([[3, 10], [6, 20]]);
        $cart = $this->carritoDeDosMil();

        $respuesta = $this->pedirPreferencia($cart);

        $respuesta->assertStatus(201)->assertJson(['preference_id' => 'PREFERENCIA-DE-PRUEBA']);

        $preferencia = $this->preferenciaEnviada();

        $this->assertArrayNotHasKey('payment_methods', $preferencia, 'Sin pedir nada, Mercado Pago ofrece lo que ofrecia siempre.');
        $this->assertCount(1, $preferencia['items']);
        $this->assertSame(2000.0, $this->totalDeLaPreferencia($preferencia), 'EL INVARIANTE: la preferencia cobra lo mismo que cart.total.');
    }

    public function test_los_campos_en_null_o_vacios_cuentan_como_no_enviados()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();

        $this->pedirPreferencia($cart, ['contado' => false, 'cuotas' => null])->assertStatus(201);

        $preferencia = $this->preferenciaEnviada();

        $this->assertArrayNotHasKey('payment_methods', $preferencia);
        $this->assertSame(2000.0, $this->totalDeLaPreferencia($preferencia));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 2. Contado
    |---------------------------------------------------------------------------------------------
    */

    public function test_contado_excluye_la_tarjeta_de_credito_y_no_recarga()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();

        $this->pedirPreferencia($cart, ['contado' => true])->assertStatus(201);

        $preferencia = $this->preferenciaEnviada();

        $this->assertSame(
            ['excluded_payment_types' => [['id' => 'credit_card']]],
            $preferencia['payment_methods']
        );
        $this->assertCount(1, $preferencia['items'], 'Contado no agrega ningun item.');
        $this->assertSame(2000.0, $this->totalDeLaPreferencia($preferencia), 'Contado: el mismo precio del carrito.');
    }

    /**
     * 🔴 La forma del JSON, que es lo que el SDK de PHP no deja ver por reflexion: `payment_methods`
     * tiene que salir como OBJETO y `excluded_payment_types` como lista de objetos `{"id": ...}`,
     * que es lo que documenta Mercado Pago. Un array PHP asociativo vacio saldria como `[]`; uno con
     * claves sale como objeto.
     */
    public function test_payment_methods_viaja_como_objeto_y_los_tipos_excluidos_como_lista_de_objetos()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();

        $this->pedirPreferencia($cart, ['contado' => true])->assertStatus(201);

        $this->assertStringContainsString(
            '"payment_methods":{"excluded_payment_types":[{"id":"credit_card"}]}',
            TransporteDelSdkSinRed::cuerpoCrudoDeLaPreferencia()
        );
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 3. N cuotas
    |---------------------------------------------------------------------------------------------
    */

    public function test_en_n_cuotas_suma_el_item_de_recargo_y_restringe_los_medios()
    {
        $this->cuotasDelMetodo([[3, 10], [6, 20]]);
        $cart = $this->carritoDeDosMil();

        $this->pedirPreferencia($cart, ['cuotas' => 3])->assertStatus(201);

        $preferencia = $this->preferenciaEnviada();

        $this->assertCount(2, $preferencia['items'], 'El articulo y el recargo.');

        $recargo = $preferencia['items'][1];
        $this->assertSame('Recargo por pago en 3 cuotas', $recargo['title']);
        $this->assertSame(1, $recargo['quantity']);
        $this->assertSame(200.0, (float) $recargo['unit_price'], '10% de $2.000.');

        $this->assertSame(
            ['installments' => 3, 'excluded_payment_types' => self::EXCLUIDOS_EN_CUOTAS],
            $preferencia['payment_methods']
        );

        $this->assertSame(
            2200.0,
            $this->totalDeLaPreferencia($preferencia),
            'EL INVARIANTE CON CUOTAS: la preferencia cobra cart.total mas el recargo.'
        );
    }

    public function test_el_json_de_las_cuotas_lleva_installments_y_los_cinco_tipos_excluidos_sin_dinero_en_cuenta()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();

        $this->pedirPreferencia($cart, ['cuotas' => 3])->assertStatus(201);

        $crudo = TransporteDelSdkSinRed::cuerpoCrudoDeLaPreferencia();

        $this->assertStringContainsString(
            '"payment_methods":{"installments":3,"excluded_payment_types":[{"id":"debit_card"},{"id":"prepaid_card"},{"id":"ticket"},{"id":"atm"},{"id":"bank_transfer"}]}',
            $crudo
        );
        $this->assertStringNotContainsString('account_money', $crudo, 'Mercado Pago no deja excluir el dinero en cuenta.');
    }

    public function test_la_cantidad_de_cuotas_se_acepta_como_texto_o_como_decimal_entero()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();

        foreach (['3', 3.0] as $valor) {
            TransporteDelSdkSinRed::instalar();

            $this->pedirPreferencia($cart, ['cuotas' => $valor])->assertStatus(201);

            $preferencia = $this->preferenciaEnviada();

            $this->assertSame(3, $preferencia['payment_methods']['installments']);
            $this->assertSame(2200.0, $this->totalDeLaPreferencia($preferencia));
        }
    }

    public function test_cuotas_sin_recargo_no_agregan_item_pero_si_restringen_los_medios()
    {
        $this->cuotasDelMetodo([[3, null]]);
        $cart = $this->carritoDeDosMil();

        $this->pedirPreferencia($cart, ['cuotas' => 3])->assertStatus(201);

        $preferencia = $this->preferenciaEnviada();

        $this->assertCount(1, $preferencia['items'], 'Un item de $0 no se manda: Mercado Pago lo rechaza.');
        $this->assertSame(3, $preferencia['payment_methods']['installments']);
        $this->assertSame(2000.0, $this->totalDeLaPreferencia($preferencia));
    }

    public function test_el_recargo_se_calcula_sobre_todo_lo_que_se_cobra_incluido_el_envio()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        $zona = new DeliveryZone();
        $zona->name = 'Centro';
        $zona->price = 500;
        $zona->user_id = $this->comercio->id;
        $zona->save();

        $cart = $this->carritoPorElEndpoint(2, ['delivery_zone_id' => $zona->id]);

        $this->pedirPreferencia($cart, ['cuotas' => 3])->assertStatus(201);

        $preferencia = $this->preferenciaEnviada();

        $nombres = array_column($preferencia['items'], 'title');
        $this->assertContains('Envio', $nombres, 'El envio por zona propia es parte de lo que se cobra.');

        $recargo = collect($preferencia['items'])->firstWhere('title', 'Recargo por pago en 3 cuotas');
        $this->assertNotNull($recargo);
        $this->assertSame(250.0, (float) $recargo['unit_price'], '10% de $2.500 ($2.000 de articulos + $500 de envio).');
        $this->assertSame(2750.0, $this->totalDeLaPreferencia($preferencia));
    }

    public function test_el_recargo_se_redondea_a_dos_decimales()
    {
        $this->cuotasDelMetodo([[3, 3.33]]);
        $cart = $this->carritoDeDosMil();

        $this->pedirPreferencia($cart, ['cuotas' => 3])->assertStatus(201);

        $recargo = $this->preferenciaEnviada()['items'][1];

        $this->assertSame(66.6, (float) $recargo['unit_price'], '3,33% de $2.000 = 66,60.');
    }

    public function test_el_recargo_neto_descuenta_el_descuento_de_la_fila()
    {
        $this->cuota($this->comercio, $this->metodo_cc_id, 3, 15, 5);
        $cart = $this->carritoDeDosMil();

        $this->pedirPreferencia($cart, ['cuotas' => 3])->assertStatus(201);

        $this->assertSame(200.0, (float) $this->preferenciaEnviada()['items'][1]['unit_price'], '15% - 5% = 10% de $2.000.');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 4. Los 422
    |---------------------------------------------------------------------------------------------
    */

    public function test_contado_y_cuotas_a_la_vez_es_422_y_no_crea_la_preferencia()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();

        $respuesta = $this->pedirPreferencia($cart, ['contado' => true, 'cuotas' => 3]);

        $respuesta->assertStatus(422);
        $this->assertNotEmpty($respuesta->json('message'));
        $this->assertSame([], TransporteDelSdkSinRed::preferenciasCreadas(), 'Un 422 no puede haber creado la preferencia en Mercado Pago.');
        $this->assertSame([], TransporteDelSdkSinRed::$pedidos, 'Un 422 de cuotas no gasta ni el GET /users/me del SDK.');
    }

    #[DataProvider('cuotasQueElServidorNoOfrece')]
    public function test_una_cantidad_que_el_servidor_no_ofrece_es_422_y_no_crea_la_preferencia($cuotas)
    {
        $this->cuotasDelMetodo([[3, 10], [6, 20]]);
        $cart = $this->carritoDeDosMil();

        $respuesta = $this->pedirPreferencia($cart, ['cuotas' => $cuotas]);

        $respuesta->assertStatus(422);
        $this->assertNotEmpty($respuesta->json('message'));
        $this->assertSame([], TransporteDelSdkSinRed::preferenciasCreadas());
        $this->assertSame([], TransporteDelSdkSinRed::$pedidos, 'Un 422 de cuotas no gasta ni el GET /users/me del SDK.');
    }

    public static function cuotasQueElServidorNoOfrece()
    {
        return [
            'una cantidad no cargada'     => [4],
            'cero'                        => [0],
            'negativa'                    => [-3],
            'texto'                       => ['abc'],
            'decimal no entero'           => [2.5],
            'una lista'                   => [[3]],
            'un booleano'                 => [true],
        ];
    }

    public function test_una_fila_de_neto_negativo_tampoco_se_puede_pedir()
    {
        $this->cuota($this->comercio, $this->metodo_cc_id, 3, 5, 15);
        $this->cuota($this->comercio, $this->metodo_cc_id, 6, 10);
        $cart = $this->carritoDeDosMil();

        $this->pedirPreferencia($cart, ['cuotas' => 3])->assertStatus(422);
        $this->assertSame([], TransporteDelSdkSinRed::preferenciasCreadas());
    }

    public function test_pedir_cuotas_de_otro_comercio_es_422()
    {
        $otro = $this->comercioConTienda();
        $this->cuota($otro, $this->metodo_cc_id, 12, 40);
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();

        $this->pedirPreferencia($cart, ['cuotas' => 12])->assertStatus(422);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 5. El porcentaje sale de la base
    |---------------------------------------------------------------------------------------------
    */

    public function test_el_porcentaje_nunca_sale_del_body()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();

        $this->pedirPreferencia($cart, [
            'cuotas'         => 3,
            'recargo'        => 99,
            'cuotas_recargo' => 99,
            'porcentaje'     => 99,
            'payment_method' => [
                'id'      => $this->payment_method->id,
                'user_id' => $this->payment_method->user_id,
                'surchage' => 99,
                'recargo'  => 99,
            ],
        ])->assertStatus(201);

        $this->assertSame(200.0, (float) $this->preferenciaEnviada()['items'][1]['unit_price'], 'Se cobra el 10% de la base, no el 99% del body.');
    }

    public function test_sin_carrito_resuelto_el_recargo_se_calcula_igual_con_el_porcentaje_de_la_base()
    {
        $this->cuotasDelMetodo([[3, 10]]);

        $this->postJson('/api/mercado-pago/preference', [
            'payment_method' => ['id' => $this->payment_method->id, 'user_id' => $this->comercio->id],
            'articles'       => [['name' => 'Articulo suelto', 'amount' => 1, 'final_price' => 1000]],
            'cuotas'         => 3,
        ])->assertStatus(201);

        $preferencia = $this->preferenciaEnviada();

        $this->assertSame(100.0, (float) $preferencia['items'][1]['unit_price']);
        $this->assertSame(1100.0, $this->totalDeLaPreferencia($preferencia));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | 7. Un metodo que no ofrece cuotas ignora los campos
    |---------------------------------------------------------------------------------------------
    */

    public function test_un_metodo_sin_vinculo_ignora_contado_y_cuotas_aunque_vengan_las_dos()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $sin_vinculo = $this->metodoMercadoPago($this->comercio, null);
        $cart = $this->carritoPorElEndpoint(2, ['payment_method_id' => $sin_vinculo->id]);

        $this->pedirPreferencia($cart, ['contado' => true, 'cuotas' => 3], $sin_vinculo)->assertStatus(201);

        $preferencia = $this->preferenciaEnviada();

        $this->assertArrayNotHasKey('payment_methods', $preferencia);
        $this->assertCount(1, $preferencia['items']);
        $this->assertSame(2000.0, $this->totalDeLaPreferencia($preferencia));
    }

    public function test_un_metodo_vinculado_sin_cuotas_cargadas_ignora_los_campos()
    {
        $cart = $this->carritoDeDosMil();

        $this->pedirPreferencia($cart, ['cuotas' => 3])->assertStatus(201);

        $preferencia = $this->preferenciaEnviada();

        $this->assertArrayNotHasKey('payment_methods', $preferencia);
        $this->assertSame(2000.0, $this->totalDeLaPreferencia($preferencia));
    }

    public function test_sin_la_columna_del_vinculo_la_preferencia_sale_como_hoy()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();

        CuotasMercadoPagoHelper::forzar_esquema(['vinculo' => false]);

        $this->pedirPreferencia($cart, ['cuotas' => 3])->assertStatus(201);

        $preferencia = $this->preferenciaEnviada();

        $this->assertArrayNotHasKey('payment_methods', $preferencia);
        $this->assertSame(2000.0, $this->totalDeLaPreferencia($preferencia));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El metodo de pago tiene que ser del comercio del carrito
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Un metodo de Mercado Pago VINCULADO de otro comercio, con cuotas cargadas y un recargo
     * distinto (la lista publica de metodos de pago lo muestra a cualquiera).
     *
     * @return \App\PaymentMethod
     */
    private function metodoDeOtroComercioConCuotas()
    {
        $otro = $this->comercioConTienda();
        $metodo = $this->metodoMercadoPago($otro, $this->metodo_cc_id);
        $this->cuota($otro, $this->metodo_cc_id, 3, 50);

        return $metodo;
    }

    /**
     * 🔴 EL HUECO DE LA PLATA: el carrito es del comercio A, el body manda el id del metodo
     * vinculado del comercio B. Sin el cruce se cobraba el recargo de B (50%) sobre el carrito de A.
     */
    public function test_cuotas_con_el_metodo_de_otro_comercio_es_422_y_no_gasta_red()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();
        $ajeno = $this->metodoDeOtroComercioConCuotas();

        $respuesta = $this->pedirPreferencia($cart, ['cuotas' => 3], $ajeno);

        $respuesta->assertStatus(422);
        $this->assertNotEmpty($respuesta->json('message'));
        $this->assertSame([], TransporteDelSdkSinRed::$pedidos, 'Ni la preferencia ni el GET /users/me.');
    }

    public function test_contado_con_el_metodo_de_otro_comercio_tambien_es_422()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();
        $ajeno = $this->metodoDeOtroComercioConCuotas();

        $this->pedirPreferencia($cart, ['contado' => true], $ajeno)->assertStatus(422);
        $this->assertSame([], TransporteDelSdkSinRed::$pedidos);
    }

    /**
     * El camino de siempre NO se toca: sin `contado` ni `cuotas` el cruce no corre y la preferencia
     * sale como antes (es un agujero preexistente y distinto, que esta mision no abre ni cierra).
     */
    public function test_sin_contado_ni_cuotas_el_cruce_de_comercios_no_corre()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();
        $ajeno = $this->metodoDeOtroComercioConCuotas();

        $this->pedirPreferencia($cart, [], $ajeno)->assertStatus(201);

        $this->assertArrayNotHasKey('payment_methods', $this->preferenciaEnviada());
    }

    /**
     * El cruce es por COMERCIO, no por `carts.payment_method_id`: el carrito del servidor puede no
     * tener todavia guardado el metodo cuando se pide la preferencia, y eso no puede romper el flujo.
     */
    public function test_un_carrito_sin_el_metodo_guardado_igual_puede_pedir_cuotas_con_el_metodo_de_su_comercio()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoPorElEndpoint(2, [], false);

        $this->assertNull($cart->payment_method_id, 'El escenario: el carrito todavia no tiene metodo de pago.');

        $this->pedirPreferencia($cart, ['cuotas' => 3])->assertStatus(201);

        $preferencia = $this->preferenciaEnviada();

        $this->assertSame(200.0, (float) $preferencia['items'][1]['unit_price']);
        $this->assertSame(3, $preferencia['payment_methods']['installments']);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El orden: el 422 de cuotas va antes del SDK
    |---------------------------------------------------------------------------------------------
    */

    /**
     * El control de los `assertSame([], TransporteDelSdkSinRed::$pedidos)` de los 422: el camino
     * feliz SI hace los dos pedidos (el GET /users/me del SDK y el POST de la preferencia). Sin este
     * caso, "no hubo pedidos" podria ser que el doble no anota nada.
     */
    public function test_el_caso_feliz_si_pasa_por_el_sdk_y_el_422_no()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();

        $this->pedirPreferencia($cart, ['cuotas' => 3])->assertStatus(201);

        $this->assertCount(2, TransporteDelSdkSinRed::$pedidos);
        $this->assertSame('GET', TransporteDelSdkSinRed::$pedidos[0]['verbo']);
        $this->assertSame('POST', TransporteDelSdkSinRed::$pedidos[1]['verbo']);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Texto del item
    |---------------------------------------------------------------------------------------------
    */

    public function test_el_item_de_una_sola_cuota_dice_cuota_en_singular()
    {
        $this->cuotasDelMetodo([[1, 10], [3, 10]]);

        foreach ([1 => 'Recargo por pago en 1 cuota', 3 => 'Recargo por pago en 3 cuotas'] as $cantidad => $titulo) {
            $cart = $this->carritoDeDosMil();

            TransporteDelSdkSinRed::instalar();

            $this->pedirPreferencia($cart, ['cuotas' => $cantidad])->assertStatus(201);

            $this->assertSame($titulo, $this->preferenciaEnviada()['items'][1]['title']);
        }
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Una falla al leer las cuotas
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 La feature opcional NUNCA rompe el checkout de hoy: sin `contado` ni `cuotas` ni siquiera
     * se lee la tabla de cuotas, asi que una falla de la base ahi no puede tocar la preferencia.
     */
    public function test_sin_pedir_cuotas_ni_se_lee_la_tabla_y_la_preferencia_sale_aunque_la_lectura_fallaria()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();

        $this->fallarLasLecturasDeCuotas();

        $this->pedirPreferencia($cart)->assertStatus(201);

        $this->assertSame(0, $this->lecturas_de_cuotas, 'El camino de siempre no lee cuotas.');
        $this->assertArrayNotHasKey('payment_methods', $this->preferenciaEnviada());
        $this->assertSame(2000.0, $this->totalDeLaPreferencia($this->preferenciaEnviada()));
    }

    /**
     * Si el comprador PIDIO cuotas y la lectura falla, es un 422 claro: ignorarlo cobraria el
     * precio sin recargo en una compra que el comprador vio en cuotas.
     */
    public function test_si_la_lectura_falla_pidiendo_cuotas_es_422_y_no_cobra_de_menos()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();

        $this->fallarLasLecturasDeCuotas();
        Log::spy();

        $respuesta = $this->pedirPreferencia($cart, ['cuotas' => 3]);

        $respuesta->assertStatus(422);
        $this->assertNotEmpty($respuesta->json('message'));
        $this->assertSame(1, $this->lecturas_de_cuotas, 'Una sola lectura: la lista ya leida se reusa.');
        $this->assertSame([], TransporteDelSdkSinRed::$pedidos);

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'no se pudieron leer las cuotas') !== false
                && (int) $contexto['commerce_id'] === (int) $this->comercio->id
                && strpos($contexto['mensaje'], 'falla simulada') !== false
                && !array_key_exists('access_token', $contexto);
        })->once();
    }

    public function test_se_lee_la_tabla_una_sola_vez_por_preferencia_con_cuotas()
    {
        $this->cuotasDelMetodo([[3, 10]]);
        $cart = $this->carritoDeDosMil();

        $this->contarLasLecturasDeCuotas();

        $this->pedirPreferencia($cart, ['cuotas' => 3])->assertStatus(201);

        $this->assertSame(1, $this->lecturas_de_cuotas);
    }
}
