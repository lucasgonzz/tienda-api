<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\Mail\PasswordReset;
use App\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * "Olvide mi contraseña" de la tienda: el comprador pide un codigo al mail, lo escribe en la pagina y,
 * si es correcto, pone su contraseña nueva. Rutas publicas a proposito (`routes/web.php`): las usa
 * alguien que no puede iniciar sesion.
 *
 * Los tres pasos, con lo que hace cada endpoint:
 *   - `send-verification-code`: genera el codigo con `random_int` y se lo manda al comprador por mail.
 *   - `check-verification-code`: dice si el codigo es el vigente. NO lo consume (la pantalla lo vuelve a
 *     mandar en el paso 3, junto con la contraseña).
 *   - `update-password`: con el codigo vigente cambia la contraseña, BORRA el codigo (sirve una sola vez)
 *     y deja al comprador logueado.
 *
 * LARGO DEL CODIGO (mision recuperar-clave-codigo-4-digitos, 20/10/2026): el cliente lo pide con el campo
 * opcional `longitud_del_codigo`. SOLO si vale 4 se generan 4 digitos (1000-9999); sin el campo, o con
 * cualquier otro valor, siguen siendo 6 digitos como siempre (la pantalla vieja no manda el campo y valida
 * `length < 6`). Con 4 digitos hay 9.000 combinaciones, asi que los topes de abajo no son opcionales.
 *
 * 🔴 DONDE VIVE EL CODIGO: en CACHE, no en `buyers.verification_code`. Hasta el 20/10/2026 el codigo se
 * guardaba en esa columna, que TAMBIEN usa el registro del comprador (`AuthController`):
 * `isBuyerRegistered()` cuenta como registrado solo al comprador con `verification_code` en null, y
 * `register()` llama a `deleteIfExist($phone)` (que borra al comprador con ese telefono) cuando NO esta
 * registrado. O sea que un codigo de recuperacion pendiente —que ademas nunca vencia— dejaba la cuenta
 * "sin registrar" y cualquiera que conociera el telefono la borraba registrandose de nuevo con ese numero.
 * Ahora el reset no lee ni escribe esa columna: el registro y la recuperacion no se pisan. (Las tablas
 * las gobierna empresa-api: tienda no tiene migraciones, por eso el estado va en cache y no en una
 * columna nueva.)
 *
 * Que se guarda en cache, por comercio + comprador (el codigo de un comercio no sirve en otro):
 *   - el codigo pendiente: su HMAC-SHA256 (el codigo en claro no queda en ningun lado), los intentos
 *     fallidos y cuando vence. Vive MINUTOS_DE_VIDA_DEL_CODIGO; pedir otro pisa el anterior.
 *   - los codigos emitidos en la ventana de VENTANA_DE_PEDIDOS_EN_MINUTOS (la hora) y, con otro contador y
 *     otra clave, en la de VENTANA_DIARIA_EN_MINUTOS (el dia). Van aparte del codigo para que pedir otro
 *     no reinicie los contadores.
 *
 * Los topes son los mismos que los de empresa-api (`CommonLaravel\PasswordResetController`):
 *   - INTENTOS_FALLIDOS_MAXIMOS por codigo: al 5.º error el codigo muere, aunque despues llegue el bueno.
 *   - CODIGOS_POR_HORA_MAXIMOS por comprador: sin este, el de intentos no sirve (cada codigo nuevo
 *     arranca en 0 intentos). Entre los dos, como mucho 5 x 5 = 25 codigos probados por hora.
 *   - CODIGOS_POR_DIA_MAXIMOS por comprador (mision tope-diario-de-codigos-recuperar-clave, 20/10/2026):
 *     el de la hora solo, sostenido todo el dia, dejaba probar 5 x 5 x 24 = 600 codigos por dia contra
 *     9.000 combinaciones de 4 digitos (≈ 6,5 % por dia de ataque). Con 10 codigos por dia son
 *     10 x 5 = 50 intentos por dia (≈ 0,55 %), unas 12 veces menos. Con codigos de 6 digitos mejora mas.
 *
 * 🔴 TRADE-OFF ACEPTADO (Lucas, 20/10/2026): quien conozca el correo de un comprador puede pedir los 10
 * codigos del dia y dejarlo sin poder recuperar su clave hasta 24 horas (con el tope de la hora eran 60
 * minutos). Por eso el mensaje le dice que pruebe de nuevo mas tarde o pida ayuda a soporte. Las dos
 * ventanas son FIJAS (arrancan con el primer pedido y no se estiran con los siguientes), se cuentan y se
 * devuelven juntas bajo el mismo candado, y se chequea primero la del dia y despues la de la hora: con las
 * dos llenas, el mensaje es el del dia (el mas restrictivo y el mas verdadero).
 */
