<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * El `StartSession` de Laravel con una sola diferencia: un request que corre con el id de una
 * sesión que un login/logout ya reemplazó no la resucita.
 *
 * ── EL CASO MEDIDO (Fenix, 23/9/2026) ───────────────────────────────────────────────────────
 *
 * El comprador 1187 ("Pinocho", vinculado al Client 750 del ERP) entraba logueado a
 * `fenix-mayorista.com.ar` y veía los artículos SIN PRECIO. No siempre: a veces sí, a veces no.
 * Lo que dejó el `laravel.log` de ese día:
 *
 *   - 08:30:30  la sesión logueada busca "sonic".
 *   - 08:30:47  se crea el carrito 5208 en una sesión ANÓNIMA, sin clave de login.
 *   - 08:31–08:35  catorce carritos (5209–5222) con `buyer_id NULL`, todos reventando con
 *     `Column 'price' cannot be null`. El SPA solo guarda el carrito en el servidor si cree
 *     tener sesión: o sea que el SPA estaba "logueado" y el servidor lo veía anónimo.
 *   - El archivo de la sesión anónima seguía existiendo con mtime 09:25:10, el segundo exacto
 *     de un login — aunque el login hace `session->migrate(true)`, que lo borra.
 *
 * ── POR QUÉ PASA ────────────────────────────────────────────────────────────────────────────
 *
 * El login hace `migrate(true)`: id nuevo (logueado) y el archivo del id viejo destruido. Pero
 * un request que arrancó ANTES del login con el id viejo —la home, una búsqueda; lentos porque
 * la tienda de Fenix vive en el shared y lee la base del VPS por el puente— termina DESPUÉS, y
 * el `StartSession` de Laravel 10.50 hace `addCookieToResponse()` + `saveSession()` sin mirar
 * nada: vuelve a escribir el archivo del id viejo (anónimo) y le manda al navegador
 * `Set-Cookie: laravel_session=<id viejo>`. Desde ahí todo request es anónimo: con la tienda en
 * "solo vinculados", `ArticleHelper::checkPriceTypes()` le borra los precios, y los carritos se
 * crean sin comprador. El SPA, mientras tanto, sigue mostrando "logueado" porque eso lo sacó
 * de la respuesta del login.
 *
 * Es intermitente por naturaleza: depende de que haya un request lento en vuelo justo en el
 * momento del login. Y el agujero es el mismo al revés en el logout: un request logueado en
 * vuelo resucita la sesión logueada después de salir.
 *
 * ── LA REGLA ────────────────────────────────────────────────────────────────────────────────
 *
 * Hay DOS ventanas, y las dos terminan igual (el navegador vuelve a recibir el id viejo):
 *
 *   1. El request SALIÓ antes del login y terminó después. Al arrancar, el id existía; al
 *      terminar, ya no (el login lo destruyó en el medio).
 *   2. El request salió del navegador con la cookie vieja y LLEGÓ al servidor después del
 *      `migrate(true)` / `invalidate()`, pero antes de que el navegador recibiera la cookie
 *      nueva. Al arrancar el id ya no existe, así que la condición de arriba no lo ve: sin nada
 *      más, lo guarda y manda `Set-Cookie` con el id viejo (lo reprodujo el chequeo
 *      independiente con un request real por el grupo `api`).
 *
 * Para la segunda hace falta memoria: cuando un request termina con el id CAMBIADO y el id de
 * arranque EXISTÍA (o sea, un login o un logout de verdad), se deja en el cache una MARCA DE
 * MIGRACIÓN para el id viejo, con TTL de diez minutos, antes de mandar la cookie nueva.
 *
 * Al terminar un request con el id SIN cambiar, se saltea el save y la cookie si:
 *   (a) el id existía al arrancar y ya no existe, o
 *   (b) hay marca de migración para ese id, exista o no el archivo.
 * En ese camino también se saca de la respuesta la cookie `XSRF-TOKEN`: `VerifyCsrfToken` corre
 * adentro de este middleware y la agrega con el token de la sesión vieja, y después de un logout
 * (que hace `regenerateToken`) eso deja el XSRF desincronizado y el próximo POST da 419.
 *
 * 🔴 No guardar es indispensable, no alcanza con no mandar la cookie: si el primer request
 * viejo reescribe el archivo, el segundo request viejo en vuelo lo encuentra "existente" y
 * vuelve a mandar la cookie vieja.
 *
 * 🔴 Y NO se hace "forwarding" del id viejo al nuevo (adoptar la sesión nueva cuando llega el
 * id viejo): eso anularía la protección contra fijación de sesión que da `migrate(true)`. El
 * request con el id marcado corre como corre hoy —anónimo, con la sesión vacía—; lo único que
 * cambia es que no guarda y no manda la cookie.
 *
 * Si el cache falla, el request no se rompe: se sigue con la regla (a) sola, que no lo usa.
 *
 * En cualquier otro caso —visitante nuevo, sesión normal, cookie con un id vencido sin marca,
 * request que migra el id como el propio login— el comportamiento es exactamente el de la
 * clase base, en el mismo orden.
 *
 * Se bindea en `AppServiceProvider::register()` sobre la clase base, así lo toman tanto el grupo
 * `web` del Kernel como el pipeline de `EnsureFrontendRequestsAreStateful` de Sanctum (los dos
 * la resuelven por el contenedor, y es el camino de toda la API de la tienda).
 */
