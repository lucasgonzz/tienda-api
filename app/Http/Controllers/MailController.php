<?php

namespace App\Http\Controllers;

use App\Mail\CommonMail;
// use App\Notifications\MailToCommerce;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class MailController extends Controller
{
    function mailToCommerce(Request $request) {

        $user = User::find($request->commerce_id);

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
