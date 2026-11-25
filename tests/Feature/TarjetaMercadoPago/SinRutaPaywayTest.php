<?php

namespace Tests\Feature\TarjetaMercadoPago;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * La ruta insegura de Payway ya no existe (mision `tienda-cobro-tarjeta`, 24/11/2026): CA14.
 *
 * `POST /api/payway/token` recibia numero de tarjeta, vencimiento, CVV y DNI crudos en el body y
 * los mandaba a un tercero con un nombre de establecimiento escrito en el codigo. Ningun consumidor
 * la usaba (Payway.vue tokeniza en el navegador y le pega a `/payment-card-info`). El pedido de
 * Lucas (regla PCI): los datos de la tarjeta nunca pasan por nuestro servidor.
 *
 * No usa base de datos: solo el router.
 */
class SinRutaPaywayTest extends TestCase
{
    /**
     * @param string $metodo
     * @param string $uri
     * @return bool True si el router tiene una ruta que atienda ese metodo y esa URI.
     */
    private function hayRuta($metodo, $uri)
    {
        try {
            app('router')->getRoutes()->match(Request::create($uri, $metodo));

            return true;
        } catch (NotFoundHttpException $e) {
            return false;
        } catch (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e) {
            return false;
        }
    }

    /**
     * CA14: la ruta no esta registrada y, con datos de tarjeta en el body, la API contesta 404.
     */
    public function test_la_ruta_payway_token_ya_no_existe()
    {
        // Primero se mira el router: si la ruta siguiera, el POST de abajo ejecutaria el controller.
        $this->assertFalse($this->hayRuta('POST', '/api/payway/token'), 'POST /api/payway/token sigue registrada.');

        $this->postJson('/api/payway/token', [
            'card_number'     => '4509953566233704',
            'security_code'   => '123',
            'expiration_month' => '12',
            'expiration_year' => '30',
            'holder_name'     => 'COMPRADOR DE PRUEBA',
            'dni'             => '30123456',
        ])->assertStatus(404);
    }

    /**
     * CA14: el controller se borro, y los otros endpoints de pago siguen registrados (no se toco
     * nada mas: Payway.vue sigue usando `/payment-card-info`).
     */
    public function test_el_controller_payway_no_existe()
    {
        $this->assertFalse(class_exists('App\Http\Controllers\PaywayController'), 'PaywayController sigue existiendo.');

        $this->assertTrue($this->hayRuta('GET', '/api/payment-methods/1'), 'GET /api/payment-methods/{id} tiene que seguir.');
        $this->assertTrue($this->hayRuta('POST', '/api/payment-card-info'), 'POST /api/payment-card-info tiene que seguir.');
    }
}
