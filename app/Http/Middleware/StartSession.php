<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

/**
 * El `StartSession` de Laravel con una sola diferencia: un request que termina DESPUÉS de que
 * otro request le destruyó la sesión no la resucita.
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
 * Si el id de sesión NO cambió en este request, EXISTÍA en el handler al arrancar, y YA NO
 * existe al terminar, es porque otro request lo destruyó en el medio (un login o un logout).
 * Entonces este request no guarda la sesión y no manda la cookie: el navegador se queda con la
 * que le dejó el login/logout.
 *
 * 🔴 No guardar es indispensable, no alcanza con no mandar la cookie: si el primer request
 * viejo reescribe el archivo, el segundo request viejo en vuelo lo encuentra "existente" y
 * vuelve a mandar la cookie vieja.
 *
 * En cualquier otro caso —visitante nuevo, sesión normal, request que migra el id como el
 * propio login— el comportamiento es exactamente el de la clase base, en el mismo orden.
 *
 * Se bindea en `AppServiceProvider::register()` sobre la clase base, así lo toman tanto el grupo
 * `web` del Kernel como el pipeline de `EnsureFrontendRequestsAreStateful` de Sanctum (los dos
 * la resuelven por el contenedor, y es el camino de toda la API de la tienda).
 */
class StartSession extends BaseStartSession
{
    /**
     * Igual que el de la clase base, salvo el caso de la sesión destruida por otro request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Contracts\Session\Session  $session
     * @param  \Closure  $next
     * @return mixed
     */
    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        /* Antes de arrancar: el id con el que llega el request (sin cookie, `getSession()` ya le
           asignó uno nuevo al azar, que obviamente no existe) y si existía en el handler. */
        $id_al_arrancar = $session->getId();
        $existia_al_arrancar = $this->existeEnElHandler($session, $id_al_arrancar);

        $request->setLaravelSession(
            $this->startSession($request, $session)
        );

        $this->collectGarbage($session);

        $response = $next($request);

        if ($this->laDestruyoOtroRequest($session, $id_al_arrancar, $existia_al_arrancar)) {
            /* Ni cookie ni save: ver el docblock de la clase. */
            return $response;
        }

        $this->storeCurrentUrl($request, $session);

        $this->addCookieToResponse($response, $session);

        $this->saveSession($request);

        return $response;
    }

    /**
     * ¿Otro request destruyó esta sesión mientras este corría?
     *
     * Las tres condiciones hacen falta:
     *   - el id no cambió en ESTE request (si cambió, fue este mismo request el que migró o
     *     invalidó la sesión —el login, el logout— y su cookie nueva es la buena);
     *   - existía al arrancar (un visitante nuevo tampoco existe al terminar, y a ese hay que
     *     mandarle la cookie);
     *   - ya no existe al terminar.
     *
     * @param  \Illuminate\Contracts\Session\Session  $session
     * @param  string  $id_al_arrancar
     * @param  bool  $existia_al_arrancar
     * @return bool
     */
    protected function laDestruyoOtroRequest($session, $id_al_arrancar, $existia_al_arrancar)
    {
        if (!$existia_al_arrancar) {
            return false;
        }

        if ($session->getId() !== $id_al_arrancar) {
            return false;
        }

        /* El handler de archivos pregunta con is_file()/filemtime(), y PHP cachea el stat del
           último archivo consultado: sin esto podría contestar lo que vio al arrancar. */
        clearstatcache();

        return !$this->existeEnElHandler($session, $id_al_arrancar);
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
}
