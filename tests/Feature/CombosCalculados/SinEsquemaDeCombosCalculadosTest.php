<?php

namespace Tests\Feature\CombosCalculados;

use App\Http\Controllers\Helpers\ComboEsquemaHelper;
use App\Http\Controllers\Helpers\HomeHelper;
use App\OnlineConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Los combos calculados contra una base a la que le falta (o le sobra) esquema, en las DOS
 * direcciones (mision combos-calculados, 30/9/2026).
 *
 * 🔴 Es el escenario NORMAL, no el borde. El esquema de esta base lo gobierna `empresa-api` y llega
 * con su release; la tienda la despliega Lucas a mano, cliente por cliente. Va a haber:
 *
 *   A. TIENDA NUEVA + EMPRESA VIEJA: la base no tiene `combo_price_type`. Sin la guarda, la home
 *      seria "Base table or view not found" —la tienda caida— para el comprador que la abre. Con
 *      ella los combos cobran `combos.price`, que es lo de siempre.
 *   B. EMPRESA NUEVA + TIENDA VIEJA: la base tiene todo y la tienda vieja solo lee `combos.price`.
 *      Eso no se puede correr desde aca (el codigo de la tienda es el nuevo), pero se fija el
 *      contrato que lo hace andar: `combos.price` de un combo con filas por lista es el precio de la
 *      lista por defecto, y la tienda nueva sin fila para una lista cobra lo mismo. Ver
 *      `test_con_la_tabla_pero_sin_filas_cobra_combos_price`.
 *   C. `ignorar_stock` (la configuracion que apaga el filtro de agotados) puede no existir: el
 *      filtro tiene que leerla sin revertar a "tira excepcion".
 *
 * ── Por que esta clase NO usa DatabaseTransactions ───────────────────────────────────────────
 *
 * El unico modo honesto de probar "la tabla no esta" es que no este, y eso es `RENAME TABLE`: DDL,
 * que MySQL comitea implicitamente, asi que el rollback del trait no revertiria nada. Lo que se
 * crea va afuera de cualquier transaccion y se borra a mano por ids EXACTOS en el `finally` y en
 * el `tearDown`. Mismo molde que `SinEsquemaDeCombosYRangosTest`.
 *
 * ⚠️ NO puede correr en paralelo con otra suite sobre la misma base: mientras la tabla esta
 * renombrada, cualquier otro test que la lea revienta. El restore va en el `finally`, en el
 * `tearDown` Y en el `setUp` (por si una corrida anterior murio a mitad).
 */
class SinEsquemaDeCombosCalculadosTest extends TestCase
{
    use ArmaCombosCalculados;

    const SUFIJO = '_escondida_test';

    /** @var array Ids de los comercios creados FUERA de transaccion. */
    private $comercios = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercios = [];

        $this->olvidarTodo();
        $this->restaurarElEsquema();

