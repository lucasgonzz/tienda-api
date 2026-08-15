<?php

namespace App\Http\Controllers;

use App\Mail\CommonMail;
// use App\Notifications\MailToCommerce;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class MailController extends Controller
{
    /**
     * Formulario de contacto de la tienda. Publico por definicion, con throttle en la ruta.
     *
     * El guard tapa un 500 medido: con un commerce_id inexistente, o con un comercio sin email
     * cargado, Mail::to(null) tira LogicException ("An email must have a To"). En una ruta
     * publica eso es un 500 que cualquiera puede disparar a voluntad, y encima con la traza de
     * Symfony adentro de la respuesta cuando APP_DEBUG esta prendido.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    function mailToCommerce(Request $request) {

        $user = User::find($request->commerce_id);

        if (is_null($user) || empty($user->email)) {
            return response()->json(['error' => 'El comercio no tiene un correo de contacto configurado'], 422);
        }

        $mensaje = [
            [
                'title'   => 'Mensaje de '.$request->name,
            ],
            [
                'title'     => 'Correo electrónico',
                'content'   => $request->email,
            ],
            [
                'title'     => 'Teléfono',
                'content'   => $request->phone,
            ],
            [
                'title'     => 'Contenido del mensaje',
                'content'   => $request->message,
            ],
        ];

        Mail::to($user->email)->send(new CommonMail([
            'mensaje'   => $mensaje, 
            'asunto'    => 'Mensaje desde Tienda Online',
        ]));

        return response(null, 200);
        // $user = User::find($request->commerce_id);
        // $user->notify(new MailToCommerce($request->name, $request->email, $request->phone, $request->message));
    }
}