class PasswordResetController extends Controller
{

    /** Lo que ve el comprador cuando el servidor de correo no acepta el envio del codigo. */
    const MENSAJE_ENVIO_FALLIDO = 'No pudimos enviarte el código a ese correo. Revisá la dirección o probá más tarde.';

    /** Lo que ve el comprador cuando pidio demasiados codigos en una hora. */
    const MENSAJE_DEMASIADOS_PEDIDOS = 'Pediste demasiados códigos de recuperación. Esperá una hora y volvé a intentar.';

    /** Lo que ve el comprador cuando pidio demasiados codigos en el dia (se chequea antes que el de la hora). */
    const MENSAJE_DEMASIADOS_PEDIDOS_POR_DIA = 'Llegaste al máximo de códigos de recuperación por día. Probá de nuevo en 24 horas o pedile ayuda a soporte.';

    /** Lo que ve el comprador cuando el codigo no sirve (equivocado, vencido, agotado) al cambiar la contraseña. */
    const MENSAJE_CODIGO_INVALIDO = 'El código venció o no es correcto. Pedí uno nuevo.';

    /** Lo que ve el comprador cuando la contraseña nueva no cumple el minimo. */
    const MENSAJE_CONTRASENA_CORTA = 'La contraseña nueva tiene que tener al menos 6 caracteres.';

    /** Largo del codigo cuando el cliente lo pide con `longitud_del_codigo` = 4. */
    const LONGITUD_DEL_CODIGO_CORTO = 4;

    /** Minutos que vive un codigo desde que se pide. */
    const MINUTOS_DE_VIDA_DEL_CODIGO = 30;

    /** Al llegar a esta cantidad de intentos fallidos, el codigo se invalida. */
    const INTENTOS_FALLIDOS_MAXIMOS = 5;

    /** Codigos que se le pueden emitir a un comprador por ventana de VENTANA_DE_PEDIDOS_EN_MINUTOS. */
    const CODIGOS_POR_HORA_MAXIMOS = 5;

    /** Ventana FIJA (desde el primer pedido; no se corre con cada uno) del tope de codigos emitidos. */
    const VENTANA_DE_PEDIDOS_EN_MINUTOS = 60;

    /** Codigos que se le pueden emitir a un comprador por ventana de VENTANA_DIARIA_EN_MINUTOS (10 x 5 intentos = 50 por dia). */
    const CODIGOS_POR_DIA_MAXIMOS = 10;

    /** Ventana FIJA (desde el primer pedido) del tope diario de codigos emitidos: 24 horas. */
    const VENTANA_DIARIA_EN_MINUTOS = 1440;

    /** Largo minimo de la contraseña nueva (la pantalla ya exige 6 o mas: lo que manda siempre pasa). */
    const MINIMO_DE_LA_CONTRASENA = 6;

    /** Segundos maximos que dura el candado que serializa los pedidos de un mismo comprador. */
    const SEGUNDOS_DEL_CANDADO = 10;

    /** Segundos maximos que un pedido espera el candado antes de rendirse. */
    const SEGUNDOS_DE_ESPERA_DEL_CANDADO = 5;

