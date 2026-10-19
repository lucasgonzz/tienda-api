<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\BuyerAddress;
use App\Http\Controllers\Helpers\DireccionesEsquemaHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Mis direcciones" del comprador (misión formas-envio-direcciones-maps, 19/10/2026).
 *
 * Rutas bajo `auth:buyer` (routes/api.php), y además solo para una CUENTA (`Buyer::es_cuenta()`:
 * contraseña o `provider_id`). La ficha que deja un checkout de invitado también abre el guard
 * `buyer` (`BuyerController::login()`), pero no tiene libreta de direcciones: responde 422
 * `sin_cuenta`. El invitado (y el vendedor cargando el pedido de un cliente) manda la dirección
 * entera en el carrito, sin guardarla acá.
 *
 * ── El alcance ────────────────────────────────────────────────────────────────────────────────
 *
 * Todo se acota por `buyer_id` = el comprador de la SESIÓN, nunca por un id del request. Una
 * dirección de otro comprador responde 404 (no 403): para quien pregunta, no existe.
 *
 * ── Lo que escribe, con el comprador bloqueado ───────────────────────────────────────────────
 *
 * Alta, edición y baja corren en una transacción que primero toma `lockForUpdate` sobre la fila
 * del comprador y después sobre sus direcciones (`bloquear()`). Sin eso, dos POST simultáneos (doble
 * click, dos pestañas) contaban las mismas direcciones y podían dejar dos principales o pasar el
 * tope de 20. El candado va sobre `buyers` y no solo sobre `buyer_addresses` a propósito: con cero
 * direcciones el `FOR UPDATE` de InnoDB toma solo un candado de hueco, que dos transacciones
 * comparten, y los dos INSERT terminan en deadlock (un 500). La fila del comprador existe siempre y
 * serializa de verdad.
 *
 * ── Contrato con tienda-spa ───────────────────────────────────────────────────────────────────
 *
 *   GET    /api/buyer/addresses        200 {models: [...]}   default primero, después la más nueva
 *   POST   /api/buyer/addresses        201 {model}           la primera queda default
 *   PUT    /api/buyer/addresses/{id}   200 {model}           los campos que vienen pisan a los guardados
 *   DELETE /api/buyer/addresses/{id}   200 {models: [...]}   si era la default, la más nueva pasa a serlo
 *
 *   401                                            sin sesión (middleware auth:buyer)
 *   422 {codigo: 'sin_direcciones', message}       la base todavía no tiene la tabla
 *   422 {codigo: 'sin_cuenta', message}            la sesión es de una ficha de invitado, no de una cuenta
 *   422 {codigo: 'direccion', message, errors?}    faltan datos, o se llegó al tope de 20
 *   404 {message}                                  no existe o es de otro comprador
 *
 * Sin `$request->validate()` a propósito: las reglas son las de `BuyerAddress::normalizar()`, que
 * son las mismas que valen para la dirección del invitado en el carrito. Escribirlas dos veces es
 * la forma segura de que dejen de coincidir.
 */
class BuyerAddressController extends Controller
{
    /** Los campos que el comprador puede escribir (el resto lo pone el servidor). */
    const CAMPOS = [
        'street', 'street_number', 'apartment', 'city', 'province', 'zip_code', 'reference',
        'formatted_address', 'lat', 'lng', 'place_id',
    ];

    /**
     * Los campos que salen de Google para UN lugar: si cambia la dirección y no vienen de nuevo, los
     * viejos describen otro lugar y se borran.
     */
    const CAMPOS_DEL_LUGAR = ['formatted_address', 'lat', 'lng', 'place_id'];

    function index() {
        if ($corte = $this->sin_libreta()) {
            return $corte;
        }

        return response()->json(['models' => $this->direcciones_del_comprador()], 200);
    }

