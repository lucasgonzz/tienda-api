<?php

namespace Tests\Feature\CombosCalculados;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La forma del JSON de un combo que recibe `tienda-spa` (mision combos-calculados, 30/9/2026).
 *
 * `tienda-spa` se construye en paralelo contra ESTE contrato, asi que lo que el SPA lee tiene que
 * estar clavado en un test y no solo en un informe: si alguien renombra `stock_disponible` o deja
 * de mandar `images`, la tarjeta del combo se rompe sin un solo error del lado del servidor.
 *
 * Lo que lee el SPA de cada combo (home y carrito):
 *   `id`, `name`, `is_combo`, `final_price` (ya resuelto por lista y con los ajustes de cliente
 *   encima), `stock_disponible` (int o null), `images` (foto propia, lista vacia si no tiene) y
 *   `articles[]` con `name`, `images` y `pivot.amount`.
 *
 * Lo que NO tiene que leer nunca: el costo, ni las instrucciones de calculo del ERP.
 */
class ContratoDelComboConElSpaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCombosCalculados;

    /** Las claves que el SPA necesita en un combo. */
    const CLAVES_DEL_COMBO = [
        'id', 'name', 'is_combo', 'final_price', 'stock_disponible', 'images', 'articles',
    ];

    /** Lo que jamas viaja: el costo y las tres columnas de calculo que crea empresa-api. */
    const CLAVES_PROHIBIDAS = [
        'cost', 'calcular_desde_articulos', 'descuento_tipo', 'descuento_valor',
    ];

    /** @var \App\User */
    private $comercio;

    /** @var \App\Combo */
    private $combo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarTodo();

        /* Si la base no tiene las columnas nuevas, "no viajan" seria un verde vacuo. */
        foreach (['calcular_desde_articulos', 'descuento_tipo', 'descuento_valor'] as $columna) {
            $this->assertTrue(Schema::hasColumn('combos', $columna),
                'La base de testing no tiene combos.'.$columna.': correr la migracion 2026_09_30_130000 de empresa-api contra esta base.');
        }

        $this->comercio = $this->comercioConTienda();
        $this->combo = $this->combo($this->comercio, [
            'calcular_desde_articulos' => 1,
            'descuento_tipo'           => 'porcentaje',
            'descuento_valor'          => 10,
        ]);
        $this->componente($this->combo, $this->articuloConStock($this->comercio, 10), 2);
    }

    protected function tearDown(): void
    {
        $this->olvidarTodo();

        parent::tearDown();
    }

    private function afirmarContrato(array $combo, $donde)
    {
        foreach (self::CLAVES_DEL_COMBO as $clave) {
            $this->assertArrayHasKey($clave, $combo, $donde.': falta la clave "'.$clave.'" que lee el SPA');
        }

        foreach (self::CLAVES_PROHIBIDAS as $clave) {
            $this->assertArrayNotHasKey($clave, $combo, $donde.': "'.$clave.'" no tiene que viajar');
        }

        $this->assertTrue($combo['is_combo']);
        $this->assertIsArray($combo['images']);
        $this->assertIsArray($combo['articles']);
        $this->assertArrayHasKey('images', $combo['articles'][0]);
        $this->assertArrayHasKey('name', $combo['articles'][0]);
        $this->assertEquals(2, $combo['articles'][0]['pivot']['amount']);
    }

    public function test_el_combo_de_la_home_cumple_el_contrato()
    {
        $this->afirmarContrato($this->comboEnLaHome($this->comercio, $this->combo), 'home');
    }

    public function test_el_combo_del_carrito_cumple_el_contrato()
    {
        $creado = $this->crearCarrito($this->comercio, [
            'combos' => [$this->lineaDeComboDelPayload($this->combo, 3)],
        ]);
        $creado->assertStatus(201);

        $combo = $creado->json('cart.combos.0');

        $this->afirmarContrato($combo, 'carrito');
        $this->assertEquals(3, $combo['pivot']['amount']);
        $this->assertEquals(self::PRECIO_COMBO, $combo['pivot']['price']);
    }

    /**
     * El descuento del combo ya esta aplicado en el precio que calcula el ERP: la tienda cobra
     * `combos.price` tal cual, sin volver a aplicarlo ni siquiera con las columnas presentes.
     */
    public function test_la_tienda_no_vuelve_a_aplicar_el_descuento_del_combo()
    {
        $this->assertEquals(self::PRECIO_COMBO, $this->comboEnLaHome($this->comercio, $this->combo)['final_price']);
    }
}
