<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

// Home
Route::get('/articles/featured-last-uploads/{commerce_id}',
	'HomeController@featuredLastUploads'
);
Route::get('/categories/{commerce_id}',
	'HomeController@categories'
);
Route::get('/brands/{commerce_id}',
	'HomeController@brands'
);
Route::get('/articles/from-brand/{brand_id}/{order_by}/{commerce_id}',
	'HomeController@articlesFromBrand'
);
Route::get('/sub-categories/{category_id}',
	'HomeController@subCategories'
);
Route::get('/articles/from-category/{category_id}/{sub_category_id}/{bodega_id}/{cepa_id}/{order_by}/{commerce_id}',
	'HomeController@articlesFromCategory'
);
Route::get('/titles/{commerce_id}',
	'TitleController@index'
);
Route::get('/platelets/{commerce_id}',
	'PlateletController@index'
);

// Commerce
Route::get('/commerce/{commerce_id}',
	'CommerceController@commerce'
);
Route::get('/commerce/workdays/{commerce_id}',
	'CommerceController@workdays'
);

// Nav
Route::get('/articles/names/{commerce_id}',
	'ArticleController@names'
);
Route::get('/articles/search/{query}/{commerce_id}',
	'ArticleController@search'
);
// /register-token y /buyer/search/{query}/{commerce_id} vivian aca, publicas. Se movieron al
// grupo auth:buyer del final. La segunda era la peor fuga de esta API — devolvia la base de
// compradores del comercio con el hash de la contraseña — y estar aca arriba, fuera del bloque
// de auth que estaba comentado, es la razon por la que nadie la habia visto.

// Payment
// 🔴 Publica a proposito: es el punto de entrada del checkout de invitado. El controller ya NO
// abre sesion de cuenta cuando el comprador tiene contraseña (era una toma de cuenta por email):
// ver el docblock de BuyerController@login.
Route::post('/buyer', 'BuyerController@store')->middleware('throttle:20,1');

// Checkout: prefill de dirección por email (ruta pública, throttled para mitigar email bombing)
Route::post('/buyer/checkout-address', 'BuyerController@checkoutAddress')->middleware('throttle:20,1');


Route::get('/delivery-day/{commerce_id}', 'DeliveryDayController@get_dias_habilitados');



// Article
Route::get('/articles/{slug}/{commerce_id}',
	'ArticleController@show'
);
Route::get('/articles/similars/{id}/{commerce_id}',
	'ArticleController@similars'
);
Route::get('/articles/set-viewed/{id}',
	'ArticleController@setViewed'
);
Route::get('/articles/questions/answered/{article_id}',
	'ArticleController@questions'
);
// Público (sin auth), pero con throttle para mitigar email bombing / abuso (prompt 355).
Route::post('advises',
	'AdviseController@store'
)->middleware('throttle:10,1');
Route::get('/articles-seleccion-especial/{articles_id}',
	'ArticleController@seleccionEspecial'
);
// Conditions
Route::get('conditions', 
	'ConditionController@index'
);



// Vinoteca
// Bodegas
Route::get('bodegas/{commerce_id}', 
	'BodegaController@index'
);
// Cepas
Route::get('cepas/{commerce_id}', 
	'CepaController@index'
);



// Help
// ⚠️ Queda publica, y hoy responde 500 para un invitado: HelpController@message inserta en
// `messages` con buyer_id null y esa columna es NOT NULL. No se cierra con auth:buyer porque
// components/help/components/Writte.vue:60 se lo ofrece a cualquiera: cerrarla convertiria el 500
// en un 401 y dejaria al invitado sin canal de contacto. Arreglarlo de verdad es una decision de
// producto (o messages.buyer_id pasa a nullable en empresa-api, o el invitado va a
// /mail-to-commerce). Queda anotado en el informe, sin tocar.
Route::post('/help/message',
	'HelpController@message'
);
// /calls/waiting-call y POST /calls se movieron al grupo auth:buyer del final: calls.buyer_id es
// NOT NULL, asi que sin sesion la primera devolvia siempre false y la segunda reventaba en 500.

// PaymentMethods
Route::get('/payment-methods/{commerce_id}',
	'PaymentMethodController@index'
);

// MercadoPago
Route::post('/mercado-pago/preference',
	'MercadoPagoController@preference'
);

// Payway
Route::post('/payway/token',
	'PaywayController@getToken'
);

// DeliveryZones
Route::get('/delivery-zones/{commerce_id}',
	'DeliveryZoneController@index'
);


// Buyer Messages
// ->only(): BuyerMessageController tiene index() y store() y nada mas. Route::resource registraba
// las 7 rutas REST, asi que create/show/edit/update/destroy existian y reventaban con
// BadMethodCallException (500) al llamarlas. apiResource tampoco alcanza: saca create y edit pero
// deja show/update/destroy, que tampoco existen.
Route::resource('/buyer-message', 'BuyerMessageController')->only(['index', 'store'])
	->middleware('auth:buyer');


