<?php

namespace App\Http\Controllers\Helpers;

use App\OnlineConfiguration;
use App\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Aplica en runtime la configuracion SMTP propia del comercio (port del helper homonimo de
 * empresa-api, prompt 358).
 *
 * Filosofia: fallar en silencio. Si el cliente no configuro su casilla, la desactivo, la dejo
 * incompleta, o la contraseña no se puede desencriptar, este helper NO tira excepcion: loguea,
 * devuelve false, y el envio que venga despues sale por el mailer del .env del sistema.
 */
class ClientMailConfigHelper
{
    /**
     * @param int|null $user_id Id del comercio dueño de la tienda.
     * @return bool true si se aplico la config propia del cliente, false si se usa el .env.
     */
    static function apply($user_id = null) {
        try {
            if (is_null($user_id)) {
                Log::info('ClientMailConfigHelper: sin user_id, se usa el .env');
                return false;
            }

            $configuration = OnlineConfiguration::where('user_id', $user_id)->first();

            if (is_null($configuration) || !$configuration->mail_enabled) {
                Log::info('ClientMailConfigHelper: correo propio desactivado o inexistente, se usa el .env', [
                    'user_id' => $user_id,
                ]);
                return false;
            }

            // El accessor de mail_password desencripta con la APP_KEY de ESTA app. Si tienda-api y
            // empresa-api no comparten APP_KEY, esto tira DecryptException: se captura, se loguea de
            // forma inequivoca (para que se pueda diagnosticar rapido) y se cae al .env.
            $password = null;
            try {
                $password = $configuration->mail_password;
            } catch (DecryptException $e) {
                Log::error('ClientMailConfigHelper: NO SE PUDO DESENCRIPTAR mail_password. Casi seguro la APP_KEY de tienda-api no coincide con la de empresa-api. Se usa el .env.', [
                    'user_id' => $user_id,
                    'error'   => $e->getMessage(),
                ]);
                return false;
            }

            if (empty($configuration->mail_host)
                || empty($configuration->mail_username)
                || empty($password)) {
                Log::info('ClientMailConfigHelper: config de correo propia incompleta, se usa el .env', [
                    'user_id' => $user_id,
                ]);
                return false;
            }

            $from_address = $configuration->mail_from_address ? $configuration->mail_from_address : $configuration->mail_username;
            $from_name = $configuration->mail_from_name;

            if (empty($from_name)) {
                $user = User::find($user_id);
                $from_name = is_null($user) ? $from_name : $user->company_name;
            }

            Config::set('mail.mailers.smtp.host', $configuration->mail_host);
            Config::set('mail.mailers.smtp.port', $configuration->mail_port);
            Config::set('mail.mailers.smtp.encryption', $configuration->mail_encryption);
            Config::set('mail.mailers.smtp.username', $configuration->mail_username);
            Config::set('mail.mailers.smtp.password', $password);
            Config::set('mail.from.address', $from_address);
            Config::set('mail.from.name', $from_name);
            Config::set('mail.default', 'smtp');

            // Sin esto, Laravel sigue usando la instancia del mailer ya armada con los valores del .env.
            if (app()->bound('mail.manager') && method_exists(app('mail.manager'), 'forgetMailers')) {
                app('mail.manager')->forgetMailers();
            }

            Log::info('ClientMailConfigHelper: se aplico la casilla propia del comercio', [
                'user_id'   => $user_id,
                'mail_host' => $configuration->mail_host,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('ClientMailConfigHelper: error aplicando la config propia, se usa el .env', [
                'user_id' => $user_id,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }
}
