<?php

namespace Tests\Feature\VisibilidadDePrecios;

use App\Article;
use App\Buyer;
use App\Client;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\OnlinePriceType;
use App\PriceType;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Que precios recibe (y cuales NO recibe) el visitante sin login (tanda-correctivos-2408,
 * item 20, especificacion dictada por Lucas el 24/8/2026).
 *
 * ── El bug ────────────────────────────────────────────────────────────────────────────────────
 *
 * ArticleHelper::checkPriceTypes() le mandaba al anonimo la lista de `position` mas alta
 * SIEMPRE: sin mirar la configuracion de visibilidad de `online_configurations` (la que el SPA
 * ya respeta en src/mixins/generals.js::puede_ver_precios()) y sin filtrar
 * `price_types.ocultar_al_publico`. El ocultamiento era cosmetico: con la tienda en "solo
 * registrados", cualquiera con curl leia los precios igual — y una lista marcada "Ocultar al
 * publico" podia ser justamente la elegida si tenia la position mas alta.
 *
 * ── La regla nueva, y la que NO cambia ────────────────────────────────────────────────────────
 *
 *   (a) El backend respeta la MISMA configuracion que el SPA: register_to_buy falsy → precios
 *       para cualquiera; si exige registro, el slug de online_price_type decide ('all' → si;
 *       'only_registered' / 'only_buyers_with_comerciocity_client' → el anonimo sin precios).
 *   (b) Una lista con ocultar_al_publico jamas se le muestra al anonimo: cae a la siguiente
 *       visible, o a ninguna si no queda.
 *   (c) 🔴 CONTRATO: el comprador LOGUEADO (con y sin vinculo a Client) recibe EXACTAMENTE lo
 *       mismo que antes. Los dos ultimos tests de esta clase son esa red: pasan sobre el codigo
 *       viejo y sobre el nuevo.
 *
 * ⚠️ Sobre la base: tienda-api no tiene database/migrations (el esquema lo gobierna
 * empresa-api). Se corre contra la base real del slot con DatabaseTransactions.
 */
class PreciosAlAnonimoTest extends TestCase
{
    use DatabaseTransactions;

    /** El precio de la lista de position mas alta (la "de mostrador" del anonimo). */
    const PRECIO_LISTA_ALTA = 111.0;

    /** El de la que le sigue. */
    const PRECIO_LISTA_BAJA = 222.0;

    /** Centinela en la COLUMNA articles.final_price: si el anonimo "sin precios" ve esto, hay fuga. */
    const PRECIO_DE_LA_COLUMNA = 999.0;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Article */
    private $articulo;

    /** @var \App\PriceType  position 20 */
    private $lista_alta;

    /** @var \App\PriceType  position 10 */
    private $lista_baja;

    protected function setUp(): void
    {
        parent::setUp();

        /* La memo de visibilidad es estatica y este proceso corre muchos requests con
           configuraciones distintas. */
        ArticleHelper::olvidar_visibilidad_del_anonimo();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        $this->lista_alta = $this->lista('Lista Cara Test', 20);
        $this->lista_baja = $this->lista('Lista Barata Test', 10);

        $this->articulo = Article::create([
            'name'        => 'Articulo Visibilidad Test',
            'slug'        => 'visibilidad-precios-'.uniqid(),
            'user_id'     => $this->comercio->id,
            'status'      => 'active',
            'online'      => 1,
            'stock'       => 10,
            'final_price' => self::PRECIO_DE_LA_COLUMNA,
        ]);

        $this->articulo->price_types()->attach($this->lista_alta->id, ['final_price' => self::PRECIO_LISTA_ALTA]);
        $this->articulo->price_types()->attach($this->lista_baja->id, ['final_price' => self::PRECIO_LISTA_BAJA]);
    }

    protected function tearDown(): void
    {
        ArticleHelper::olvidar_visibilidad_del_anonimo();

        parent::tearDown();
    }

    /**
     * Una lista de precios del comercio. PriceType no declara fillable, asi que va por
     * asignacion directa.
     *
     * @param  string  $nombre
     * @param  int  $position
     * @return \App\PriceType
     */
    private function lista($nombre, $position)
    {
        $lista = new PriceType;
        $lista->name     = $nombre;
        $lista->position = $position;
        $lista->user_id  = $this->comercio->id;
        $lista->save();

        return $lista;
    }

