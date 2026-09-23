<?php

namespace Tests\Feature\Sesion;

use App\Http\Middleware\StartSession;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Un request que corre con el id de una sesion que un login/logout ya reemplazo no le vuelve a
 * mandar al navegador esa cookie, ni resucita la sesion (mision tienda-precios-buyer-logueado,
 * 23/9/2026 — el caso medido en Fenix esta en el docblock de App\Http\Middleware\StartSession).
 *
 * Las dos ventanas: el request que salio ANTES del login y termino despues (la sesion existia al
 * arrancar y ya no), y el que salio del navegador con la cookie vieja pero LLEGO despues del
 * login (la sesion ya no existia al arrancar: lo frena la marca de migracion en el cache).
 *
 * ── Como se arma ─────────────────────────────────────────────────────────────────────────────
 *
 * El middleware se instancia directo, sin rutas: cada "request" es un Request con la cookie de
 * sesion y un $next que hace lo que haria el otro request (destruir el id, migrarlo como el
 * login) o nada. El driver es `file` sobre una carpeta temporal, que es el de produccion: cada
 * "request" tiene su PROPIO SessionManager (su propio Store y su propio handler), y lo unico
 * que comparten es la carpeta, igual que dos procesos PHP del shared hosting.
 *
 * No toca la base.
 */
class SesionDestruidaPorOtroRequestTest extends TestCase
{
    /** @var string */
    private $carpeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->carpeta = storage_path('framework/testing/sesiones-'.Str::random(8));
        File::ensureDirectoryExists($this->carpeta);

        config([
            'session.driver'   => 'file',
            'session.files'    => $this->carpeta,
            'session.lifetime' => 120,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->carpeta);

        parent::tearDown();
    }

    /**
     * 🔴 El binding tiene que ganarle al singleton que registra SessionServiceProvider: si no,
     * ni el grupo `web` ni el pipeline de Sanctum usan esta clase y todo lo de abajo es teoria.
     */
    public function test_el_contenedor_entrega_el_start_session_propio()
    {
        $resuelto = app(BaseStartSession::class);

        $this->assertInstanceOf(StartSession::class, $resuelto,
            'El StartSession que resuelve el contenedor tiene que ser el de App, no el del framework.');
        $this->assertSame($resuelto, app(BaseStartSession::class),
            'Sigue siendo singleton, como lo registra el framework.');
    }

    /**
     * 🔴 EL CASO DE FENIX: un request arranca con la sesion anonima, en el medio el login la
     * destruye (migrate(true)), y este termina despues. No puede mandar la cookie vieja ni
     * volver a escribir la sesion destruida.
     */
    public function test_si_otro_request_destruye_la_sesion_en_el_medio_no_hay_cookie_ni_se_resucita()
    {
        $id = $this->sesionExistente();

        $respuesta = $this->correr($id, function () use ($id) {
            /* El login, en otro proceso: borra el archivo del id viejo. */
            $this->otroHandler()->destroy($id);
        });

        $this->assertNull($this->cookieDeSesion($respuesta),
            'La respuesta en vuelo no puede pisarle al navegador la cookie que le dejo el login.');
        $this->assertSame('', $this->otroHandler()->read($id),
            'La sesion destruida no puede volver a escribirse.');
    }

    /**
     * 🔴 Por que no alcanza con sacar la cookie: con DOS requests viejos en vuelo, si el primero
     * reescribiera el archivo, el segundo lo veria "existente" y mandaria la cookie vieja.
     */
    public function test_con_dos_requests_en_vuelo_ninguno_manda_la_cookie_vieja()
    {
        $id = $this->sesionExistente();

        $respuesta_de_afuera = null;
        $respuesta_de_adentro = null;

        /* El de afuera arranca primero y termina ultimo; el de adentro arranca despues que el
           de afuera, y el login destruye la sesion mientras los dos estan en vuelo. */
        $respuesta_de_afuera = $this->correr($id, function () use ($id, &$respuesta_de_adentro) {
            $respuesta_de_adentro = $this->correr($id, function () use ($id) {
                $this->otroHandler()->destroy($id);
            });
        });

        $this->assertNull($this->cookieDeSesion($respuesta_de_adentro));
        $this->assertNull($this->cookieDeSesion($respuesta_de_afuera),
            'El segundo request viejo tampoco puede mandar la cookie vieja.');
        $this->assertSame('', $this->otroHandler()->read($id));
    }

    /** Sin regresion: un request comun con sesion existente manda su cookie y guarda la sesion. */
    public function test_un_request_comun_manda_la_cookie_y_guarda_la_sesion()
    {
        $id = $this->sesionExistente();

        $respuesta = $this->correr($id, function (Request $request) {
            $request->session()->put('carritos_propios', [5208]);
        });

        $this->assertSame($id, $this->cookieDeSesion($respuesta));
        $this->assertSame([5208], $this->leer($id)['carritos_propios'] ?? null,
            'Lo que el request puso en la sesion tiene que quedar guardado.');
    }