// Tracking de comportamiento de compradores (mision tracking-buyers-tienda).
// Ingesta por LOTES: un request por evento le costaria un pedido al comprador en cada vista.
// Va a proposito ANTES del bloque de auth:sanctum: el visitante anonimo es el caso normal de
// una tienda, no el borde, y el buyer_id lo resuelve el controller desde la sesion cuando la hay.
// Lejos del bloque de /articles/, donde el orden de registro ya sombrea una ruta viva
// (/articles/{slug}/{commerce_id} se come a /articles/set-viewed/{id}, los dos de 3 segmentos).
//
// 🔴 El withoutMiddleware NO es opcional, y sacarlo degrada la navegacion del comprador.
// El grupo `api` ya trae 'throttle:api' (Kernel.php:45), definido en RouteServiceProvider:35-39
// como Limit::perMinute(60)->by(optional($request->user())->id ?? $request->ip()). Ese
// $request->user() usa el guard `web`, que para un comprador es SIEMPRE null, asi que el limite
// termina siendo por IP — y ese cubo de 60 por minuto esta COMPARTIDO con toda la API de la
// tienda: articulos, carrito, categorias, pedidos.
// O sea que sin esta exclusion el tracking (que hace flush cada 5 segundos) le come el cupo al
// comprador, y los 429 no caen en el tracking sino en los requests que si importan. Peor todavia
// con varios compradores detras de un mismo NAT.
// La ruta conserva su propio 'throttle:60,1', que es un cubo aparte: sigue acotada, pero con su
// presupuesto propio y no con el de la navegacion.
Route::post('buyer-tracking/events', 'BuyerTrackingController@store')
	->middleware('throttle:60,1')
	->withoutMiddleware('throttle:api');


/*
|--------------------------------------------------------------------------
| Cuenta del comprador — auth:buyer
|--------------------------------------------------------------------------
|
| 🔴 Este grupo estuvo COMENTADO hasta el 15/8/2026, con `auth:sanctum`. Todo lo de adentro era
| publico: pedidos, mensajes, notificaciones, favoritos, cupones y direcciones dependian de que
| cada controller filtrara por buyerId(), y varios no filtraban.
|
| ⚠️ Y descomentarlo tal cual habria roto la tienda entera, asi que si alguien vuelve a ver
| `auth:sanctum` en este archivo, esta mal. Medido:
|     config('sanctum.guard')       -> ["web"]
|     config('auth.guards.sanctum') -> {"driver":"sanctum","provider":null}  (cae al provider
|                                      default, 'users', que es el COMERCIO)
| El comprador vive en el guard de sesion `buyer` (Auth::guard('buyer'), usado en todo el
| codebase). `auth:sanctum` habria devuelto 401 a todos los compradores, en todas estas rutas.
|
| Tampoco sirve el alias `auth.buyer` comentado en Kernel.php: AuthenticateGuardBuyer no autentica
| nada, solo cambia el provider del guard `web` en runtime.
|
| El middleware correcto es `auth:buyer`.
|
*/
Route::middleware('auth:buyer')->group(function() {
	Route::get('/user', 'BuyerController@getBuyer');

	// Payment Gateway
	Route::post('/payments', 'PaymentController@store');
	Route::get('/customers/cards/{email}', 'CustomerController@cards');

	// Buscador de clientes del vendedor. Ademas de auth:buyer, el controller exige seller_id y
	// resuelve el comercio desde el vendedor y no desde la URL: era la peor fuga de la API y
	// estaba FUERA del bloque comentado, asi que descomentarlo ni la habria tocado.
	Route::get('/buyer/search/{query}/{commerce_id}', 'BuyerController@search');

	// Configuration
	Route::put('/buyer',
		'BuyerController@update'
	);
	Route::put('/buyer/phone',
		'BuyerController@updatePhone'
	);
	Route::put('/buyer/password',
		'BuyerController@updatePassword'
	);

	// Last Searchs
	Route::get('/last-searchs',
		'LastSearchController@index'
	);

	// Notifications
	Route::get('/notifications',
		'NotificationController@index'
	);
	Route::get('/notifications/unread',
		'NotificationController@unread'
	);
	Route::post('/notifications/mark-as-read',
		'NotificationController@markAsRead'
	);
	Route::post('/register-token',
		'NotificationController@createBinding'
	);

	// Messages
	Route::get('/messages',
		'MessageController@index'
	);
	Route::get('/messages/set-read',
		'MessageController@setRead'
	);
	Route::post('/messages',
		'MessageController@store'
	);

	// Cupons
	Route::get('cupons',
		'CuponController@index',
	);
	Route::get('cupons/set-read',
		'CuponController@setRead',
	);

	// Favorites
	Route::get('/favorites',
		'ArticleController@favorites'
	);
	Route::get('/articles/favorite/{article_id}',
		'ArticleController@favorite'
	);

	// Orders
	Route::get('/orders',
		'OrderController@index'
	);

	// Carrito de un pedido ya confirmado. Va aca y no con el resto del carrito: el que la llama
	// es un comprador con cuenta mirando un mensaje viejo (mixins/messages.js:59), nunca un
	// invitado en medio de una compra.
	Route::get('/carts/from-order/{order_id}',
		'CartController@fromOrder'
	);

	// Questions
	Route::get('/questions',
		'QuestionController@index'
	);
	Route::post('/questions',
		'QuestionController@store'
	);

	// Addresses
	Route::post('/addresses',
		'AddressController@store'
	);

	// Calls
	Route::get('/calls/waiting-call',
		'CallController@waitingCall'
	);
	Route::post('/calls',
		'CallController@store'
	);
});


