<?php

use App\Events\OrderEvent;
use App\Http\Controllers\Helpers\BuyerHelper;
use App\Http\Controllers\PaymentController;
use App\Mail\VerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/email', function() {
	$user = App\User::where('company_name', 'CandyGuay')->first();
	$url = App\Http\Controllers\Helpers\ImageHelper::image($user);
	return new VerificationCode($url);
});


Route::post('/sociallogin/{provider}/{commerce_id}', 'AuthController@social');

// Route::get('auth/{provider}', 'Auth\SocialAuthController@redirectToProvider')->name('social.auth');
Route::get('auth/{provider}/callback', 'Auth\SocialAuthController@handleProviderCallback');

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

Route::get('asd', function() {
	$customer = BuyerHelper::getCustomer('lucasgonzalez5500@gmail.com');
	dd($customer);
});
