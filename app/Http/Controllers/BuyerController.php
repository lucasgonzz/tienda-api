<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Helpers\BuyerHelper;
use App\Http\Controllers\Helpers\StringHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class BuyerController extends Controller
{
	function getBuyer() {
		if (Auth::guard('buyer')->check()) {
			$buyer = Buyer::where('id', $this->buyerId())
								->withAll()
								->first();
			AuthController::setLastLogin($buyer);
			// $buyer = BuyerHelper::addMercadoPagoCards($buyer);
			return response()->json(['buyer' => $buyer], 200);
		}
		return response(null, 401);
	}

	function store(Request $request) {
		$model = $this->getFullBuyer($request);
		if (is_null($model)) {
			$model = Buyer::create([
				'name'		=> $request->name,
				'email'		=> $request->email,
				'phone'		=> $request->phone,
				'user_id'	=> $request->commerce_id
			]);

			$model = $this->getFullBuyer($request);
			$this->login($model);

			return response()->json(['model' => $model], 201);
		}
		$this->login($model);
		return response()->json(['model' => $model], 200);
	}

	function getFullBuyer($request) {
		return Buyer::where('email', $request->email)
						->where('user_id', $request->commerce_id)
						->withAll()
						->first();
	}

	function login($model) {
		Auth::guard('buyer')->login($model);
	}

	function update(Request $request) {
		$buyer = Buyer::find($this->buyerId());
		$buyer->name = StringHelper::modelName($request->name);
		$buyer->surname = StringHelper::modelName($request->surname);
		$buyer->email = $request->email;
		$buyer->save();
		return response(null, 200);
	}

	function updatePhone(Request $request) {
		if ($this->phoneExist($request->phone)) {
			return response()->json(['phone_exist' => true], 200);
		} else {
			$buyer = Auth::guard('buyer')->user();
			$buyer->phone = $request->phone;
			$buyer->save();
			return response()->json(['phone_exist' => false], 200);
		}
	}

	function updatePassword(Request $request) {
		$buyer = Auth::guard('buyer')->user();
		if (Hash::check($request->current_password, $buyer->password)) {
            $buyer->update([
                'password' => bcrypt($request->new_password),
            ]);
            return response()->json(['updated' => true], 200);
        } else {
            return response()->json(['updated' => false], 200);
        }

	}

	function phoneExist($phone) {
		$auth_buyer = Auth::guard('buyer')->user();
		$buyer = Buyer::where('phone', $phone)
						->where('user_id', $auth_buyer->user_id)
						->where('id', '!=', $this->buyerId())
						->first();
		if ($buyer) {
			return true;
		}
		return false;
	}
}