    /**
     * Deja la configuracion online del comercio en un modo de visibilidad.
     *
     * @param  string  $slug  all | only_registered | only_buyers_with_comerciocity_client
     * @param  int  $register_to_buy
     */
    private function configurarVisibilidad($slug, $register_to_buy = 1)
    {
        $online_price_type_id = OnlinePriceType::where('slug', $slug)->value('id');
        $this->assertNotNull($online_price_type_id, 'La base del slot tiene que tener el catalogo online_price_types sembrado.');

        $this->comercio->online_configuration()->update([
            'register_to_buy'      => $register_to_buy,
            'online_price_type_id' => $online_price_type_id,
        ]);

        /* La config cambio adentro del mismo proceso: la proxima request tiene que releerla. */
        ArticleHelper::olvidar_visibilidad_del_anonimo();
    }

    /**
     * El articulo tal como lo recibe quien mira la tienda, por el endpoint publico de detalle.
     *
     * @return array
     */
    private function verArticulo()
    {
        return $this->json('GET', '/api/articles/'.$this->articulo->slug.'/'.$this->comercio->id)
            ->assertStatus(200)
            ->json('article');
    }

    /** Con "Cualquiera que ingrese a la Web": igual que siempre, la lista de position mas alta. */
    public function test_con_cualquiera_el_anonimo_ve_la_lista_de_position_mas_alta()
    {
        $this->configurarVisibilidad('all');

        $articulo = $this->verArticulo();

        $this->assertEquals(self::PRECIO_LISTA_ALTA, (float) $articulo['final_price']);
    }

    /** 🔴 (a) Con "Solo los usuarios registrados", el anonimo no recibe NINGUN precio. */
    public function test_con_solo_registrados_el_anonimo_no_recibe_ningun_precio()
    {
        $this->configurarVisibilidad('only_registered');

        $this->assertSinNingunPrecio($this->verArticulo());
    }

    /** 🔴 (a) Idem con "Solo los usuarios registrados vinculados a un Cliente". */
    public function test_con_solo_vinculados_el_anonimo_no_recibe_ningun_precio()
    {
        $this->configurarVisibilidad('only_buyers_with_comerciocity_client');

        $this->assertSinNingunPrecio($this->verArticulo());
    }

    /**
     * (a) El corte de register_to_buy va PRIMERO, igual que en el SPA: si la tienda no exige
     * registro para comprar, los precios son visibles para cualquiera aunque el slug diga
     * "solo registrados". Si el backend los escondiera aca, el SPA los estaria mostrando
     * vacios.
     */
    public function test_sin_registro_obligatorio_el_anonimo_ve_precios_aunque_el_slug_sea_restrictivo()
    {
        $this->configurarVisibilidad('only_registered', 0);

        $articulo = $this->verArticulo();

        $this->assertEquals(self::PRECIO_LISTA_ALTA, (float) $articulo['final_price']);
    }

    /**
     * 🔴 (b) La lista marcada "Ocultar al publico" jamas se le muestra al anonimo, aunque
     * tenga la position mas alta: cae a la siguiente visible. Y sus precios tampoco viajan
     * escondidos en el payload de price_types.
     */
    public function test_la_lista_oculta_no_se_muestra_al_anonimo_aunque_tenga_la_position_mas_alta()
    {
        $this->configurarVisibilidad('all');
        $this->ocultarAlPublico($this->lista_alta);

        $articulo = $this->verArticulo();

        $this->assertEquals(self::PRECIO_LISTA_BAJA, (float) $articulo['final_price'],
            'Con la lista de position mas alta oculta, el anonimo tiene que caer a la siguiente visible.');

        $ids_en_payload = array_column($articulo['price_types'], 'id');
        $this->assertNotContains($this->lista_alta->id, $ids_en_payload,
            'El pivot de la lista oculta no puede viajar en el payload del anonimo.');
    }

    /** (b) Y si TODAS las listas estan ocultas, el anonimo queda sin precios — ni el de la columna. */
    public function test_con_todas_las_listas_ocultas_el_anonimo_queda_sin_precios()
    {
        $this->configurarVisibilidad('all');
        $this->ocultarAlPublico($this->lista_alta);
        $this->ocultarAlPublico($this->lista_baja);

        $this->assertSinNingunPrecio($this->verArticulo());
    }