class StartSession extends BaseStartSession
{
    /**
     * Cuánto vive la marca de migración de un id, en segundos. Alcanza con cubrir los requests
     * que el navegador tenía en vuelo al momento del login/logout.
     */
    const SEGUNDOS_DE_LA_MARCA = 600;

    /**
     * Igual que el de la clase base, salvo los casos del docblock de la clase.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Contracts\Session\Session  $session
     * @param  \Closure  $next
     * @return mixed
     */
    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        /* El request va al handler ANTES de la primera lectura: `CookieSessionHandler::read()`
           lo usa, y sin esto el driver `cookie` revienta con un 500. */
        $session->setRequestOnHandler($request);

        /* El id con el que llega el request (sin cookie, `getSession()` ya le asignó uno nuevo
           al azar, que obviamente no existe) y si existía en el handler. */
        $id_al_arrancar = $session->getId();
        $existia_al_arrancar = $this->existeEnElHandler($session, $id_al_arrancar);

        $request->setLaravelSession(
            $this->startSession($request, $session)
        );

        $this->collectGarbage($session);

        $response = $next($request);

        if ($session->getId() !== $id_al_arrancar) {

            /* Este request migró o invalidó la sesión (login, logout): el id viejo queda marcado
               ANTES de mandar la cookie nueva, para que los requests que el navegador todavía
               tiene en vuelo con el id viejo no lo resuciten. */
            if ($existia_al_arrancar) {
                $this->marcarMigracion($id_al_arrancar);
            }

        } else if ($this->laReemplazoOtroRequest($session, $id_al_arrancar, $existia_al_arrancar)) {

            /* Ni cookie ni save: ver el docblock de la clase. */
            $this->sacarCookieXsrf($response);

            return $response;
        }

        $this->storeCurrentUrl($request, $session);

        $this->addCookieToResponse($response, $session);

        $this->saveSession($request);

        return $response;
    }

    /**
     * ¿Otro request (un login o un logout) reemplazó esta sesión? Solo se pregunta cuando el id
     * NO cambió en este request.
     *
     *   (a) existía al arrancar y ya no existe: la destruyeron mientras este corría;
     *   (b) tiene marca de migración: la reemplazaron antes de que este llegara, o mientras
     *       corría, exista o no el archivo.
     *
     * @param  \Illuminate\Contracts\Session\Session  $session
     * @param  string  $id_al_arrancar
     * @param  bool  $existia_al_arrancar
     * @return bool
     */
    protected function laReemplazoOtroRequest($session, $id_al_arrancar, $existia_al_arrancar)
    {
        if ($existia_al_arrancar) {
            /* El handler de archivos pregunta con is_file()/filemtime(), y PHP cachea el stat
               del último archivo consultado: sin esto podría contestar lo que vio al arrancar. */
            clearstatcache();

            if (!$this->existeEnElHandler($session, $id_al_arrancar)) {
                return true;
            }
        }

        return $this->tieneMarcaDeMigracion($id_al_arrancar);
    }

    /**
     * ¿El handler tiene datos para ese id? `read()` devuelve '' tanto si no existe como si
     * venció, que para este caso es lo mismo.
     *
     * @param  \Illuminate\Contracts\Session\Session  $session
     * @param  string|null  $id
     * @return bool
     */
    protected function existeEnElHandler($session, $id)
    {
        if (!is_string($id) || $id === '') {
            return false;
        }

        return $session->getHandler()->read($id) !== '';
    }

    /**
     * Deja la marca de migración del id viejo en el cache por defecto de la app (en las tiendas
     * de producción, `file`: ver `config/cache.php` y `.env.example`). Si el cache falla, se
     * sigue sin marca: el request no se rompe.
     *
     * @param  string  $id
     * @return void
     */
    protected function marcarMigracion($id)
    {
        try {
            Cache::put($this->claveDeLaMarca($id), 1, self::SEGUNDOS_DE_LA_MARCA);
        } catch (\Throwable $e) {
            Log::warning('StartSession: no se pudo escribir la marca de migracion de sesion: '.$e->getMessage());
        }
    }

    /**
     * @param  string  $id
     * @return bool  false también si el cache falla
     */
    protected function tieneMarcaDeMigracion($id)
    {
        if (!is_string($id) || $id === '') {
            return false;
        }

        try {
            return Cache::has($this->claveDeLaMarca($id));
        } catch (\Throwable $e) {
            Log::warning('StartSession: no se pudo leer la marca de migracion de sesion: '.$e->getMessage());

            return false;
        }
    }

    /**
     * La clave va hasheada: el id de sesión crudo no se guarda en ningún lado fuera del handler.
     *
     * @param  string  $id
     * @return string
     */
    protected function claveDeLaMarca($id)
    {
        return 'sesion-migrada:'.sha1($id);
    }

    /**
     * Saca de la respuesta la cookie `XSRF-TOKEN` que puso `VerifyCsrfToken` con el token de la
     * sesión reemplazada. Solo si la respuesta tiene headers de Symfony.
     *
     * @param  mixed  $response
     * @return void
     */
    protected function sacarCookieXsrf($response)
    {
        if (!is_object($response) || !isset($response->headers) || !($response->headers instanceof ResponseHeaderBag)) {
            return;
        }

        $config = $this->manager->getSessionConfig();

        $response->headers->removeCookie(
            'XSRF-TOKEN',
            isset($config['path']) ? $config['path'] : '/',
            isset($config['domain']) ? $config['domain'] : null
        );
    }
}
