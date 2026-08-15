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
Route::post('/register-token',
	'NotificationController@createBinding'
);

// Payment Sellers
Route::get('/buyer/search/{query}/{commerce_id}', 'BuyerController@search');

// Payment
Route::post('/buyer', 'BuyerController@store');

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
Route::post('/help/message',
	'HelpController@message'
);
Route::get('/calls/waiting-call',
	'CallController@waitingCall'
);
Route::post('/calls',
	'CallController@store'
);

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
Route::resource('/buyer-message', 'BuyerMessageController');


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


// Route::middleware('auth:sanctum')->group(function() {
	Route::get('/user', 'BuyerController@getBuyer');

	// Payment Gateway
	Route::post('/payments', 'PaymentController@store');
	Route::get('/customers/cards/{email}', 'CustomerController@cards');

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

	Route::get('/orders/confirmed/{commerce_id}',
		'OrderController@confirmed'
	);
	// Last Searchs 
	Route::get('/last-searchs',
		'LastSearchController@index'
	);
	Route::get('/last-searchs/for-search-page/{commerce_id}',
		'LastSearchController@forSearchPage'
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

	// Mail to commerce
	Route::post('/mail-to-commerce', 'MailController@mailToCommerce');
	
	// Cupons
	Route::get('cupons', 
		'CuponController@index',
	);
	Route::get('cupons/set-read', 
		'CuponController@setRead',
	);
	Route::get('cupons/search/{commerce_id}/{code}', 
		'CuponController@search',
	);


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
	Route::get('/carts/from-order/{order_id}',
		'CartController@fromOrder'
	);
	Route::put('/carts/update-article-amount/{cart_id}',
		'CartController@update_article_amount'
	);


	// PaymentCardInfo
	Route::post('/payment-card-info',
		'PaymentCardInfoController@store'
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
	Route::post('/orders', 
		'OrderController@store'
	);
	Route::get('/orders/current/{commerce_id}', 
		'OrderController@current'
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

	// Cuenta corriente
	Route::get('/credit-accounts', 'CurrentAcountController@getCreditAccounts');
	Route::get('/current-acount/sale-pdf-token/{sale_id}', 'CurrentAcountController@salePdfToken');
	Route::get('/current-acount/{credit_account_id}/{cantidad_movimientos}', 'CurrentAcountController@getMovements');

	// Destruir sesión guest tras confirmar pedido (sin requerir autenticación previa)
	Route::post('/buyer/logout', 'BuyerController@logout');
// });

