<?php

namespace Tests\Feature\CuotasMercadoPago;

use App\Article;
use App\Cart;
use App\Http\Controllers\Helpers\CuotasMercadoPagoHelper;
use App\PaymentMethod;
use App\PaymentMethodType;
use App\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CombosYRangos\ArmaComercioConCombosYRangos;

/**
 * Fixtures de los tests de la mision tienda-cuotas-mercado-pago (15/10/2026).
 *
 * Reusa las de `combos-y-rangos-de-precio` (`ArmaComercioConCombosYRangos`: comercio propio por
 * caso con su configuracion online, articulo publicado, carrito por el endpoint publico) y agrega
 * lo que esta mision necesita: el metodo de cuenta corriente al que se vincula la tienda, las
 * filas de `cuotas` del ERP y el metodo de pago de la tienda de tipo `MercadoPago`.
 *
 * ── El esquema ────────────────────────────────────────────────────────────────────────────────
 *
 * `tienda-api` no tiene migraciones: las 6 columnas nuevas las crea `empresa-api`
 * (`2026_10_15_100000_add_cuotas_de_mercado_pago_a_la_tienda`) y la base de testing del slot ya
 * las tiene aplicadas. Estas clases NO las crean solas a proposito (seria DDL: MySQL le hace
 * commit implicito a la transaccion de `DatabaseTransactions` y el primer caso dejaria filas
 * sueltas fuera del rollback). Si faltan, `armarCuotas()` falla con un mensaje que lo dice, en
 * vez de dar verdes vacuos. Y para probar "esta base todavia no tiene la columna" se usa
 * `CuotasMercadoPagoHelper::forzar_esquema()`, que no toca la base.
 */
trait ArmaCuotasMercadoPago
{
    use ArmaComercioConCombosYRangos;

    /** @var \App\User El comercio del caso (uno nuevo por caso). */
    protected $comercio;

    /** @var \App\Article Un articulo publicado del comercio. */
    protected $articulo;

    /** @var int El metodo de cuenta corriente «Mercado Pago» al que se vincula la tienda. */
    protected $metodo_cc_id;

    /** @var \App\PaymentMethod El metodo de pago de la tienda, de tipo MercadoPago, YA vinculado. */
    protected $payment_method;

    /** @var int Cuantas lecturas de la tabla `cuotas` se hicieron desde `contarLasLecturasDeCuotas()` o `fallarLasLecturasDeCuotas()`. */
    protected $lecturas_de_cuotas = 0;

    /**
     * Preparacion comun: memorias limpias, esquema presente, un comercio con su articulo y su
     * metodo de Mercado Pago vinculado.
     *
     * @param float $precio Precio del articulo.
     * @return void
     */
    protected function armarCuotas($precio = 1000.0)
    {
        $this->olvidarCuotas();

        $this->assertTrue(
            CuotasMercadoPagoHelper::vinculo_disponible()
            && CuotasMercadoPagoHelper::carrito_disponible()
            && CuotasMercadoPagoHelper::pedido_disponible()
            && CuotasMercadoPagoHelper::cobro_disponible(),
            'La base de testing no tiene las 6 columnas de la migracion 2026_10_15_100000 de empresa-api: aplicarla contra esta base.'
        );

        $this->comercio = $this->comercioConTienda();
        $this->articulo = $this->articuloPublicado($this->comercio, ['final_price' => $precio]);

        $this->metodo_cc_id = $this->metodoDeCuentaCorriente('Mercado Pago (test cuotas)');
        $this->payment_method = $this->metodoMercadoPago($this->comercio, $this->metodo_cc_id);
    }

    /**
     * Descarta TODAS las memorias estaticas de estos caminos. Va en el `setUp` Y en el `tearDown`.
     *
     * @return void
     */
    protected function olvidarCuotas()
    {
        CuotasMercadoPagoHelper::olvidar();
        $this->olvidarLasMemorias();
    }

    /**
     * Cuenta cada SELECT sobre la tabla `cuotas` que se haga de aca en adelante en este test
     * (`$this->lecturas_de_cuotas`).
     *
     * @return void
     */
    protected function contarLasLecturasDeCuotas()
    {
        $this->lecturas_de_cuotas = 0;

        DB::listen(function ($consulta) {
            if (strpos($consulta->sql, 'from `cuotas`') !== false) {
                $this->lecturas_de_cuotas++;
            }
        });
    }

    /**
     * Hace FALLAR toda lectura de la tabla `cuotas` de aca en adelante en este test, sin DDL: un
     * `DB::listen` que tira una excepcion cuando el SQL consulta `cuotas` (el listener corre despues
     * de ejecutar la consulta y su excepcion sube hasta quien la hizo, como una falla de la base).
     * Cuenta los intentos en `$this->lecturas_de_cuotas`, para que el caso pueda comprobar que la
     * falla de verdad se disparo.
     *
     * Las escrituras de las fixtures (INSERT) y las demas tablas no se ven afectadas.
     *
     * @return void
     */
    protected function fallarLasLecturasDeCuotas()
    {
        $this->lecturas_de_cuotas = 0;

        DB::listen(function ($consulta) {
            if (strpos($consulta->sql, 'from `cuotas`') !== false) {
                $this->lecturas_de_cuotas++;

                throw new \RuntimeException('falla simulada de la base al leer cuotas');
            }
        });
    }

