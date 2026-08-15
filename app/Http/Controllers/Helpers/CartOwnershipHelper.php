<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Titularidad del carrito.
 *
 * ── Por que existe ────────────────────────────────────────────────────────────────────────────
 *
 * Hasta el 15/8/2026 CartController resolvia el carrito con Cart::find($id) a secas, sin
 * comprobar de quien era. Medido con curl sin ninguna sesion: `DELETE /api/carts/{id}` borro de
 * verdad el carrito de otro comprador, y `GET /api/carts/from-order/{order_id}` devolvio el
 * carrito completo de otro, enumerable por id.
 *
 * El arreglo obvio —meter las rutas de carrito detras de auth— NO se puede hacer: la tienda
 * permite comprar sin registrarse, y hay caminos del SPA que guardan el carrito antes de que el
 * comprador se identifique (components/payment/components/payment-method/CardPaymentMethod.vue:52
 * al elegir el medio de pago, y mixins/articles.js:36 al sacar un articulo). Un invitado no tiene
 * buyer_id: no hay con que acotarlo.
 *
 * Lo que si tiene un invitado es sesion. Se verifico midiendo, no suponiendo: con el Origin del
 * dominio stateful, la respuesta trae la cookie laravel_session y la sesion sobrevive entre
 * requests (se hizo POST /api/buyer y despues GET /api/user con el mismo cookie jar, y devolvio
 * el comprador). Entonces la titularidad se guarda ahi.
 *
 * ── El detalle que hace que esto funcione ─────────────────────────────────────────────────────
 *
 * Auth::guard('buyer')->login() llama internamente a session->migrate(true), y Store::migrate()
 * (vendor/laravel/framework/src/Illuminate/Session/Store.php) destruye el registro viejo y
 * regenera el id, pero NO toca $this->attributes. O sea que la lista de carritos propios
 * SOBREVIVE al login del invitado en el checkout. Si no fuera asi, todo este mecanismo se caeria
 * justo en el paso mas importante.
 *
 * ── Y el que casi lo rompe ────────────────────────────────────────────────────────────────────
 *
 * CartController nunca rellenaba cart.buyer_id despues de que el invitado se identifica: lo setea
 * al crear (con null) y no lo vuelve a tocar. O sea que un carrito creado sin sesion de comprador
 * quedaba con buyer_id NULL para siempre, y la unica forma de volver a encontrarlo era el
 * whereNull que esta mision cierra. De ahi adoptar(): sin el, un comprador que se identifica en
 * el checkout pierde su carrito en cuanto se le vence la sesion.
 *
 * ⚠️ Precision que conviene tener escrita, porque es facil creer lo contrario: esto NO arregla el
 * retorno de Mercado Pago de un INVITADO. `cart/getLastCart` se dispara desde
 * App.vue::callAuthMethods(), que esta detras de `if (this.authenticated)`, y `authenticated`
 * solo se pone en true cuando GET /api/user devuelve 200. Un invitado nunca lo es, asi que por
 * ese camino lastCart no se llama nunca — ni antes ni ahora. Para el invitado la titularidad la
 * sostiene la lista de la sesion, no la adopcion. adoptar() sirve para el comprador CON cuenta,
 * cuyo carrito de invitado previo pasa a quedar atado a su buyer_id.
 *
 * ── Si no hay sesion ──────────────────────────────────────────────────────────────────────────
 *
 * EnsureFrontendRequestsAreStateful solo monta StartSession cuando el Referer/Origin matchea
 * SANCTUM_STATEFUL_DOMAINS (ver el comentario de app/Http/Middleware/VerifyCsrfToken.php). Un
 * curl pelado no lo matchea, la sesion no arranca, ids() devuelve [] y puede() da false: 403 en
 * todo. Ese es exactamente el atacante, y el modo de falla es el correcto.
 */
class CartOwnershipHelper
{
    /** Clave de la lista de carritos propios dentro de la sesion. */
    const CLAVE = 'carritos_propios';

    /**
     * Tope de ids guardados. La sesion de un comprador que navega mucho no puede crecer sin
     * techo: cada carrito abandonado dejaria un id adentro de la cookie de sesion para siempre.
     */
    const TOPE = 20;

