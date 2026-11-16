<?php

namespace App\Http\Controllers\Helpers;

use App\Cart;
use App\OnlineConfiguration;
use App\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Contrato v1 compartido con Empresa. Reservar nunca escribe existencias ni movimientos de dinero. */
class ReservaStockWebHelper
{
    const PROTOCOLO = 1;
    const TABLA = 'order_stock_reservations';

    public static function disponible()
    {
        $medir = function () {
            foreach (['reservar_stock_pedidos_web', 'reserva_stock_erp_protocolo', 'reserva_stock_tienda_protocolo', 'reserva_stock_tienda_url'] as $columna) {
                if (!Schema::hasColumn('online_configurations', $columna)) {
                    return false;
                }
            }
            foreach (['reserva_stock_web', 'reserva_stock_web_consumida', 'reserva_stock_web_egreso'] as $columna) {
                if (!Schema::hasColumn('orders', $columna)) {
                    return false;
                }
            }
            if (!Schema::hasTable(self::TABLA)) {
                return false;
            }
            foreach (['user_id', 'order_id', 'article_id', 'article_variant_id', 'promocion_vinoteca_id', 'address_id', 'amount'] as $columna) {
                if (!Schema::hasColumn(self::TABLA, $columna)) {
                    return false;
                }
            }
            return true;
        };
        if (app()->runningInConsole()) {
            return $medir();
        }
        $atributos = request()->attributes;
        if (!$atributos->has('tienda.reserva_stock.esquema')) {
            $atributos->set('tienda.reserva_stock.esquema', $medir());
        }
        return $atributos->get('tienda.reserva_stock.esquema');
    }

    public static function activa($configuracion)
    {
        return $configuracion && (bool) $configuracion->reservar_stock_pedidos_web
            && !$configuracion->ignorar_stock
            && (int) $configuracion->reserva_stock_erp_protocolo === self::PROTOCOLO
            && (int) $configuracion->reserva_stock_tienda_protocolo === self::PROTOCOLO
            && self::disponible();
    }

    /** La configuración siempre se bloquea ANTES del carrito y de cualquier recurso de stock. */
    public static function con_carrito($id, callable $operacion)
    {
        if (!self::disponible() || !is_numeric($id)) {
            return ConsultaStockHelper::con_carrito($id, $operacion);
        }
        // Se lee la pertenencia fuera de la transacción para no fijar un snapshot viejo al esperar.
        $comercio_id = Cart::whereKey($id)->value('user_id');
        return DB::transaction(function () use ($id, $comercio_id, $operacion) {
            OnlineConfiguration::where('user_id', $comercio_id)->lockForUpdate()->first();
            return $operacion(Cart::lockForUpdate()->find($id));
        }, 3);
    }

    public static function pedido_marcado($id)
    {
        return $id && self::disponible() && (bool) DB::table('orders')->where('id', $id)->value('reserva_stock_web');
    }

    public static function deposito_del_pedido($comercio_id, $elegido)
    {
        if ($elegido !== null && $elegido !== '' && (string) $elegido !== '0') {
            if (!is_numeric($elegido) || (int) $elegido != $elegido || !DB::table('addresses')->where('id', $elegido)->where('user_id', $comercio_id)->whereNull('buyer_id')->exists()) {
                ConsultaStockHelper::fallar('Elegí un depósito válido para el pedido.');
            }
            return (int) $elegido;
        }
        return self::deposito_principal($comercio_id);
    }

    public static function deposito_principal($comercio_id)
    {
        // Este cliente ya publica exclusivamente el depósito 1 antes de la opción; se conserva.
        if (env('APP_URL') === 'https://api.golonorte.com.ar' && DB::table('addresses')->where('id', 1)->where('user_id', $comercio_id)->whereNull('buyer_id')->exists()) {
            return 1;
        }
        $consulta = DB::table('addresses')->where('user_id', $comercio_id)->whereNull('buyer_id')
            ->orderByDesc('default_address');
        if (Schema::hasColumn('addresses', 'es_deposito_madre')) {
            $consulta->orderByDesc('es_deposito_madre');
        }
        return $consulta->orderBy('id')->value('id');
    }