    /**
     * Manda por mail el codigo para recuperar la contraseña.
     *
     * 🔴 Si el SMTP rechaza el envio (cualquier `TransportExceptionInterface` de Symfony Mailer) se
     * responde 422 con un mensaje para el comprador, y no el 500 de antes. Medido el 14/10/2026 en
     * la tienda de demo: `UnexpectedResponseException` "554 5.7.1 Recipient domain is reserved" con
     * una casilla @example.com y, antes, `TransportException` 535 con la clave del SMTP vacia. Los dos
     * terminaban en un 500 y el comprador se quedaba con el boton girando y sin saber que hacer. El
     * error real queda en el log (warning, con el comercio, el comprador y la excepcion).
     *
     * El codigo que no salio se descarta: el pedido no cuenta para el tope por hora y el codigo que
     * el comprador ya tenia (si tenia uno) sigue sirviendo, porque el nuevo no se guarda hasta que el
     * mail salio.
     *
     * Pasado el tope de CODIGOS_POR_DIA_MAXIMOS (o el de CODIGOS_POR_HORA_MAXIMOS) NO se genera codigo ni se
     * manda mail ni se toca el codigo pendiente: 422 con `message` (la pantalla —la de hoy y la vieja—
     * muestra ese texto) y `email_send` false, con la misma forma en los dos casos; solo cambia el texto.
     *
     * El caso que anda (mail enviado) y el del email desconocido responden igual que siempre.
     *
     * @param  Request  $request  email, commerce_id, longitud_del_codigo (opcional; 4 para 4 digitos)
     * @return \Illuminate\Http\JsonResponse
     */
    function sendVerificationCode(Request $request) {
        $buyer = $this->comprador_del_pedido($request);
        if (is_null($buyer)) {
            return response()->json(['email_send' => false], 200);
        }

        try {
            return $this->con_candado($buyer, function () use ($request, $buyer) {
                return $this->emitir_codigo($request, $buyer);
            });
        } catch (LockTimeoutException $e) {
            // Otro pedido del mismo comprador esta en curso y no termino a tiempo: se le pide reintentar.
            return response()->json([
                'email_send' => false,
                'message'    => self::MENSAJE_ENVIO_FALLIDO,
            ], 422);
        }
    }

    /**
     * Segundo paso: ¿el codigo que escribio el comprador es el que se le mando?
     *
     * Un codigo equivocado suma un intento fallido; al llegar a INTENTOS_FALLIDOS_MAXIMOS el codigo
     * muere. NO consume el codigo cuando es el bueno. Siempre responde 200 con `verified`.
     *
     * @param  Request  $request  email, commerce_id, verification_code
     * @return \Illuminate\Http\JsonResponse
     */
    function checkVerificationCode(Request $request) {
        $buyer = $this->comprador_del_pedido($request);

        if (is_null($buyer) || !$this->codigo_valido_con_candado($buyer, $request->input('verification_code'))) {
            return response()->json(['verified' => false], 200);
        }

        return response()->json(['verified' => true], 200);
    }

