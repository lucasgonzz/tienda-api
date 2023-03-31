<?php

namespace App\Http\Controllers;

use App\Notifications\MailToCommerce;
use App\User;
use Illuminate\Http\Request;

class MailController extends Controller
{
    function mailToCommerce(Request $request) {
        $user = User::find($request->commerce_id);
        $user->notify(new MailToCommerce($request->name, $request->email, $request->phone, $request->message));
    }
}