    /** SQL compartido por filtros y serialización: no deja agotados comprables por un filtro físico. */
    public static function expresion_articulo($alias, $comercio_id, $variante = false)
    {
        $deposito = (int) self::deposito_principal($comercio_id);
        $pivote = $variante ? 'address_article_variant' : 'address_article';
        $recurso = $variante ? 'article_variant_id' : 'article_id';
        $id = $alias.'.id';
        $fisico = "CASE WHEN EXISTS (SELECT 1 FROM $pivote fp WHERE fp.$recurso = $id) "
            ."THEN (SELECT COALESCE(SUM(fp.amount), 0) FROM $pivote fp WHERE fp.$recurso = $id AND fp.address_id = $deposito) "
            ."ELSE $alias.stock END";
        $restriccion = $variante
            ? "r.article_variant_id = $id"
            : "r.article_id = $id AND r.article_variant_id IS NULL";
        $reservado = "(SELECT COALESCE(SUM(r.amount), 0) FROM ".self::TABLA." r WHERE $restriccion "
            ."AND (NOT EXISTS (SELECT 1 FROM $pivote rp WHERE rp.$recurso = $id) OR COALESCE(r.address_id,0) = $deposito))";
        $disponible = "CASE WHEN ($fisico) IS NULL THEN NULL ELSE GREATEST(0, ($fisico) - $reservado) END";
        if (!$variante) {
            // El padre de variantes no conserva unidades disponibles cuando todas sus variantes se reservaron.
            $variante_disponible = self::expresion_articulo('rv', $comercio_id, true);
            return "CASE WHEN EXISTS (SELECT 1 FROM article_variants rv WHERE rv.article_id = $id) THEN "
                ."CASE WHEN EXISTS (SELECT 1 FROM article_variants rv WHERE rv.article_id = $id AND rv.oculta = 0 AND ($variante_disponible) IS NULL) THEN NULL "
                ."ELSE (SELECT COALESCE(SUM($variante_disponible),0) FROM article_variants rv WHERE rv.article_id = $id AND rv.oculta = 0) END "
                ."ELSE ($disponible) END";
        }
        return $disponible;
    }

    public static function stock_articulo($id, $comercio_id, $fisico, $variante = false)
    {
        if (!$comercio_id) {
            $comercio_id = $variante
                ? DB::table('article_variants')->join('articles', 'articles.id', '=', 'article_variants.article_id')->where('article_variants.id', $id)->value('articles.user_id')
                : DB::table('articles')->where('id', $id)->value('user_id');
        }
        $configuracion = ContextoDeComercio::comercio($comercio_id)?->online_configuration;
        if (!self::activa($configuracion)) {
            return $fisico;
        }
        $tabla = $variante ? 'article_variants' : 'articles';
        $stock = DB::table($tabla)->where('id', $id)
            ->selectRaw(self::expresion_articulo($tabla, $comercio_id, $variante).' AS disponible')->value('disponible');
        return $stock === null ? null : (float) $stock;
    }

    /** La receta ya trae su configuración por JOIN: opción apagada sigue costando una sola consulta. */
    public static function stocks_de_componentes($filas)
    {
        $grupos = [];
        foreach ($filas as $fila) {
            if (!empty($fila->reservar_stock_pedidos_web) && empty($fila->ignorar_stock)
                && (int) ($fila->reserva_stock_erp_protocolo ?? 0) === self::PROTOCOLO
                && (int) ($fila->reserva_stock_tienda_protocolo ?? 0) === self::PROTOCOLO) {
                $grupos[$fila->comercio_id][] = $fila->article_id;
            }
        }
        if (!$grupos || !self::disponible()) {
            return [];
        }
        $resultado = [];
        foreach ($grupos as $comercio => $ids) {
            $stocks = DB::table('articles')->whereIn('id', array_unique($ids))->select('id')
                ->selectRaw(self::expresion_articulo('articles', $comercio).' AS disponible')->get();
            foreach ($stocks as $stock) {
                $resultado[$stock->id] = $stock->disponible;
            }
        }
        return $resultado;
    }

