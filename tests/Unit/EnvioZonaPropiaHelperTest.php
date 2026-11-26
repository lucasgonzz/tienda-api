<?php

namespace Tests\Unit;

use App\DeliveryZone;
use App\Http\Controllers\Helpers\DireccionesEsquemaHelper;
use App\Http\Controllers\Helpers\EnvioZonaPropiaHelper;
use Tests\TestCase;

/**
 * La regla de "envío gratis" de una forma de envío propia (misión truvari-carrito-entrega-pago,
 * 26/11/2026), criterio de aceptación CA3.
 *
 * `EnvioZonaPropiaHelper::resolver($zona, $subtotal, $tiene_combo)` es una función pura: dada la
 * zona (modelo o array), el subtotal del carrito (`carts.total`: artículos + promociones + combos,
 * sin envío ni recargos) y si el carrito tiene un combo o promoción, devuelve
 * `{precio, gratis, motivo, a_convenir}`. La misma tabla de casos, copiada literal, la prueba el
 * espejo JS de tienda-spa (CA14): si una cambia, cambia la otra.
 *
 * Orden de la regla (PLAN §2):
 *   1. a convenir            -> precio 0, gratis false, motivo null, a_convenir true
 *   2. desde > 0 y subtotal >= desde -> 0, gratis, 'monto'   (el `>=` es el de Zipnova)
 *   3. casilla de combos y carrito con combo -> 0, gratis, 'combo'
 *   4. si no                 -> el precio de la zona
 * Si aplican monto y combo a la vez, el motivo es 'monto'.
 */
class EnvioZonaPropiaHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /* "A convenir" se mide contra el esquema de la base del slot: arranca sin memoria. */
        DireccionesEsquemaHelper::olvidar();
    }

    /**
     * La zona de referencia del plan: $7.000, gratis desde $80.000 y con combos.
     *
     * @param array $cambios Claves que pisan (o `null` en el valor para sacarla).
     * @return array
     */
    private static function zona(array $cambios = [])
    {
        $zona = array_merge([
            'id'                     => 1,
            'name'                   => 'Envío Truvari',
            'price'                  => 7000,
            'costo_a_convenir'       => 0,
            'envio_gratis_desde'     => 80000,
            'envio_gratis_con_combo' => 1,
        ], $cambios);

        return $zona;
    }

    /**
     * Tabla de casos de CA3: [zona, subtotal, tiene_combo, precio, gratis, motivo, a_convenir].
     *
     * @return array
     */
    public static function tablaDeCasos()
    {
        /* Zona sin las claves nuevas (base vieja): se arma a mano, sin las dos columnas. */
        $sin_claves = ['id' => 1, 'name' => 'Envío Truvari', 'price' => 7000, 'costo_a_convenir' => 0];

        return [
            '90000 sin combo, pasa el mínimo'                  => [self::zona(), 90000, false, 0, true, 'monto', false],
            '80000 justos es gratis (>=)'                      => [self::zona(), 80000, false, 0, true, 'monto', false],
            '79999.99 no llega'                                => [self::zona(), 79999.99, false, 7000, false, null, false],
            '35000 con combo y casilla'                        => [self::zona(), 35000, true, 0, true, 'combo', false],
            '35000 con combo y casilla apagada'                => [self::zona(['envio_gratis_con_combo' => 0]), 35000, true, 7000, false, null, false],
            '35000 sin combo con casilla: cobra'               => [self::zona(), 35000, false, 7000, false, null, false],
            '90000 sin mínimo y sin casilla'                   => [self::zona(['envio_gratis_desde' => null, 'envio_gratis_con_combo' => 0]), 90000, false, 7000, false, null, false],
            'desde 0 es "nunca gratis por monto"'              => [self::zona(['envio_gratis_desde' => 0, 'envio_gratis_con_combo' => 0]), 90000, false, 7000, false, null, false],
            '90000 con combo: gana el monto'                   => [self::zona(), 90000, true, 0, true, 'monto', false],
            'a convenir con 90000'                             => [self::zona(['costo_a_convenir' => 1]), 90000, false, 0, false, null, true],
            'a convenir con combo'                             => [self::zona(['costo_a_convenir' => 1]), 35000, true, 0, false, null, true],
            'zona sin las claves nuevas con 90000'             => [$sin_claves, 90000, true, 7000, false, null, false],
            'strings: desde "80000.00" y casilla "1" pasan'    => [self::zona(['envio_gratis_desde' => '80000.00', 'envio_gratis_con_combo' => '1']), 90000, false, 0, true, 'monto', false],
            'strings: no llega con "80000.00"'                 => [self::zona(['envio_gratis_desde' => '80000.00', 'envio_gratis_con_combo' => '1']), 79999.99, false, 7000, false, null, false],
            'strings: combo con casilla "1"'                   => [self::zona(['envio_gratis_desde' => '80000.00', 'envio_gratis_con_combo' => '1']), 35000, true, 0, true, 'combo', false],
            'strings: casilla "0" no da combo'                 => [self::zona(['envio_gratis_desde' => '80000.00', 'envio_gratis_con_combo' => '0']), 35000, true, 7000, false, null, false],
            'precio como string "7000.00"'                     => [self::zona(['price' => '7000.00']), 50000, false, 7000, false, null, false],
        ];
    }

    /**
     * CA3: la regla da lo mismo para cada fila de la tabla.
     *
     * @dataProvider tablaDeCasos
     * @return void
     */
    public function test_tabla_de_casos_de_la_regla($zona, $subtotal, $tiene_combo, $precio, $gratis, $motivo, $a_convenir)
    {
        $resultado = EnvioZonaPropiaHelper::resolver($zona, $subtotal, $tiene_combo);

        $this->assertEquals($precio, $resultado['precio'], 'precio');
        $this->assertSame($gratis, $resultado['gratis'], 'gratis');
        $this->assertSame($motivo, $resultado['motivo'], 'motivo');
        $this->assertSame($a_convenir, $resultado['a_convenir'], 'a_convenir');
    }

    /**
     * El modelo `DeliveryZone` (lo que tiene `$cart->delivery_zone`) da lo mismo que el array.
     *
     * @return void
     */
    public function test_acepta_el_modelo_igual_que_el_array()
    {
        $modelo = new DeliveryZone();
        $modelo->name = 'Envío Truvari';
        $modelo->price = 7000;
        $modelo->costo_a_convenir = 0;
        $modelo->envio_gratis_desde = 80000;
        $modelo->envio_gratis_con_combo = 1;

        $gratis = EnvioZonaPropiaHelper::resolver($modelo, 90000, false);
        $this->assertTrue($gratis['gratis']);
        $this->assertSame('monto', $gratis['motivo']);
        $this->assertEquals(0, $gratis['precio']);

        $combo = EnvioZonaPropiaHelper::resolver($modelo, 35000, true);
        $this->assertSame('combo', $combo['motivo']);

        $cobra = EnvioZonaPropiaHelper::resolver($modelo, 35000, false);
        $this->assertFalse($cobra['gratis']);
        $this->assertEquals(7000, $cobra['precio']);
    }
}
