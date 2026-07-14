<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Helpers\BuyerHelper;
use App\Http\Controllers\Helpers\StringHelper;
use Exception;
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


	// La usan los vendedores de Golonorte
	function search($query, $commerce_id) {
		$buyers = Buyer::where('user_id', $commerce_id)
						->where(function($que) use ($query) {

							$que->where('name', 'LIKE', "%$query%")
								->orWhere('email', 'LIKE', "%$query%");
						})
						->whereNotNull('comercio_city_client_id')
						->orderBy('name', 'ASC')
						->withAll()
						->get();

		return response()->json(['buyers' => $buyers], 200);

	}

	/**
	 * Crea o autentica un buyer para el checkout.
	 *
	 * Si es nuevo: inserta con todos los datos del request.
	 *
	 * Si ya existe (mismo email + commerce_id): actualiza SOLO los campos address, ciudad y barrio
	 * (Lucas explícitamente prohibió tocar name y phone desde el checkout: un vendedor puede estar
	 * cargando el pedido a nombre de otro).
	 *
	 * Los campos se actualizan SOLO si vienen en el request con contenido (no vacíos ni espacios).
	 * Luego se hace login y se devuelve el modelo refreshed con las relaciones.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\JsonResponse
	 */
	function store(Request $request) {
		$model = $this->getFullBuyer($request);
		if (is_null($model)) {
			$model = Buyer::create([
				'name'		=> $request->name,
				'email'		=> $request->email,
				'phone'		=> $request->phone,
				'ciudad'	=> $request->ciudad,
				'barrio'	=> $request->barrio,
				'address'	=> $request->address,
				'user_id'	=> $request->commerce_id
			]);

			$model = $this->getFullBuyer($request);
			$this->login($model);

			return response()->json(['model' => $model], 201);
		}

		// Buyer existente: actualizar solo address, ciudad y barrio (si vienen en el request con contenido)
		// 🔴 PROHIBIDO tocar name, phone, o cualquier otra columna
		// 🔴 PROHIBIDO tocar la relación comercio_city_client o el Client del ERP
		if (isset($request->address) && trim($request->address) !== '') {
			$model->address = trim($request->address);
		}
		if (isset($request->ciudad) && trim($request->ciudad) !== '') {
			$model->ciudad = trim($request->ciudad);
		}
		if (isset($request->barrio) && trim($request->barrio) !== '') {
			$model->barrio = trim($request->barrio);
		}
		$model->save();

		// Refrescar el modelo con las relaciones (withAll) para que la respuesta vuelva
		// con la dirección actualizada, no la vieja
		$model = $this->getFullBuyer($request);
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

	/**
	 * Cierra la sesión del buyer guest y destruye la cookie de sesión del browser.
	 * Se usa tras confirmar un pedido para que la próxima visita no quede autenticado.
	 *
	 * @return \Illuminate\Http\Response Respuesta vacía con HTTP 200
	 */
	function logout() {
		// Cerrar sesión del guard buyer
		Auth::guard('buyer')->logout();
		try {
			// Invalidar sesión Laravel y regenerar token CSRF
			request()->session()->invalidate();
			request()->session()->regenerateToken();
		} catch (Exception $e) {
			// Silenciar: si la sesión ya no existe no importa
		}
		return response(null, 200);
	}

	/**
	 * Endpoint público de prefill del checkout: retorna los datos de dirección de un buyer
	 * existente buscado por email + commerce_id.
	 *
	 * 🔴 RESTRICCIÓN CRÍTICA DE SEGURIDAD: este endpoint es público (sin autenticación) y throttled.
	 * Devuelve SOLO: found, address, ciudad, barrio. Nada más.
	 *
	 * No devuelve: name, phone, id, modelo Buyer completo, Client, ni ninguna otra columna.
	 * Es una ruta pública con acceso por email: Lucas aceptó este riesgo a conciencia como el
	 * mínimo necesario para permitir que el comprador vea y corrija su dirección de envío.
	 * Si en el futuro alguien quiere agregar un dato extra por acá, tiene que ser una decisión
	 * consciente, no un descuido.
	 *
	 * @param  \Illuminate\Http\Request  $request (email, commerce_id)
	 * @return \Illuminate\Http\JsonResponse
	 */
	function checkoutAddress(Request $request) {
		// Buscar el buyer por email + commerce_id
		$buyer = Buyer::where('email', $request->email)
						->where('user_id', $request->commerce_id)
						->with('comercio_city_client')
						->first();

		// Si no existe, devolver found: false sin revelar nada
		if (is_null($buyer)) {
			return response()->json([
				'found'   => false,
				'address' => null,
				'ciudad'  => null,
				'barrio'  => null
			], 200);
		}

		// Buyer existe: resolver los tres campos de dirección
		// Prioridad: Buyer primero; fallback al Client si el Buyer no tiene el dato

		// Address: buyer.address si tiene contenido; si no, buyer.comercio_city_client.address
		$address = null;
		if (!empty($buyer->address)) {
			$address = $buyer->address;
		} elseif ($buyer->comercio_city_client && !empty($buyer->comercio_city_client->address)) {
			$address = $buyer->comercio_city_client->address;
		}

		// Ciudad: buyer.ciudad si tiene contenido; si no, buyer.comercio_city_client.ciudad (si existe esa columna)
		$ciudad = null;
		if (!empty($buyer->ciudad)) {
			$ciudad = $buyer->ciudad;
		} elseif ($buyer->comercio_city_client && isset($buyer->comercio_city_client->ciudad) && !empty($buyer->comercio_city_client->ciudad)) {
			$ciudad = $buyer->comercio_city_client->ciudad;
		}

		// Barrio: buyer.barrio si tiene contenido; si no, buyer.comercio_city_client.barrio (si existe esa columna)
		$barrio = null;
		if (!empty($buyer->barrio)) {
			$barrio = $buyer->barrio;
		} elseif ($buyer->comercio_city_client && isset($buyer->comercio_city_client->barrio) && !empty($buyer->comercio_city_client->barrio)) {
			$barrio = $buyer->comercio_city_client->barrio;
		}

		return response()->json([
			'found'   => true,
			'address' => $address,
			'ciudad'  => $ciudad,
			'barrio'  => $barrio
		], 200);
	}
}
