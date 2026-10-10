<?php

namespace App\Http\Controllers;

use App\Buyer;
use App\CreditAccount;
use App\CurrentAcount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CurrentAcountController extends Controller
{
    /**
     * Tabla de los links de PDF con token (mision pdf-de-venta-publico, 10/10/2026).
     *
     * 🔴 La crea la migracion de `empresa-api`, NO la tienda: `tienda-api` no tiene
     * database/migrations y su deploy no corre `migrate`. Aca solo se inserta y se lee.
     *
     * El contrato es fijo y no se cambia sin hablar (plan de la mision, "Contrato empresa ↔
     * tienda"): id, user_id (dueño del recurso), tipo (string 40), model_id, token (string 64,
     * unico), revoked_at (null = vigente) y timestamps. empresa-api sirve el PDF si el `t` del
     * query coincide con una fila de ese `tipo` y `model_id` que no este revocada.
     */
    const TABLA_PDF_LINKS = 'pdf_links';

    /** Tipo del contrato para el estado de cuenta: `current-acount/pdf/{credit_account_id}/{n}/{type}`. */
    const TIPO_PDF_CREDIT_ACCOUNT = 'credit_account';

    /** Tipo del contrato para un movimiento (pago o nota de credito): `current-acount/pdf/{id}`. */
    const TIPO_PDF_CURRENT_ACOUNT = 'current_acount';

    /** Largo del token: `Str::random(48)`, el mismo generador que usa empresa-api. */
    const LARGO_TOKEN_PDF = 48;

    function getCreditAccounts() {
        $buyer = $this->buyer();

        if (!$buyer || !$buyer->comercio_city_client_id) {
            return response()->json(['credit_accounts' => []], 200);
        }

        $credit_accounts = CreditAccount::where('model_name', 'client')
                                        ->where('model_id', $buyer->comercio_city_client_id)
                                        ->with('moneda')
                                        ->get();

        return response()->json(['credit_accounts' => $credit_accounts], 200);
    }

    function getMovements($credit_account_id, $cantidad_movimientos) {
        $buyer = $this->buyer();

        if (!$buyer || !$buyer->comercio_city_client_id) {
            return response(null, 403);
        }

        $credit_account = CreditAccount::find($credit_account_id);

        if (!$credit_account 
            || $credit_account->model_name != 'client' 
            || $credit_account->model_id != $buyer->comercio_city_client_id) {
            return response(null, 403);
        }

        $models = CurrentAcount::where('credit_account_id', $credit_account_id)
                                ->orderBy('created_at', 'DESC')
                                ->take($cantidad_movimientos)
                                ->with('current_acount_payment_methods')
                                ->with('sale')
                                ->get()
                                ->reverse()
                                ->values();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Emite un token de un solo uso (5 min) para imprimir el PDF de una venta
     * de la cuenta corriente del buyer autenticado.
     *
     * @param int $sale_id ID de la venta a imprimir
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    function salePdfToken($sale_id) {
        $buyer = $this->buyer();

        if (!$buyer || !$buyer->comercio_city_client_id) {
            return response(null, 403);
        }

        // Ownership: la venta tiene que estar en un movimiento de una cuenta corriente de este buyer/cliente.
        $movement = CurrentAcount::where('sale_id', $sale_id)
            ->whereHas('credit_account', function ($query) use ($buyer) {
                $query->where('model_name', 'client')
                      ->where('model_id', $buyer->comercio_city_client_id);
            })
            ->first();

        if (!$movement) {
            return response(null, 403);
        }

        $token = bin2hex(random_bytes(32));

        \App\SalePdfAccessToken::create([
            'token' => $token,
            'sale_id' => $sale_id,
            'expires_at' => now()->addMinutes(5),
            'used_at' => null,
        ]);

        return response()->json(['token' => $token], 200);
    }

    /**
     * Token del link del PDF del ESTADO DE CUENTA de una cuenta corriente del comprador logueado
     * (mision pdf-de-venta-publico, 10/10/2026).
     *
     * ── Por que existe ──────────────────────────────────────────────────────────────────────────
     *
     * empresa-api deja de servir los PDF sin sesion ni token cuando se cierra la ventana de
     * transicion (60 dias por instalacion). El comprador de la tienda NO tiene sesion de empresa,
     * asi que el link `current-acount/pdf/{credit_account_id}/{n}/{type}` que le arma la SPA
     * (views/CuentaCorriente.vue) tiene que llevar `?t=<token>`. Ese token sale de aca: la tienda
     * comparte la base con el ERP, asi que alcanza con insertar (o reusar) la fila de `pdf_links`.
     *
     * ── Respuestas ──────────────────────────────────────────────────────────────────────────────
     *
     *   - 403 sin sesion de comprador: lo mismo que devuelven las rutas vecinas
     *     (salePdfToken, getMovements), que tampoco estan en el grupo auth:buyer.
     *   - 404 si la cuenta no es del comprador, si no existe, o si el comprador no tiene cliente
     *     del ERP vinculado. Las tres dan lo MISMO a proposito: no es un oraculo de ids.
     *   - 200 {token: "<48 caracteres>"} si es suya.
     *   - 200 {token: null} si la base todavia no tiene `pdf_links` (empresa sin actualizar): con
     *     empresa vieja la ruta del PDF sigue publica y la SPA abre el link de siempre.
     *
     * @param int $credit_account_id ID de la cuenta corriente cuyo estado de cuenta se va a abrir
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    function creditAccountPdfToken($credit_account_id) {
        $buyer = $this->buyer();

        if (!$buyer) {
            return response(null, 403);
        }

        $credit_account = $this->creditAccountDelComprador($buyer, $credit_account_id);

        if (!$credit_account) {
            return response(null, 404);
        }

        $token = $this->tokenDePdfLink(
            self::TIPO_PDF_CREDIT_ACCOUNT,
            $credit_account->id,
            $credit_account->user_id
        );

        return response()->json(['token' => $token], 200);
    }

    /**
     * Token del link del PDF de UN MOVIMIENTO de la cuenta corriente del comprador logueado: el
     * comprobante de un pago o una nota de credito, `current-acount/pdf/{id}` en empresa-api
     * (components/cuenta-corriente/Table.vue). Mision pdf-de-venta-publico, 10/10/2026.
     *
     * Mismo criterio de pertenencia que salePdfToken: el movimiento tiene que estar en una cuenta
     * corriente (`model_name = 'client'`) cuyo `model_id` sea el `comercio_city_client_id` del
     * comprador. Un movimiento sin `credit_account_id` no es de nadie para la tienda (tampoco
     * aparece en getMovements, que lista por cuenta).
     *
     * No se filtra por `status`: la SPA solo lo pide para pagos y notas de credito, pero el
     * movimiento es del comprador y ya lo ve entero en getMovements. El PDF de la VENTA no pasa por
     * aca: va por salePdfToken + `origin=tienda`, que empresa-api valida con su propio candado.
     *
     * Respuestas: las mismas que creditAccountPdfToken (403 sin sesion, 404 si no es suyo,
     * {token} o {token: null} sin la tabla).
     *
     * @param int $current_acount_id ID del movimiento cuyo comprobante se va a abrir
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    function currentAcountPdfToken($current_acount_id) {
        $buyer = $this->buyer();

        if (!$buyer) {
            return response(null, 403);
        }

        // 🔴 Sin cliente vinculado se corta ACA y no en la consulta: where('model_id', null) lo
        // convierte Eloquent en whereNull('model_id'), la clase de error que ya abrio mas de un
        // agujero en esta API. Hoy no matchearia (model_id es NOT NULL), pero no se apuesta a eso.
        if (!$buyer->comercio_city_client_id) {
            return response(null, 404);
        }

        $movement = CurrentAcount::where('id', $current_acount_id)
            ->whereHas('credit_account', function ($query) use ($buyer) {
                $query->where('model_name', 'client')
                      ->where('model_id', $buyer->comercio_city_client_id);
            })
            ->with('credit_account')
            ->first();

        if (!$movement) {
            return response(null, 404);
        }

        // Dueño del recurso = el `user_id` del movimiento. Es nullable en `current_acounts`, y el
        // contrato no admite user_id null: si falta, el dueño es el de su cuenta corriente
        // (NOT NULL), que es el mismo comercio.
        $user_id = $movement->user_id ? $movement->user_id : $movement->credit_account->user_id;

        $token = $this->tokenDePdfLink(
            self::TIPO_PDF_CURRENT_ACOUNT,
            $movement->id,
            $user_id
        );

        return response()->json(['token' => $token], 200);
    }

    /**
     * La cuenta corriente pedida, si es del comprador; null si no (o si no existe, o si el
     * comprador no tiene cliente del ERP vinculado).
     *
     * Mismo criterio que getMovements y salePdfToken: Buyer.comercio_city_client_id → Client.id,
     * contra una cuenta con `model_name = 'client'`.
     *
     * @param \App\Buyer $buyer Comprador logueado
     * @param int $credit_account_id ID pedido por la SPA
     * @return \App\CreditAccount|null
     */
    private function creditAccountDelComprador($buyer, $credit_account_id) {
        // Ver el comentario del whereNull en currentAcountPdfToken.
        if (!$buyer->comercio_city_client_id) {
            return null;
        }

        return CreditAccount::where('id', $credit_account_id)
            ->where('model_name', 'client')
            ->where('model_id', $buyer->comercio_city_client_id)
            ->first();
    }

    /**
     * Devuelve el token vigente de `pdf_links` para ese recurso, creandolo si no hay.
     *
     * Contrato (no se cambia sin hablar con empresa-api):
     *   - REUSO: si ya hay una fila con ese `tipo` y `model_id` y `revoked_at` null, se devuelve
     *     su token. Asi el mismo comprobante tiene siempre el mismo link, y no se junta una fila
     *     por cada click. Una fila revocada NO se reusa: se crea otra.
     *   - Si no, se inserta una con `user_id` = dueño del recurso y token `Str::random(48)`.
     *
     * Si la tabla no existe (cliente con empresa todavia sin actualizar) devuelve null SIN error:
     * es el estado normal mientras empresa no salga, no el borde. La guarda va por la fachada
     * `Schema` a proposito: es la costura que usa el test del caso "sin tabla" para simularlo sin
     * tocar el esquema real. Se paga una consulta al information_schema por click, no por pagina.
     *
     * Si dos clicks llegan juntos pueden quedar dos filas vigentes para el mismo recurso: no rompe
     * nada (empresa-api acepta cualquier fila no revocada que coincida) y no vale un lock.
     *
     * Un error de otra clase (la tabla con otra forma, la base caida) NO se traga: sale como 500 y
     * queda en el log, y la SPA abre igual el link de siempre.
     *
     * @param string $tipo Uno de los TIPOS del contrato (TIPO_PDF_*)
     * @param int $model_id ID del recurso
     * @param int $user_id Dueño del recurso (el comercio)
     * @return string|null
     */
    private function tokenDePdfLink($tipo, $model_id, $user_id) {
        if (!Schema::hasTable(self::TABLA_PDF_LINKS)) {
            return null;
        }

        $vigente = DB::table(self::TABLA_PDF_LINKS)
            ->where('tipo', $tipo)
            ->where('model_id', $model_id)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->value('token');

        if (!is_null($vigente)) {
            return $vigente;
        }

        $token = Str::random(self::LARGO_TOKEN_PDF);
        $ahora = now();

        DB::table(self::TABLA_PDF_LINKS)->insert([
            'user_id'    => $user_id,
            'tipo'       => $tipo,
            'model_id'   => $model_id,
            'token'      => $token,
            'revoked_at' => null,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        return $token;
    }
}