    /**
     * 🔴 (c) CONTRATO: el comprador vinculado sigue recibiendo el precio de SU lista, con la
     * configuracion mas restrictiva y hasta con su lista marcada "Ocultar al publico" (ese
     * checkbox esconde la lista del ANONIMO, no del cliente que la tiene asignada).
     *
     * Este test pasa sobre el codigo viejo y sobre el nuevo: es la red del contrato.
     */
    public function test_el_vinculado_sigue_recibiendo_el_precio_de_su_lista()
    {
        $this->configurarVisibilidad('only_buyers_with_comerciocity_client');
        $this->ocultarAlPublico($this->lista_alta);

        $this->actingAs($this->compradorVinculadoA($this->lista_alta), 'buyer');

        $articulo = $this->verArticulo();

        $this->assertEquals(self::PRECIO_LISTA_ALTA, (float) $articulo['final_price'],
            'El vinculado recibe el precio de su lista, este oculta al publico o no.');

        $ids_en_payload = array_column($articulo['price_types'], 'id');
        $this->assertContains($this->lista_alta->id, $ids_en_payload,
            'Al logueado no se le filtra nada del payload: contrato compatible hacia atras.');
    }

    /**
     * 🔴 (c) CONTRATO: el registrado SIN vinculo cae en el mismo camino de siempre — la lista
     * de position mas alta, SIN filtro de ocultas (el filtro es solo para el anonimo; para el
     * logueado seria un cambio de contrato).
     *
     * Tambien pasa sobre el codigo viejo y sobre el nuevo.
     */
    public function test_el_registrado_sin_vinculo_sigue_recibiendo_la_lista_de_position_mas_alta()
    {
        $this->configurarVisibilidad('only_registered');
        $this->ocultarAlPublico($this->lista_alta);

        $comprador = Buyer::create([
            'name'    => 'Registrado Sin Vinculo Test',
            'email'   => 'sin-vinculo-'.Str::random(10).'@test.local',
            'user_id' => $this->comercio->id,
        ]);
        $this->actingAs($comprador, 'buyer');

        $articulo = $this->verArticulo();

        $this->assertEquals(self::PRECIO_LISTA_ALTA, (float) $articulo['final_price'],
            'El registrado sin vinculo sigue viendo la lista de position mas alta, como siempre.');
    }

    /** Marca una lista con el checkbox "Ocultar al publico" del ABM del ERP. */
    private function ocultarAlPublico(PriceType $lista)
    {
        $lista->ocultar_al_publico = 1;
        $lista->save();
    }

    /**
     * Un comprador logueado cuyo Client del ERP tiene asignada la lista dada.
     *
     * @param  \App\PriceType  $lista
     * @return \App\Buyer
     */
    private function compradorVinculadoA(PriceType $lista)
    {
        $client = Client::create([
            'name'    => 'Cliente Vinculado Test',
            'user_id' => $this->comercio->id,
        ]);
        $client->price_type_id = $lista->id;
        $client->save();

        return Buyer::create([
            'name'                    => 'Comprador Vinculado Test',
            'email'                   => 'vinculado-'.Str::random(10).'@test.local',
            'comercio_city_client_id' => $client->id,
            'user_id'                 => $this->comercio->id,
        ]);
    }

    /**
     * La forma de "sin precios" que promete el backend: final_price nulo (ni la lista ni el
     * centinela de la columna), price y cost nulos, y price_types vacio.
     *
     * @param  array  $articulo
     */
    private function assertSinNingunPrecio($articulo)
    {
        $this->assertNull($articulo['final_price'],
            'El anonimo sin visibilidad no puede recibir final_price (ni el de la columna).');
        $this->assertNull($articulo['price'],
            'El anonimo sin visibilidad no puede recibir la columna price.');
        $this->assertNull($articulo['cost'],
            'El anonimo sin visibilidad no puede recibir la columna cost.');
        $this->assertSame([], $articulo['price_types'],
            'El anonimo sin visibilidad no puede recibir los pivots de las listas.');
    }
}