    /**
     * Ultimo paso: la contraseña nueva. Se guarda solo con el codigo vigente de ese comprador, el mismo
     * que paso el segundo paso (la pantalla lo vuelve a mandar junto con la contraseña: siempre lo hizo,
     * tambien la version 1.1.0). Sin codigo, o con uno que no es, responde 422 y no cambia nada.
     *
     * La contraseña se mira ANTES que el codigo, a proposito: una contraseña vacia o demasiado corta no
     * le suma un intento fallido al codigo (la persona la corrige y reintenta con el mismo).
     *
     * El codigo sirve una sola vez: al cambiar la contraseña se borra.
     *
     * @param  Request  $request  email, commerce_id, verification_code, password
     * @return \Illuminate\Http\JsonResponse
     */
    function updatePassword(Request $request) {
        $contrasena = $request->input('password');

        if (!is_string($contrasena) || trim($contrasena) === '') {
            return response()->json([
                'password_updated' => false,
                'message'          => self::MENSAJE_CODIGO_INVALIDO,
            ], 422);
        }

        if (mb_strlen($contrasena) < self::MINIMO_DE_LA_CONTRASENA) {
            return response()->json([
                'password_updated' => false,
                'message'          => self::MENSAJE_CONTRASENA_CORTA,
            ], 422);
        }

        $buyer = $this->comprador_del_pedido($request);

        if (is_null($buyer) || !$this->codigo_valido_con_candado($buyer, $request->input('verification_code'))) {
            return response()->json([
                'password_updated' => false,
                'message'          => self::MENSAJE_CODIGO_INVALIDO,
            ], 422);
        }

        // Un codigo sirve una sola vez: se borra antes de cambiar nada. `verification_code` (columna del
        // registro del comprador) NO se toca: ver el docblock de la clase.
        Cache::forget($this->clave_del_codigo($buyer));

        $buyer->password = bcrypt($contrasena);
        $buyer->save();

        // Deja al comprador logueado, como siempre. Si por algo no entra, la contraseña igual quedo cambiada.
        Auth::guard('buyer')->attempt(['email' => $request->input('email'), 'user_id' => $request->input('commerce_id'), 'password' => $contrasena], false);

        return response()->json(['password_updated' => true], 200);
    }

    /**
     * Genera, guarda y manda el codigo. Se llama con el candado del comprador tomado.
     *
     * El orden importa: primero se mira el tope (el del dia y despues el de la hora), despues se cuenta el
     * pedido en las dos ventanas (para que pedidos en paralelo no se cuelen), despues sale el mail, y
     * recien cuando salio se guarda el codigo. Si el mail falla, se devuelve el pedido a las dos ventanas
     * y el codigo anterior queda como estaba.
     *
     * @param  Request  $request
     * @param  \App\Buyer  $buyer
     * @return \Illuminate\Http\JsonResponse
     */
    private function emitir_codigo(Request $request, Buyer $buyer)
    {
        $motivo_del_tope = $this->motivo_para_no_emitir_otro_codigo($buyer);

        if (!is_null($motivo_del_tope)) {
            return response()->json([
                'email_send' => false,
                'message'    => $motivo_del_tope,
            ], 422);
        }

        $code = $this->generar_codigo($request);
        $commerce = User::find($request->input('commerce_id'));

        $this->contar_codigo_emitido($buyer);

        try {
            Mail::to($buyer)->send(new PasswordReset($code, $commerce));
        } catch (TransportExceptionInterface $e) {
            Log::warning('Recuperar clave: el servidor de correo rechazo el envio del codigo', [
                'commerce_id' => $request->input('commerce_id'),
                'buyer_id'    => $buyer->id,
                'excepcion'   => get_class($e),
                'mensaje'     => $e->getMessage(),
            ]);

            $this->devolver_codigo_emitido($buyer);

            return response()->json([
                'email_send' => false,
                'message'    => self::MENSAJE_ENVIO_FALLIDO,
            ], 422);
        } catch (\Throwable $e) {
            // Cualquier otro error al armar o mandar el mail (una vista rota, por ejemplo) tampoco
            // le gasta al comprador un codigo de la hora ni del dia: se devuelve y el error sigue su camino.
            $this->devolver_codigo_emitido($buyer);

            throw $e;
        }

        $this->guardar_codigo($buyer, $code);

        return response()->json(['email_send' => true], 200);
    }

    /**
     * Genera el codigo con un generador criptografico (`random_int`, nunca `rand`): es un secreto.
     * 4 digitos (1000-9999) solo si el cliente mando `longitud_del_codigo` = 4; si no, 6 (100000-999999).
     *
     * @param  Request  $request
     * @return int
     */
    private function generar_codigo(Request $request)
    {
        $longitud = $request->input('longitud_del_codigo');

        if (is_scalar($longitud) && (string) $longitud === (string) self::LONGITUD_DEL_CODIGO_CORTO) {
            return random_int(1000, 9999);
        }

        return random_int(100000, 999999);
    }

