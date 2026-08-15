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


	/**
	 * Buscador de clientes para el VENDEDOR que carga un pedido a nombre de otro.
	 * La usan los vendedores de Golonorte.
	 *
	 * 🔴 Era la peor fuga de la API. Ruta publica, sin auth, y devolvia la base de compradores
	 * completa del comercio con `withAll()`: nombre, mail, telefono, direccion, el Client del ERP
	 * — y el hash bcrypt de la contraseña, el remember_token y el verification_code, porque
	 * App\Buyer no declaraba $hidden. Medido el 15/8/2026 con curl sin sesion.
	 *
	 * Y estaba FUERA del grupo auth:sanctum comentado, asi que descomentar ese grupo ni la habria
	 * tocado.
	 *
	 * Ahora tiene tres candados, y hacen falta los tres:
	 *
	 *   1. auth:buyer en la ruta (routes/api.php). Los vendedores son compradores logueados: el
	 *      SPA solo muestra este buscador con v-if="user.seller_id"
	 *      (components/payment/components/SellerSelectClient.vue:3) y `user` sale de /api/user.
	 *   2. seller_id obligatorio. Con auth:buyer solo no alcanza: cualquier invitado que confirmo
	 *      un pedido queda logueado en el guard 'buyer', y podria llamarla.
	 *   3. El comercio sale del vendedor, NO de la URL. Sin esto un vendedor de un comercio se
	 *      lleva la base de compradores de otro cambiando un numero.
	 *
	 * Y aun con los tres, se devuelve un select explicito en vez de confiar en el $hidden del
	 * modelo: lo que el vendedor necesita para elegir un cliente es nombre, mail y direccion. Una
	 * lista blanca no se puede filtrar sola cuando alguien agregue una columna nueva a `buyers`.
	 *
	 * @param  string  $query
	 * @param  int     $commerce_id  se ignora a proposito, ver el punto 3
	 * @return \Illuminate\Http\JsonResponse
	 */
	function search($query, $commerce_id) {
		$vendedor = $this->buyer();

		if (is_null($vendedor) || is_null($vendedor->seller_id)) {
			return response()->json(['buyers' => []], 403);
		}

		$buyers = Buyer::where('user_id', $vendedor->user_id)
						->where(function($que) use ($query) {

							$que->where('name', 'LIKE', "%$query%")
								->orWhere('email', 'LIKE', "%$query%");
						})
						->whereNotNull('comercio_city_client_id')
						->orderBy('name', 'ASC')
						->select('id', 'name', 'surname', 'email', 'phone', 'address', 'ciudad', 'barrio', 'user_id', 'comercio_city_client_id')
						/*
						 * 🔴 La relacion tambien va recortada, y no es exceso de celo: `clients` es
						 * la tabla del ERP y trae `saldo`, `saldo_pesos`, `saldo_dolares`, `cuit`,
						 * `cuil`, `dni` y `razon_social`. Mandarle al vendedor el saldo de cuenta
						 * corriente y la CUIT de cada cliente para que elija uno de una lista es
						 * regalar exactamente lo que la lista blanca de arriba viene a evitar.
						 * El SPA solo lee `.address` y `.phone` de esta relacion (verificado con
						 * grep sobre todo tienda-spa/src); `name` va para poder mostrarlo.
						 */
						->with(['comercio_city_client' => function($q) {
							$q->select('id', 'name', 'address', 'phone');
						}])
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

			return response()->json(['model' => $this->modeloParaElCheckout($model)], 201);
		}

		// 🔴 Desde el 15/8/2026, la actualizacion de la direccion SOLO corre si el registro es una
		// ficha de invitado. Esta ruta es publica y se resuelve por email: si tambien escribiera
		// sobre cuentas con credencial, cualquiera podria reescribirle la direccion de entrega a
		// otra persona —de forma permanente y en la base que comparte con el ERP— sabiendo su
		// mail. El pedido igual sale a la direccion correcta: OrderController@get_address le da
		// prioridad al `address` del request, que es la que el comprador vio en pantalla.
		if ($this->esFichaDeInvitado($model)) {
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
		}

		$this->login($model);
		return response()->json(['model' => $this->modeloParaElCheckout($model)], 200);
	}

	/**
	 * Recorta lo que POST /api/buyer devuelve, segun si el registro es una ficha o una cuenta.
	 *
	 * 🔴 Esta ruta es PUBLICA y se resuelve por email. Cerrar la toma de sesion no alcanzaba: el
	 * metodo seguia devolviendo `getFullBuyer()`, que usa el scope withAll() de App\Buyer y trae
	 * `addresses`, `comercio_city_client` (el registro Client del ERP, que no declara $hidden) y
	 * **todo el historial de mensajes privados** del comprador con el comercio. O sea que con
	 * saber un mail se leia el perfil entero de esa persona, 20 veces por minuto.
	 *
	 * Que devuelve ahora:
	 *
	 *   - Ficha de invitado -> lo mismo que antes, menos los mensajes. Es su propia ficha, no hay
	 *     credencial de por medio, y el checkout no lee `messages` en ningun lado (verificado en
	 *     tienda-spa: cero usos en components/payment, mixins/cart.js y store/auth.js).
	 *   - Cuenta con credencial -> solo lo que el checkout necesita mecanicamente, que es la misma
	 *     clase de dato que ya devuelve POST /api/buyer/checkout-address. Ese endpoint es publico
	 *     por decision explicita de Lucas y su docblock dice que a proposito NO devuelve nombre,
	 *     telefono, el modelo completo ni el Client. Este ahora respeta el mismo limite.
	 *
	 * El SPA no se rompe con el modelo recortado: `mixins/cart.js` lee `seller_id` y `address`, y
	 * la direccion del pedido sale del formulario (`cart.buyer.address`), no de aca.
	 *
	 * @param  \App\Buyer  $model
	 * @return array
	 */
	function modeloParaElCheckout($model) {
		if ($this->esFichaDeInvitado($model)) {
			$ficha = $model->toArray();
			unset($ficha['messages']);

			return $ficha;
		}

		return [
			'id'        => $model->id,
			'address'   => $model->address,
			'ciudad'    => $model->ciudad,
			'barrio'    => $model->barrio,
			'seller_id' => $model->seller_id,
			// Va vacio, no ausente: mixins/app.js::checkAddress() lee `this.user.addresses.length`.
			// Hoy no lo llama nadie, pero una clave faltante ahi es un TypeError a un call site de
			// distancia, y el costo de dejarla es cero.
			'addresses' => [],
		];
	}

	function getFullBuyer($request) {
		return Buyer::where('email', $request->email)
						->where('user_id', $request->commerce_id)
						->withAll()
						->first();
	}

	/**
	 * Identifica al comprador para poder terminar la compra.
	 *
	 * 🔴 ESTE METODO ERA UNA TOMA DE CUENTA. Hacia Auth::guard('buyer')->login($model) sobre
	 * cualquier comprador encontrado por email + comercio, sin pedir contraseña ni nada. O sea:
	 * sabiendo el mail de alguien, POST /api/buyer te dejaba adentro de su cuenta. Medido el
	 * 15/8/2026 con curl: se entro a una cuenta con contraseña mandando solo el email, y despues
	 * GET /api/user devolvio esa cuenta, con sus pedidos y su cuenta corriente detras.
	 *
	 * No se podia sacar el login a secas: la atribucion del pedido de invitado depende de que
	 * quede una identidad en la sesion (orders.buyer_id es NOT NULL). Entonces se parte en dos:
	 *
	 *   - Ficha SIN credencial -> no es una cuenta, es un registro que dejo un checkout de invitado
	 *     anterior. Se loguea igual que antes: no hay credencial que proteger y sacarlo cambiaria
	 *     el comportamiento del flujo mas usado de la tienda.
	 *   - Cuenta CON credencial -> NO se abre sesion: se guarda una identidad de checkout acotada
	 *     (Controller::CLAVE_CHECKOUT), que alcanza para atribuirle el pedido y nada mas. Para
	 *     entrar a la cuenta hay que loguearse, como corresponde.
	 *
	 * 🔴 "Con credencial" es contraseña **O** provider_id, y la segunda mitad es facil de olvidar:
	 * AuthController@social crea el comprador de login con Google SIN contraseña (no la necesita).
	 * Si la condicion mirara solo el password, toda cuenta creada con Google seguiria siendo
	 * tomable sabiendo el mail — el mismo agujero, por otra puerta.
	 *
	 * El SPA no se entera de la diferencia: sigue recibiendo el mismo modelo en la misma forma y
	 * sigue llamando a los mismos endpoints. Por eso esto no obliga a desplegar tienda-spa.
	 *
	 * @param  \App\Buyer  $model
	 * @return void
	 */
	function login($model) {
		if ($this->esFichaDeInvitado($model)) {
			Auth::guard('buyer')->login($model);
			return;
		}

		session()->put(self::CLAVE_CHECKOUT, (int) $model->id);
	}

	/**
	 * Indica si el registro es una ficha creada por un checkout de invitado y no una cuenta.
	 *
	 * @param  \App\Buyer  $model
	 * @return bool
	 */
	function esFichaDeInvitado($model) {
		$sin_password = is_null($model->password) || $model->password === '';
		$sin_provider = is_null($model->provider_id) || $model->provider_id === '';

		return $sin_password && $sin_provider;
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
