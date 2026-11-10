<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use App\Cart;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConsultaStockHelper
{
    public static function hay_columna($tabla, $columna)
    {
        $clave = "tienda.consulta_stock.$tabla.$columna";
        $atributos = request()->attributes;

        if (!$atributos->has($clave)) {
            $atributos->set($clave, Schema::hasColumn($tabla, $columna));
        }

        return $atributos->get($clave);
    }

    public static function disponible()
    {
        return self::hay_columna('online_configurations', 'permitir_pedidos_sin_stock')
            && self::hay_columna('article_cart', 'stock_a_consultar')
            && self::hay_columna('article_order', 'stock_a_consultar')
            && self::hay_columna('carts', 'preferencia_online_emitida');
    }

    public static function activa($configuracion)
    {
        return $configuracion
            && $configuracion->permitir_pedidos_sin_stock
            && !$configuracion->ignorar_stock
            && self::disponible();
    }

    public static function configuracion(Cart $cart)
    {
        return ContextoDeComercio::comercio($cart->user_id)?->online_configuration;
    }

    /** Serializa las mutaciones del carrito con la creación del pedido y de la referencia de pago. */
    public static function con_carrito($id, callable $operacion)
    {
        if (!is_numeric($id)) {
            return $operacion(null);
        }
        if (!self::hay_columna('carts', 'preferencia_online_emitida')) {
            return $operacion(Cart::find($id));
        }

        return DB::transaction(fn () => $operacion(Cart::lockForUpdate()->find($id)));
    }

    public static function clasificar_payload(Cart $cart, array $articulos)
    {
        $configuracion = self::configuracion($cart);
        $activa = self::activa($configuracion);

        return $activa || self::carrito_marcado($cart)
            ? self::clasificar($cart, self::lineas_del_payload($articulos), $configuracion, $activa)
            : [];
    }

    public static function clave_linea($articulo_id, $variante_id)
    {
        return $articulo_id.':'.(int) $variante_id;
    }

    public static function lineas_del_carrito(Cart $cart)
    {
        return DB::table('article_cart')->where('cart_id', $cart->id)->get();
    }

    public static function carrito_marcado(Cart $cart)
    {
        return $cart->exists
            && self::hay_columna('article_cart', 'stock_a_consultar')
            && DB::table('article_cart')->where('cart_id', $cart->id)->where('stock_a_consultar', 1)->exists();
    }

    public static function pedido_marcado($pedido_id)
    {
        return $pedido_id
            && self::hay_columna('article_order', 'stock_a_consultar')
            && DB::table('article_order')->where('order_id', $pedido_id)->where('stock_a_consultar', 1)->exists();
    }

    public static function lineas_del_payload(array $articulos)
    {
        $lineas = [];
        foreach ($articulos as $articulo) {
            if (!isset($articulo['is_promocion_vinoteca'])) {
                $lineas[] = (object) [
                    'article_id' => CartHelper::id_de_linea($articulo),
                    'variant_id' => $articulo['pivot']['variant_id'] ?? null,
                    'amount' => $articulo['pivot']['amount'] ?? null,
                ];
            }
        }
        return $lineas;
    }

    /** Devuelve el artículo con precios del servidor de cada renglón a consultar. */
    public static function clasificar(Cart $cart, iterable $lineas, $configuracion, $activa)
    {
        if ($configuracion?->ignorar_stock) {
            return [];
        }

        $lineas = collect($lineas);
        if ($lineas->isEmpty()) {
            return [];
        }

        $articulos = Article::where('user_id', $cart->user_id)
            ->whereIn('id', $lineas->pluck('article_id')->unique())
            ->with('article_variants', 'price_types')
            ->withExists('images')
            ->get()
            ->keyBy('id');

        $vistas = [];
        $consultas = [];
        $consultables = [];

        foreach ($lineas as $linea) {
            $articulo = $articulos->get($linea->article_id);
            if (!$articulo) {
                self::fallar('Un artículo ya no está disponible. Revisá tu carrito.');
            }

            $variante_id = $linea->variant_id;
            $variante = $articulo->article_variants->firstWhere('id', $variante_id);
            if (($variante_id && (!$variante || $variante->oculta))
                || (!$variante_id && $articulo->article_variants->isNotEmpty())) {
                self::fallar("Elegí una variante disponible para {$articulo->name}.");
            }

            $clave = self::clave_linea($articulo->id, $variante_id);
            if (isset($vistas[$clave]) || !is_numeric($linea->amount) || (float) $linea->amount <= 0) {
                self::fallar("Revisá la cantidad de {$articulo->name} en tu carrito.");
            }
            $vistas[$clave] = true;

            $stock = $variante ? $variante->stock : $articulo->stock;
            if ($stock !== null && (float) $stock === 0.0 && $activa) {
                $cantidad = (float) $linea->amount;
                if ($cantidad > 2147483647 || floor($cantidad) !== $cantidad) {
                    self::fallar("Ingresá una cantidad entera para consultar {$articulo->name}.");
                }
                if (!$articulo->online || $articulo->status !== 'active' || $articulo->precio_pausado
                    || (!$configuracion->show_articles_without_images && !$articulo->images_exists)) {
                    self::fallar("{$articulo->name} ya no está disponible en la tienda.");
                }

                $consultas[$clave] = $articulo;
                $consultables[$articulo->id] = $articulo;
                continue;
            }

            if ($stock === null ? (!$variante && $configuracion?->stock_null_equal_0) : (float) $linea->amount > (float) $stock) {
                self::fallar("No hay stock suficiente de {$articulo->name}. Revisá tu carrito.");
            }
        }

        if (!$consultas) {
            return [];
        }

        $articulos_con_precio = self::validar_acceso_y_precios($configuracion, array_values($consultables), $cart->user_id);
        return array_map(fn ($articulo) => $articulos_con_precio->get($articulo->id), $consultas);
    }

    private static function validar_acceso_y_precios($configuracion, array $articulos, $comercio_id)
    {
        $comprador = Auth::guard('buyer')->user();
        if ($configuracion->pausar_tienda_online
            || ($configuracion->register_to_buy && (!$comprador || (int) $comprador->user_id !== (int) $comercio_id))
            || ($configuracion->register_to_buy
                && $configuracion->online_price_type?->slug === 'only_buyers_with_comerciocity_client'
                && !$comprador->comercio_city_client_id && !$comprador->seller_id)) {
            self::fallar('El acceso actual de tu cuenta no permite consultar este pedido.');
        }

        $articulos = ArticleHelper::checkPriceTypes($articulos);
        foreach ($articulos as $articulo) {
            if ((float) $articulo->final_price <= 0 && !collect($articulo->ranges)->contains(fn ($rango) => (float) $rango->price > 0)) {
                self::fallar("{$articulo->name} no tiene un precio disponible para tu pedido.");
            }
        }
        return collect($articulos)->keyBy('id');
    }

    public static function fallar($mensaje, $codigo = 'consulta_stock')
    {
        throw new HttpResponseException(response()->json([
            'codigo' => $codigo,
            'message' => $mensaje,
        ], 422));
    }
}
