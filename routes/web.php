<?php

use App\Events\OrderEvent;
use App\Http\Controllers\Helpers\BuyerHelper;
use App\Http\Controllers\PaymentController;
use App\Mail\VerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Broadcasting\BroadcastManager;

/*
 * Se sacaron de aca dos rutas de debug que estaban vivas y accesibles sin autenticacion:
 *
 *   - GET /email — renderizaba un mailable buscando un comercio por company_name hardcodeado.
 *   - GET /asd  — consultaba la API de Mercado Pago con el mail de Lucas hardcodeado y hacia
 *     dd() del resultado. Un dd() en una ruta publica imprime el volcado en la respuesta.
 *
 * Y la ruta de callback social, que apuntaba a Auth\SocialAuthController, clase que NO EXISTE en
 * este repo. No era solo codigo muerto: rompia `php artisan route:list` ENTERO
 * ("Class App\Http\Controllers\Auth\SocialAuthController does not exist"), o sea que nadie podia
 * enumerar las rutas de este repo con la herramienta estandar. Que es exactamente lo primero que
 * hace cualquiera que quiera auditar la superficie publica, y probablemente parte de la razon por
 * la que estos agujeros vivieron tanto.
 *
 * El login con Google entraba por POST /sociallogin/{provider}/{commerce_id} (AuthController@social)
 * y se saco el 14/10/2026 junto con el boton de la tienda (mision tiendas-demo-y-limpieza-tienda):
 * la tienda ya no tiene login social. Las cuentas que se crearon por ahi siguen en `buyers` (con
 * `provider_id` y sin contraseña) y pueden entrar poniendose una con "Olvide mi contraseña".
 */

Route::post('/register', 'AuthController@register');
Route::post('/register/resend-code', 'AuthController@resendVerificationCode');
Route::post('/register/verify-code', 'AuthController@verifyCode');
Route::post('/login', 'AuthController@login');
Route::post('/logout', 'AuthController@logout');

// Password Reset
Route::post('/password-reset/send-verification-code',
	'PasswordResetController@sendVerificationCode'
);
Route::post('/password-reset/check-verification-code',
	'PasswordResetController@checkVerificationCode'
);
Route::post('/password-reset/update-password',
	'PasswordResetController@updatePassword'
);

Route::post('/payment-notification', 'PaymentController@notification');
