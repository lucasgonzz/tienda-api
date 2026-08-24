<?php

namespace Tests\Feature\VisibilidadDePrecios;

use App\Article;
use App\Buyer;
use App\Category;
use App\CategoryPriceTypeRange;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CommerceHelper;
use App\OnlinePriceType;
use App\PriceType;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La visibilidad de precios del anonimo TAMBIEN cubre el camino de rangos (tanda-correctivos-2408,
 * item 20, hallazgo D1 del chequeo independiente del 24/8/2026).
 *
 * ── El agujero ────────────────────────────────────────────────────────────────────────────────
 *
 * Con la extension `lista_de_precios_por_rango_de_cantidad_vendida`, checkPriceTypes() entraba
 * en set_ranges() y retornaba ANTES de evaluar la visibilidad: un comercio con la tienda en
 * "solo registrados" le seguia mandando al anonimo `ranges[].price`, las columnas de precio y
 * los pivots completos. En el SPA ese camino corre adentro de articlePriceEfectivo(), detras
 * del MISMO puede_ver_precios() que el backend espeja — asi que el espejo fiel lo cubre igual.
 *
 * ── La forma del vaciado ──────────────────────────────────────────────────────────────────────
 *
 * `ranges` viaja como ARRAY VACIO, no ausente: es la forma que set_ranges() ya deja hoy en los
 * articulos sin rangos configurados (el SPA ya convive con ella), el forEach de generals.js no
 * itera sobre [] (con undefined tiraria TypeError) y PriceRanges.vue renderiza vacio.
 *
 * Los tramos NO se filtran por ocultar_al_publico cuando el anonimo SI ve precios: son
 * configuracion deliberada del comercio en el ABM de rangos por categoria, y sacar un tramo del
 * medio dejaria la escala de cantidades con agujeros (la decision esta documentada en el helper).
 *
 * ⚠️ Sin migraciones propias: se corre contra la base real del slot con DatabaseTransactions.
 */
class PreciosAlAnonimoConRangosTest extends TestCase
{
    use DatabaseTransactions;

    /** Precio del tramo minorista (min 1), lista de position mas alta. */
    const PRECIO_TRAMO_MINORISTA = 111.0;

    /** Precio del tramo mayorista (min 10), lista de position mas baja. */
    const PRECIO_TRAMO_MAYORISTA = 222.0;

    /** Centinela en la COLUMNA articles.final_price: si el anonimo "sin precios" ve esto, hay fuga. */
    const PRECIO_DE_LA_COLUMNA = 999.0;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article */
    private $articulo;

    protected function setUp(): void
    {
        parent::setUp();

        ArticleHelper::olvidar_visibilidad_del_anonimo();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        $this->activarExtensionDeRangos();

        $lista_alta = $this->lista('Rangos Cara Test', 20);
        $lista_baja = $this->lista('Rangos Barata Test', 10);

        $categoria = Category::create([
            'name'    => 'Categoria Rangos Test',
            'user_id' => $this->comercio->id,
        ]);

        $this->articulo = Article::create([
            'name'        => 'Articulo Rangos Test',
            'slug'        => 'visibilidad-rangos-'.uniqid(),
            'user_id'     => $this->comercio->id,
            'category_id' => $categoria->id,
            'status'      => 'active',
            'online'      => 1,
            'stock'       => 10,
            'final_price' => self::PRECIO_DE_LA_COLUMNA,
        ]);

        /* set_ranges() lee pivot->price (no final_price): los dos van cargados. */
        $this->articulo->price_types()->attach($lista_alta->id, [
            'price'       => self::PRECIO_TRAMO_MINORISTA,
            'final_price' => self::PRECIO_TRAMO_MINORISTA,
        ]);
        $this->articulo->price_types()->attach($lista_baja->id, [
            'price'       => self::PRECIO_TRAMO_MAYORISTA,
            'final_price' => self::PRECIO_TRAMO_MAYORISTA,
        ]);

        $this->tramo($categoria, $lista_alta, 1, 9);
        $this->tramo($categoria, $lista_baja, 10, null);
    }

    protected function tearDown(): void
    {
        ArticleHelper::olvidar_visibilidad_del_anonimo();

        parent::tearDown();
    }

    /**
     * Prende la extension de rangos para el comercio del slot. Ojo con el nombre: la relacion
     * User::extencions() de este repo es belongsToMany(App\ExtencionEmpresa) — las tablas son
     * `extencion_empresas` y `extencion_empresa_user`, NO `extencions`. La fila se crea si
     * falta; todo dentro de la transaccion del test.
     */
    private function activarExtensionDeRangos()
    {
        $slug = 'lista_de_precios_por_rango_de_cantidad_vendida';

        $extencion_id = DB::table('extencion_empresas')->where('slug', $slug)->value('id');
        if (is_null($extencion_id)) {
            $extencion_id = DB::table('extencion_empresas')->insertGetId([
                'name' => 'Lista de precios por rango de cantidad vendida',
                'slug' => $slug,
            ]);
        }

        DB::table('extencion_empresa_user')->insert([
            'extencion_empresa_id' => $extencion_id,
            'user_id'              => $this->comercio->id,
        ]);

        /* Si esto no quedo activo, todos los tests de abajo probarian el camino comun y serian
           vacuos. hasExtencion() consulta la relacion sin memo, asi que ve el insert de arriba. */
        $this->assertTrue(
            CommerceHelper::hasExtencion($slug, null, $this->comercio->id),
            'La extension de rangos tiene que quedar activa para el comercio del test.'
        );
    }

