<?php

namespace Tests\Feature\Sesion;

use App\Article;
use App\Buyer;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CartHelper;
use App\Http\Controllers\Helpers\ClientOfferHelper;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\PromocionPersonalizada\CreaElEsquemaDeOfertas;
use Tests\TestCase;

/**
 * El respaldo de `CartHelper::get_price()` para una linea sin precio en el payload, con una
 * OFERTA PERSONALIZADA vigente (H8 del chequeo independiente, mision
 * tienda-precios-buyer-logueado, 23/9/2026).
 *
 * `checkPriceTypes()` -> `ClientOfferHelper::aplicar()` resuelve distinto segun el tipo:
 *   - 'unidad': deja `final_price` ya descontado y la base en `precio_sin_oferta`;
 *   - 'cantidad': deja `final_price` en la BASE y solo setea `precio_sin_oferta`, porque el
 *     tramo depende de la cantidad de la linea y lo elige `precioDeLinea()`.
 * Devolver el `final_price` resuelto a secas cobraba de mas en 'cantidad'.
 *
 * La vara de los dos casos: una linea sin precio tiene que cobrar EXACTAMENTE lo mismo que la
 * misma linea llegando por el camino normal, o sea con el `final_price` y el
 * `precio_sin_oferta` que el servidor le mostro al comprador.
 *
 * ⚠️ Sin DatabaseTransactions por el CREATE TABLE del trait (DDL con commit implicito): se
 * limpia a mano en el tearDown, igual que PrecioDelCarritoTest.
 */
class PrecioDelCarritoSinPrecioConOfertaTest extends TestCase
{
    use CreaElEsquemaDeOfertas;

    /** Cliente del ERP del comprador (no hace falta la fila: la oferta se busca por el id). */
    const CLIENT_ID = 987655;

    /** La columna articles.final_price del articulo del caso. */
    const PRECIO_BASE = 1000.00;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article|null */
    private $articulo = null;

    /** @var \App\Buyer|null */
    private $comprador = null;

    protected function setUp(): void
    {
        parent::setUp();

        ArticleHelper::olvidar_visibilidad_del_anonimo();
        ClientOfferHelper::olvidarMemoria();

        $this->crearEsquemaDeOfertasSiFalta();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        $this->articulo = Article::create([
            'name'        => 'Articulo Carrito Sin Precio Oferta Test',
            'slug'        => 'carrito-sin-precio-oferta-'.uniqid(),
            'user_id'     => $this->comercio->id,
            'status'      => 'active',
            'online'      => 1,
            'stock'       => 100,
            'final_price' => self::PRECIO_BASE,
        ]);

        $this->comprador = Buyer::create([
            'name'                    => 'Comprador Carrito Sin Precio Oferta Test',
            'email'                   => 'carrito-sin-precio-oferta-'.Str::random(10).'@test.local',
            'comercio_city_client_id' => self::CLIENT_ID,
            'user_id'                 => $this->comercio->id,
        ]);

        $this->limpiarOfertasDe($this->comercio->id);

        $this->actingAs($this->comprador, 'buyer');
    }

    protected function tearDown(): void
    {
        $this->limpiarOfertasDe($this->comercio->id);

        if (!is_null($this->comprador)) {
            DB::table('buyers')->where('id', $this->comprador->id)->delete();
        }

        if (!is_null($this->articulo)) {
            DB::table('articles')->where('id', $this->articulo->id)->delete();
        }

        ArticleHelper::olvidar_visibilidad_del_anonimo();
        ClientOfferHelper::olvidarMemoria();

        parent::tearDown();
    }

    /** 'unidad' 15%: sin precio en el payload cobra lo mismo que por el camino normal (850). */
    public function test_con_oferta_unidad_la_linea_sin_precio_cobra_lo_mismo_que_la_normal()
    {
        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $this->articulo->id,
            'porcentaje' => 15,
        ]);

        $normal = $this->precioPorElCaminoNormal(3);
        $sin_precio = $this->precioSinPrecioEnElPayload(3);

        $this->assertSame(850.00, (float) $normal, 'la vara: el camino normal cobra la base con el 15%');
        $this->assertSame((float) $normal, (float) $sin_precio,
            'la linea sin precio tiene que cobrar lo mismo que la normal');
    }

    /**
     * 🔴 'cantidad': el tramo lo elige la cantidad de la linea. Con 3 unidades entra el tramo
     * 1-5 (5%) y con 40 el ultimo, sin techo (18%). Antes del arreglo cobraba la base (1000).
     */
    public function test_con_oferta_cantidad_la_linea_sin_precio_cobra_lo_mismo_que_la_normal()
    {
        $offer_id = $this->insertarOferta([
            'user_id'        => $this->comercio->id,
            'client_id'      => self::CLIENT_ID,
            'article_id'     => $this->articulo->id,
            'tipo_descuento' => ClientOfferHelper::TIPO_CANTIDAD,
            'porcentaje'     => null,
        ]);

        $this->insertarRangos($offer_id, [
            [1, 5, 5],
            [6, 11, 10],
            [12, null, 18],
        ]);

        foreach ([3 => 950.00, 40 => 820.00] as $cantidad => $esperado) {
            $normal = $this->precioPorElCaminoNormal($cantidad);
            $sin_precio = $this->precioSinPrecioEnElPayload($cantidad);

            $this->assertSame($esperado, (float) $normal, "la vara con {$cantidad} unidades");
            $this->assertSame((float) $normal, (float) $sin_precio,
                "con {$cantidad} unidades la linea sin precio tiene que cobrar lo mismo que la normal, no la base");
        }
    }

    /**
     * La linea como la manda el SPA cuando tiene el articulo tal como el servidor se lo mostro
     * al comprador logueado: `final_price` y `precio_sin_oferta` de `checkPriceTypes()`.
     *
     * @param  int  $cantidad
     * @return mixed
     */
    private function precioPorElCaminoNormal($cantidad)
    {
        $mostrado = ArticleHelper::checkPriceTypes(
            collect([Article::with('price_types')->find($this->articulo->id)])
        )->first();

        $linea = $this->linea($cantidad, [
            'final_price'       => $mostrado->final_price,
            'precio_sin_oferta' => $mostrado->precio_sin_oferta,
        ]);

        return CartHelper::get_price([$linea], $linea, false, collect(), $this->comercio->id);
    }

    /**
     * La misma linea pedida como anonimo: sin precio.
     *
     * @param  int  $cantidad
     * @return mixed
     */
    private function precioSinPrecioEnElPayload($cantidad)
    {
        $linea = $this->linea($cantidad, ['final_price' => null]);

        return CartHelper::get_price([$linea], $linea, false, collect(), $this->comercio->id);
    }

    /**
     * @param  int  $cantidad
     * @param  array  $atributos
     * @return array
     */
    private function linea($cantidad, array $atributos)
    {
        return array_merge([
            'id'      => $this->articulo->id,
            'user_id' => $this->comercio->id,
            'name'    => $this->articulo->name,
            'cost'    => null,
            'amount'  => $cantidad,
            'pivot'   => ['amount' => $cantidad, 'notes' => null],
        ], $atributos);
    }
}
