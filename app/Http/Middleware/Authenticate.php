<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * A donde redirigir cuando no hay sesion.
     *
     * 🔴 Devuelve null SIEMPRE, y no es un descuido: este repo es una API sin ninguna vista de
     * login. La version anterior hacia `route('login')` cuando el request no pedia JSON, y en
     * este repo NO existe ninguna ruta con nombre 'login' (routes/web.php declara
     * `Route::post('/login', 'AuthController@login')` sin ->name('login')). O sea que cualquier
     * request sin `Accept: application/json` contra una ruta con `auth:buyer` reventaba con un
     * RouteNotFoundException 500 en vez de un 401 limpio.
     *
     * Con null, el middleware base tira AuthenticationException y el handler responde 401.
     * Importa para la auditoria: un 500 no distingue "no estas autenticado" de "el servidor esta
     * roto", y es justo la señal que mira quien esta probando la superficie publica.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        return null;
    }
}