    /**
     * Sin regresion: el request que migra el id —el propio login— manda la cookie con el id
     * NUEVO, deja guardada la sesion nueva y la vieja destruida.
     */
    public function test_el_request_que_migra_el_id_manda_la_cookie_con_el_id_nuevo()
    {
        $id_viejo = $this->sesionExistente();

        $respuesta = $this->correr($id_viejo, function (Request $request) {
            $request->session()->migrate(true);
            $request->session()->put('login_buyer', 1187);
        });

        $id_nuevo = $this->cookieDeSesion($respuesta);

        $this->assertNotNull($id_nuevo, 'El login tiene que mandar la cookie.');
        $this->assertNotSame($id_viejo, $id_nuevo, 'Y con el id nuevo, no el viejo.');
        $this->assertSame(1187, $this->leer($id_nuevo)['login_buyer'] ?? null);
        $this->assertSame('', $this->otroHandler()->read($id_viejo));
    }

    /** Sin regresion: un visitante nuevo (sin cookie) recibe su cookie y su sesion se guarda. */
    public function test_un_visitante_nuevo_recibe_la_cookie()
    {
        $respuesta = $this->correr(null, function (Request $request) {
            $request->session()->put('visto', true);
        });

        $id = $this->cookieDeSesion($respuesta);

        $this->assertNotNull($id, 'El visitante nuevo tiene que recibir la cookie de sesion.');
        $this->assertTrue($this->leer($id)['visto'] ?? false);
    }

    /**
     * 🔴 LA SEGUNDA VENTANA (H1 del chequeo independiente): el login ya migro la sesion, y
     * DESPUES llega un request que el navegador mando con la cookie vieja antes de recibir la
     * nueva. Al arrancar, el id viejo ya no existe en disco — la regla de "existia y ya no" no lo
     * ve —, y sin la marca de migracion lo guardaba y mandaba `Set-Cookie` con el id viejo.
     *
     * Corre anonimo con la sesion vacia (no se adopta la sesion nueva: eso anularia la
     * proteccion contra fijacion de sesion de migrate(true)); solo no guarda ni manda cookies.
     */
    public function test_un_request_con_el_id_viejo_que_llega_despues_del_login_no_lo_resucita()
    {
        $id_viejo = $this->sesionExistente();

        $login = $this->correr($id_viejo, function (Request $request) {
            $request->session()->migrate(true);
            $request->session()->put('login_buyer', 1187);
        });
        $id_nuevo = $this->cookieDeSesion($login);
        $this->assertNotSame($id_viejo, $id_nuevo);

        $sesion_vista = null;
        $respuesta = $this->correr($id_viejo, function (Request $request) use (&$sesion_vista) {
            $sesion_vista = $request->session()->all();
            $request->session()->put('carritos_propios', [5209]);

            return $this->respuestaConXsrf($request);
        });

        $this->assertNull($this->cookieDeSesion($respuesta),
            'El request que llego tarde con el id viejo no puede pisarle al navegador la cookie del login.');
        $this->assertNull($this->cookieXsrf($respuesta),
            'Tampoco el XSRF-TOKEN de la sesion vieja.');
        $this->assertSame('', $this->otroHandler()->read($id_viejo),
            'El archivo del id viejo no se puede recrear.');
        $this->assertArrayNotHasKey('login_buyer', $sesion_vista,
            'El request con el id viejo NO adopta la sesion nueva: corre anonimo, como hoy.');
        $this->assertSame(1187, $this->leer($id_nuevo)['login_buyer'] ?? null,
            'La sesion nueva sigue intacta.');
    }

    /**
     * El logout con `invalidate()` mientras un request logueado esta en vuelo: la respuesta en
     * vuelo no resucita la sesion logueada ni manda el XSRF-TOKEN viejo (que despues del
     * `regenerateToken` del logout dejaria el proximo POST en 419).
     */
    public function test_un_request_en_vuelo_durante_el_logout_no_manda_la_cookie_ni_el_xsrf()
    {
        $id = $this->sesionExistente();

        $logout = null;

        $en_vuelo = $this->correr($id, function (Request $request) use ($id, &$logout) {
            $logout = $this->correr($id, function (Request $request) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            });

            return $this->respuestaConXsrf($request);
        });

        $this->assertNotNull($this->cookieDeSesion($logout), 'El logout manda su cookie nueva.');
        $this->assertNotSame($id, $this->cookieDeSesion($logout));

