<?php

namespace App\Http\Controllers;

use App\BuyerAddress;
use App\Http\Controllers\Helpers\DireccionesEsquemaHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Mis direcciones" del comprador (misión formas-envio-direcciones-maps, 19/10/2026).
 *
 * Rutas bajo `auth:buyer` (routes/api.php): solo el comprador con sesión de CUENTA tiene una
 * libreta de direcciones. El invitado (y el vendedor cargando el pedido de un cliente) manda la
 * dirección entera en el carrito, sin guardarla acá.
 *
 * ── El alcance ────────────────────────────────────────────────────────────────────────────────
 *
 * Todo se acota por `buyer_id` = el comprador de la SESIÓN, nunca por un id del request. Una
 * dirección de otro comprador responde 404 (no 403): para quien pregunta, no existe.
 *
 * ── Contrato con tienda-spa ───────────────────────────────────────────────────────────────────
 *
 *   GET    /api/buyer/addresses        200 {models: [...]}   default primero, después la más nueva
 *   POST   /api/buyer/addresses        201 {model}           la primera queda default
 *   PUT    /api/buyer/addresses/{id}   200 {model}           los campos que vienen pisan a los guardados
 *   DELETE /api/buyer/addresses/{id}   200 {models: [...]}   si era la default, la más nueva pasa a serlo
 *
 *   422 {codigo: 'sin_direcciones', message}       la base todavía no tiene la tabla
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

    function index() {
        if (!DireccionesEsquemaHelper::disponible()) {
            return $this->sin_direcciones();
        }

        return response()->json(['models' => $this->direcciones_del_comprador()], 200);
    }

    function store(Request $request) {
        if (!DireccionesEsquemaHelper::disponible()) {
            return $this->sin_direcciones();
        }

        $buyer = $this->buyer();
        $cantidad = BuyerAddress::where('buyer_id', $buyer->id)->count();

        if ($cantidad >= BuyerAddress::MAXIMO_POR_COMPRADOR) {
            return response()->json([
                'codigo'  => 'direccion',
                'message' => 'Ya tenés '.BuyerAddress::MAXIMO_POR_COMPRADOR.' direcciones guardadas. Borrá alguna para agregar otra.',
            ], 422);
        }

        $normalizada = BuyerAddress::normalizar($request->only(self::CAMPOS));

        if (count($normalizada['errores']) > 0) {
            return $this->con_errores($normalizada['errores']);
        }

        // La primera dirección queda como principal sin que nadie lo pida.
        $es_default = $cantidad === 0 || $this->pide_default($request);

        $direccion = DB::transaction(function () use ($buyer, $normalizada, $es_default) {
            if ($es_default) {
                $this->desmarcar_las_demas($buyer->id, null);
            }

            return BuyerAddress::create(array_merge($normalizada['datos'], [
                'buyer_id'   => $buyer->id,
                // El comercio del comprador: las direcciones son por tienda, como el comprador.
                'user_id'    => $buyer->user_id,
                'is_default' => $es_default,
            ]));
        });

        return response()->json(['model' => $direccion->fresh()], 201);
    }

    /**
     * Edita una dirección. Los campos que llegan pisan a los guardados y el resultado se vuelve a
     * validar entero: así sirve tanto para editar todo como para el "Marcar como principal" del SPA
     * (`{is_default: true}` solo).
     *
     * `is_default: false` sobre la principal se ignora: siempre hay una principal mientras haya
     * direcciones. Para cambiarla se marca otra.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    function update(Request $request, $id) {
        if (!DireccionesEsquemaHelper::disponible()) {
            return $this->sin_direcciones();
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

        // Si cambió la calle o la localidad y no vino una línea de Google nueva, la vieja ya no
        // describe la dirección: se vuelve a armar desde los campos.
        if (!$request->exists('formatted_address') && $this->cambio_algun_campo_de_la_linea($request, $direccion)) {
            $datos['formatted_address'] = null;
        }

        $normalizada = BuyerAddress::normalizar($datos);

        if (count($normalizada['errores']) > 0) {
            return $this->con_errores($normalizada['errores']);
        }

        $marcar_default = $this->pide_default($request) && !$direccion->is_default;

        DB::transaction(function () use ($direccion, $normalizada, $marcar_default) {
            if ($marcar_default) {
                $this->desmarcar_las_demas($direccion->buyer_id, $direccion->id);
                $direccion->is_default = true;
            }

            $direccion->fill($normalizada['datos']);
            $direccion->save();
        });

        return response()->json(['model' => $direccion->fresh()], 200);
    }

    function destroy($id) {
        if (!DireccionesEsquemaHelper::disponible()) {
            return $this->sin_direcciones();
        }

        $direccion = $this->direccion_del_comprador($id);

        if (is_null($direccion)) {
            return $this->no_existe();
        }

        DB::transaction(function () use ($direccion) {
            $era_default = (bool) $direccion->is_default;
            $buyer_id = $direccion->buyer_id;

            $direccion->delete();

            if ($era_default) {
                $siguiente = BuyerAddress::where('buyer_id', $buyer_id)
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
     * @param int $buyer_id
     * @param int|null $salvo_id
     * @return void
     */
    private function desmarcar_las_demas($buyer_id, $salvo_id) {
        $query = BuyerAddress::where('buyer_id', $buyer_id)->where('is_default', 1);

        if (!is_null($salvo_id)) {
            $query->where('id', '!=', $salvo_id);
        }

        // Uno por uno y no un update masivo: así cada fila se toca con su `updated_at`, que es lo
        // que ordena "la más nueva" cuando hay que elegir una principal.
        foreach ($query->get() as $otra) {
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
     * @return \Illuminate\Http\JsonResponse
     */
    private function sin_direcciones() {
        return response()->json([
            'codigo'  => 'sin_direcciones',
            'message' => 'Esta tienda todavía no permite guardar direcciones.',
        ], 422);
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
