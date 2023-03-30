<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Auth;

class UserHelper
{
    static function buyerId() {
    	if (Auth::guard('buyer')->check()) {
    		return Auth::guard('buyer')->id();
    	} 
    	return null;
    }
}
