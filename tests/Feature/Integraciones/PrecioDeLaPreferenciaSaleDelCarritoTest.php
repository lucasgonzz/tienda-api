<?php

namespace Tests\Feature\Integraciones;

use App\Article;
use App\Cart;
use App\Combo;
use App\Cupon;
use App\DeliveryZone;
use App\Http\Controllers\Helpers\CartHelper;
use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use App\Http\Controllers\MercadoPagoController;
use App\PaymentMethod;
use App\PaymentMethodType;
use App\PromocionVinoteca;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Lo que se le cobra a Mercado Pago sale del carrito persistido en el servidor, nunca del body
 * de la request que pide la preferencia (misión `mp-precio-servidor-y-credenciales-env`,
 * 16/9/2026).
 *
 * ── El defecto que arregla ────────────────────────────────────────────────────────────────────
 * `articulos_a_cobrar()` mandaba `$request->articles` derecho a `OnlinePaymentHelper::setPrices()`,
 * que solo aplica el recargo del comercio y del medio de pago sobre el `final_price` que le llega
 * — sin volver a mirar la base. El carrito podía valer $27.999 en pantalla y la preferencia se
 * armaba igual con el mismo POST pidiendo $1: nada entre el carrito y Mercado Pago volvía a
 * consultar el precio real. Mismo agujero para `cupon` y para `delivery_zone`.
 *
 * ── Lo que fija esta clase ────────────────────────────────────────────────────────────────────
 * Con un carrito resuelto, `articulos_a_cobrar()` arma los items desde `cart.articles` (pivot
 * `price`/`amount`), el cupón desde `cart.cupon` y la zona desde `cart.delivery_zone` — todo lo
 * que el servidor ya tiene guardado — e ignora lo que venga en el body. Sin carrito resuelto (SPA
 * viejo sin `cart_id`) el camino de siempre queda intacto: es un agujero distinto y preexistente
 * ("el cliente fija el precio base" al AGREGAR al carrito, documentado en
 * `CartHelper::get_price()`), que esta misión no abre ni cierra.
 *
 * `articulos_a_cobrar()` está afuera de `preference()` por el mismo motivo que
 * `datos_de_preferencia()` (ver `PreferenciaYWebhookDeMercadoPagoTest`): es lo que se puede probar
 * sin que `$preference->save()` salga a la red.
 *
 * @group integraciones
 */