/*
|--------------------------------------------------------------------------
| Rutas del flujo de invitado — publicas a proposito
|--------------------------------------------------------------------------
|
| 🔴 Estas NO pueden llevar auth:buyer y no es un olvido: la tienda permite comprar sin
| registrarse, y estas son justamente las que usa alguien que todavia no tiene identidad. Meterlas
| detras del middleware rompe la compra de invitado, que es el flujo mas usado.
|
| El alcance se acota ADENTRO de cada controller:
|   - las de carrito, por CartOwnershipHelper (la sesion es lo unico que ata un carrito de
|     invitado a quien lo creo);
|   - POST /orders, por pertenencia del carrito y por la identidad de la sesion, ignorando el
|     buyer_id del request salvo que quien pida sea un vendedor.
|
*/

// Cart
Route::get('/carts/last-cart/{commerce_id}',
	'CartController@lastCart'
);
Route::post('/carts',
	'CartController@store'
);
Route::put('/carts',
	'CartController@update'
);
Route::delete('/carts/{cart_id}',
	'CartController@delete'
);
Route::put('/carts/update-article-amount/{cart_id}',
	'CartController@update_article_amount'
);

// PaymentCardInfo
Route::post('/payment-card-info',
	'PaymentCardInfoController@store'
)->middleware('throttle:30,1');

Route::post('/orders',
	'OrderController@store'
);

/*
| Estas tres devuelven 200 con payload vacio cuando no hay comprador, A PROPOSITO, y por eso NO
| van en el grupo de arriba: el SPA trata `order === null` y `credit_accounts: []` como estados
| validos, y un 401 le dispararia el manejo de error en medio del checkout. El guard esta adentro
| del controller y ya estaba bien puesto (ver el docblock de OrderController@current).
*/
Route::get('/orders/current/{commerce_id}',
	'OrderController@current'
);
Route::get('/orders/confirmed/{commerce_id}',
	'OrderController@confirmed'
);
Route::get('/credit-accounts', 'CurrentAcountController@getCreditAccounts');

// Cuenta corriente: estas dos ya tenian control de pertenencia real contra
// comercio_city_client_id y devuelven 403. Se dejan como estaban.
Route::get('/current-acount/sale-pdf-token/{sale_id}', 'CurrentAcountController@salePdfToken');
Route::get('/current-acount/{credit_account_id}/{cantidad_movimientos}', 'CurrentAcountController@getMovements');

// Tendencias de busqueda: por diseño muestra terminos de otros visitantes, pero solo devuelve
// articulos, nunca los terminos. Es una vitrina, no un dato de cuenta.
Route::get('/last-searchs/for-search-page/{commerce_id}',
	'LastSearchController@forSearchPage'
);

// Validacion de un codigo de cupon en el checkout. Publica porque el invitado tambien canjea, con
// throttle porque el codigo es enumerable.
Route::get('cupons/search/{commerce_id}/{code}',
	'CuponController@search',
)->middleware('throttle:20,1');

// Formulario de contacto. Publico por definicion; throttled por el mismo motivo que /advises
// (api.php mas arriba): manda un mail y sin limite es un vector de spam.
Route::post('/mail-to-commerce', 'MailController@mailToCommerce')->middleware('throttle:10,1');

// Destruir sesión guest tras confirmar pedido (sin requerir autenticación previa).
// 🔴 NO ponerle auth:buyer: mixins/cart.js la llama justo cuando la sesion puede ya no existir, y
// un 401 ahi corta la cadena del checkout antes de que el comprador llegue a la pagina de gracias.
Route::post('/buyer/logout', 'BuyerController@logout');