    /**
     * El comprador del comercio y el email del pedido, o null.
     *
     * Solo con un email de texto no vacio: con `email` ausente, `where('email', null)` se convierte en
     * `whereNull('email')` y devolveria a cualquier comprador sin email. Y solo con `commerce_id`
     * presente, para que el codigo nunca se resuelva contra un comprador de otro comercio.
     *
     * @param  Request  $request
     * @return \App\Buyer|null
     */
    private function comprador_del_pedido(Request $request)
    {
        $email = $request->input('email');
        $commerce_id = $request->input('commerce_id');

        if (!is_string($email) || trim($email) === '' || !is_scalar($commerce_id) || $commerce_id === '') {
            return null;
        }

        return Buyer::where('email', $email)
                    ->where('user_id', $commerce_id)
                    ->first();
    }

    /**
     * Ejecuta `$accion` con un candado por comprador. Sin el, dos pedidos en paralelo leen el mismo contador
     * de intentos y los dos lo suben a la vez (lectura-modificacion-escritura sobre la cache): con 4 digitos
     * eso le regala intentos a quien dispara muchos pedidos juntos.
     *
     * @param  \App\Buyer  $buyer
     * @param  callable  $accion
     * @return mixed
     * @throws LockTimeoutException si no consigue el candado a tiempo
     */
    private function con_candado(Buyer $buyer, callable $accion)
    {
        return Cache::lock($this->clave_del_codigo($buyer).':candado', self::SEGUNDOS_DEL_CANDADO)
                    ->block(self::SEGUNDOS_DE_ESPERA_DEL_CANDADO, $accion);
    }

    /**
     * `codigo_valido()` dentro del candado del comprador. Si no consigue el candado, el codigo no se da por bueno.
     *
     * @param  \App\Buyer  $buyer
     * @param  mixed  $codigo
     * @return bool
     */
    private function codigo_valido_con_candado(Buyer $buyer, $codigo)
    {
        try {
            return $this->con_candado($buyer, function () use ($buyer, $codigo) {
                return $this->codigo_valido($buyer, $codigo);
            });
        } catch (LockTimeoutException $e) {
            return false;
        }
    }

    /**
     * Clave de cache del codigo pendiente de un comprador (uno por comprador: pedir otro pisa el anterior).
     * Lleva el comercio ademas del comprador: el codigo de un comercio no sirve en otro.
     *
     * @param  \App\Buyer  $buyer
     * @return string
     */
    private function clave_del_codigo(Buyer $buyer)
    {
        return 'tienda_password_reset:'.$buyer->user_id.':'.$buyer->id;
    }

    /**
     * Clave de cache de los codigos emitidos de un comprador en la HORA. Es APARTE del codigo pendiente a
     * proposito: emitir un codigo nuevo pisa el pendiente, pero este contador no se reinicia.
     *
     * @param  \App\Buyer  $buyer
     * @return string
     */
    private function clave_de_pedidos(Buyer $buyer)
    {
        return 'tienda_password_reset_pedidos:'.$buyer->user_id.':'.$buyer->id;
    }

    /**
     * Clave de cache de los codigos emitidos de un comprador en el DIA. Contador propio, aparte del de la
     * hora y del codigo pendiente. Lleva el comercio ademas del comprador, igual que las otras: el mismo
     * correo en otro comercio es otro comprador y tiene su propio tope diario.
     *
     * @param  \App\Buyer  $buyer
     * @return string
     */
    private function clave_de_pedidos_del_dia(Buyer $buyer)
    {
        return 'tienda_password_reset_pedidos_dia:'.$buyer->user_id.':'.$buyer->id;
    }
    /**
     * Huella del codigo que se guarda en cache: HMAC-SHA256 con la clave de la app y la identidad
     * comercio + comprador. El codigo en claro no queda en ningun lado y la huella de un comprador no
     * vale para otro.
     *
     * @param  \App\Buyer  $buyer
     * @param  mixed  $codigo
     * @return string
     */
    private function huella_del_codigo(Buyer $buyer, $codigo)
    {
        return hash_hmac('sha256', (string) $codigo, $this->clave_del_codigo($buyer).'|'.config('app.key'));
    }