    function store(Request $request) {
        if ($corte = $this->sin_libreta()) {
            return $corte;
        }

        $normalizada = BuyerAddress::normalizar($request->only(self::CAMPOS));

        if (count($normalizada['errores']) > 0) {
            return $this->con_errores($normalizada['errores']);
        }

        $buyer = $this->buyer();
        $pide_default = $this->pide_default($request);

        // null = se llegó al tope (se decide adentro del candado, con la cuenta que vale).
        $direccion = DB::transaction(function () use ($buyer, $normalizada, $pide_default) {
            $actuales = $this->bloquear($buyer->id);

            if ($actuales->count() >= BuyerAddress::MAXIMO_POR_COMPRADOR) {
                return null;
            }

            // La primera dirección queda como principal sin que nadie lo pida.
            $es_default = $actuales->count() === 0 || $pide_default;

            if ($es_default) {
                $this->desmarcar_las_demas($actuales, null);
            }

            return BuyerAddress::create(array_merge($normalizada['datos'], [
                'buyer_id'   => $buyer->id,
                // El comercio del comprador: las direcciones son por tienda, como el comprador.
                'user_id'    => $buyer->user_id,
                'is_default' => $es_default,
            ]));
        });

        if (is_null($direccion)) {
            return response()->json([
                'codigo'  => 'direccion',
                'message' => 'Ya tenés '.BuyerAddress::MAXIMO_POR_COMPRADOR.' direcciones guardadas. Borrá alguna para agregar otra.',
            ], 422);
        }

        return response()->json(['model' => $direccion->fresh()], 201);
    }

    /**
     * Edita una dirección. Los campos que llegan pisan a los guardados y el resultado se vuelve a
     * validar entero: así sirve tanto para editar todo como para el "Marcar como principal" del SPA
     * (`{is_default: true}` solo).
     *
     * Si cambia la calle, la altura, la localidad o la provincia, lo que Google dio para el lugar
     * viejo (`formatted_address`, `lat`, `lng`, `place_id`) se borra salvo que venga de nuevo en el
     * request: unas coordenadas de otro lugar pondrían el marcador del mapa del ERP en otra casa.
     *
     * `is_default: false` sobre la principal se ignora: siempre hay una principal mientras haya
     * direcciones. Para cambiarla se marca otra.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    function update(Request $request, $id) {
        if ($corte = $this->sin_libreta()) {
            return $corte;
        }

        $direccion = $this->direccion_del_comprador($id);

        if (is_null($direccion)) {
            return $this->no_existe();
        }

        $datos = $direccion->only(self::CAMPOS);

        foreach (self::CAMPOS as $campo) {
            if ($request->exists($campo)) {
                $datos[$campo] = $request->input($campo);
            }
        }

        if ($this->cambio_algun_campo_de_la_linea($request, $direccion)) {
            foreach (self::CAMPOS_DEL_LUGAR as $campo) {
                if (!$request->exists($campo)) {
                    $datos[$campo] = null;
                }
            }
        }

        $normalizada = BuyerAddress::normalizar($datos);

        if (count($normalizada['errores']) > 0) {
            return $this->con_errores($normalizada['errores']);
        }

        $pide_default = $this->pide_default($request);

        $direccion = DB::transaction(function () use ($direccion, $normalizada, $pide_default) {
            $actuales = $this->bloquear($direccion->buyer_id);

            // La fila releída adentro del candado: el `is_default` que vale es el de ahora.
            $direccion = $actuales->firstWhere('id', $direccion->id);

            if (is_null($direccion)) {
                // La borró otro request mientras este esperaba el candado.
                return null;
            }

            if ($pide_default && !$direccion->is_default) {
                $this->desmarcar_las_demas($actuales, $direccion->id);
                $direccion->is_default = true;
            }

            $direccion->fill($normalizada['datos']);
            $direccion->save();

            return $direccion;
        });

        if (is_null($direccion)) {
            return $this->no_existe();
        }

        return response()->json(['model' => $direccion->fresh()], 200);
    }

    function destroy($id) {
        if ($corte = $this->sin_libreta()) {
            return $corte;
        }

        $direccion = $this->direccion_del_comprador($id);

        if (is_null($direccion)) {
            return $this->no_existe();
        }

        DB::transaction(function () use ($direccion) {
            $actuales = $this->bloquear($direccion->buyer_id);
            $direccion = $actuales->firstWhere('id', $direccion->id);

            if (is_null($direccion)) {
                // La borró otro request mientras este esperaba el candado: no queda nada que hacer.
                return;
            }

            $era_default = (bool) $direccion->is_default;

            $direccion->delete();

            if ($era_default) {
                // Las filas ya están bloqueadas por `bloquear()`: esta lectura no compite con nadie.
                $siguiente = BuyerAddress::where('buyer_id', $direccion->buyer_id)
                    ->orderBy('updated_at', 'DESC')
                    ->orderBy('id', 'DESC')
                    ->first();

                if (!is_null($siguiente)) {
                    $siguiente->is_default = true;
                    $siguiente->save();
                }
            }
        });

        // La lista que queda (con la principal nueva, si cambió): el SPA no tiene que adivinarla.
        return response()->json(['models' => $this->direcciones_del_comprador()], 200);
    }

    /**
     * Toma el candado del comprador y devuelve sus direcciones, también bloqueadas. Va SIEMPRE
     * adentro de una transacción. Ver el docblock de la clase: el candado sobre `buyers` es el que
     * serializa de verdad (con cero direcciones no hay filas de `buyer_addresses` que bloquear).
     *
     * @param int $buyer_id
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function bloquear($buyer_id) {
        Buyer::where('id', $buyer_id)->lockForUpdate()->first();

        return BuyerAddress::where('buyer_id', $buyer_id)->lockForUpdate()->get();
    }

    /**
     * Corta si esta sesión no tiene libreta de direcciones: la base no tiene la tabla, o el
     * comprador de la sesión es una ficha de invitado y no una cuenta.
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    private function sin_libreta() {
        if (!DireccionesEsquemaHelper::disponible()) {
            return response()->json([
                'codigo'  => 'sin_direcciones',
                'message' => 'Esta tienda todavía no permite guardar direcciones.',
            ], 422);
        }

        $buyer = $this->buyer();

        if (is_null($buyer) || !$buyer->es_cuenta()) {
            return response()->json([
                'codigo'  => 'sin_cuenta',
                'message' => 'Iniciá sesión con tu cuenta para guardar direcciones.',
            ], 422);
        }

        return null;
    }

    /**
     * Las direcciones del comprador de la sesión: la principal primero, después la más nueva.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function direcciones_del_comprador() {
        return BuyerAddress::where('buyer_id', $this->buyerId())
            ->orderBy('is_default', 'DESC')
            ->orderBy('updated_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();
    }

    /**
     * @param int $id
     * @return BuyerAddress|null
     */
    private function direccion_del_comprador($id) {
        if (!is_numeric($id)) {
            return null;
        }

        return BuyerAddress::where('id', (int) $id)
            ->where('buyer_id', $this->buyerId())
            ->first();
    }

