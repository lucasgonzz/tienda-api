<?php

namespace Tests\Fakes;

use Illuminate\Http\Client\Factory;

/**
 * El cliente HTTP de los tests: el de Laravel, con una sola diferencia — un pedido que NINGÚN
 * `Http::fake()` atiende no sale a internet: lanza `PedidoHttpRealBloqueado`.
 *
 * 🔴 POR QUÉ EXISTE (5/10/2026). Un test de `admin-api` (`ClaudeImplementaciones`) avanzó una
 * implementación a la etapa 2 sin falsear la llamada al user setup, y el POST salió de verdad a
 * `https://api-<cliente>.comerciocity.com/api/admin-sync/user-setup`. Del otro lado ese endpoint corre
 * `migrate:fresh`: vació la base de producción de un cliente real. Un test no puede ser capaz de eso, y
 * `tienda-api` tenía el mismo agujero: la suite solo falseaba lo que alguien pensó en falsear, y el
 * resto confiaba en no llamar a nada.
 *
 * Laravel 10 trae `Http::preventStrayRequests()`, pero lanza una excepción pelada y nada más: la
 * aplicación atrapa `\Throwable` casi en todas partes, y un test cuyo código se la traga queda en verde
 * (es exactamente lo que pasó el 5/10). Esta fábrica hace lo mismo y además ANOTA cada pedido frenado,
 * para que `Tests\TestCase` haga fallar al test que lo intentó.
 *
 * CÓMO FUNCIONA. Cada pedido pasa por una lista de "stubs" (los que registra `Http::fake()`), y
 * Laravel se queda con la primera respuesta que no sea nula; si todos devuelven nulo, deja que el
 * pedido siga hacia la red. Acá se envuelve esa lista en un único stub que hace exactamente lo mismo
 * (se invocan TODOS los stubs, igual que en el original, y gana el primero que responde) y, si
 * ninguno respondió, en vez de dejar seguir el pedido lo frena. Con un fake que cubre la URL, el
 * comportamiento es idéntico al de siempre. También cubre `Http::pool()`: el `Pool` arma cada pedido con
 * `$factory->setHandler(...)`, que pasa por el mismo `__call`.
 *
 * Cada pedido frenado queda anotado (`frenados()`): `Tests\TestCase` los vuelca a
 * `storage/logs/http-frenado-en-tests.log` al terminar el test y lo hace FALLAR. La misma lista la usa
 * `StreamHttpSinSalida` (los pedidos por streams de PHP).
 *
 * Esto es la primera de varias capas; las otras: `StreamHttpSinSalida` (todo lo que sale por
 * `file_get_contents`, `get_headers`, `getimagesize`…), un proxy muerto en `phpunit.xml` (para curl —el
 * SDK de Mercado Pago y Twilio— y para Guzzle armado a mano, sin pasar por acá) y las credenciales
 * neutralizadas (`EntornoSinCredenciales`).
 */
class HttpFactorySinSalida extends Factory
{
    /**
     * Los pedidos frenados desde el último `vaciar()`, como "POST https://…" (o "STREAM https://…").
     *
     * @var array<int, string>
     */
    protected static $frenados = [];

    /**
     * @return array<int, string> Los pedidos que se frenaron desde el último `vaciar()`.
     */
    public static function frenados(): array
    {
        return self::$frenados;
    }

    /**
     * Borra la lista de pedidos frenados (`Tests\TestCase` lo hace al arrancar cada test).
     *
     * @return void
     */
    public static function vaciar(): void
    {
        self::$frenados = [];
    }

    /**
     * Anota un pedido frenado. Lo usan las demás barreras (`StreamHttpSinSalida`) para que todas
     * compartan una sola lista y una sola alarma.
     *
     * @param string $pedido Descripción del pedido, como "STREAM https://…".
     *
     * @return void
     */
    public static function anotar(string $pedido): void
    {
        self::$frenados[] = $pedido;
    }

    /**
     * Igual que `Factory::__call()`, salvo que la lista de stubs del pedido es un único stub que, si
     * ninguno de los registrados responde, frena el pedido.
     *
     * @param string $method     Método del `PendingRequest` (`post`, `get`, `withHeaders`…).
     * @param array  $parameters Argumentos.
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        $stubs = $this->stubCallbacks;

        $guarda = function ($request, $opciones) use ($stubs) {
            $respuesta = $stubs->map->__invoke($request, $opciones)->filter()->first();

            if ($respuesta !== null) {
                return $respuesta;
            }

            $pedido = $request->method() . ' ' . $request->url();

            self::anotar($pedido);

            throw new PedidoHttpRealBloqueado(
                'Pedido HTTP real bloqueado en un test: ' . $pedido . '. Ningún Http::fake() lo atiende y los '
                . 'tests de tienda-api no salen a internet. Falsealo con Http::fake([...]).'
            );
        };

        return tap($this->newPendingRequest(), function ($pendiente) use ($guarda) {
            $pendiente->stub(collect([$guarda]));
        })->{$method}(...$parameters);
    }
}
