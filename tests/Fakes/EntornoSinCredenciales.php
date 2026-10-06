<?php

namespace Tests\Fakes;

/**
 * Las credenciales REALES de servicios externos no entran a los tests.
 *
 * 🔴 POR QUÉ (6/10/2026). El `.env.testing` de cada slot es una copia del `.env` de desarrollo de Lucas
 * (`setup-pool.ps1`). Hoy los de tienda no traen claves reales, pero la plantilla se copia el día que se arma
 * cada slot, y en `empresa-api` ese mismo archivo trae las de Anthropic, OpenAI, Tienda Nube, Mercado Libre y
 * Mercado Pago. Laravel carga ese archivo ENCIMA de lo que el test crea, así que un pedido que se escapara del
 * freno de internet (`HttpFactorySinSalida`, `StreamHttpSinSalida`, el proxy muerto de `phpunit.xml`) saldría
 * AUTENTICADO como Lucas. En tienda lo leen con `env()`/`getenv()` el SDK de Mercado Pago (`PaymentController`,
 * `CustomerController`, `BuyerHelper`) y Twilio (`TwilioHelper`, `NotificationController`): los dos sacan sus
 * pedidos por un curl propio, que no pasa por la fachada `Http`.
 *
 * Qué hace: antes de que arranque la aplicación de CADA test (`CreatesApplication::createApplication()`), deja
 * con `putenv()` esas variables VACÍAS (que es lo que ven hoy los slots de tienda). Como el dotenv de Laravel es
 * inmutable, lo que ya está definido (en `$_SERVER`, `$_ENV` o `putenv()`) no se pisa con el `.env.testing`.
 *
 * 🔴 `Illuminate\Support\Env` lee `$_SERVER`, después `$_ENV` y recién al final `putenv()` (medido el 6/10/2026 en
 * Laravel 8.83 y en 10.50). Por eso, antes de cada arranque las credenciales viven SOLO en `putenv()`: lo que haya en
 * `$_SERVER` o en `$_ENV` con ese nombre se BORRA (una clave real definida como variable de entorno de Windows llega ahí
 * cuando arranca PHP, o lo que dejó el test anterior). Así la regla es siempre la misma: un test que necesita una clave
 * concreta la pone con `$_SERVER`, `$_ENV`, `putenv()` o `config()` —después del arranque— y gana sobre el valor de mentira.
 *
 * 🔴 Por qué en cada test y no una vez en `phpunit.xml`: un test que simula el entorno con `putenv()` /
 * `$_ENV[...]` y al terminar BORRA la variable deja que el arranque del test siguiente vuelva a cargar el valor
 * real desde `.env.testing`. Reaplicarlo en cada `createApplication()` hace que ningún test pueda dejarle el
 * valor real al siguiente.
 *
 * Los procesos hijos: heredan `getenv()`, pero `proc_open` con un entorno armado a mano descarta los valores
 * vacíos y un hijo que arranca sin la variable la vuelve a leer del `.env.testing`. Un test que lance procesos
 * suma `para_procesos_hijos()` a su entorno.
 *
 * Qué NO hace: las credenciales por comercio (Mercado Pago, Zipnova) viven en la base y las arma cada test; el SMTP de la
 * casilla del comercio (`online_configurations`) lo cubre `Tests\TestCase` con un transporte en memoria. Un test que necesita
 * una clave la pone él con `config([...])`, `putenv()`, `$_ENV` o `$_SERVER`: eso corre DESPUÉS de este arranque y gana.
 */
class EntornoSinCredenciales
{
    /**
     * Valor de mentira que reemplaza a las credenciales presentes. No es una clave válida de nadie.
     */
    const SIN_CREDENCIAL_REAL = 'sin-credencial-real-en-tests';

    /**
     * Credenciales que hoy están presentes en algún `.env.testing`: se reemplazan por un valor de mentira no vacío.
     * (Ninguna: los slots de tienda no las traen. Queda la lista para que el criterio sea el mismo que en `empresa-api`.)
     *
     * @var array<string, string>
     */
    const REEMPLAZADAS = [];

    /**
     * Credenciales que pueden aparecer en un slot nuevo: se dejan vacías, que es lo que ven los slots que no las tienen.
     *
     * @var array<int, string>
     */
    const VACIAS = [
        'MERCADO_PAGO_ACCESS_TOKEN',
        'ENV_ACCESS_TOKEN',
        'TWILIO_API_KEY',
        'TWILIO_API_SECRET',
        'TWILIO_ACCOUNT_SID',
        'TWILIO_VERIFY_SID',
        'TWILIO_NOTIFY_SERVICE_SID',
        'PUSHER_APP_KEY',
        'PUSHER_APP_SECRET',
        'FACEBOOK_CLIENT_SECRET',
        'GOOGLE_SECRET',
        'MAIL_PASSWORD',
    ];

    /**
     * Todas las variables que este arranque neutraliza, con el valor que les deja.
     *
     * @return array<string, string>
     */
    public static function valores(): array
    {
        $valores = self::REEMPLAZADAS;

        foreach (self::VACIAS as $nombre) {
            $valores[$nombre] = '';
        }

        return $valores;
    }

    /**
     * Lo mismo que `valores()`, pero con TODOS los valores no vacíos: es lo que se le pasa a un proceso hijo
     * (`proc_open` con un entorno armado a mano descarta los valores vacíos).
     *
     * @return array<string, string>
     */
    public static function para_procesos_hijos(): array
    {
        $valores = [];

        foreach (self::valores() as $nombre => $valor) {
            $valores[$nombre] = $valor === '' ? self::SIN_CREDENCIAL_REAL : $valor;
        }

        return $valores;
    }

    /**
     * Deja las credenciales neutralizadas en el entorno del proceso: el valor de mentira en `putenv()` y nada en `$_SERVER` ni en
     * `$_ENV` (ver el docblock de la clase). Se llama en cada arranque de la aplicación de test.
     *
     * @return void
     */
    public static function aplicar(): void
    {
        foreach (self::valores() as $nombre => $valor) {
            unset($_SERVER[$nombre], $_ENV[$nombre]);

            putenv($nombre . '=' . $valor);
        }
    }
}
