<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\Fakes\StreamHttpSinSalida;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Cada test arranca SIN salida a internet.
     *
     * 🔴 La salida a internet: ver cerrar_la_salida_a_internet().
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cerrar_la_salida_a_internet();
    }

    /**
     * La alarma del freno de internet: si el test intentó un pedido que ningún `Http::fake()` atiende (o se llevó puesto el freno),
     * FALLA.
     *
     * 🔴 Lanzar `PedidoHttpRealBloqueado` no alcanza: la aplicación atrapa `\Throwable` casi en todas partes, así que un servicio que
     * se traga la excepción deja el test en verde (es lo que pasó el 5/10/2026 en admin-api: el test "pasó" y el pedido salió). Por eso
     * cada pedido frenado se anota (`storage/logs/http-frenado-en-tests.log`) y, además, hace fallar al test que lo intentó.
     *
     * 🔴 Va acá y no en `tearDown()`: PHPUnit corre `assertPostConditions()` DESPUÉS del test y ANTES de `tearDown()`, y siempre corre
     * `tearDown()` aunque esto falle. Si la falla se levantara en el `tearDown()` de esta clase, un test que limpia algo DESPUÉS de
     * `parent::tearDown()` se quedaría sin limpiar justo cuando la alarma salta. Y si `setUp()` revienta, esto no corre: el error que se
     * ve es el original.
     *
     * Un test que sobreescriba este método tiene que llamar a `parent::assertPostConditions()` (un test de `tests/Unit` lo verifica).
     *
     * @return void
     */
    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        $this->exigir_que_el_freno_siga_puesto();
        $this->exigir_que_no_se_haya_frenado_ningun_pedido();
    }

    /**
     * Si el test falló antes de llegar a `assertPostConditions()` (el código bajo prueba dejó salir la excepción del freno, por
     * ejemplo), igual queda anotado en el registro de auditoría lo que intentó salir. No levanta ninguna falla: el test ya falló.
     *
     * `parent::tearDown()` corre SIEMPRE: ahí se deshace la transacción de la base.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->anotar_los_pedidos_frenados();

        HttpFactorySinSalida::vaciar();

        parent::tearDown();
    }

    /**
     * Ningún test de tienda-api sale a internet.
     *
     * 🔴 POR QUÉ (5/10/2026). Un test de `admin-api` avanzó una implementación a la etapa 2 sin falsear la llamada al user
     * setup: el POST salió de verdad a `https://api-<cliente>.comerciocity.com/api/admin-sync/user-setup`, que del otro lado
     * corre `migrate:fresh`, y vació la base de producción de un cliente real. `tienda-api` tenía el mismo agujero: solo
     * falseaba lo que alguien pensó en falsear (Zipnova, Mercado Pago, SEO) y el resto confiaba en no llamar a nada.
     *
     * Lo que hace esto, y qué NO hace:
     *  - Cambia el cliente HTTP por `HttpFactorySinSalida`: un pedido que ningún `Http::fake()` atiende lanza
     *    `PedidoHttpRealBloqueado` en vez de salir (Laravel 10 trae `Http::preventStrayRequests()`, que lanza pero no anota,
     *    y la aplicación se traga la excepción). Con un fake que lo cubre, nada cambia. Se construye con el despachador de
     *    eventos de la aplicación, como el que arma el contenedor. Un test que necesite una fábrica limpia (los stubs de
     *    `Http::fake()` se acumulan y gana el primero) tiene que hacer
     *    `Http::swap(new HttpFactorySinSalida(app('events')))`, NO `Http::swap(new \Illuminate\Http\Client\Factory(...))`:
     *    esta última deja el test sin freno, y `assertPostConditions()` lo detecta y hace fallar al test.
     *  - Reemplaza los wrappers `http://` y `https://` de PHP por `StreamHttpSinSalida`: `file_get_contents($url)`,
     *    `get_headers($url)`, `getimagesize($url)`, `copy($url, …)` no pasan por la fachada ni respetan un proxy. Se reinstala
     *    en CADA test: un test que toque los wrappers por su cuenta no tiene que dejar la barrera apagada.
     *  - Pone el transporte `smtp` de los mailers en memoria (`ArrayTransport`): `ClientMailConfigHelper::apply()` pisa en
     *    caliente `mail.default` con el SMTP de la casilla del comercio (host, usuario y clave de `online_configurations`, que
     *    viven en la base) —lo dispara `SendOrderEmails`, que `OrderController` despacha después de la respuesta— y eso anularía
     *    `MAIL_MAILER=array` de `phpunit.xml`: un SMTP abre su propio socket.
     *  - `phpunit.xml` agrega un proxy muerto (curl nativo y Guzzle armado a mano: el SDK de Mercado Pago de
     *    `MercadoPagoController::preference()` / `PaymentController` y Twilio sacan sus pedidos por un curl propio) y fija
     *    el broadcast y el mailer; `EntornoSinCredenciales` deja vacías las credenciales de servicios externos.
     *  - NO cubre lo que no pasa por nada de eso: SSH (phpseclib), SOAP, Web Push y los sockets crudos. Dependen de datos que
     *    el test mismo arma.
     *
     * @return void
     */
    protected function cerrar_la_salida_a_internet(): void
    {
        HttpFactorySinSalida::vaciar();

        StreamHttpSinSalida::instalar();

        Http::swap(new HttpFactorySinSalida(app('events')));

        app('mail.manager')->extend('smtp', function () {
            return new ArrayTransport();
        });
    }

    /**
     * Lo que corre en `assertPostConditions()`: si el freno frenó pedidos en este test, los anota, vacía la lista (para que no se
     * le cuenten al test siguiente) y levanta la falla, con la lista de pedidos y qué hacer.
     *
     * Un test que frena pedidos A PROPÓSITO (los de las barreras mismas) vacía la lista él antes de terminar:
     * `HttpFactorySinSalida::vaciar()`.
     *
     * @return void
     *
     * @throws AssertionFailedError Si se frenó algún pedido.
     */
    protected function exigir_que_no_se_haya_frenado_ningun_pedido(): void
    {
        $frenados = HttpFactorySinSalida::frenados();

        if ($frenados === []) {
            return;
        }

        $this->anotar_los_pedidos_frenados();

        HttpFactorySinSalida::vaciar();

        throw new AssertionFailedError(
            'Este test intentó ' . count($frenados) . ' pedido(s) HTTP que ningún Http::fake() atiende, y el freno los detuvo: '
            . implode('; ', $frenados) . '. No salieron, pero el código bajo prueba se tragó la excepción y el test siguió. '
            . 'Falseá cada uno con Http::fake([...]) o apuntá la fixture a un host .test. Un test no puede tener a mano un pedido real '
            . '(el 5/10/2026 uno le vació la base de producción a un cliente).'
        );
    }

    /**
     * Lo que corre en `assertPostConditions()`: si el cliente HTTP con el que terminó el test ya no es el que frena, el test FALLA.
     *
     * 🔴 Un `Http::swap(new \Illuminate\Http\Client\Factory())` "para limpiar los stubs" se lleva puesto el freno: desde ahí
     * cualquier pedido que el `Http::fake()` siguiente no cubra sale de verdad. Se detecta al terminar el test, aunque no haya
     * salido ningún pedido (la próxima edición del test puede agregar uno). Un doble de Mockery (`Http::shouldReceive()`) sí
     * se acepta: no tiene red.
     *
     * @return void
     *
     * @throws AssertionFailedError Si el cliente HTTP no frena.
     */
    protected function exigir_que_el_freno_siga_puesto(): void
    {
        if (! $this->app) {
            return;
        }

        $raiz = Http::getFacadeRoot();

        if ($raiz instanceof HttpFactorySinSalida || $raiz instanceof \Mockery\MockInterface) {
            return;
        }

        throw new AssertionFailedError(
            'Este test dejó un cliente HTTP que NO frena (' . (is_object($raiz) ? get_class($raiz) : gettype($raiz)) . '): un '
            . 'Http::swap(new Factory(...)) se lleva puesto el freno de internet de los tests. Usá '
            . "Http::swap(new \\Tests\\Fakes\\HttpFactorySinSalida(app('events'))), o Http::fake([...]) sin el swap."
        );
    }

    /**
     * Vuelca a `storage/logs/http-frenado-en-tests.log` los pedidos que el freno detuvo en este test (si hubo). Nunca rompe un
     * test: es solo un registro.
     *
     * @return void
     */
    protected function anotar_los_pedidos_frenados(): void
    {
        $frenados = HttpFactorySinSalida::frenados();

        if ($frenados === []) {
            return;
        }

        try {
            $lineas = '';

            foreach ($frenados as $pedido) {
                $lineas .= date('Y-m-d H:i:s') . ' ' . static::class . '::' . $this->name() . ' ' . $pedido . PHP_EOL;
            }

            file_put_contents(storage_path('logs/http-frenado-en-tests.log'), $lineas, FILE_APPEND);
        } catch (\Throwable $excepcion) {
            // Es un registro de auditoría: si no se puede escribir, el test sigue igual.
        }
    }
}
