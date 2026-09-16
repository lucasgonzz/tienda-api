<?php

namespace Tests\Feature\CombosYRangos;

use App\Article;
use App\Cart;
use App\Http\Controllers\Helpers\ArticlePriceRangeHelper;
use App\Http\Controllers\Helpers\CartHelper;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🔴 EL INVARIANTE DE COSTO de `CartHelper::resincronizar_precios_por_rango()` (mision
 * combos-y-rangos-de-precio, 16/9/2026).
 *
 * ── Por que esta clase existe, y por que NO alcanzaba con el resto de la suite ────────────────
 *
 * Los tramos por cantidad los usan poquisimos comercios; `set_total()` corre en los TRES caminos
 * que escriben el carrito, en todas las tiendas, todo el dia. O sea que el costo de esta
 * funcionalidad no lo paga el que la usa: lo paga el que no.
 *
 * La primera version entraba con `ArticlePriceRangeHelper::hay_tabla()` a secas, y esa guarda no
 * filtra a NADIE: `article_price_ranges` la crea una migracion de noviembre de 2025 y hoy la tienen
 * todos los clientes. El resultado medido, sobre un carrito de invitado con un articulo sin tramos:
 * master 4 queries reales por `set_total()`, esa version 6 — una lectura de `article_cart` y un
 * `whereHas` con `withAll()` encima, para terminar descubriendo que no habia nada que hacer.
 *
 * Y no lo agarro ninguna revision: lo denuncio
 * `PromocionPersonalizada\ResincronizacionDelCarritoTest`, que cuenta las queries de `set_total()`
 * para la resincronizacion de OFERTAS. Aquel invariante era de las dos funciones y nadie lo habia
 * escrito para esta. Esta clase lo escribe.
 *
 * ── Los marcadores, y por que estos ──────────────────────────────────────────────────────────
 *
 *   - `select * from `article_cart``: la lectura de las lineas que hace la resincronizacion. El
 *     `$cart->load('articles')` del propio `set_total()` compila distinto
 *     (`select `articles`.*, ... inner join `article_cart``), asi que este marcador solo lo
 *     enciende la funcion que se esta midiendo. Mismo criterio que la clase de ofertas.
 *   - `from `articles`` + `article_price_ranges` en la misma query: el
 *     `Article::whereIn(...)->whereHas('article_price_ranges')->withAll()`, que es la cara de las
 *     dos. No matchea la query de la guarda (`select * from `article_price_ranges` where
 *     `article_id` in ...`), que no nombra a `articles`.
 *
 * 🔴 Y la contracara va en la misma clase: un test que prueba que algo NO aparece se vuelve vacuo
 * en silencio el dia que el marcador deja de matchear. `test_con_tramos_...` mide con los MISMOS
 * marcadores el escenario donde las dos queries SI tienen que estar.
 */
class CostoDeLaGuardaDeTramosTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaComercioConCombosYRangos;

    /** @var \App\User */
    private $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarLasMemorias();

        $this->assertTrue(ArticlePriceRangeHelper::hay_tabla(),
            'La base del slot tiene que tener `article_price_ranges`: sin eso estos casos serian vacuos.');

        $this->comercio = $this->comercioConTienda();
    }

    protected function tearDown(): void
    {
        $this->olvidarLasMemorias();

        parent::tearDown();
    }

    /**
     * 🔴 EL CASO DE LA CLASE: un carrito cuyos articulos NO tienen tramos no lee las lineas ni
     * carga los articulos.
     *
     * El carrito se arma escribiendo la fila a mano y `set_total()` se llama derecho, sin pasar por
     * `get_price()`: es la forma del camino mas hostil para la guarda, el de
     * `update_article_amount()` —el boton "Actualizar"—, donde la memoria del helper esta fria y la
     * guarda tiene que pagar su propia query. Si en el peor caso la guarda ya corta, en los otros
     * dos (que llegan con la memoria caliente) corta gratis.
     */
    public function test_sin_tramos_set_total_no_lee_las_lineas_ni_carga_los_articulos()
    {
        $articulo = $this->articuloPublicado($this->comercio);

        $carrito = $this->carritoCon($articulo, 10, self::PRECIO_NORMAL);

        $queries = $this->queriesDurante(function () use ($carrito) {
            CartHelper::set_total($carrito);
        });

        $this->assertSame([], $this->lecturasDeLineas($queries),
            'sin tramos no se pueden leer las lineas del carrito para resincronizar');

        $this->assertSame([], $this->cargasDeArticulosConTramos($queries),
            'sin tramos no se puede cargar ningun articulo con withAll()');

        /* Y la guarda misma tiene que ser barata: una consulta a los tramos de las lineas, no mas.
           Sin esto, una "guarda" que igual hiciera la consulta cara pasaria los dos asserts de
           arriba escondiendo el costo en otra query. */
        $this->assertLessThanOrEqual(1, count($this->consultasDeTramos($queries)),
            'la guarda tiene que costar a lo sumo una query: '.implode(' | ', $this->consultasDeTramos($queries)));

        /* Y el precio guardado queda exactamente como estaba: sin tramos no hay nada que
           resincronizar. */
        $this->assertSame(self::PRECIO_NORMAL, $this->precioGuardado($carrito->id, $articulo->id),
            'sin tramos el carrito se comporta como antes de esta mision');
    }

    /**
     * 🔴 LA CONTRACARA, y sin ella el caso de arriba seria un adorno.
     *
     * Con tramos cargados los dos marcadores TIENEN que encenderse. Si alguno deja de matchear —un
     * backtick de mas, un `select *` que Laravel compile distinto—, se pone rojo acá y no en
     * silencio alla.
     *
     * Y de paso fija lo otro que la guarda nueva movio: `set_total()` ahora carga las lineas ANTES
     * de resincronizar, asi que si la resincronizacion escribe, lo que quedo en memoria es viejo y
     * hay que releerlo. El total de este caso sale de un precio que la propia resincronizacion
     * acaba de corregir: sumar con los pivots viejos daria 39.480 en vez de 30.000.
     */
    public function test_con_tramos_set_total_si_consulta_y_el_total_sale_del_precio_corregido()
    {
        $articulo = $this->conLaEscalaDeLaReproduccion($this->articuloPublicado($this->comercio));

        /* La fila quedo guardada al precio de lista y con 10 unidades: le corresponde el tramo de
           `Mayor o igual 10`. */
        $carrito = $this->carritoCon($articulo, 10, self::PRECIO_NORMAL);

        $queries = $this->queriesDurante(function () use ($carrito) {
            CartHelper::set_total($carrito);
        });

        $this->assertNotEmpty($this->lecturasDeLineas($queries),
            'el marcador de la lectura de lineas dejo de matchear: el caso de costo quedo vacuo');

        $this->assertNotEmpty($this->cargasDeArticulosConTramos($queries),
            'el marcador de la carga de articulos dejo de matchear: el caso de costo quedo vacuo');

        $this->assertSame(self::TRAMO_10, $this->precioGuardado($carrito->id, $articulo->id),
            'con 10 unidades corresponde el tramo de `Mayor o igual 10`');

        $this->assertSame(self::TRAMO_10 * 10, $this->totalGuardado($carrito->id),
            'el total tiene que salir del precio corregido, no de los pivots que quedaron en memoria');
    }

    /**
     * Y por el camino de GUARDAR el carrito la guarda no cuesta ninguna query propia.
     *
     * Es la mitad del diseño y la que no se ve: `store` y `update` pasan por `attachArticles()` ->
     * `get_price()` -> `ArticlePriceRangeHelper::precio_de_articulo()`, que ya trajo los tramos de
     * TODOS los articulos del carrito de una sola vez. Cuando `set_total()` pregunta despues, la
     * respuesta ya esta en memoria.
     *
     * Por eso se cuenta sobre el request ENTERO y no sobre `set_total()`: lo que se fija es que los
     * tramos de las lineas se lean UNA sola vez en toda la operacion. El eager load de
     * `withAll()` compila con la columna calificada (`article_price_ranges`.`article_id`) y no
     * entra en este marcador.
     */
    public function test_al_guardar_el_carrito_los_tramos_se_leen_una_sola_vez()
    {
        $articulo = $this->articuloPublicado($this->comercio);

        $queries = $this->queriesDurante(function () use ($articulo) {
            $this->crearCarrito($this->comercio, [
                'articles' => [$this->lineaDelPayload($articulo, 3)],
            ])->assertStatus(201);
        });

        $consultas = $this->consultasDeTramos($queries);

        $this->assertCount(1, $consultas,
            'los tramos de las lineas se leen una vez, en get_price(); la guarda de set_total() se '
            .'contesta con eso. Medido: '.implode(' | ', $consultas));
    }

    /**
     * Las queries que leen `article_cart` para resincronizar.
     *
     * @param  array  $queries
     * @return array
     */
    private function lecturasDeLineas($queries)
    {
        return array_values(array_filter($queries, function ($sql) {
            return strpos($sql, 'select * from `article_cart`') === 0;
        }));
    }

    /**
     * Las queries que cargan los articulos con tramos (el `whereHas` + `withAll`, la cara).
     *
     * @param  array  $queries
     * @return array
     */
    private function cargasDeArticulosConTramos($queries)
    {
        return array_values(array_filter($queries, function ($sql) {
            return strpos($sql, 'from `articles`') !== false
                && strpos($sql, 'article_price_ranges') !== false;
        }));
    }

    /**
     * Las lecturas de tramos que hace `ArticlePriceRangeHelper::precargar()` — o sea la guarda y
     * `get_price()`. La columna va SIN calificar; el eager load de `withAll()` la califica.
     *
     * @param  array  $queries
     * @return array
     */
    private function consultasDeTramos($queries)
    {
        return array_values(array_filter($queries, function ($sql) {
            return strpos($sql, 'select * from `article_price_ranges` where `article_id` in') === 0;
        }));
    }

    /**
     * Todas las queries que dispara la accion.
     *
     * El listener lleva su propio interruptor porque en Laravel no se puede desregistrar: sin el,
     * una segunda medicion en el mismo caso le seguiria sumando a la primera. Molde textual de
     * `PromocionPersonalizada\ResincronizacionDelCarritoTest`.
     *
     * @param  callable  $accion
     * @return array
     */
    private function queriesDurante(callable $accion)
    {
        $queries  = [];
        $midiendo = true;

        DB::listen(function ($query) use (&$queries, &$midiendo) {
            if ($midiendo) {
                $queries[] = $query->sql;
            }
        });

        $accion();

        $midiendo = false;

        return $queries;
    }

    /**
     * Un carrito de INVITADO con una sola linea, escrita a mano tal como la dejaria
     * `attachArticles()`.
     *
     * Se escribe la fila directo y no por el endpoint porque estos casos necesitan la memoria del
     * helper FRIA: pasar por `get_price()` la calentaria y la guarda no pagaria su query, que es
     * justo el peor caso que se quiere medir.
     *
     * @param  \App\Article  $articulo
     * @param  float  $amount
     * @param  float  $price
     * @return \App\Cart
     */
    private function carritoCon(Article $articulo, $amount, $price)
    {
        $carrito = Cart::create([
            'user_id'  => $this->comercio->id,
            'buyer_id' => null,
            'total'    => $price * $amount,
        ]);

        DB::table('article_cart')->insert([
            'cart_id'    => $carrito->id,
            'article_id' => $articulo->id,
            'amount'     => $amount,
            'price'      => $price,
            'cost'       => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $carrito;
    }
}