        $this->assertNull($this->cookieDeSesion($en_vuelo),
            'La respuesta en vuelo no puede devolverle al navegador la sesion logueada.');
        $this->assertNull($this->cookieXsrf($en_vuelo),
            'Ni el XSRF-TOKEN de la sesion que el logout invalido.');
        $this->assertSame('', $this->otroHandler()->read($id));
    }

    /**
     * Si el cache tira una excepcion, el request no se rompe: el login manda su cookie nueva y
     * un request comun sigue mandando la suya y guardando la sesion.
     */
    public function test_un_cache_que_tira_una_excepcion_no_rompe_el_request()
    {
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('cache caido'));
        Cache::shouldReceive('has')->andThrow(new \RuntimeException('cache caido'));

        $id_viejo = $this->sesionExistente();

        $login = $this->correr($id_viejo, function (Request $request) {
            $request->session()->migrate(true);
        });

        $id_nuevo = $this->cookieDeSesion($login);
        $this->assertNotNull($id_nuevo);
        $this->assertNotSame($id_viejo, $id_nuevo);

        $comun = $this->correr($id_nuevo, function (Request $request) {
            $request->session()->put('visto', true);
        });

        $this->assertSame($id_nuevo, $this->cookieDeSesion($comun));
        $this->assertTrue($this->leer($id_nuevo)['visto'] ?? false);
    }

    /**
     * Con el driver `cookie`, `CookieSessionHandler::read()` usa el request: la lectura inicial
     * tiene que hacerse con el request ya puesto en el handler, o revienta con un 500.
     */
    public function test_con_el_driver_cookie_la_lectura_inicial_no_revienta()
    {
        config(['session.driver' => 'cookie']);

        $respuesta = $this->correr(Str::random(40), function (Request $request) {
            $request->session()->put('visto', true);
        });

        $this->assertNotNull($this->cookieDeSesion($respuesta));
    }

    /**
     * Sin regresion: una cookie con un id que no existe (sesion vencida y barrida) y SIN marca
     * de migracion se trata como hoy: se manda la cookie y la sesion se guarda con ese id.
     */
    public function test_una_cookie_con_un_id_que_no_existe_y_sin_marca_se_trata_como_siempre()
    {
        $id = Str::random(40);

        $respuesta = $this->correr($id, function (Request $request) {
            $request->session()->put('visto', true);
        });

        $this->assertSame($id, $this->cookieDeSesion($respuesta));
        $this->assertTrue($this->leer($id)['visto'] ?? false);
    }

    /**
     * Un request que pasa por el middleware con su propio SessionManager (o sea, su propio
     * proceso) y un $next que hace `$accion` en el medio.
     *
     * @param  string|null  $id  el id de la cookie, o null para un visitante sin cookie
     * @param  callable  $accion
     * @return \Illuminate\Http\Response
     */
    private function correr($id, callable $accion)
    {
        $cookies = is_null($id) ? [] : [config('session.cookie') => $id];

        $request = Request::create('/api/articles', 'GET', [], $cookies);

        $middleware = new StartSession(new SessionManager($this->app));

        return $middleware->handle($request, function (Request $request) use ($accion) {
            $respuesta = $accion($request);

            return $respuesta instanceof Response ? $respuesta : new Response('ok');
        });
    }

    /**
     * Una respuesta con la cookie `XSRF-TOKEN` que agrega `VerifyCsrfToken` (que corre ADENTRO de
     * StartSession) con el token de la sesion del request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    private function respuestaConXsrf(Request $request)
    {
        $config = config('session');

        $respuesta = new Response('ok');
        $respuesta->headers->setCookie(new Cookie(
            'XSRF-TOKEN',
            $request->session()->token(),
            0,
            $config['path'],
            $config['domain'],
            $config['secure'] ?? false,
            false,
            false,
            $config['same_site'] ?? null
        ));

        return $respuesta;
    }

    /**
     * @param  \Symfony\Component\HttpFoundation\Response  $respuesta
     * @return string|null
     */
    private function cookieXsrf($respuesta)
    {
        foreach ($respuesta->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'XSRF-TOKEN') {
                return $cookie->getValue();
            }
        }

        return null;
    }

    /**
     * Una sesion que ya existe en la carpeta, como la del anonimo que navega antes de loguearse.
     *
     * @return string el id
     */
    private function sesionExistente()
    {
        $id = Str::random(40);

        $this->otroHandler()->write($id, serialize([
            '_token'           => Str::random(40),
            'carritos_propios' => [],
        ]));

        return $id;
    }

    /**
     * El handler de "otro proceso": misma carpeta, instancia aparte.
     *
     * @return \SessionHandlerInterface
     */
    private function otroHandler()
    {
        return (new SessionManager($this->app))->driver()->getHandler();
    }

    /**
     * @param  string  $id
     * @return array
     */
    private function leer($id)
    {
        clearstatcache();

        $datos = $this->otroHandler()->read($id);

        return $datos === '' ? [] : unserialize($datos);
    }

    /**
     * El valor de la cookie de sesion de la respuesta, o null si no la manda.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $respuesta
     * @return string|null
     */
    private function cookieDeSesion($respuesta)
    {
        foreach ($respuesta->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                return $cookie->getValue();
            }
        }

        return null;
    }
}
