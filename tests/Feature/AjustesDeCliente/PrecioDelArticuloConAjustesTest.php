<?php

namespace Tests\Feature\AjustesDeCliente;

use App\Http\Controllers\Helpers\AjustesDeClienteHelper;
use App\Http\Controllers\Helpers\ClientOfferHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PromocionPersonalizada\CreaElEsquemaDeOfertas;
use Tests\TestCase;

/**
 * El precio que la tienda le MUESTRA a un comprador cuyo cliente del ERP tiene descuentos y
 * recargos de venta vinculados (mision descuentos-recargos-por-cliente, 23/9/2026).
 *
 * La formula es la de Vender: precio × Π(1 − d/100) × Π(1 + r/100), aplicada UNA vez sobre el
 * precio ya resuelto por checkPriceTypes() y ENCIMA de la oferta personalizada (decision 1 de
 * Lucas). El articulo viaja con `precio_sin_ajustes_de_cliente` (la base que el carrito necesita
 * para no aplicar el factor dos veces) y `ajustes_de_cliente` (la lista de badges).
 *
 * ⚠️ Sin DatabaseTransactions: el trait crea tablas (DDL, commit implicito). Se limpia a mano.
 */
class PrecioDelArticuloConAjustesTest extends TestCase
{
    use ArmaAjustesDeCliente;
    use CreaElEsquemaDeOfertas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armarAjustes();
    }

    protected function tearDown(): void
    {
        $this->limpiarAjustes();
        $this->limpiarOfertasDe(null);

        parent::tearDown();
    }

    /** El caso base del plan: 10% de descuento sobre 1000 -> 900, con su base y su badge. */
    public function test_un_descuento_del_10_deja_el_articulo_de_1000_en_900()
    {
        $comprador = $this->compradorVinculado();
        $descuento = $this->crearDescuento(10);
        $this->vincular($comprador, [$descuento]);
        $articulo = $this->crearArticulo(1000);

        $this->actingAs($comprador, 'buyer');

        $visto = $this->articuloComoLoVe($articulo);

        $this->assertSame(900.0, (float) $visto->final_price);
        $this->assertSame(1000.0, (float) $visto->precio_sin_ajustes_de_cliente);
        $this->assertCount(1, $visto->ajustes_de_cliente);
        $this->assertSame($descuento, $visto->ajustes_de_cliente[0]['id']);
        $this->assertSame(AjustesDeClienteHelper::TIPO_DESCUENTO, $visto->ajustes_de_cliente[0]['tipo']);
        $this->assertSame(10.0, $visto->ajustes_de_cliente[0]['percentage']);
    }

    /** Descuento y recargo componen como en Vender: 1000 × 0,9 × 1,05 = 945. */
    public function test_descuento_del_10_y_recargo_del_5_dan_945()
    {
        $comprador = $this->compradorVinculado();
        $this->vincular($comprador, [$this->crearDescuento(10)], [$this->crearRecargo(5)]);
        $articulo = $this->crearArticulo(1000);

        $this->actingAs($comprador, 'buyer');

        $visto = $this->articuloComoLoVe($articulo);

        $this->assertSame(945.0, (float) $visto->final_price);
        $this->assertSame(1000.0, (float) $visto->precio_sin_ajustes_de_cliente);
        $this->assertCount(2, $visto->ajustes_de_cliente);
        $this->assertSame(AjustesDeClienteHelper::TIPO_DESCUENTO, $visto->ajustes_de_cliente[0]['tipo']);
        $this->assertSame(AjustesDeClienteHelper::TIPO_RECARGO, $visto->ajustes_de_cliente[1]['tipo']);
    }

    /**
     * Decision 1 de Lucas: los ajustes van ENCIMA de la oferta personalizada. Oferta 20% y
     * descuento 10% sobre 1000 -> 720. La base de la oferta (`precio_sin_oferta`) queda en 1000,
     * sin ajustar, y la base de los ajustes es el precio de oferta.
     */
    public function test_oferta_del_20_mas_descuento_del_10_da_720()
    {
        $this->crearEsquemaDeOfertasSiFalta();

        $comprador = $this->compradorVinculado();
        $this->vincular($comprador, [$this->crearDescuento(10)]);
        $articulo = $this->crearArticulo(1000);

        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => $comprador->comercio_city_client_id,
            'article_id' => $articulo->id,
            'porcentaje' => 20,
        ]);

        $this->actingAs($comprador, 'buyer');

        $visto = $this->articuloComoLoVe($articulo);

        $this->assertSame(720.0, (float) $visto->final_price);
        $this->assertSame(1000.0, (float) $visto->precio_sin_oferta);
        $this->assertSame(800.0, (float) $visto->precio_sin_ajustes_de_cliente);
    }

    /** Un comprador sin cliente del ERP no tiene ajustes: el precio queda como en master. */
    public function test_un_comprador_sin_cliente_ve_el_precio_sin_cambios()
    {
        $this->crearDescuento(10);
        $articulo = $this->crearArticulo(1000);

        $this->actingAs($this->compradorSinCliente(), 'buyer');

        $visto = $this->articuloComoLoVe($articulo);

        $this->assertSame(1000.0, (float) $visto->final_price);
        $this->assertFalse(isset($visto->precio_sin_ajustes_de_cliente));
        $this->assertFalse(isset($visto->ajustes_de_cliente));
    }

    /** El anonimo tampoco: no hay cliente del que leer nada. */
    public function test_el_anonimo_ve_el_precio_sin_cambios()
    {
        $articulo = $this->crearArticulo(1000);

        $visto = $this->articuloComoLoVe($articulo);

        $this->assertFalse(isset($visto->ajustes_de_cliente));
    }

    /** Un cliente vinculado pero sin ajustes: sin cambios. */
    public function test_un_cliente_sin_ajustes_ve_el_precio_sin_cambios()
    {
        $comprador = $this->compradorVinculado();
        $articulo = $this->crearArticulo(1000);

        $this->actingAs($comprador, 'buyer');

        $visto = $this->articuloComoLoVe($articulo);

        $this->assertSame(1000.0, (float) $visto->final_price);
        $this->assertFalse(isset($visto->ajustes_de_cliente));
    }

    /** Un descuento borrado en el ERP (soft delete) deja de aplicarse. */
    public function test_un_descuento_borrado_no_aplica()
    {
        $comprador = $this->compradorVinculado();
        $this->vincular($comprador, [$this->crearDescuento(10, ['deleted_at' => Carbon::now()])]);
        $articulo = $this->crearArticulo(1000);

        $this->actingAs($comprador, 'buyer');

        $this->assertSame(1000.0, (float) $this->articuloComoLoVe($articulo)->final_price);
    }

    /**
     * La base es compartida entre comercios: un vinculo que apunta a un descuento de OTRO
     * comercio es un dato roto y no se cobra.
     */
    public function test_un_descuento_de_otro_comercio_no_aplica()
    {
        $comprador = $this->compradorVinculado();
        $this->vincular($comprador, [
            $this->crearDescuento(10, ['user_id' => $this->comercio->id + 999999]),
        ], [
            $this->crearRecargo(5, ['user_id' => $this->comercio->id + 999999]),
        ]);
        $articulo = $this->crearArticulo(1000);

        $this->actingAs($comprador, 'buyer');

        $this->assertSame(1000.0, (float) $this->articuloComoLoVe($articulo)->final_price);
    }

    /** Porcentajes inservibles (0, mas de 100 en un descuento, negativo) se ignoran enteros. */
    public function test_un_porcentaje_inservible_se_ignora()
    {
        $comprador = $this->compradorVinculado();
        $this->vincular(
            $comprador,
            [$this->crearDescuento(0), $this->crearDescuento(150), $this->crearDescuento(10)],
            [$this->crearRecargo(-5), $this->crearRecargo(0)]
        );
        $articulo = $this->crearArticulo(1000);

        $this->actingAs($comprador, 'buyer');

        $visto = $this->articuloComoLoVe($articulo);

        $this->assertSame(900.0, (float) $visto->final_price);
        $this->assertCount(1, $visto->ajustes_de_cliente);
    }

    /**
     * 🔴 La ficha SIN credencial de un checkout de invitado no es la cuenta del cliente, aunque
     * este vinculada y aunque este en el guard: no ve ajustes ni los trae en /api/user.
     */
    public function test_la_ficha_de_invitado_vinculada_no_tiene_ajustes()
    {
        $ficha = $this->fichaDeInvitadoVinculada();
        $this->vincular($ficha, [$this->crearDescuento(10)], [$this->crearRecargo(5)]);
        $articulo = $this->crearArticulo(1000);

        $this->actingAs($ficha, 'buyer');

        $visto = $this->articuloComoLoVe($articulo);

        $this->assertSame(1000.0, (float) $visto->final_price);
        $this->assertFalse(isset($visto->ajustes_de_cliente));

        $respuesta = $this->getJson('/api/user');
        $respuesta->assertStatus(200);
        $this->assertSame(['descuentos' => [], 'recargos' => []], $respuesta->json('buyer.ajustes_de_cliente'));
    }

    /** Un cliente borrado en el ERP (soft delete) deja de tener ajustes. */
    public function test_un_cliente_borrado_no_tiene_ajustes()
    {
        $comprador = $this->compradorVinculado();
        $this->vincular($comprador, [$this->crearDescuento(10)]);
        $articulo = $this->crearArticulo(1000);

        DB::table('clients')->where('id', $comprador->comercio_city_client_id)->update(['deleted_at' => Carbon::now()]);

        $this->actingAs($comprador, 'buyer');

        $this->assertSame(1000.0, (float) $this->articuloComoLoVe($articulo)->final_price);
    }

    /**
     * El esquema del contrato se mide con UNA consulta al information_schema (las cuatro tablas
     * en el IN), no con un Schema::hasTable por tabla.
     */
    public function test_el_esquema_se_mide_con_una_sola_consulta()
    {
        $comprador = $this->compradorVinculado();
        $this->vincular($comprador, [$this->crearDescuento(10)]);
        $articulo = $this->crearArticulo(1000);

        $this->actingAs($comprador, 'buyer');

        $articulos = \App\Article::where('id', $articulo->id)->withAll()->get();
        $this->olvidarMemorias();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        \App\Http\Controllers\Helpers\AjustesDeClienteHelper::aplicar($articulos);

        $del_esquema = array_filter($queries, function ($sql) {
            return stripos($sql, 'information_schema') !== false;
        });

        $this->assertCount(1, $del_esquema, implode(' | ', $del_esquema));
        $this->assertSame(900.0, (float) $articulos->first()->final_price);
    }

    /** Un mismo descuento vinculado dos veces se aplica una. */
    public function test_un_descuento_vinculado_dos_veces_se_aplica_una()
    {
        $comprador = $this->compradorVinculado();
        $descuento = $this->crearDescuento(10);
        $this->vincular($comprador, [$descuento, $descuento]);
        $articulo = $this->crearArticulo(1000);

        $this->actingAs($comprador, 'buyer');

        $this->assertSame(900.0, (float) $this->articuloComoLoVe($articulo)->final_price);
    }

    /**
     * 🔴 Compatibilidad hacia atras: la tienda nueva contra una base de empresa vieja (sin las
     * tablas). No aplica nada y no revienta.
     */
    public function test_sin_las_tablas_no_aplica_nada_y_no_revienta()
    {
        $comprador = $this->compradorVinculado();
        $this->vincular($comprador, [$this->crearDescuento(10)]);
        $articulo = $this->crearArticulo(1000);

        $this->actingAs($comprador, 'buyer');

        $this->esconderTabla(AjustesDeClienteHelper::TABLA_DESCUENTOS);

        $visto = $this->articuloComoLoVe($articulo);

        $this->assertSame(1000.0, (float) $visto->final_price);
        $this->assertFalse(isset($visto->ajustes_de_cliente));

        /* Y /api/user sigue andando, con los ajustes vacios. */
        $respuesta = $this->getJson('/api/user');
        $respuesta->assertStatus(200);
        $this->assertSame(['descuentos' => [], 'recargos' => []], $respuesta->json('buyer.ajustes_de_cliente'));
    }

    /** /api/user trae la lista para el desplegable del nombre. */
    public function test_el_comprador_trae_sus_ajustes_en_api_user()
    {
        $comprador = $this->compradorVinculado();
        $descuento = $this->crearDescuento(10);
        $recargo = $this->crearRecargo(5);
        $this->vincular($comprador, [$descuento], [$recargo]);

        $this->actingAs($comprador, 'buyer');

        $respuesta = $this->getJson('/api/user');

        $respuesta->assertStatus(200);
        $this->assertSame($descuento, $respuesta->json('buyer.ajustes_de_cliente.descuentos.0.id'));
        $this->assertEquals(10, $respuesta->json('buyer.ajustes_de_cliente.descuentos.0.percentage'));
        $this->assertSame($recargo, $respuesta->json('buyer.ajustes_de_cliente.recargos.0.id'));
        $this->assertEquals(5, $respuesta->json('buyer.ajustes_de_cliente.recargos.0.percentage'));

        /* Y el comprador no quedo con una columna inventada: /api/user lo guarda (last_login). */
        $this->assertNotNull(DB::table('buyers')->where('id', $comprador->id)->value('last_login'));
    }

    /** Las promociones de vinoteca de la home tambien se muestran ajustadas (decision 2). */
    public function test_la_promo_de_la_home_se_muestra_ajustada()
    {
        $comprador = $this->compradorVinculado();
        $this->vincular($comprador, [$this->crearDescuento(10)], [$this->crearRecargo(5)]);

        $promo_id = DB::table('promocion_vinotecas')->insertGetId([
            'name'        => 'Promo Ajustes Test',
            'slug'        => 'promo-ajustes-test-'.uniqid(),
            'final_price' => 2000,
            'online'      => 1,
            'user_id'     => $this->comercio->id,
            'created_at'  => Carbon::now(),
            'updated_at'  => Carbon::now(),
        ]);
        $this->anotar('promocion_vinotecas', $promo_id);

        $this->actingAs($comprador, 'buyer');

        $promos = \App\Http\Controllers\Helpers\HomeHelper::get_promociones_vinoteca($this->comercio->id);
        $promo = $promos->firstWhere('id', $promo_id);

        $this->assertSame(1890.0, (float) $promo->final_price);
        $this->assertSame(2000.0, (float) $promo->precio_sin_ajustes_de_cliente);
        $this->assertCount(2, $promo->ajustes_de_cliente);
    }
}
