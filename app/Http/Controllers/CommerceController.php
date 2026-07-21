<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\GoogleLoginHelper;
use App\User;
use App\Workday;
use Illuminate\Http\Request;

class CommerceController extends Controller
{

    function commerce($commerce_id) {
        $commerce = User::where('id', $commerce_id)
                        ->with('addresses')
                        ->with('extencions')
                        ->with('configuration')
                        ->with('online_configuration.online_price_type')
                        ->with('online_configuration.online_template')
                        ->first();

        // El SPA muestra el boton de "Continuar con Google" segun
        // commerce.configuration.show_google_login (prompt 590, grupo 164). Antes esa columna
        // era un master switch generico en user_configurations (default true para todos los
        // comercios, sin relacion con si el comercio tenia o no credenciales cargadas). Ahora se
        // sobreescribe en runtime con la disponibilidad REAL: solo true si el comercio tiene el
        // login con Google habilitado Y credenciales cargadas en su online_configuration. Esto
        // evita mostrar un boton roto para los comercios que ya tenian el switch viejo en true
        // pero nunca cargaron client_id/client_secret.
        if (!is_null($commerce) && !is_null($commerce->configuration)) {
            $commerce->configuration->show_google_login = GoogleLoginHelper::isAvailable($commerce_id);
        }

        return response()->json(['commerce' => $commerce], 200);
    }

    function workdays($commerce_id) {
        $workdays = Workday::with(['schedules' => function($q) use ($commerce_id) {
                                $q->where('user_id', $commerce_id);
                            }])
                            ->get();
        return response()->json(['workdays' => $workdays], 200);
    }
}
