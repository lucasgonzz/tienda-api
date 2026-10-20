<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Registro de rutas y límites de tasa para Laravel 10.
 */
class RouteServiceProvider extends ServiceProvider
{
    /**
     * Namespace base para rutas estilo Controller@method.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * Ruta home por defecto (auth redirects).
     */
    public const HOME = '/home';

    /** Texto del 429 de la recuperación de clave: no revela si el correo existe. */
    public const MENSAJE_DEMASIADOS_INTENTOS = 'Hiciste demasiados intentos. Esperá unos minutos y volvé a probar.';

    /** Pedir el código (send-verification-code): pedidos por IP por minuto / por hora. */
    public const PEDIR_POR_MINUTO = 5;
    public const PEDIR_POR_HORA = 20;

    /** Verificar el código (check-verification-code): pedidos por IP por minuto / por hora. */
    public const VERIFICAR_POR_MINUTO = 10;
    public const VERIFICAR_POR_HORA = 60;

    /** Cambiar la clave (update-password): pedidos por IP por minuto / por hora. */
    public const CAMBIAR_POR_MINUTO = 10;
    public const CAMBIAR_POR_HORA = 30;

    /**
     * Registra rutas y el rate limiter `api` usado por throttle:api en Kernel si se migra.
     *
     * @return void
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $user_key = optional($request->user())->id;

            return Limit::perMinute(60)->by($user_key ?? $request->ip());
        });

        $this->registrar_limites_de_recuperar_clave();

        $this->routes(function () {
            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));

            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));
        });
    }

    /**
     * Registra los tres limitadores POR IP de la recuperación de clave (misión
     * throttle-recuperar-clave-por-ip, 20/10/2026), uno por cada ruta pública de
     * `routes/web.php`:
     *
     *   - `recuperar-clave-pedir`    -> POST /password-reset/send-verification-code
     *   - `recuperar-clave-verificar`-> POST /password-reset/check-verification-code
     *   - `recuperar-clave-cambiar`  -> POST /password-reset/update-password
     *
     * Qué frena: barridos de muchos correos desde una misma IP, el bombardeo de mails de código a un
     * comprador y el agotamiento de los intentos de una víctima. Qué NO cambia: los topes POR CUENTA
     * del controlador (5 intentos por código, 5 códigos por hora, 30 min de vida) siguen intactos;
     * esta capa se suma por encima y se evalúa antes de que el pedido llegue al controlador.
     *
     * 🔴 Cada paso tiene dos ventanas (por minuto y por hora) y cada una lleva una CLAVE DISTINTA
     * ('m:' y 'h:' + IP). `ThrottleRequests` arma la clave final como md5(nombre del limitador + clave
     * del Limit): si los dos Limit usaran el mismo `by()` compartirían contador y la ventana por hora
     * se llenaría al ritmo de la del minuto. Y como el nombre del limitador forma parte de la clave,
     * los tres pasos tienen cubos independientes entre sí.
     *
     * 🔴 La IP sale de `$request->ip()`. Hoy `TrustProxies::$proxies` es nulo (no se confía en ningún
     * proxy, así que cuenta la IP de la conexión). Si la tienda pasa a estar detrás de un proxy o
     * balanceador, hay que revisar `TrustProxies`: sin él todos los pedidos aparecerían con la IP del
     * proxy y compartirían un único cubo; y con `'*'` el header X-Forwarded-For se podría falsear para
     * rotar la IP. Es un freno para el script torpe, no una defensa contra un atacante decidido. Otro
     * límite de esta capa: varias personas detrás de una misma IP (un local, una oficina) comparten
     * cubo; por eso los valores por hora dejan margen.
     *
     * Al pasar el límite se responde 429 con un JSON en español (ver `respuesta_de_demasiados_intentos`),
     * una respuesta nueva que no altera ninguna de las existentes.
     *
     * @return void
     */
    protected function registrar_limites_de_recuperar_clave(): void
    {
        // Pedir el código: es el único paso que manda un mail, por eso el más ajustado.
        RateLimiter::for('recuperar-clave-pedir', function (Request $request) {
            return $this->limites_por_ip($request, self::PEDIR_POR_MINUTO, self::PEDIR_POR_HORA);
        });

        // Verificar el código: cada pedido puede gastar un intento del código de la persona.
        RateLimiter::for('recuperar-clave-verificar', function (Request $request) {
            return $this->limites_por_ip($request, self::VERIFICAR_POR_MINUTO, self::VERIFICAR_POR_HORA);
        });

        // Cambiar la clave: también prueba el código, y además escribe la contraseña.
        RateLimiter::for('recuperar-clave-cambiar', function (Request $request) {
            return $this->limites_por_ip($request, self::CAMBIAR_POR_MINUTO, self::CAMBIAR_POR_HORA);
        });
    }

    /**
     * Arma las dos ventanas (minuto y hora) de un paso de la recuperación de clave, por IP.
     *
     * @param  Request  $request
     * @param  int  $por_minuto  Pedidos permitidos por minuto desde una misma IP.
     * @param  int  $por_hora  Pedidos permitidos por hora desde una misma IP.
     * @return \Illuminate\Cache\RateLimiting\Limit[]
     */
    protected function limites_por_ip(Request $request, int $por_minuto, int $por_hora): array
    {
        $ip = $request->ip();

        return [
            // Claves distintas ('m:' / 'h:') para que las dos ventanas lleven contadores separados.
            Limit::perMinute($por_minuto)
                ->by('m:' . $ip)
                ->response(function (Request $request, array $headers) {
                    return $this->respuesta_de_demasiados_intentos($headers);
                }),
            Limit::perHour($por_hora)
                ->by('h:' . $ip)
                ->response(function (Request $request, array $headers) {
                    return $this->respuesta_de_demasiados_intentos($headers);
                }),
        ];
    }

    /**
     * Respuesta 429 de la recuperación de clave cuando una IP pasa el límite.
     *
     * El texto es genérico a propósito: no menciona el correo ni dice si existe una cuenta, así que el
     * límite no sirve para averiguar qué correos están registrados. `errors.email` repite el mensaje
     * con la forma de una validación de Laravel. `$headers` trae los de Laravel (`Retry-After`,
     * `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`) de la ventana que se agotó.
     *
     * @param  array  $headers  Headers de límite de tasa armados por ThrottleRequests.
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_de_demasiados_intentos(array $headers)
    {
        return response()->json([
            'message' => self::MENSAJE_DEMASIADOS_INTENTOS,
            'errors'  => [
                'email' => [self::MENSAJE_DEMASIADOS_INTENTOS],
            ],
        ], 429, $headers);
    }
}
