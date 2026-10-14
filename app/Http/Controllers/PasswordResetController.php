<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Http\Controllers\Helpers\TwilioHelper;
use App\Mail\PasswordReset;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetController extends Controller
{

    /** Lo que ve el comprador cuando el servidor de correo no acepta el envio del codigo. */
    const MENSAJE_ENVIO_FALLIDO = 'No pudimos enviarte el código a ese correo. Revisá la dirección o probá más tarde.';

    /**
     * Manda por mail el codigo para recuperar la contraseña.
     *
     * 🔴 Si el SMTP rechaza el envio (cualquier `TransportExceptionInterface` de Symfony Mailer) se
     * responde 422 con un mensaje para el comprador, y no el 500 de antes. Medido el 14/10/2026 en
     * la tienda de demo: `UnexpectedResponseException` "554 5.7.1 Recipient domain is reserved" con
     * una casilla @example.com y, antes, `TransportException` 535 con la clave del SMTP vacia. Los dos
     * terminaban en un 500 y el comprador se quedaba con el boton girando y sin saber que hacer. El
     * error real queda en el log (warning, con el comercio, el comprador y la excepcion).
     *
     * El codigo que no salio se descarta: `verification_code` vuelve a lo que tenia antes. No es
     * prolijidad: `AuthController::isBuyerRegistered()` cuenta como registrado solo al comprador con
     * `verification_code` en null, asi que un codigo que nadie recibio no tiene que quedar puesto.
     *
     * El caso que anda (mail enviado) y el del email desconocido responden igual que siempre.
     *
     * @param  Request  $request  email, commerce_id
     * @return \Illuminate\Http\JsonResponse
     */
    function sendVerificationCode(Request $request) {
        $buyer = Buyer::where('email', $request->email)
                    ->where('user_id', $request->commerce_id)
                    ->first();
        if (is_null($buyer)) {
            return response()->json(['email_send' => false], 200);
        }
        $code = rand(100000, 999999);
        $commerce = User::find($request->commerce_id);
        $codigo_anterior = $buyer->verification_code;
        $buyer->verification_code = $code;
        $buyer->save();

        try {
            Mail::to($buyer)->send(new PasswordReset($code, $commerce));
        } catch (TransportExceptionInterface $e) {
            Log::warning('Recuperar clave: el servidor de correo rechazo el envio del codigo', [
                'commerce_id' => $request->commerce_id,
                'buyer_id'    => $buyer->id,
                'excepcion'   => get_class($e),
                'mensaje'     => $e->getMessage(),
            ]);

            $buyer->verification_code = $codigo_anterior;
            $buyer->save();

            return response()->json([
                'email_send' => false,
                'message'    => self::MENSAJE_ENVIO_FALLIDO,
            ], 422);
        }

        return response()->json(['email_send' => true], 200);
    }

    function checkVerificationCode(Request $request) {
        $buyer = Buyer::where('email', $request->email)
                        ->where('user_id', $request->commerce_id)
                        ->first();
        if ($buyer->verification_code == $request->verification_code) {
            return response()->json(['verified' => true], 200);
        }
        return response()->json(['verified' => false], 200);
    }

    function updatePassword(Request $request) {
        $buyer = Buyer::where('email', $request->email)
                        ->where('user_id', $request->commerce_id)
                        ->first();
        $buyer->password = bcrypt($request->password);
        $buyer->verification_code = null;
        $buyer->save();

        if (Auth::guard('buyer')->attempt(['email' => $request->email, 'user_id' => $request->commerce_id, 'password' => $request->password], false)) {
            return response()->json(['password_updated' => true], 200);
        }
    }

}
