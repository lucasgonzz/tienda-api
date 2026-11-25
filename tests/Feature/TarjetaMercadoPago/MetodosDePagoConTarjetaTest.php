<?php

namespace Tests\Feature\TarjetaMercadoPago;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La lista publica de metodos de pago con el metodo de tarjeta (mision `tienda-cobro-tarjeta`,
 * 24/11/2026): CA13 del PLAN.
 *
 * `GET /api/payment-methods/{commerce_id}` es PUBLICA: lo unico sensible de `payment_methods` es
 * `access_token`, que nunca viaja. La fila de tarjeta lleva la `public_key` de la cuenta con la que
 * se cobra (el Brick tokeniza en el navegador con ella) y sus `cuotas_disponibles`. Si el comercio no
 * tiene credencial, la fila de tarjeta NO se publica: una opcion muerta en el checkout es peor que
 * ninguna. Las demas filas no cambian.
 */
class MetodosDePagoConTarjetaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaCobroConTarjeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armarCobroConTarjeta();
    }

    protected function tearDown(): void
    {
        $this->olvidarCuotas();

        parent::tearDown();
    }

    /**
     * La fila de tarjeta dentro de la lista publicada, o null.
     *
     * @param array $metodos
     * @return array|null
     */
    private function filaDeTarjeta(array $metodos)
    {
        foreach ($metodos as $fila) {
            if (isset($fila['type']['name']) && $fila['type']['name'] === 'Tarjeta Mercado Pago') {
                return $fila;
            }
        }

        return null;
    }

    /**
     * CA13: con credencial, la fila trae public_key y cuotas, y ningun token.
     */
    public function test_la_fila_de_tarjeta_publica_public_key_y_cuotas_sin_token()
    {
        $respuesta = $this->getJson('/api/payment-methods/'.$this->comercio->id)->assertStatus(200);

        $fila = $this->filaDeTarjeta($respuesta->json('payment_methods'));

        $this->assertNotNull($fila, 'La fila de tipo Tarjeta Mercado Pago tiene que publicarse.');
        $this->assertSame($this->metodo_tarjeta->id, (int) $fila['id']);
        $this->assertSame('TEST-PK-C', $fila['public_key']);
        $this->assertSame([
            ['cantidad_cuotas' => 3, 'recargo' => '10.00'],
            ['cantidad_cuotas' => 6, 'recargo' => '20.00'],
        ], $fila['cuotas_disponibles']);
        $this->assertArrayNotHasKey('access_token', $fila);

        $this->assertStringNotContainsString('TEST-AT-C', $respuesta->getContent(), 'Ninguna fila puede llevar el access_token.');
    }

    /**
     * CA13: sin access_token no hay con que cobrar -> la fila de tarjeta no aparece; las otras si.
     */
    public function test_sin_credencial_la_fila_de_tarjeta_no_se_publica()
    {
        $this->metodo_mp->access_token = null;
        $this->metodo_mp->public_key   = null;
        $this->metodo_mp->save();

        $respuesta = $this->getJson('/api/payment-methods/'.$this->comercio->id)->assertStatus(200);

        $metodos = $respuesta->json('payment_methods');

        $this->assertNull($this->filaDeTarjeta($metodos), 'Sin credencial no se ofrece la tarjeta.');

        $ids = array_map(function ($fila) {
            return (int) $fila['id'];
        }, $metodos);

        $this->assertContains($this->metodo_mp->id, $ids);
        $this->assertContains($this->metodo_transferencia->id, $ids);
        $this->assertContains($this->metodo_efectivo->id, $ids);
        $this->assertNotContains($this->metodo_tarjeta->id, $ids);
    }
}