    /**
     * Saca la marca de principal a las demás (las ya bloqueadas por `bloquear()`).
     *
     * Una por una y no un update masivo: así cada fila se toca con su `updated_at`, que es lo que
     * ordena "la más nueva" cuando hay que elegir una principal.
     *
     * @param \Illuminate\Database\Eloquent\Collection $actuales
     * @param int|null $salvo_id
     * @return void
     */
    private function desmarcar_las_demas($actuales, $salvo_id) {
        foreach ($actuales as $otra) {
            if (!$otra->is_default || (!is_null($salvo_id) && (int) $otra->id === (int) $salvo_id)) {
                continue;
            }

            $otra->is_default = false;
            $otra->save();
        }
    }

    /**
     * @param Request $request
     * @return bool
     */
    private function pide_default(Request $request) {
        return filter_var($request->input('is_default'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * True si el request cambia algo de lo que forma la línea armada (calle, altura, localidad o
     * provincia).
     *
     * @param Request $request
     * @param BuyerAddress $direccion
     * @return bool
     */
    private function cambio_algun_campo_de_la_linea(Request $request, BuyerAddress $direccion) {
        foreach (['street', 'street_number', 'city', 'province'] as $campo) {
            if ($request->exists($campo) && trim((string) $request->input($campo)) !== trim((string) $direccion->{$campo})) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $errores
     * @return \Illuminate\Http\JsonResponse
     */
    private function con_errores(array $errores) {
        return response()->json([
            'codigo'  => 'direccion',
            'message' => 'Faltan datos de la dirección.',
            'errors'  => $errores,
        ], 422);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    private function no_existe() {
        return response()->json(['message' => 'No encontramos esa dirección.'], 404);
    }
}
