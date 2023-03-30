<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Http\Controllers\Helpers\TwilioHelper;
use App\Mail\PasswordReset;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class PasswordResetController extends Controller
{

    function sendVerificationCode(Request $request) {
        $buyer = Buyer::where('email', $request->email)
                    ->where('user_id', $request->commerce_id)
                    ->first();
        if (is_null($buyer)) {
            return response()->json(['email_send' => false], 200);
        }
        $code = rand(100000, 999999);
        $commerce = User::find($request->commerce_id);
        $buyer->verification_code = $code;
        $buyer->save();

        Mail::to($buyer)->send(new PasswordReset($code, $commerce));
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
