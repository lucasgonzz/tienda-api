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
Route::get('/sub-categories/{category_id}',
	'HomeController@subCategories'
);
Route::get('/articles/from-category/{category_id}/{sub_category_id}/{commerce_id}',
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
Route::post('advises',
	'AdviseController@store'
);
// Conditions
Route::get('conditions', 
	'ConditionController@index'
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
// });