    /**
     * Registra un carrito recien creado como propio de esta sesion.
     *
     * @param  int  $cart_id
     * @return void
     */
    public static function registrar($cart_id)
    {
        // Se saca primero el id si ya estaba, para que re-registrarlo lo mueva AL FINAL y no lo
        // deje en su posicion vieja. Con array_unique() sobre la lista ya concatenada pasaba lo
        // contrario (conserva la primera aparicion), y un carrito activo podia ser desalojado por
        // el recorte de abajo. Hoy no puede pasar —hay un solo call site, justo despues de
        // Cart::create—, pero es una mina para el segundo.
        $ids = array_values(array_diff(self::ids(), [(int) $cart_id]));
        $ids[] = (int) $cart_id;

        // Se dejan los ultimos TOPE. array_values para que no queden agujeros en las claves:
        // la lista se serializa a la sesion y un array asociativo la ensucia sin necesidad.
        $ids = array_values(array_slice($ids, -self::TOPE));

        session()->put(self::CLAVE, $ids);
    }

    /**
     * Ids de carrito que esta sesion creo.
     *
     * @return int[]
     */
    public static function ids()
    {
        self::avisarSiNoHaySesion();

        $ids = session()->get(self::CLAVE, []);

        return is_array($ids) ? $ids : [];
    }

    /**
     * Deja constancia en el log cuando la sesion no arranco.
     *
     * 🔴 Existe porque el modo de falla de esta funcionalidad es MUDO. Si un cliente no tiene
     * SANCTUM_STATEFUL_DOMAINS bien seteado, EnsureFrontendRequestsAreStateful no monta
     * StartSession, la lista de carritos propios queda vacia y el comprador recibe 403 al
     * modificar SU PROPIO carrito. Del lado del SPA eso cae en un .catch() que solo hace
     * console.log (store/cart.js), o sea que el comerciante ve "no puedo comprar" y no hay ni un
     * error en ningun lado que explique por que.
     *
     * Con esto, al menos queda una linea que nombra la causa.
     *
     * @return void
     */
    private static function avisarSiNoHaySesion()
    {
        // Una sola linea por request: ids() se llama varias veces por request (puede() y adoptar()
        // pasan por aca), y en un cliente mal configurado eso serian cuatro lineas identicas por
        // cada toque del carrito. Mismo criterio que la memoizacion de BuyerTrackingHelper.
        static $ya_aviso = false;

        if ($ya_aviso) {
            return;
        }

        $request = request();

        if (!is_null($request) && !$request->hasSession()) {
            $ya_aviso = true;
            Log::warning('CartOwnershipHelper: no hay sesion en el request, el comprador no va a poder operar su carrito. Revisar SANCTUM_STATEFUL_DOMAINS del .env contra el dominio del SPA.', [
                'ruta'   => $request->path(),
                'origin' => $request->headers->get('Origin'),
            ]);
        }
    }

    /**
     * Indica si el carrito es de quien esta pidiendo.
     *
     * Dos formas de serlo, y las dos hacen falta:
     *   - lo creo esta sesion (el invitado, que no tiene buyer_id);
     *   - es del comprador autenticado (el que vuelve al dia siguiente con su cuenta, cuya
     *     sesion ya no tiene la lista).
     *
     * @param  \App\Cart|null  $cart
     * @return bool
     */
    public static function puede($cart)
    {
        if (is_null($cart)) {
            return false;
        }

        if (in_array((int) $cart->id, self::ids(), true)) {
            return true;
        }

        $buyer_id = self::buyerId();

        return !is_null($buyer_id) && !is_null($cart->buyer_id) && (int) $cart->buyer_id === $buyer_id;
    }

    /**
     * Le escribe el buyer_id al carrito de un invitado que acaba de identificarse.
     *
     * Sin esto, un carrito creado sin sesion de comprador queda con buyer_id NULL para siempre, y
     * la unica forma de volver a encontrarlo seria el whereNull que esta mision cierra.
     *
     * @param  \App\Cart|null  $cart
     * @return void
     */
    public static function adoptar($cart)
    {
        if (is_null($cart) || !is_null($cart->buyer_id)) {
            return;
        }

        $buyer_id = self::buyerId();

        if (is_null($buyer_id) || !self::puede($cart)) {
            return;
        }

        $cart->buyer_id = $buyer_id;
        $cart->save();
    }

    /**
     * Id del comprador autenticado en el guard 'buyer', o null.
     *
     * Se repite la logica de Controller@buyerId a proposito: este helper es estatico y lo llaman
     * controllers distintos; hacerlo depender de una instancia de Controller lo ataria a quien lo
     * usa.
     *
     * @return int|null
     */
    private static function buyerId()
    {
        if (Auth::guard('buyer')->check()) {
            return (int) Auth::guard('buyer')->id();
        }

        return null;
    }
}
