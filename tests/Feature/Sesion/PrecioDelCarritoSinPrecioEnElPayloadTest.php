<?php

namespace Tests\Feature\Sesion;

use App\Article;
use App\Buyer;
use App\Client;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CartHelper;
use App\Http\Controllers\Helpers\ClientOfferHelper;
use App\OnlinePriceType;
use App\PriceType;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `CartHelper::get_price()` con una linea que llega con `final_price` en null (mision
 * tienda-precios-buyer-logueado, 23/9/2026).
 *
 * ── El caso ───────────────────────────────────────────────────────────────────────────────────
 *
 * En Fenix (tienda en "solo vinculados") el SPA tenia en el store articulos pedidos como
 * anonimo —con `final_price` null, porque `checkPriceTypes()` se los borra al anonimo— y un
 * comprador logueado los agregaba al carrito. `get_price()` devolvia ese null y el attach
 * reventaba con `Column 'price' cannot be null`.
 *
 * Ahora, SOLO cuando el payload no trae precio, el servidor lo resuelve con la misma
 * `ArticleHelper::checkPriceTypes()` para el comprador del request. Si el comprador tampoco
 * puede ver precios, sigue null como hoy.
 *
 * ⚠️ Sobre la base: tienda-api no tiene database/migrations (el esquema lo gobierna
 * empresa-api). Se corre contra la base real del slot con DatabaseTransactions.
 */
class PrecioDelCarritoSinPrecioEnElPayloadTest extends TestCase
{
    use DatabaseTransactions;

    /** La columna articles.final_price: lo que ve un logueado sin lista propia (caso 4). */
    const PRECIO_DE_LA_COLUMNA = 777.0;

    /** El pivot de la lista asignada al Client del comprador (caso 3). */
    const PRECIO_DE_SU_LISTA = 555.0;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article */
    private $articulo;

    protected function setUp(): void
    {
        parent::setUp();

        ArticleHelper::olvidar_visibilidad_del_anonimo();
        ClientOfferHelper::olvidarMemoria();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        $this->articulo = Article::create([
            'name'        => 'Articulo Carrito Sin Precio Test',
            'slug'        => 'carrito-sin-precio-'.uniqid(),
            'user_id'     => $this->comercio->id,
            'status'      => 'active',
            'online'      => 1,
            'stock'       => 10,
            'final_price' => self::PRECIO_DE_LA_COLUMNA,
        ]);

        /* La configuracion de Fenix: exige registro y solo los vinculados ven precios. */
        $online_price_type_id = OnlinePriceType::where('slug', 'only_buyers_with_comerciocity_client')->value('id');
        $this->assertNotNull($online_price_type_id, 'La base del slot tiene que tener el catalogo online_price_types sembrado.');

        $this->comercio->online_configuration()->update([
            'register_to_buy'      => 1,
            'online_price_type_id' => $online_price_type_id,
        ]);

        ArticleHelper::olvidar_visibilidad_del_anonimo();
    }

    protected function tearDown(): void
    {
        ArticleHelper::olvidar_visibilidad_del_anonimo();
        ClientOfferHelper::olvidarMemoria();

        parent::tearDown();
    }

    /**
     * 🔴 EL CASO DE FENIX: comprador vinculado a un Client sin lista, con la linea que el SPA
     * pidio como anonimo (sin precio). Cobra el precio del articulo, no revienta.
     */
    public function test_el_vinculado_sin_lista_con_la_linea_sin_precio_cobra_el_precio_del_articulo()
    {
        $this->actingAs($this->compradorVinculado(null), 'buyer');

        $precio = $this->precioDeLinea(null);

        $this->assertNotNull($precio, 'Una linea sin precio en el payload no puede terminar en un attach con price null.');
        $this->assertEquals(self::PRECIO_DE_LA_COLUMNA, (float) $precio);
    }

    /**
     * Es la MISMA checkPriceTypes(), no una copia: el vinculado con lista asignada cobra el
     * precio de SU lista, igual que lo que la tienda le muestra.
     */
    public function test_el_vinculado_con_lista_cobra_el_precio_de_su_lista()
    {
        $lista = new PriceType;
        $lista->name     = 'Lista Carrito Sin Precio Test';
        $lista->position = 1;
        $lista->user_id  = $this->comercio->id;
        $lista->save();

        $this->articulo->price_types()->attach($lista->id, ['final_price' => self::PRECIO_DE_SU_LISTA]);

        $this->actingAs($this->compradorVinculado($lista), 'buyer');

        $this->assertEquals(self::PRECIO_DE_SU_LISTA, (float) $this->precioDeLinea(null));
    }

    /** El anonimo en tienda restringida sigue sin precio: el servidor tampoco se lo da. */
    public function test_el_anonimo_en_tienda_restringida_sigue_sin_precio()
    {
        $this->assertNull($this->precioDeLinea(null));
    }

    /** Sin regresion: con precio en el payload, se cobra el del payload, byte por byte. */
    public function test_con_precio_en_el_payload_se_cobra_el_del_payload()
    {
        $this->actingAs($this->compradorVinculado(null), 'buyer');

        $this->assertSame(123.45, $this->precioDeLinea(123.45));
    }

    /** El respaldo busca el articulo dentro del comercio del CARRITO, no del payload. */
    public function test_un_articulo_de_otro_comercio_no_se_resuelve()
    {
        $this->actingAs($this->compradorVinculado(null), 'buyer');

        $linea = $this->linea(null);

        $precio = CartHelper::get_price([$linea], $linea, false, collect(), $this->comercio->id + 999999);

        $this->assertNull($precio);
    }

    /**
     * @param  float|null  $final_price  lo que manda el SPA en la linea
     * @return mixed
     */
    private function precioDeLinea($final_price)
    {
        $linea = $this->linea($final_price);

        return CartHelper::get_price([$linea], $linea, false, collect(), $this->comercio->id);
    }

    /**
     * Una linea del carrito como la manda el SPA.
     *
     * @param  float|null  $final_price
     * @return array
     */
    private function linea($final_price)
    {
        return [
            'id'          => $this->articulo->id,
            'user_id'     => $this->comercio->id,
            'name'        => $this->articulo->name,
            'final_price' => $final_price,
            'cost'        => null,
            'amount'      => 1,
            'pivot'       => ['amount' => 1, 'notes' => null],
        ];
    }

    /**
     * Un comprador logueado vinculado a un Client del ERP, con o sin lista asignada.
     *
     * @param  \App\PriceType|null  $lista
     * @return \App\Buyer
     */
    private function compradorVinculado($lista)
    {
        $client = Client::create([
            'name'    => 'Cliente Carrito Sin Precio Test',
            'user_id' => $this->comercio->id,
        ]);

        if (!is_null($lista)) {
            $client->price_type_id = $lista->id;
            $client->save();
        }

        return Buyer::create([
            'name'                    => 'Comprador Carrito Sin Precio Test',
            'email'                   => 'carrito-sin-precio-'.Str::random(10).'@test.local',
            'comercio_city_client_id' => $client->id,
            'user_id'                 => $this->comercio->id,
        ]);
    }
}
