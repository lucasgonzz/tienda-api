<?php

namespace App\Http\Controllers\Helpers;

use App\PaymentMethod;
use App\PaymentMethodType;
use App\Platform;
use App\PlatformConnector;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve con que credenciales de Mercado Pago cobra un comercio.
 *
 * 🔴 PUERTO EXACTO DE `empresa-api/app/Http/Controllers/Helpers/MercadoPagoCredentialsHelper.php`.
 * Lo unico que cambia son los namespaces (`App\Models\X` -> `App\X`). La base es compartida entre
 * el ERP y la tienda, el codigo no: si el orden de resolucion divergiera entre los dos repos, un
 * comercio cobraria con una credencial en el ERP y con otra en la tienda. Cualquier cambio de
 * orden se hace en los dos archivos, en la misma mision.
 *
 * EL UNICO LUGAR QUE CONOCE EL FALLBACK. Hay dos lugares posibles donde puede estar la credencial
 * de cobro de un comercio, y el orden importa:
 *
 * 1. `platform_connectors` — el conector de `mercado_pago` del comercio, si esta conectado.
 *    Es lo que deja el OAuth del ERP y lo que va a quedar cuando se limpie el resto.
 * 2. `payment_methods` — la fila del tipo "MercadoPago" del comercio, con `access_token` y
 *    `public_key` cargados a mano. Es lo UNICO con lo que esta tienda cobra hasta esta mision,
 *    asi que no se toca ni se vacia: hay clientes en produccion cuya unica credencial vive ahi.
 *
 * Un comercio que nunca conecto por OAuth sigue cobrando por (2) exactamente igual que antes.
 * Uno que conecta pasa a cobrar por (1) sin que nadie tenga que migrar nada a mano.
 */
class MercadoPagoCredentialsHelper
{
    /**
     * Devuelve las credenciales vigentes de Mercado Pago del comercio.
     *
     * Siempre devuelve el array con las tres claves; vienen en null cuando el comercio no tiene
     * con que cobrar por ningun lado. El llamador decide que hacer con eso (no se lanza
     * excepcion: no poder cobrar es un estado posible del sistema, no un error del programa).
     *
     * @param int $user_id Comercio (owner) del que se quieren las credenciales.
     * @return array{access_token: string|null, public_key: string|null, origen: string|null}
     */
    public static function credentials($user_id)
    {
        $user_id = (int) $user_id;

        $connector = PlatformConnector::find_for_user_and_slug($user_id, Platform::SLUG_MERCADO_PAGO);

        if ($connector && $connector->is_connected()) {
            // `is_connected()` mira el atributo crudo, sin desencriptar. El desencriptado real
            // pasa aca y puede fallar (APP_KEY distinta a la de la empresa-api del mismo cliente,
            // o una fila escrita en plano antes de que corriera la migracion de cifrado). En ese
            // caso no se propaga la excepcion: se avisa y se cae al fallback, que es lo que deja
            // al comercio cobrando igual que antes en vez de un 500 en el checkout.
            try {
                $access_token = $connector->access_token;
            } catch (\Throwable $e) {
                Log::error(
                    "MercadoPagoCredentialsHelper: no se pudo leer el access_token del platform_connector ".
                    "{$connector->id}: " . $e->getMessage()
                );
                $access_token = null;
            }

            if (!empty($access_token)) {
                return [
                    'access_token' => $access_token,
                    // El conector puede no tener public_key si Mercado Pago no la devolvio en el
                    // canje: en ese caso se completa con la de `payment_methods`, que no es
                    // secreta y viaja al navegador igual.
                    'public_key'   => !empty($connector->public_key)
                        ? $connector->public_key
                        : self::public_key_de_payment_method($user_id),
                    'origen'       => 'platform_connector',
                ];
            }
        }

        $payment_method = self::payment_method_mercado_pago($user_id);

        if ($payment_method && !empty($payment_method->access_token)) {
            return [
                'access_token' => $payment_method->access_token,
                'public_key'   => $payment_method->public_key,
                'origen'       => 'payment_method',
            ];
        }

        return [
            'access_token' => null,
            'public_key'   => null,
            'origen'       => null,
        ];
    }

    /**
     * Solo el access_token, para los llamadores que no necesitan la public key.
     *
     * @param int $user_id Comercio (owner).
     * @return string|null
     */
    public static function access_token($user_id)
    {
        $credentials = self::credentials($user_id);

        return $credentials['access_token'];
    }

    /**
     * Fila de `payment_methods` del tipo "MercadoPago" del comercio, si tiene una.
     *
     * Devuelve null (no explota) si la tabla `payment_method_types` no tiene la fila
     * "MercadoPago": la siembra el seeder de `empresa-api`, pero una base armada a mano o un
     * fixture parcial puede no tenerla.
     *
     * @param int $user_id Comercio (owner).
     * @return PaymentMethod|null
     */
    protected static function payment_method_mercado_pago($user_id)
    {
        $type = PaymentMethodType::where('name', 'MercadoPago')->first();

        if (!$type) {
            return null;
        }

        return PaymentMethod::where('user_id', $user_id)
            ->where('payment_method_type_id', $type->id)
            ->first();
    }

    /**
     * Public key de `payment_methods`, usada solo para completar la del conector cuando falta.
     *
     * @param int $user_id Comercio (owner).
     * @return string|null
     */
    protected static function public_key_de_payment_method($user_id)
    {
        $payment_method = self::payment_method_mercado_pago($user_id);

        if (!$payment_method) {
            return null;
        }

        return $payment_method->public_key;
    }
}