    /**
     * Las ventanas de tope de codigos emitidos, EN EL ORDEN EN QUE SE CHEQUEAN: primero el dia y despues
     * la hora, para que con las dos llenas el mensaje sea el del dia (el mas restrictivo y el mas
     * verdadero). Cada una: `clave` del contador en cache, `minutos` de la ventana fija, `maximo` de
     * codigos y `mensaje` que ve el comprador cuando se llena.
     *
     * @param  \App\Buyer  $buyer
     * @return array
     */
    private function ventanas_de_pedidos(Buyer $buyer)
    {
        return [
            [
                'clave'   => $this->clave_de_pedidos_del_dia($buyer),
                'minutos' => self::VENTANA_DIARIA_EN_MINUTOS,
                'maximo'  => self::CODIGOS_POR_DIA_MAXIMOS,
                'mensaje' => self::MENSAJE_DEMASIADOS_PEDIDOS_POR_DIA,
            ],
            [
                'clave'   => $this->clave_de_pedidos($buyer),
                'minutos' => self::VENTANA_DE_PEDIDOS_EN_MINUTOS,
                'maximo'  => self::CODIGOS_POR_HORA_MAXIMOS,
                'mensaje' => self::MENSAJE_DEMASIADOS_PEDIDOS,
            ],
        ];
    }

    /**
     * Los pedidos de una ventana vigente del comprador: `desde` (timestamp del primero) y `cantidad`. Una
     * ventana vencida, o nada guardado, cuenta como ventana nueva (null).
     *
     * @param  array  $ventana  Una de `ventanas_de_pedidos()`.
     * @return array|null
     */
    private function pedidos_de_la_ventana(array $ventana)
    {
        $pedidos = Cache::get($ventana['clave']);

        if (!is_array($pedidos) || !isset($pedidos['desde'], $pedidos['cantidad'])) {
            return null;
        }

        // now() y no time(): el reloj de Carbon es el que mueven los tests.
        if ((int) $pedidos['desde'] + $ventana['minutos'] * 60 <= now()->getTimestamp()) {
            return null;
        }

        return $pedidos;
    }

    /**
     * ¿Hay que negarle otro codigo a este comprador? Mira las ventanas en orden (primero el dia, despues
     * la hora) y devuelve el mensaje de la primera que esta llena, o null si puede pedir otro.
     *
     * @param  \App\Buyer  $buyer
     * @return string|null
     */
    private function motivo_para_no_emitir_otro_codigo(Buyer $buyer)
    {
        foreach ($this->ventanas_de_pedidos($buyer) as $ventana) {
            $pedidos = $this->pedidos_de_la_ventana($ventana);

            if (!is_null($pedidos) && (int) $pedidos['cantidad'] >= $ventana['maximo']) {
                return $ventana['mensaje'];
            }
        }

        return null;
    }

    /**
     * Suma un codigo emitido en las dos ventanas a la vez. Cada ventana es FIJA: arranca con el primer
     * pedido y no se corre con los siguientes (si se corriera, pedir uno cada 59 minutos —o cada 23 horas—
     * la estiraria para siempre); la cache vive hasta que la ventana vence.
     *
     * @param  \App\Buyer  $buyer
     * @return void
     */
    private function contar_codigo_emitido(Buyer $buyer)
    {
        foreach ($this->ventanas_de_pedidos($buyer) as $ventana) {
            $pedidos = $this->pedidos_de_la_ventana($ventana);

            if (is_null($pedidos)) {
                $pedidos = ['desde' => now()->getTimestamp(), 'cantidad' => 0];
            }

            $pedidos['cantidad'] = (int) $pedidos['cantidad'] + 1;

            $this->guardar_pedidos($ventana, $pedidos);
        }
    }