        $this->assertTrue(Schema::hasTable('combo_price_type'),
            'La base tiene que ARRANCAR con combo_price_type: correr la migracion 2026_09_30_130100 de empresa-api.');
        $this->assertTrue(ComboEsquemaHelper::precios_por_lista_disponible());
    }

    protected function tearDown(): void
    {
        $this->restaurarElEsquema();
        $this->limpiar();
        $this->olvidarTodo();

        parent::tearDown();
    }

    /** Un comercio con tienda, anotado para borrarlo despues. */
    private function comercioCreado()
    {
        $comercio = $this->comercioConTienda();
        $this->comercios[] = $comercio->id;

        return $comercio;
    }

    /*
    |---------------------------------------------------------------------------------------------
    | A. Tienda nueva, empresa vieja: sin `combo_price_type`
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 Sin la tabla del precio por lista, la home y el carrito siguen andando y el combo cobra
     * `combos.price`, tenga el comprador lista o no.
     *
     * Las listas y las filas se cargan ANTES de esconder la tabla, asi lo que se mide es la guarda
     * y no la falta de datos; y con la tabla puesta el precio por lista SI se usa (la contraprueba
     * que impide un verde vacuo).
     */
    public function test_sin_la_tabla_de_precios_por_lista_la_tienda_sigue_andando_al_precio_de_siempre()
    {
        $comercio = $this->comercioCreado();
        $minorista = $this->lista($comercio, 'Minorista', 1);
        $mayorista = $this->lista($comercio, 'Mayorista', 2);

        $combo = $this->combo($comercio, ['price' => 8500]);
        $this->componente($combo, $this->articuloConStock($comercio, 20), 2);
        $this->precioPorLista($combo, $minorista, 7000);
        $this->precioPorLista($combo, $mayorista, 8000);

        $con_lista = $this->compradorConLista($comercio, $minorista);

        /* Contraprueba: CON la tabla el anonimo ve la lista publica (Mayorista). */
        $this->assertEquals(8000, $this->comboEnLaHome($comercio, $combo)['final_price'],
            'el escenario arranca con el precio por lista funcionando: si no, el caso seria vacuo');

        $this->esconderTabla('combo_price_type');

        try {
            $this->olvidarTodo();

            $this->assertFalse(ComboEsquemaHelper::precios_por_lista_disponible(),
                'con la tabla escondida la guarda tiene que dar false');
            $this->assertTrue(ComboEsquemaHelper::disponible(),
                'los combos siguen disponibles: solo faltan los precios por lista');

            /* 1. La home: 200 y el combo al precio de siempre, con su stock y su foto. */
            $home = $this->comboEnLaHome($comercio, $combo);
            $this->assertEquals(8500, $home['final_price'], 'anonimo: combos.price');
            $this->assertSame(10, $home['stock_disponible'], 'el stock no depende del esquema nuevo');
            $this->assertSame([], $home['images']);

            /* 2. El carrito del anonimo. */
            $creado = $this->crearCarrito($comercio, [
                'combos' => [$this->lineaDeComboDelPayload($combo, 2)],
            ]);
            $creado->assertStatus(201);
            $cart_id = (int) $creado->json('cart.id');

            $this->assertEquals(8500, $this->lineaDeComboGuardada($cart_id, $combo)->price);
            $this->assertEquals(17000, $this->totalGuardado($cart_id));

            /* 3. El carrito del logueado con lista propia: tampoco hay tabla que leer. */
            $this->actingAs($con_lista, 'buyer');
            $this->olvidarTodo();

            $this->assertEquals(8500, $this->comboEnLaHome($comercio, $combo)['final_price'],
                'logueado con lista propia: sin tabla cae a combos.price');

            $creado = $this->crearCarrito($comercio, [
                'combos' => [$this->lineaDeComboDelPayload($combo, 1)],
            ]);
            $creado->assertStatus(201);
            $cart_logueado = (int) $creado->json('cart.id');

            $this->assertEquals(8500, $this->lineaDeComboGuardada($cart_logueado, $combo)->price);

            /* 4. Y el boton "Actualizar" tampoco revienta. */
            $this->putJson('/api/carts/update-article-amount/'.$cart_logueado, [
                'id'       => $combo->id,
                'amount'   => 3,
                'is_combo' => true,
            ])->assertStatus(200);

            $this->assertEquals(25500, $this->totalGuardado($cart_logueado));
        } finally {
            $this->restaurarElEsquema();
        }

        $this->assertTrue(Schema::hasTable('combo_price_type'), 'la base volvio a tener combo_price_type');
        $this->olvidarTodo();
        $this->assertTrue(ComboEsquemaHelper::precios_por_lista_disponible());
    }

    /**
     * B. Con la tabla puesta pero SIN FILAS para el combo (un combo manual, o uno que el ERP nuevo
     * todavia no recalculo): la tienda cobra `combos.price`. Es la mitad del contrato que hace
     * andar a una tienda VIEJA contra un ERP nuevo, que solo lee esa columna.
     */
    public function test_con_la_tabla_pero_sin_filas_cobra_combos_price()
    {
        $comercio = $this->comercioCreado();
        $this->lista($comercio, 'Minorista', 1);
        $this->lista($comercio, 'Mayorista', 2);

        $combo = $this->combo($comercio, ['price' => 8500]);
        $this->componente($combo, $this->articuloConStock($comercio, 20), 2);

        $this->assertEquals(8500, $this->comboEnLaHome($comercio, $combo)['final_price']);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | C. `ignorar_stock`
    |---------------------------------------------------------------------------------------------
    */

    /**
     * `ignorar_stock` prendido apaga el filtro de agotados aunque `show_articles_without_stock`
     * diga que se oculten. Como la columna no existe en esta base, se agrega para el caso y se
     * saca al terminar (DDL: por eso esta clase y no `AgotadosEnLaHomeTest`).
     */
    public function test_ignorar_stock_apaga_el_filtro_de_agotados()
    {
        $comercio = $this->comercioCreado();

        $agotado = $this->combo($comercio);
        $this->componente($agotado, $this->articuloConStock($comercio, 0), 1);

        OnlineConfiguration::where('user_id', $comercio->id)->update(['show_articles_without_stock' => 0]);

        $columna_era_nuestra = false;

        try {
            $this->assertTrue(HomeHelper::ocultar_combos_agotados($comercio->id),
                'sin ignorar_stock manda show_articles_without_stock: se oculta');
            $this->assertNotContains($agotado->id, array_column($this->combosDeLaHome($comercio), 'id'));

            if (!Schema::hasColumn('online_configurations', 'ignorar_stock')) {
                DB::statement('ALTER TABLE `online_configurations` ADD COLUMN `ignorar_stock` TINYINT(1) NOT NULL DEFAULT 0');
                $columna_era_nuestra = true;
            }

            DB::table('online_configurations')->where('user_id', $comercio->id)->update(['ignorar_stock' => 1]);

            $this->assertFalse(HomeHelper::ocultar_combos_agotados($comercio->id),
                'con ignorar_stock prendido no se oculta nada, aunque show_articles_without_stock sea 0');
            $this->assertContains($agotado->id, array_column($this->combosDeLaHome($comercio), 'id'));
        } finally {
            if ($columna_era_nuestra && Schema::hasColumn('online_configurations', 'ignorar_stock')) {
                DB::statement('ALTER TABLE `online_configurations` DROP COLUMN `ignorar_stock`');
            }
        }
    }

    /**
     * 🔴 EL ULTIMO CASO NO ES DECORATIVO: la base quedo exactamente como estaba. Los de arriba
     * hacen DDL sobre la base que comparten todos los tests de este slot; una tabla que quedara
     * renombrada se llevaria puesta la suite entera con un error que no nombra la causa.
     */
    public function test_la_base_quedo_como_estaba()
    {
        $this->assertTrue(Schema::hasTable('combo_price_type'));
        $this->assertFalse(Schema::hasTable('combo_price_type'.self::SUFIJO));
        $this->assertTrue(Schema::hasTable('cart_combo'));
        $this->assertTrue(Schema::hasTable('order_combo'));
        $this->assertTrue(Schema::hasColumn('combos', 'online'));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El esconder y el restaurar
    |---------------------------------------------------------------------------------------------
    */

    private function esconderTabla($tabla)
    {
        if (Schema::hasTable($tabla)) {
            DB::statement('RENAME TABLE `'.$tabla.'` TO `'.$tabla.self::SUFIJO.'`');
        }
    }

    /** Devuelve TODO lo que esta clase puede haber escondido. Idempotente. */
    private function restaurarElEsquema()
    {
        $tabla = 'combo_price_type';

        if (Schema::hasTable($tabla.self::SUFIJO) && !Schema::hasTable($tabla)) {
            DB::statement('RENAME TABLE `'.$tabla.self::SUFIJO.'` TO `'.$tabla.'`');
        }
    }

    /**
     * Borra lo que esta clase escribio, por ids EXACTOS (nunca por un `like` sobre el mail, que
     * tambien matchearia los comercios de las otras clases de la carpeta). Idempotente.
     *
     * @return void
     */
    private function limpiar()
    {
        if (empty($this->comercios)) {
            return;
        }

        $ids = $this->comercios;

        $carritos = DB::table('carts')->whereIn('user_id', $ids)->pluck('id')->all();
        $combos = DB::table('combos')->whereIn('user_id', $ids)->pluck('id')->all();
        $articulos = DB::table('articles')->whereIn('user_id', $ids)->pluck('id')->all();

        if (!empty($carritos)) {
            DB::table('article_cart')->whereIn('cart_id', $carritos)->delete();
            DB::table('cart_promocion_vinoteca')->whereIn('cart_id', $carritos)->delete();
            DB::table('cart_combo')->whereIn('cart_id', $carritos)->delete();
            DB::table('carts')->whereIn('id', $carritos)->delete();
        }

        if (!empty($combos)) {
            DB::table('article_combo')->whereIn('combo_id', $combos)->delete();
            DB::table('images')->where('imageable_type', 'combo')->whereIn('imageable_id', $combos)->delete();

            if (Schema::hasTable('combo_price_type')) {
                DB::table('combo_price_type')->whereIn('combo_id', $combos)->delete();
            }

            DB::table('combos')->whereIn('id', $combos)->delete();
        }

        if (!empty($articulos)) {
            DB::table('images')->where('imageable_type', 'article')->whereIn('imageable_id', $articulos)->delete();
            DB::table('articles')->whereIn('id', $articulos)->delete();
        }

        DB::table('buyers')->whereIn('user_id', $ids)->delete();
        DB::table('clients')->whereIn('user_id', $ids)->delete();
        DB::table('price_types')->whereIn('user_id', $ids)->delete();
        DB::table('online_configurations')->whereIn('user_id', $ids)->delete();
        DB::table('users')->whereIn('id', $ids)->delete();

        $this->comercios = [];
    }
}