    /**
     * Una fila de `current_acount_payment_methods` (los metodos de pago de cuenta corriente del
     * ERP: «Efectivo», «Mercado Pago», etc.).
     *
     * @param string $nombre
     * @return int
     */
    protected function metodoDeCuentaCorriente($nombre)
    {
        return DB::table('current_acount_payment_methods')->insertGetId([
            'name'       => $nombre,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * El tipo de metodo de pago de la tienda con ese nombre; lo crea si no esta (el rollback lo
     * deshace).
     *
     * @param string $nombre
     * @return \App\PaymentMethodType
     */
    protected function tipoDePago($nombre)
    {
        $tipo = PaymentMethodType::where('name', $nombre)->first();

        if (!$tipo) {
            $tipo = new PaymentMethodType;
            $tipo->name = $nombre;
            $tipo->save();
        }

        return $tipo;
    }

    /**
     * Un metodo de pago de la tienda de tipo MercadoPago, con credencial cargada (la que usa la
     * preferencia y el webhook), vinculado o no a un metodo de cuenta corriente.
     *
     * @param \App\User $comercio
     * @param int|null $vinculo `payment_methods.current_acount_payment_method_id`.
     * @return \App\PaymentMethod
     */
    protected function metodoMercadoPago(User $comercio, $vinculo = null)
    {
        $payment_method = new PaymentMethod;
        $payment_method->name                             = 'Mercado Pago';
        $payment_method->user_id                          = $comercio->id;
        $payment_method->payment_method_type_id           = $this->tipoDePago('MercadoPago')->id;
        $payment_method->public_key                       = 'PUBLIC-KEY-DE-PRUEBA';
        $payment_method->access_token                     = 'TOKEN-DE-PRUEBA';
        $payment_method->current_acount_payment_method_id = $vinculo;
        $payment_method->save();

        return $payment_method;
    }

    /**
     * Una fila de `cuotas` del ERP.
     *
     * @param \App\User $comercio Dueno de la fila (`cuotas.user_id`).
     * @param int|null $metodo_cc_id `cuotas.payment_method_id`; null = regla generica.
     * @param int $cantidad
     * @param float|null $recargo Porcentaje.
     * @param float|null $descuento Porcentaje.
     * @return int El id de la fila.
     */
    protected function cuota(User $comercio, $metodo_cc_id, $cantidad, $recargo, $descuento = null)
    {
        return DB::table('cuotas')->insertGetId([
            'user_id'           => $comercio->id,
            'payment_method_id' => $metodo_cc_id,
            'cantidad_cuotas'   => $cantidad,
            'recargo'           => $recargo,
            'descuento'         => $descuento,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /**
     * Carga varias cuotas del comercio del caso para el metodo vinculado.
     *
     * @param array<int, array{0: int, 1: float|null}> $filas Pares [cantidad, recargo].
     * @return void
     */
    protected function cuotasDelMetodo(array $filas)
    {
        foreach ($filas as $fila) {
            $this->cuota($this->comercio, $this->metodo_cc_id, $fila[0], $fila[1]);
        }
    }

    /**
     * Crea un carrito por el endpoint publico (como hace el SPA del comprador invitado), con UNA
     * linea del articulo del caso, el metodo de pago elegido y lo que se pase de mas.
     *
     * @param int $cantidad Unidades del articulo.
     * @param array $extra Campos del carrito (`cuotas`, `delivery_zone_id`, ...).
     * @param bool $con_metodo Si el carrito guarda `payment_method_id` del metodo del caso.
     * @return \App\Cart
     */
    protected function carritoPorElEndpoint($cantidad = 2, array $extra = [], $con_metodo = true)
    {
        $cart = array_merge([
            'articles' => [$this->lineaDelPayload($this->articulo, $cantidad)],
        ], $con_metodo ? ['payment_method_id' => $this->payment_method->id] : [], $extra);

        $respuesta = $this->crearCarrito($this->comercio, $cart);
        $respuesta->assertStatus(201);

        return Cart::findOrFail((int) $respuesta->json('cart.id'));
    }

    /**
     * El `POST /api/mercado-pago/preference` tal como lo manda el SPA: el metodo elegido, el
     * carrito (que va en la sesion, porque es de un invitado) y lo que se pase de mas.
     *
     * @param \App\Cart $cart
     * @param array $extra `contado`, `cuotas` y lo que haga falta.
     * @param \App\PaymentMethod|null $payment_method El del caso si no se pasa.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedirPreferencia(Cart $cart, array $extra = [], ?PaymentMethod $payment_method = null)
    {
        $payment_method = $payment_method ?: $this->payment_method;

        return $this->withSession(['carritos_propios' => [$cart->id]])
            ->postJson('/api/mercado-pago/preference', array_merge([
                'payment_method' => [
                    'id'      => $payment_method->id,
                    'user_id' => $payment_method->user_id,
                ],
                'cart_id'        => $cart->id,
            ], $extra));
    }

    /**
     * Lo que Mercado Pago le cobraria al comprador: la suma de `unit_price * quantity` de los items
     * del cuerpo que viajo en la preferencia.
     *
     * @param array $preferencia Cuerpo decodificado del POST a `/checkout/preferences`.
     * @return float
     */
    protected function totalDeLaPreferencia(array $preferencia)
    {
        return round(array_sum(array_map(function ($item) {
            return $item['unit_price'] * $item['quantity'];
        }, $preferencia['items'])), 2);
    }
}
