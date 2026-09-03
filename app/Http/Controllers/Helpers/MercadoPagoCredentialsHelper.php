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
 *
 * ── Las dos cosas que este repo agrega, sin tocar `credentials()` ────────────────────────────
 *
 * 1. `credentials_for_payment_method()`. En la tienda el COMPRADOR elige una fila de
 *    `payment_methods`, y un comercio puede tener mas de una de tipo MercadoPago (no hay ningun
 *    indice unico que lo impida). `credentials()` hace `first()` y no sabe cual eligieron, asi
 *    que sin este metodo el backend cobraria siempre con la primera mientras el navegador
 *    tokeniza con la public key de la elegida: la plata iria a la cuenta equivocada. El metodo
 *    nuevo no cambia el orden — desempata DENTRO del paso (2), que es donde estaba la
 *    ambiguedad. El ERP no tiene este problema porque no hay comprador eligiendo nada.
 *
 * 2. El `try/catch` de `QueryException` vive en `PlatformConnector::find_for_user_and_slug()`,
 *    no aca: hay bases de clientes sin las tablas `platform_connectors` / `platforms`, y sin esa
 *    guarda el listado publico de medios de pago responde 500. Ver el comentario del modelo.
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
     * Credenciales vigentes para cobrar la fila de `payment_methods` que eligio el comprador.
     *
     * 🔴 EXCLUSIVO DE ESTE REPO. `credentials()` arriba es identico caracter a caracter al de
     * `empresa-api` y tiene que seguir siendolo: es el criterio compartido de que cuenta cobra.
     * Este metodo NO lo cambia — lo envuelve, y solo desempata dentro del paso que ya era
     * ambiguo.
     *
     * El caso que arregla (medido el 3/9/2026 en `tienda_testing_s5`): dos filas de tipo
     * MercadoPago del mismo comercio, id 33 con `TOKEN-CUENTA-A` y id 34 con `TOKEN-CUENTA-B`,
     * sin conector. `credentials()` filtra por comercio + tipo y hace `first()`, asi que devolvia
     * `TOKEN-CUENTA-A` eligiera lo que eligiera el comprador. Mientras tanto el navegador
     * arranca el SDK con la public key de la fila ELEGIDA
     * (`tienda-spa/.../CardPaymentMethod.vue:66`): brick de la cuenta B, preferencia de la cuenta
     * A. La plata a la cuenta equivocada.
     *
     * El orden:
     *
     * 1. Si hay conector conectado, gana el conector. No hay ambiguedad posible: el comercio
     *    conecto UNA cuenta por OAuth y esa es la que cobra, elija la fila que elija el
     *    comprador. (Y `PaymentMethodController@index` le pone a todas las filas la public key
     *    del conector, asi que el navegador y el backend siguen apuntando a la misma cuenta.)
     * 2. Si no hay conector, cobra LA FILA QUE ELIGIO EL COMPRADOR. Es el comportamiento
     *    anterior a esta mision, y es el correcto.
     * 3. Si esa fila no tiene token, lo que haya encontrado `credentials()` en `payment_methods`.
     *    Ultima red: sin esto, un comercio con la primera fila cargada y la elegida vacia dejaria
     *    de cobrar.
     *
     * @param int $user_id Comercio (owner).
     * @param \App\PaymentMethod|null $payment_method Fila que eligio el comprador.
     * @return array{access_token: string|null, public_key: string|null, origen: string|null}
     */
    public static function credentials_for_payment_method($user_id, $payment_method)
    {
        $credentials = self::credentials($user_id);

        if ($credentials['origen'] === 'platform_connector') {
            return $credentials;
        }

        if ($payment_method && !empty($payment_method->access_token)) {
            return [
                'access_token' => $payment_method->access_token,
                'public_key'   => $payment_method->public_key,
                'origen'       => 'payment_method_elegido',
            ];
        }

        return $credentials;
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
