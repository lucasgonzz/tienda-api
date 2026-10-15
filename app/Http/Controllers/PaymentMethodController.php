<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\CuotasMercadoPagoHelper;
use App\Http\Controllers\Helpers\MercadoPagoCredentialsHelper;
use App\PaymentMethod;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{

    /**
     * Metodos de pago del comercio, tal como los pinta el checkout de la tienda.
     *
     * 🔴 RUTA PUBLICA, SIN AUTH (`GET /api/payment-methods/{commerce_id}`). Lo unico sensible que
     * tiene `payment_methods` es `access_token`, y esta en el $hidden del modelo: ninguna
     * respuesta de este endpoint puede llevar un token. La `public_key` SI viaja, y tiene que
     * viajar: el SPA la necesita para inicializar el brick de Mercado Pago en el navegador del
     * comprador (`CardPaymentMethod.initMp()`), y Mercado Pago la publica por diseño.
     *
     * @param int $commerce_id Comercio (owner) del que se piden los metodos de pago.
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($commerce_id) {
        $payment_methods = PaymentMethod::where('user_id', $commerce_id)
                                        ->with('type')
                                        ->with('payment_method_installments')
                                        ->get();

        $this->publicar_public_key_vigente_de_mercado_pago($payment_methods, $commerce_id);

        $this->publicar_cuotas_disponibles($payment_methods);

        return response()->json(['payment_methods' => $payment_methods], 200);
    }

    /**
     * Pone en las filas de tipo "MercadoPago" el campo `cuotas_disponibles`: las cuotas que el
     * comerciante cargo en el ERP para el metodo de cuenta corriente al que vinculo ese metodo
     * (mision `tienda-cuotas-mercado-pago`, 15/10/2026).
     *
     * Forma: `[{"cantidad_cuotas": 3, "recargo": "10.00"}, ...]`, ordenada por cantidad de cuotas y
     * con el recargo ya NETO (recargo menos descuento, en porcentaje). Los metodos de otro tipo no
     * ganan el campo. En un Mercado Pago sin vinculo, sin cuotas cargadas o en una base sin la
     * columna del vinculo viaja `[]`: el SPA lo lee como "este metodo no ofrece cuotas" y el
     * checkout queda como siempre.
     *
     * 🔴 Esta ruta es PUBLICA: el campo solo lleva cantidad de cuotas y porcentaje, que es lo que
     * el comprador va a ver en pantalla. Solo cambia lo que se serializa; no se guarda nada.
     *
     * @param \Illuminate\Database\Eloquent\Collection $payment_methods Filas ya cargadas con `type`.
     * @return void
     */
    protected function publicar_cuotas_disponibles($payment_methods)
    {
        foreach ($payment_methods as $payment_method) {
            if (!CuotasMercadoPagoHelper::es_mercado_pago($payment_method)) {
                continue;
            }

            $payment_method->cuotas_disponibles = CuotasMercadoPagoHelper::cuotas_disponibles($payment_method);
        }
    }

    /**
     * Pone en las filas de tipo "MercadoPago" la public key con la que de verdad se va a cobrar.
     *
     * Por que hace falta: desde la mision `abm-integraciones-mp`, `MercadoPagoController@preference`
     * arma la preferencia con el access_token que resuelve `MercadoPagoCredentialsHelper`. Si el
     * comercio conecto su cuenta por OAuth desde el ERP, ese token es el del conector — y la
     * public key que el navegador usa para tokenizar tiene que ser la de ESA MISMA cuenta, no la
     * que quedo escrita a mano en `payment_methods`. Sin esto, el brick del comprador y la
     * preferencia del backend podrian apuntar a dos cuentas distintas de Mercado Pago.
     *
     * Se resuelve del lado de la API a proposito, para que el contrato con el SPA no cambie:
     * `tienda-spa` sigue leyendo `cart_payment_method.public_key` y no se toca.
     *
     * 🔴 Cuando NO hay conector conectado no se pisa nada. La respuesta queda identica a la de
     * antes de esta mision: cada fila con su propia public key. Es el caso de todos los comercios
     * que hoy cobran por `payment_methods`, que son la mayoria mientras el ERP no este desplegado,
     * y ninguno tiene que notar el cambio.
     *
     * @param \Illuminate\Database\Eloquent\Collection $payment_methods Filas ya cargadas con `type`.
     * @param int $commerce_id Comercio (owner).
     * @return void
     */
    protected function publicar_public_key_vigente_de_mercado_pago($payment_methods, $commerce_id)
    {
        $credentials = MercadoPagoCredentialsHelper::credentials($commerce_id);

        if ($credentials['origen'] !== 'platform_connector' || empty($credentials['public_key'])) {
            return;
        }

        foreach ($payment_methods as $payment_method) {
            if (!$payment_method->type || $payment_method->type->name !== 'MercadoPago') {
                continue;
            }

            // Solo cambia lo que se serializa; no se guarda nada. `payment_methods` es el origen
            // de respaldo y esta mision no lo toca.
            $payment_method->public_key = $credentials['public_key'];
        }
    }

}
