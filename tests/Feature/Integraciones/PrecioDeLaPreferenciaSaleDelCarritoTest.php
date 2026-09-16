<?php

namespace Tests\Feature\Integraciones;

use App\Article;
use App\Cart;
use App\Cupon;
use App\DeliveryZone;
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
}
