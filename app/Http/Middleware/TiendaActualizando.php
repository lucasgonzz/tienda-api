<?php

namespace App\Http\Middleware;

use App\Services\ActualizacionDelSistema;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Mientras empresa-api actualiza el sistema de un cliente, su tienda online contesta la "marca":
 * HTTP 503 con `Retry-After: 30` y `{"error":"sistema_actualizando", ...}`. tienda-spa la reconoce y
 * muestra "Estamos actualizando la tienda" en vez de una pantalla en blanco (misión aviso-actualizacion-sistema,
 * decisión D4 de Lucas: la tienda también se bloquea).
 *
 * Va en el grupo `api` del Kernel. El comercio se resuelve, en este orden:
 *   1. parámetro de ruta `commerce_id` (casi todas las rutas de la tienda);
 *   2. input `commerce_id` (query o cuerpo, p. ej. el alta `POST carts` y el webhook de Mercado Pago);
 *   3. el carrito (`carts.user_id`, que ES el comercio: `CartController@store` guarda
 *      `user_id = commerce_id` y `lastCart` filtra por él): el `{cart_id}` de las rutas de carrito, el
 *      input `cart_id` (`POST mercado-pago/preference`, `POST orders`, `POST mercado-pago/tarjeta/montos`)
 *      y el `id` del cuerpo de `PUT api/carts`. Se lee de la base, no del cuerpo: un `user_id` mentido en el
 *      cuerpo no puede bloquear a otro comercio;
 *   4. `payment_method.user_id` del cuerpo (`POST mercado-pago/preference` sin carrito): es el comercio que
 *      cobra (`MercadoPagoController@preference` hace `User::find($request->payment_method['user_id'])`);
 *   5. el comprador autenticado (`Buyer.user_id`, guard `buyer`) para las rutas `auth:buyer` sin comercio.
 * El SPA real NO manda `commerce_id` en la compra (los puntos 3 y 4 existen por eso): sin ellos un invitado
 * podía pagar con Mercado Pago en plena actualización. Si no se puede identificar ningún comercio, o la
 * resolución falla, la request pasa (no hay a quién bloquear).
 *
 * Exceptuadas, siempre pasan:
 *   - `api/mercado-pago/webhook`: Mercado Pago no puede perder avisos de pago por una actualización;
 *   - `api/seo/*`: los crawlers no deben indexar un 503 por una actualización de minutos.
 *
 * La lectura del estado (tabla `actualizaciones_del_sistema`, que sólo crea empresa-api) está en
 * `ActualizacionDelSistema`: tolera que la tabla no exista y cachea 10 s por comercio. Esta clase sólo lee.
 */
class TiendaActualizando
{
    /** Segundos que se le pide al cliente que espere antes de reintentar (header `Retry-After` y cuerpo). */
    const SEGUNDOS_PARA_REINTENTAR = 30;

    /** Texto de la marca de tienda (el que tienda-spa muestra al comprador). */
    const MENSAJE = 'Estamos actualizando la tienda. Volvé a intentar en unos minutos.';

    /**
     * Contesta la marca si el comercio de la request se está actualizando; si no, sigue la cadena.
     *
     * @param \Illuminate\Http\Request $request Request entrante.
     * @param \Closure $next Siguiente middleware.
     * @return mixed La marca 503 o la respuesta del resto de la cadena.
     */
    public function handle(Request $request, Closure $next)
    {
        if ($this->esta_exceptuada($request)) {
            return $next($request);
        }

        $commerce_id = $this->resolver_comercio($request);

        if ($commerce_id > 0 && ActualizacionDelSistema::bloquea($commerce_id)) {
            return $this->marca();
        }

        return $next($request);
    }

    /**
     * Rutas que nunca se cortan por una actualización (webhook de Mercado Pago y SEO).
     *
     * @param \Illuminate\Http\Request $request Request entrante.
     * @return bool true si la ruta está exceptuada.
     */
    protected function esta_exceptuada(Request $request): bool
    {
        return $request->is('api/mercado-pago/webhook', 'api/seo/*');
    }