    public static function stock_promocion($id, $comercio_id, $fisico)
    {
        if (!self::activa(ContextoDeComercio::comercio($comercio_id)?->online_configuration) || $fisico === null) {
            return $fisico;
        }
        return max(0, (float) $fisico - (float) DB::table(self::TABLA)->where('promocion_vinoteca_id', $id)->sum('amount'));
    }

    /** Relee físico y reservas con current reads; un SUM común conservaría un snapshot anterior al lock. */
    public static function reservar_pedido(Order $pedido)
    {
        if (!self::disponible()) {
            return;
        }
        $configuracion = OnlineConfiguration::where('user_id', $pedido->user_id)->lockForUpdate()->first();
        if (!self::activa($configuracion)) {
            return;
        }
        if (Order::whereKey($pedido->id)->lockForUpdate()->value('reserva_stock_web')) {
            return;
        }
        $deposito = self::deposito_del_pedido($pedido->user_id, $pedido->address_id);
        $recursos = [];
        $agregar = function ($articulo, $variante, $promo, $cantidad, $consulta = false, $directo = true) use (&$recursos) {
            if (!is_numeric($cantidad) || !is_finite((float) $cantidad) || (float) $cantidad <= 0) {
                ConsultaStockHelper::fallar('Revisá las cantidades de tu pedido.');
            }
            if ($consulta) {
                return;
            }
            $clave = $promo ? 'p:'.$promo : 'a:'.$articulo.':'.(int) $variante;
            if (!isset($recursos[$clave])) {
                $recursos[$clave] = ['article_id' => $articulo, 'article_variant_id' => $variante ?: null,
                    'promocion_vinoteca_id' => $promo, 'amount' => 0, 'directo' => false];
            }
            $recursos[$clave]['amount'] = round($recursos[$clave]['amount'] + (float) $cantidad, 4);
            $recursos[$clave]['directo'] = $recursos[$clave]['directo'] || $directo;
        };
        foreach (DB::table('article_order')->where('order_id', $pedido->id)->orderBy('id')->lockForUpdate()->get() as $linea) {
            $agregar($linea->article_id, $linea->variant_id, null, $linea->amount, (bool) ($linea->stock_a_consultar ?? false));
        }
        if (ComboEsquemaHelper::disponible()) {
            foreach (DB::table('order_combo')->where('order_id', $pedido->id)->orderBy('id')->lockForUpdate()->get() as $combo) {
                $modelo = DB::table('combos')->where('id', $combo->combo_id)->where('user_id', $pedido->user_id)->whereNull('deleted_at')->lockForUpdate()->first();
                if (!$modelo || !$modelo->online) {
                    ConsultaStockHelper::fallar('Un combo ya no está disponible. Revisá tu carrito.');
                }
                foreach (DB::table('article_combo')->where('combo_id', $combo->combo_id)->orderBy('id')->lockForUpdate()->get() as $componente) {
                    $agregar($componente->article_id, null, null, (float) $componente->amount * (float) $combo->amount, false, false);
                }
            }
        }
        foreach (DB::table('order_promocion_vinoteca')->where('order_id', $pedido->id)->orderBy('id')->lockForUpdate()->get() as $linea) {
            $agregar(null, null, $linea->promocion_vinoteca_id, $linea->amount);
        }
        ksort($recursos);
        foreach ($recursos as $recurso) {
            $fisico = self::fisico_actual($recurso, $pedido->user_id, $deposito);
            // Un stock sin control nunca se convierte en una reserva ficticia de unidades.
            if ($fisico['amount'] === null) {
                if ($recurso['directo'] && !$recurso['article_variant_id'] && !$recurso['promocion_vinoteca_id'] && $configuracion->stock_null_equal_0) {
                    ConsultaStockHelper::fallar('Un artículo de tu pedido no tiene stock disponible.');
                }
                continue;
            }
            $consulta = DB::table(self::TABLA)->where('user_id', $pedido->user_id)->where('order_id', '!=', $pedido->id);
            foreach (['article_id', 'article_variant_id', 'promocion_vinoteca_id'] as $campo) {
                $recurso[$campo] === null ? $consulta->whereNull($campo) : $consulta->where($campo, $recurso[$campo]);
            }
            if ($fisico['por_deposito']) {
                $deposito === null ? $consulta->whereNull('address_id') : $consulta->where('address_id', $deposito);
            }
            $reservado = $consulta->orderBy('id')->lockForUpdate()->get()->sum('amount');
            if ($recurso['amount'] <= 0 || $recurso['amount'] > round(max(0, (float) $fisico['amount'] - (float) $reservado), 4)) {
                ConsultaStockHelper::fallar('Cambió la disponibilidad de tu pedido. Revisá el carrito antes de enviarlo.');
            }
            unset($recurso['directo']);
            DB::table(self::TABLA)->insert($recurso + ['user_id' => $pedido->user_id, 'order_id' => $pedido->id,
                'address_id' => $deposito, 'created_at' => now(), 'updated_at' => now()]);
        }
        $pedido->reserva_stock_web = true;
        $pedido->address_id = $deposito ?: 0;
        $pedido->save();
    }

