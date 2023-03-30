<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use App\Http\Controllers\Helpers\TwilioHelper;
use Carbon\Carbon;

class CuponHelper {
	static function getAmount($cupon) {
		if (is_null($cupon->percentage)) {
			return $cupon->amount;
		}
		return null;
	}

	static function getPercentage($cupon) {
		if (is_null($cupon->amount)) {
			return $cupon->percentage;
		}
		return null;
	}

	static function getMinAmount($cupon) {
		if (!is_null($cupon->min_amount)) {
			return $cupon->min_amount;
		}
		return null;
	}

	static function getExpirationDate($cupon) {
		if (!is_null($cupon->expiration_days)) {
			return Carbon::now()->addDays($cupon->expiration_days);
		}
		return null;
	}

	static function isForNewBuyers($cupon) {
		return $cupon['for_new_buyers'] == true;
	}

	static function getExpirationDays($cupon) {
		if ($cupon['expiration_days'] != '') {
			return $cupon['expiration_days'];
		}
		return null;
	}

	static function sendCuponNotification($cupon) {
		if (!is_null($cupon->amount)) {
        	$message = 'Tenes un descuento de $'.$cupon->amount;
		} else {
        	$message = 'Tenes un descuento del '.$cupon->percentage.'%';
		}
        $title = '¡Te regalamos un cupon!';
        TwilioHelper::sendNotification($cupon->buyer_id, $title, $message);
	}

}