<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Registro de callbacks reportables (Laravel 10).
     *
     * @return void
     */
    public function register(): void
    {
        //
    }

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \Exception
     */
    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $exception)
    {
        return parent::render($request, $exception);
    }

    /**
     * Respuesta cuando falta autenticacion. SIEMPRE 401 JSON.
     *
     * 🔴 Esto NO se puede resolver desde Authenticate@redirectTo, y el intento anterior de esta
     * misma mision lo hizo mal. El handler de Laravel 10 hace, en
     * vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php:570:
     *
     *     : redirect()->guest($exception->redirectTo() ?? route('login'));
     *
     * O sea que devolver null desde redirectTo() **no evita** la llamada a route('login'): la
     * habilita, por el `??`. Y en este repo no existe ninguna ruta con nombre 'login'
     * (routes/web.php declara POST /login sin ->name()). Resultado medido: 500 con
     * RouteNotFoundException y la traza completa en el cuerpo cuando APP_DEBUG esta prendido.
     *
     * Importa mas que antes porque esta misma mision movio ~25 rutas detras de auth:buyer: hasta
     * ahora casi nadie llegaba a ese camino, y ahora lo dispara cualquier request sin
     * `Accept: application/json` — un curl, un bot, la previsualizacion de un link.
     *
     * Este repo es una API sin ninguna vista de login, asi que no hay a donde redirigir: la
     * respuesta correcta para todo cliente es 401.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Auth\AuthenticationException  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