    private static function fisico_actual(array $recurso, $comercio_id, $deposito)
    {
        if ($recurso['promocion_vinoteca_id']) {
            $modelo = DB::table('promocion_vinotecas')->where('id', $recurso['promocion_vinoteca_id'])
                ->where('user_id', $comercio_id)->whereNull('deleted_at')->lockForUpdate()->first();
            if (!$modelo || !$modelo->online) {
                ConsultaStockHelper::fallar('Una promoción ya no está disponible.');
            }
            return ['amount' => $modelo->stock, 'por_deposito' => false];
        }
        $articulo = DB::table('articles')->where('id', $recurso['article_id'])->where('user_id', $comercio_id)
            ->whereNull('deleted_at')->lockForUpdate()->first();
        if (!$articulo || ($recurso['directo'] && (!$articulo->online || $articulo->status !== 'active'))) {
            ConsultaStockHelper::fallar('Un artículo ya no está disponible. Revisá tu carrito.');
        }
        if (!$recurso['article_variant_id']
            && DB::table('article_variants')->where('article_id', $articulo->id)->lockForUpdate()->get()->isNotEmpty()) {
            ConsultaStockHelper::fallar($recurso['directo'] ? 'Elegí una variante disponible.' : 'Este combo necesita una variante definida. Consultá al negocio antes de pedirlo.');
        }
        $variante = null;
        if ($recurso['article_variant_id']) {
            $variante = DB::table('article_variants')->where('id', $recurso['article_variant_id'])
                ->where('article_id', $articulo->id)->lockForUpdate()->first();
            if (!$variante || $variante->oculta) {
                ConsultaStockHelper::fallar('Elegí una variante disponible.');
            }
        }
        $tabla = $variante ? 'address_article_variant' : 'address_article';
        $campo = $variante ? 'article_variant_id' : 'article_id';
        $id = $variante ? $variante->id : $articulo->id;
        $filas = DB::table($tabla)->where($campo, $id)->orderBy('id')->lockForUpdate()->get();
        return ['amount' => $filas->isNotEmpty() ? $filas->where('address_id', $deposito)->sum('amount') : ($variante ? $variante->stock : $articulo->stock),
            'por_deposito' => $filas->isNotEmpty()];
    }
}