    /**
     * Le saca a las dos ventanas un pedido que no llego a salir (el servidor de correo lo rechazo): un
     * problema del correo no tiene que gastarle al comprador sus codigos de la hora ni del dia.
     *
     * @param  \App\Buyer  $buyer
     * @return void
     */
    private function devolver_codigo_emitido(Buyer $buyer)
    {
        foreach ($this->ventanas_de_pedidos($buyer) as $ventana) {
            $pedidos = $this->pedidos_de_la_ventana($ventana);

            if (is_null($pedidos)) {
                continue;
            }

            $pedidos['cantidad'] = max(0, (int) $pedidos['cantidad'] - 1);

            $this->guardar_pedidos($ventana, $pedidos);
        }
    }

    /**
     * Escribe el contador de una ventana con el tiempo que le queda (no con una ventana entera nueva).
     *
     * @param  array  $ventana  Una de `ventanas_de_pedidos()`.
     * @param  array  $pedidos  ['desde' => timestamp, 'cantidad' => int]
     * @return void
     */
    private function guardar_pedidos(array $ventana, array $pedidos)
    {
        $segundos_que_le_quedan = (int) $pedidos['desde'] + $ventana['minutos'] * 60 - now()->getTimestamp();

        Cache::put($ventana['clave'], $pedidos, max(1, $segundos_que_le_quedan));
    }
    /**
     * Guarda el codigo pendiente: su huella, los intentos fallidos y cuando vence. El vencimiento va
     * tambien adentro del valor porque el contador de intentos se reescribe con el tiempo que le queda,
     * no con 30 minutos nuevos.
     *
     * @param  \App\Buyer  $buyer
     * @param  int  $codigo
     * @return void
     */
    private function guardar_codigo(Buyer $buyer, $codigo)
    {
        $segundos = self::MINUTOS_DE_VIDA_DEL_CODIGO * 60;

        Cache::put($this->clave_del_codigo($buyer), [
            'huella'   => $this->huella_del_codigo($buyer, $codigo),
            'intentos' => 0,
            'vence'    => now()->getTimestamp() + $segundos,
        ], $segundos);
    }

    /**
     * ¿El codigo recibido es el pendiente del comprador y sigue vigente? NO lo consume.
     *
     * Un codigo equivocado (o vacio) suma un intento; al llegar a INTENTOS_FALLIDOS_MAXIMOS el codigo se
     * borra y ya no sirve ni el correcto. Uno vencido se borra. Se compara con hash_equals. Sin codigo
     * pendiente (nunca se pidio, venció, murio) devuelve false sin sumar nada.
     *
     * @param  \App\Buyer  $buyer
     * @param  mixed  $codigo  Lo que mando el cliente (la pantalla lo manda como texto).
     * @return bool
     */
    private function codigo_valido(Buyer $buyer, $codigo)
    {
        $clave = $this->clave_del_codigo($buyer);
        $pendiente = Cache::get($clave);

        if (!is_array($pendiente) || !isset($pendiente['huella'], $pendiente['intentos'], $pendiente['vence'])) {
            return false;
        }

        // now() y no time(): el reloj de Carbon es el que mueven los tests.
        $ahora = now()->getTimestamp();

        if ((int) $pendiente['vence'] <= $ahora) {
            Cache::forget($clave);

            return false;
        }

        $codigo = is_scalar($codigo) ? trim((string) $codigo) : '';

        if ($codigo !== '' && hash_equals((string) $pendiente['huella'], $this->huella_del_codigo($buyer, $codigo))) {
            return true;
        }

        $pendiente['intentos'] = (int) $pendiente['intentos'] + 1;

        if ($pendiente['intentos'] >= self::INTENTOS_FALLIDOS_MAXIMOS) {
            Cache::forget($clave);
        } else {
            Cache::put($clave, $pendiente, (int) $pendiente['vence'] - $ahora);
        }

        return false;
    }

}