class PrecioDeLaPreferenciaSaleDelCarritoTest extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\User */
    private $comercio;

    /** @var \App\PaymentMethod */
    private $payment_method;

    /** @var \App\Article */
    private $articulo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        $this->articulo = Article::where('user_id', $this->comercio->id)->first();
        $this->assertNotNull($this->articulo, 'La base del slot tiene que tener al menos un articulo del comercio.');

        $tipo = PaymentMethodType::where('name', 'MercadoPago')->first();

        if (!$tipo) {
            $tipo = new PaymentMethodType;
            $tipo->name = 'MercadoPago';
            $tipo->save();
        }

        $this->payment_method = new PaymentMethod;
        $this->payment_method->name                   = 'Mercado Pago';
        $this->payment_method->user_id                = $this->comercio->id;
        $this->payment_method->payment_method_type_id = $tipo->id;
        $this->payment_method->save();
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Carrito del comercio con UNA línea del artículo fixture, con el pivot que se le pase
     * (típicamente `price`).
     *
     * @param array $pivot
     * @return \App\Cart
     */
    private function carritoCon(array $pivot)
    {
        $cart = Cart::create(['user_id' => $this->comercio->id, 'buyer_id' => null]);
        $cart->articles()->attach($this->articulo->id, array_merge(['amount' => 1], $pivot));

        return $cart->fresh();
    }

    /**
     * Cuelga del carrito un combo NUEVO del comercio, con su precio congelado en el pivote
     * `cart_combo` — que es de donde tiene que salir lo que se cobra, igual que en las otras dos
     * colecciones.
     *
     * `num` va siempre: es NOT NULL sin default en el esquema que crea `empresa-api`.
     *
     * @param \App\Cart $cart
     * @param float $price
     * @param int|float $amount
     * @return \App\Combo
     */
    private function conCombo(Cart $cart, $price, $amount = 1)
    {
        $combo = Combo::create([
            'num'     => random_int(100000, 999999),
            'name'    => 'Combo de prueba',
            'user_id' => $this->comercio->id,
            'price'   => $price,
            'cost'    => 0,
            'online'  => 1,
        ]);

        $cart->combos()->attach($combo->id, ['price' => $price, 'amount' => $amount, 'cost' => 0]);

        return $combo;
    }

    /**
     * Lo que Mercado Pago le va a cobrar al comprador: la suma de `final_price * amount` de todos
     * los items de la preferencia.
     *
     * @param array $items
     * @return float
     */
    private function sumaDeLosItems(array $items)
    {
        return array_sum(array_map(function ($item) {
            return $item['final_price'] * $item['amount'];
        }, $items));
    }

    /**
     * El total que el servidor le calculó al carrito: lo que el comprador vio en pantalla y
     * confirmó.
     *
     * Se recalcula con el mismo `CartHelper::set_total()` que corre en cada escritura del carrito
     * en vez de leer la columna a mano, porque el invariante que estos casos clavan es
     * exactamente "lo que suma ESE método es lo que se cobra".
     *
     * @param \App\Cart $cart
     * @return float
     */
    private function totalDelCarrito(Cart $cart)
    {
        CartHelper::set_total($cart);

        return (float) $cart->fresh()->total;
    }

    /**
     * Los dos recargos que `OnlinePaymentHelper::getArticlePrice()` aplica arriba del precio del
     * carrito (el del comercio y el del medio de pago) están en cero en este escenario.
     *
     * Sin esto, "la suma de la preferencia es igual a `cart.total`" no sería un invariante sino
     * una coincidencia: con un recargo del 10% la preferencia tiene que valer MÁS que el carrito,
     * y el caso fallaría por un motivo que no es el que está probando. Esto lo denuncia acá.
     *
     * @return void
     */
    private function assertSinRecargos()
    {
        $this->assertNull(
            $this->comercio->online_configuration->online_price_surchage,
            'El comercio sembrado del slot no puede tener recargo online: el invariante de este caso lo compara 1 a 1 con cart.total.'
        );
        $this->assertNull(
            $this->payment_method->surchage,
            'El medio de pago que arma el setUp no puede tener recargo.'
        );
    }

    /**
     * Invoca `articulos_a_cobrar()` por reflexión, igual que el resto de los tests de este
     * controller invocan sus métodos protegidos.
     *
     * @param Request $request
     * @param \App\Cart|null $cart
     * @return array
     */
    private function articulosACobrar(Request $request, $cart)
    {
        $controller = new MercadoPagoController();
        $metodo = new ReflectionMethod($controller, 'articulos_a_cobrar');
        $metodo->setAccessible(true);

        return $metodo->invoke($controller, $request, $this->comercio, $this->payment_method, $cart);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Tests
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 EL CASO QUE JUSTIFICA LA MISIÓN: el carrito vale $1.500, el body pide $1, y lo que se
     * cobra es $1.500.
     *
     * @return void
     */
    public function test_un_precio_manipulado_en_el_body_se_ignora_si_hay_carrito()
    {
        $cart = $this->carritoCon(['price' => 1500]);

        $request = Request::create('/api/mercado-pago/preference', 'POST', [
            'articles' => [[
                'id'          => $this->articulo->id,
                'name'        => 'nombre distinto, tambien deberia ignorarse',
                'amount'      => 1,
                'final_price' => 1,
            ]],
        ]);

        $items = $this->articulosACobrar($request, $cart);

        $this->assertCount(1, $items);
        $this->assertSame(1500.0, (float) $items[0]['final_price'], 'Tiene que cobrar lo que dice el carrito, no el $1 del body.');
        $this->assertSame($this->articulo->name, $items[0]['name'], 'El nombre también sale del carrito, no del body.');
    }

    /**
     * Sin carrito resuelto (SPA viejo sin `cart_id`, o `cart_id` ajeno) el comportamiento es
     * exactamente el de antes: esta misión no lo toca.
     *
     * @return void
     */
    public function test_sin_carrito_sigue_confiando_en_el_body_como_antes()
    {
        $request = Request::create('/api/mercado-pago/preference', 'POST', [
            'articles' => [['name' => 'Articulo suelto', 'amount' => 1, 'final_price' => 1]],
        ]);

        $items = $this->articulosACobrar($request, null);

        $this->assertSame(1.0, (float) $items[0]['final_price'], 'Comportamiento preexistente sin carrito: no lo toca esta misión.');
    }

    /**
     * Un cupón inventado en el body (90% de descuento) no aplica si el carrito no tiene ningún
     * cupón atado de verdad.
     *
     * @return void
     */
    public function test_un_cupon_inventado_en_el_body_no_aplica_si_el_carrito_no_tiene_cupon()
    {
        $cart = $this->carritoCon(['price' => 1000]);

        $request = Request::create('/api/mercado-pago/preference', 'POST', [
            'cupon' => ['amount' => null, 'percentage' => 90],
        ]);

        $items = $this->articulosACobrar($request, $cart);

        $this->assertSame(1000.0, (float) $items[0]['final_price'], 'Sin cupón atado al carrito, el 90% del body no se aplica.');
    }

    /**
     * El cupón que de verdad quedó atado al carrito (por su `cupon_id`, no por lo que mande el
     * body) sí se aplica, con el porcentaje real de la base.
     *
     * @return void
     */
    public function test_el_cupon_real_atado_al_carrito_si_se_aplica()
    {
        $cupon = Cupon::create([
            'num'        => random_int(100000, 999999),
            'percentage' => 10,
            'user_id'    => $this->comercio->id,
            'type'       => 'normal',
            'valid'      => 1,
        ]);

        $cart = $this->carritoCon(['price' => 1000]);
        $cart->cupon_id = $cupon->id;
        $cart->save();

        $items = $this->articulosACobrar(Request::create('/api/mercado-pago/preference', 'POST'), $cart->fresh());

        $this->assertSame(900.0, (float) $items[0]['final_price']);
    }

    /**
     * El precio del envío por zona propia sale de la zona atada al carrito, no del `price` que
     * mande el body.
     *
     * @return void
     */
    public function test_la_zona_de_envio_sale_del_carrito_no_del_body()
    {
        // DeliveryZone no declara $fillable/$guarded: sin mass assignment, propiedad por propiedad.
        $zona = new DeliveryZone();
        $zona->name = 'Centro';
        $zona->price = 500;
        $zona->user_id = $this->comercio->id;
        $zona->save();

        $cart = $this->carritoCon(['price' => 1000]);
        $cart->delivery_zone_id = $zona->id;
        $cart->save();

        $request = Request::create('/api/mercado-pago/preference', 'POST', [
            'delivery_zone' => ['price' => 1],
        ]);

        $items = $this->articulosACobrar($request, $cart->fresh());

        $envio = collect($items)->firstWhere('name', 'Envio');

        $this->assertNotNull($envio, 'Tiene que haber una línea de envío.');
        $this->assertSame(500.0, (float) $envio['final_price'], 'El envío sale de la zona atada al carrito, no del $1 del body.');
    }

    /**
     * El recargo del medio de pago se sigue aplicando, arriba del precio que viene del carrito:
     * esta misión no cambia CÓMO se recarga, solo DE DÓNDE sale la base.
     *
     * @return void
     */
    public function test_el_recargo_del_medio_de_pago_se_sigue_aplicando_sobre_el_precio_del_carrito()
    {
        $this->payment_method->surchage = 10;
        $this->payment_method->save();

        $cart = $this->carritoCon(['price' => 1000]);

        $items = $this->articulosACobrar(Request::create('/api/mercado-pago/preference', 'POST'), $cart);

        $this->assertSame(1100.0, (float) $items[0]['final_price'], 'El recargo del medio de pago se sigue sumando arriba del precio del carrito.');
    }

    /**
     * 🔴 EL HALLAZGO BLOQUEANTE DEL CHEQUEO INDEPENDIENTE: las promociones de vinoteca viven en
     * OTRA relación del carrito (`promociones_vinoteca`, no `articles`) y `CartHelper::set_total()`
     * las suma igual al total que ve el comprador. Sin traerlas acá, un carrito de $6.000
     * ($1.000 de artículo + $5.000 de promoción) armaba la preferencia por $1.000 — la promoción
     * desaparecía entera, sin error ni warning.
     *
     * @return void
     */
    public function test_las_promociones_de_vinoteca_entran_en_lo_que_se_cobra()
    {
        $cart = $this->carritoCon(['price' => 1000]);

        $promo = PromocionVinoteca::create([
            'name'        => 'Promo de prueba',
            'user_id'     => $this->comercio->id,
            'final_price' => 5000,
            'online'      => 1,
        ]);
        $cart->promociones_vinoteca()->attach($promo->id, ['price' => 5000, 'amount' => 1]);

        $items = $this->articulosACobrar(Request::create('/api/mercado-pago/preference', 'POST'), $cart->fresh());

        $this->assertCount(2, $items, 'El artículo Y la promoción, las dos líneas.');

        $total = array_sum(array_map(function ($item) {
            return $item['final_price'] * $item['amount'];
        }, $items));

        $this->assertSame(6000.0, $total, 'Lo mismo que cart.total: $1.000 de artículo + $5.000 de promoción.');
    }

    /**
     * 🔴 EL SEGUNDO HALLAZGO: `Article` usa SoftDeletes, y el scope global de Eloquent excluye
     * automáticamente las filas borradas de `$cart->articles`. Un comercio que da de baja un
     * artículo agotado mientras un comprador tiene el carrito abierto (caso operativo normal, no
     * un ataque) hacía que ese artículo se esfumara del cobro — el carrito ya tenía el precio
     * acordado, y excluirlo cobraba de menos en silencio.
     *
     * @return void
     */
    public function test_un_articulo_borrado_despues_de_agregarlo_sigue_cobrando_lo_que_el_carrito_ya_tenia()
    {
        $cart = $this->carritoCon(['price' => 1500]);

        // UPDATE crudo, no Article::delete(): el modelo tiene el trait Likeable enganchado a los
        // eventos de Eloquent, y este slot no tiene la tabla likeable_likes migrada (gap previo,
        // no relacionado a esta misión). Lo único que importa acá es el estado final de la
        // columna, no el evento de borrado en sí.
        DB::table('articles')->where('id', $this->articulo->id)->update(['deleted_at' => now()]);
        $this->assertNotNull($this->articulo->fresh()->deleted_at, 'Confirmar que de verdad quedó soft-deleted.');

        $items = $this->articulosACobrar(Request::create('/api/mercado-pago/preference', 'POST'), $cart->fresh());

        $this->assertCount(1, $items, 'El artículo borrado tiene que seguir cobrándose, no desaparecer.');
        $this->assertSame(1500.0, (float) $items[0]['final_price']);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | La TERCERA colección: los combos (misión `combos-y-rangos-de-precio`, 16/9/2026)
    |---------------------------------------------------------------------------------------------
    |
    | 🔴 EL MISMO BUG DE `test_las_promociones_de_vinoteca_entran_en_lo_que_se_cobra`, UNA
    | COLECCIÓN DESPUÉS. La misión de combos agregó `cart.combos` y enseñó a
    | `CartHelper::set_total()` a sumarla (`:549-553`), pero no volvió a este lugar: la preferencia
    | se seguía armando con `array_merge($articles, $promociones)`. Carrito de $6.000 ($1.000 de
    | artículo + $5.000 de combo), preferencia por $1.000 — y el pedido nace igual en el ERP por
    | $6.000, con el stock de los componentes descontado. Cobrar de menos, en silencio.
    |
    | Que se haya repetido con la colección nueva es el dato que importa: el docblock del punto 1
    | describía este caso exacto y aun así pasó. Por eso estos casos NO se escriben con números
    | fijos sino con el invariante —la suma de los items de la preferencia es igual a
    | `cart.total`—, que es la única forma en que la CUARTA colección comprable se vuelva roja sola
    | el día que alguien la agregue y se olvide de acá.
    */

    /**
     * 🔴 EL INVARIANTE, Y ES EL CASO QUE JUSTIFICA ESTE ARREGLO: lo que suma
     * `CartHelper::set_total()` es exactamente lo que se le cobra al comprador.
     *
     * Escrito así y no con un `assertSame(6000.0, ...)` a propósito: si mañana el combo cambia de
     * precio, o el carrito gana una cuarta colección, el número fijo habría que ir a tocarlo a
     * mano y el invariante no.
     *
     * @return void
     */
    public function test_los_combos_entran_en_lo_que_se_cobra()
    {
        $this->assertTrue(ComboEsquemaHelper::disponible(),
            'La base del slot tiene que tener el esquema de combos para que este caso mida algo.');
        $this->assertSinRecargos();

        $cart = $this->carritoCon(['price' => 1000]);
        $this->conCombo($cart, 5000);

        $cart = $cart->fresh();

        $total_del_carrito = $this->totalDelCarrito($cart);

        // Contraprueba: si `set_total()` no sumara el combo, el caso de abajo daría verde contra
        // dos números igual de chicos y no probaría nada.
        $this->assertSame(6000.0, $total_del_carrito,
            'El escenario arranca con el combo dentro de cart.total: $1.000 de artículo + $5.000 de combo.');

        $items = $this->articulosACobrar(Request::create('/api/mercado-pago/preference', 'POST'), $cart->fresh());

        $this->assertCount(2, $items, 'El artículo Y el combo, las dos líneas.');
        $this->assertSame($total_del_carrito, $this->sumaDeLosItems($items),
            'EL INVARIANTE: la preferencia de Mercado Pago cobra lo mismo que el carrito que el comprador confirmó.');
    }

    /**
     * 🔴 El carrito de SOLO un combo, que es el peor caso de este defecto: sin artículos, la
     * preferencia no quedaba en "cobra de menos" sino en CERO ITEMS — o sea, una compra entera
     * regalada, o el rechazo de Mercado Pago por una preferencia vacía.
     *
     * Es un carrito perfectamente normal: la home de la tienda lista los combos en su propia
     * sección y nada obliga al comprador a agregar además un artículo suelto.
     *
     * @return void
     */
    public function test_un_carrito_de_solo_un_combo_no_arma_una_preferencia_vacia()
    {
        $this->assertSinRecargos();

        $cart = Cart::create(['user_id' => $this->comercio->id, 'buyer_id' => null]);
        $this->conCombo($cart, 7500, 2);

        $cart = $cart->fresh();

        $total_del_carrito = $this->totalDelCarrito($cart);
        $this->assertSame(15000.0, $total_del_carrito, '2 x $7.500 de combo, sin un solo artículo.');

        $items = $this->articulosACobrar(Request::create('/api/mercado-pago/preference', 'POST'), $cart->fresh());

        $this->assertNotEmpty($items, 'Un carrito de solo combos no puede armar una preferencia vacía.');
        $this->assertSame($total_del_carrito, $this->sumaDeLosItems($items),
            'El invariante también vale cuando el combo es lo único que hay.');
        $this->assertSame(2.0, (float) $items[0]['amount'],
            'La cantidad sale del pivote del carrito: cobrar 1 de 2 es la misma fuga por otra puerta.');
    }

    /**
     * El precio sale del PIVOTE del carrito —el precio congelado cuando el comprador lo agregó—,
     * nunca del catálogo. Si el comercio le cambia el precio al combo mientras el comprador tiene
     * el carrito abierto, se cobra lo que se le mostró.
     *
     * Mismo criterio que las otras dos colecciones, y la razón por la que el `$mapear` de
     * `articulos_a_cobrar()` lee `$item->pivot->price` y no `$item->price`.
     *
     * @return void
     */
    public function test_el_precio_del_combo_sale_del_pivote_del_carrito_no_del_catalogo()
    {
        $this->assertSinRecargos();

        $cart = Cart::create(['user_id' => $this->comercio->id, 'buyer_id' => null]);
        $combo = $this->conCombo($cart, 5000);

        // El comercio le sube el precio en el ABM del ERP después de que el comprador lo agregó.
        $combo->price = 99999;
        $combo->save();

        $items = $this->articulosACobrar(Request::create('/api/mercado-pago/preference', 'POST'), $cart->fresh());

        $this->assertCount(1, $items);
        $this->assertSame(5000.0, (float) $items[0]['final_price'],
            'Se cobra el precio congelado en cart_combo, no el nuevo precio del catálogo.');
    }

    /**
     * Y el tercer punto del docblock de `articulos_a_cobrar()` aplicado a los combos: `Combo` usa
     * SoftDeletes igual que `Article` y `PromocionVinoteca`, así que un combo dado de baja
     * mientras el comprador tiene el carrito abierto se esfumaría del cobro sin el `withTrashed()`.
     *
     * @return void
     */
    public function test_un_combo_borrado_despues_de_agregarlo_sigue_cobrando_lo_que_el_carrito_ya_tenia()
    {
        $cart = Cart::create(['user_id' => $this->comercio->id, 'buyer_id' => null]);
        $combo = $this->conCombo($cart, 5000);

        $combo->delete();
        $this->assertNotNull($combo->fresh()->deleted_at, 'Confirmar que de verdad quedó soft-deleted.');

        $items = $this->articulosACobrar(Request::create('/api/mercado-pago/preference', 'POST'), $cart->fresh());

        $this->assertCount(1, $items, 'El combo borrado tiene que seguir cobrándose, no desaparecer.');
        $this->assertSame(5000.0, (float) $items[0]['final_price']);
    }
}