    /** Una lista de precios del comercio (PriceType no declara fillable: asignacion directa). */
    private function lista($nombre, $position)
    {
        $lista = new PriceType;
        $lista->name     = $nombre;
        $lista->position = $position;
        $lista->user_id  = $this->comercio->id;
        $lista->save();

        return $lista;
    }

    /** Un tramo de la escala de cantidades de la categoria (rango a nivel categoria: sin sub_category). */
    private function tramo(Category $categoria, PriceType $lista, $min, $max)
    {
        $tramo = new CategoryPriceTypeRange;
        $tramo->category_id     = $categoria->id;
        $tramo->sub_category_id = null;
        $tramo->min             = $min;
        $tramo->max             = $max;
        $tramo->price_type_id   = $lista->id;
        $tramo->user_id         = $this->comercio->id;
        $tramo->save();

        return $tramo;
    }

    /** Deja la configuracion online del comercio en un modo de visibilidad. */
    private function configurarVisibilidad($slug, $register_to_buy = 1)
    {
        $online_price_type_id = OnlinePriceType::where('slug', $slug)->value('id');
        $this->assertNotNull($online_price_type_id, 'La base del slot tiene que tener el catalogo online_price_types sembrado.');

        $this->comercio->online_configuration()->update([
            'register_to_buy'      => $register_to_buy,
            'online_price_type_id' => $online_price_type_id,
        ]);

        ArticleHelper::olvidar_visibilidad_del_anonimo();
    }

    /** El articulo tal como lo recibe quien mira la tienda, por el endpoint publico de detalle. */
    private function verArticulo()
    {
        return $this->json('GET', '/api/articles/'.$this->articulo->slug.'/'.$this->comercio->id)
            ->assertStatus(200)
            ->json('article');
    }

    /**
     * 🔴 D1: con la tienda en modo restrictivo, el anonimo no recibe NADA — tampoco los rangos.
     * Sobre el codigo anterior este test esta rojo: set_ranges() corria antes del gate y los
     * tramos viajaban con precio adentro.
     */
    public function test_con_rangos_y_modo_restrictivo_el_anonimo_no_recibe_ni_precios_ni_rangos()
    {
        $this->configurarVisibilidad('only_registered');

        $articulo = $this->verArticulo();

        $this->assertNull($articulo['final_price'],
            'El anonimo sin visibilidad no puede recibir final_price (ni el de la columna).');
        $this->assertNull($articulo['price']);
        $this->assertNull($articulo['cost']);
        $this->assertSame([], $articulo['price_types'],
            'Los pivots de las listas no pueden viajar.');
        $this->assertSame([], $articulo['ranges'],
            'Los rangos viajan como array vacio: ni un tramo con precio para el anonimo.');
    }

    /** El modo abierto no cambia: los tramos llegan con sus precios, ordenados por min. */
    public function test_con_rangos_y_modo_abierto_el_anonimo_recibe_los_tramos_como_siempre()
    {
        $this->configurarVisibilidad('all');

        $articulo = $this->verArticulo();

        $this->assertCount(2, $articulo['ranges']);
        $this->assertEquals(self::PRECIO_TRAMO_MINORISTA, (float) $articulo['ranges'][0]['price']);
        $this->assertEquals(self::PRECIO_TRAMO_MAYORISTA, (float) $articulo['ranges'][1]['price']);
    }

    /**
     * El corte de register_to_buy va primero, igual que en el SPA: sin registro obligatorio
     * los rangos son visibles aunque el slug diga "solo registrados".
     */
    public function test_sin_registro_obligatorio_el_anonimo_recibe_los_tramos_aunque_el_slug_sea_restrictivo()
    {
        $this->configurarVisibilidad('only_registered', 0);

        $articulo = $this->verArticulo();

        $this->assertCount(2, $articulo['ranges']);
        $this->assertEquals(self::PRECIO_TRAMO_MINORISTA, (float) $articulo['ranges'][0]['price']);
    }

    /**
     * 🔴 CONTRATO: el logueado recibe los rangos intactos con la config mas restrictiva —
     * el gate es solo para el anonimo. Pasa sobre el codigo viejo y sobre el nuevo.
     */
    public function test_con_rangos_el_logueado_sigue_recibiendo_los_tramos_con_config_restrictiva()
    {
        $this->configurarVisibilidad('only_registered');

        $comprador = Buyer::create([
            'name'    => 'Registrado Rangos Test',
            'email'   => 'rangos-'.Str::random(10).'@test.local',
            'user_id' => $this->comercio->id,
        ]);
        $this->actingAs($comprador, 'buyer');

        $articulo = $this->verArticulo();

        $this->assertCount(2, $articulo['ranges'],
            'El logueado sigue recibiendo la escala completa: contrato compatible hacia atras.');
        $this->assertEquals(self::PRECIO_TRAMO_MINORISTA, (float) $articulo['ranges'][0]['price']);
        $this->assertEquals(self::PRECIO_TRAMO_MAYORISTA, (float) $articulo['ranges'][1]['price']);
    }
}