    /**
     * Resuelve el comercio de la request: ruta, input `commerce_id`, carrito, medio de pago y comprador autenticado (ver la clase).
     *
     * @param \Illuminate\Http\Request $request Request entrante.
     * @return int Id del comercio, o 0 si no se pudo identificar.
     */
    protected function resolver_comercio(Request $request): int
    {
        $de_la_ruta = $this->como_id($request->route('commerce_id'));

        if ($de_la_ruta > 0) {
            return $de_la_ruta;
        }

        $del_input = $this->como_id($request->input('commerce_id'));

        if ($del_input > 0) {
            return $del_input;
        }

        $del_carrito = $this->comercio_del_carrito($request);

        if ($del_carrito > 0) {
            return $del_carrito;
        }

        $del_medio_de_pago = $this->como_id($request->input('payment_method.user_id'));

        if ($del_medio_de_pago > 0) {
            return $del_medio_de_pago;
        }

        return $this->comercio_del_comprador();
    }

    /**
     * Comercio dueño del carrito que nombra la request (`carts.user_id`), leído de la base.
     *
     * El id del carrito sale, en este orden, de: parámetro de ruta `cart_id` (DELETE/PATCH/PUT de
     * `carts/{cart_id}...`), input `cart_id` (preference, orders, montos de tarjeta) y, sólo en
     * `PUT api/carts`, del `id` del cuerpo (el SPA manda el carrito entero que devolvió el servidor). El `id` no
     * se mira en otras rutas: es un nombre demasiado genérico y podría ser de cualquier otro recurso.
     * Nunca rompe la request: carrito inexistente, id inválido o error de base devuelven 0.
     *
     * @param \Illuminate\Http\Request $request Request entrante.
     * @return int Id del comercio dueño del carrito, o 0 si no se pudo determinar.
     */
    protected function comercio_del_carrito(Request $request): int
    {
        $cart_id = $this->como_id($request->route('cart_id'));

        if ($cart_id <= 0) {
            $cart_id = $this->como_id($request->input('cart_id'));
        }

        if ($cart_id <= 0 && $request->isMethod('PUT') && $request->is('api/carts')) {
            $cart_id = $this->como_id($request->input('id'));
        }

        if ($cart_id <= 0) {
            return 0;
        }

        try {
            return $this->como_id(DB::table('carts')->where('id', $cart_id)->value('user_id'));
        } catch (Throwable $excepcion) {
            return 0;
        }
    }

    /**
     * Comercio del comprador autenticado (`Buyer.user_id`). Nunca rompe la request: sin sesión o ante
     * cualquier error devuelve 0.
     *
     * @return int Id del comercio, o 0 si no hay comprador autenticado.
     */
    protected function comercio_del_comprador(): int
    {
        try {
            $comprador = Auth::guard('buyer')->user();

            return $comprador ? $this->como_id($comprador->user_id) : 0;
        } catch (Throwable $excepcion) {
            return 0;
        }
    }

    /**
     * Normaliza un valor de entrada a un id entero positivo; lo que no sea un número entero positivo es 0.
     *
     * @param mixed $valor Valor crudo (parámetro de ruta, input o columna).
     * @return int El id, o 0 si no es válido.
     */
    protected function como_id($valor): int
    {
        if (is_int($valor)) {
            return $valor > 0 ? $valor : 0;
        }

        if (is_string($valor) && ctype_digit($valor)) {
            return (int) $valor;
        }

        return 0;
    }

    /**
     * La marca de tienda: 503, `Retry-After: 30` y `error: sistema_actualizando`.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function marca()
    {
        return response()->json([
            'error' => 'sistema_actualizando',
            'message' => self::MENSAJE,
            'reintentar_en_segundos' => self::SEGUNDOS_PARA_REINTENTAR,
        ], 503, [
            'Retry-After' => (string) self::SEGUNDOS_PARA_REINTENTAR,
        ], JSON_UNESCAPED_UNICODE);
    }
}
