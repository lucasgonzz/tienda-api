<?php

namespace Tests\Feature\PromocionPersonalizada;

use App\Article;
use App\Buyer;
use App\Http\Controllers\Helpers\ClientOfferHelper;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET /api/client-offers/{commerce_id}`: los articulos con oferta personalizada vigente del
 * comprador logueado (mision promocion-personalizada-tienda).
 *
 * ── Que modos de falla cubre ──────────────────────────────────────────────────────────────────
 *
 *   - que la ruta quede accesible sin sesion: devuelve la promocion que un comercio le armo a UNA
 *     persona, asi que es informacion de su cuenta. La lista completa de rutas de la cuenta vive
 *     en Tests\Feature\Seguridad\SuperficiePublicaTest y esta ruta esta ahi tambien;
 *   - que el mensaje de bienvenida lleve a la ficha de un articulo que la tienda no muestra
 *     (dado de baja, apagado del catalogo online o sin stock): el comprador clickea y cae en una
 *     pantalla que no existe;
 *   - y que "no hay ofertas" se conteste con algo distinto de un 200 con la lista vacia, que es
 *     lo unico que el SPA sabe manejar sin romper la carga.
 *
 * ⚠️ Sin DatabaseTransactions por el CREATE TABLE del trait (DDL con commit implicito). Los
 * articulos que crea el caso se borran a mano en el tearDown.
 */
class EndpointDeOfertasTest extends TestCase
{
    use CreaElEsquemaDeOfertas;

    const CLIENT_ID = 987654;

    /** @var \App\User */
    private $comercio;

    /** @var \App\Buyer|null */
    private $comprador = null;

    /** @var array Ids de los articulos que creo este caso. */
    private $articulos_creados = [];

    protected function setUp(): void
    {
        parent::setUp();

        ClientOfferHelper::olvidarMemoria();

        $this->crearEsquemaDeOfertasSiFalta();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        /*
         * Precondicion explicita del caso U: los scopes checkOnline()/checkStock() leen la
         * configuracion online del comercio. Si el slot la cambiara, el test tiene que fallar
         * diciendo POR QUE y no por un filtro sorpresa.
         */
        $configuracion = $this->comercio->online_configuration;
        $this->assertNotNull($configuracion, 'El comercio del slot tiene que tener online_configuration.');
        $this->assertTrue((bool) $configuracion->show_articles_without_images,
            'Este test asume show_articles_without_images = 1 en la base del slot.');

        $this->comprador = Buyer::create([
            'name'                    => 'Comprador Endpoint',
            'email'                   => 'endpoint-'.Str::random(10).'@test.local',
            'comercio_city_client_id' => self::CLIENT_ID,
            'user_id'                 => $this->comercio->id,
        ]);

        $this->limpiarOfertasDe($this->comercio->id);
    }

    protected function tearDown(): void
    {
        $this->limpiarOfertasDe($this->comercio->id);

        if (!empty($this->articulos_creados)) {
            DB::table('articles')->whereIn('id', $this->articulos_creados)->delete();
        }

        if (!is_null($this->comprador)) {
            DB::table('buyers')->where('id', $this->comprador->id)->delete();
        }

        ClientOfferHelper::olvidarMemoria();

        parent::tearDown();
    }

    /**
     * TEST R — sin sesion, 401.
     *
     * La oferta es de UN cliente del ERP y se resuelve por `buyers.comercio_city_client_id` de la
     * sesion: si la ruta se saliera de `auth:buyer`, quedaria una ruta publica devolviendo la
     * promocion personal de alguien.
     */
    public function test_r_sin_sesion_devuelve_401()
    {
        $this->getJson('/api/client-offers/'.$this->comercio->id)->assertStatus(401);
    }

    /** TEST S — con sesion y sin ofertas: 200 con la lista vacia, ni 404 ni 500. */
    public function test_s_con_sesion_y_sin_ofertas_devuelve_la_lista_vacia()
    {
        $this->actingAs($this->comprador, 'buyer')
            ->getJson('/api/client-offers/'.$this->comercio->id)
            ->assertStatus(200)
            ->assertExactJson(['articles' => []]);
    }

    /**
     * TEST T — con una oferta vigente vuelve el ARTICULO, con la oferta colgada.
     *
     * El endpoint devuelve articulos y no ofertas a proposito: es la misma forma que cualquier
     * listado de la tienda, asi que el SPA los muestra y navega a ellos con lo que ya tiene. Por
     * eso el precio tambien tiene que venir resuelto, igual que en cualquier otra pantalla.
     */
    public function test_t_con_una_oferta_vigente_devuelve_el_articulo_con_la_oferta_colgada()
    {
        $article_id = $this->crearArticulo(['final_price' => 1000]);

        $offer_id = $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $article_id,
            'porcentaje' => 15,
        ]);

        $respuesta = $this->actingAs($this->comprador, 'buyer')
            ->getJson('/api/client-offers/'.$this->comercio->id)
            ->assertStatus(200);

        $respuesta->assertJsonCount(1, 'articles');
        $respuesta->assertJsonPath('articles.0.id', $article_id);
        $respuesta->assertJsonPath('articles.0.oferta_personalizada.id', $offer_id);
        $respuesta->assertJsonPath('articles.0.oferta_personalizada.precio_aplicado', true);

        $articulo = $respuesta->json('articles.0');

        $this->assertSame(850.00, (float) $articulo['final_price'],
            'el precio viene YA descontado, igual que en cualquier otra pantalla');
        $this->assertSame(1000.00, (float) $articulo['precio_sin_oferta'],
            'y con la base al lado, para el tachado');
    }

    /**
     * TEST U — un articulo que la tienda no muestra NO aparece en el mensaje, aunque tenga oferta.
     *
     * Es el caso realista: la oferta la genero el ERP el lunes y el martes el comerciante dio de
     * baja el articulo o lo saco del catalogo online. Sin este filtro, el overlay le ofrece al
     * comprador algo que al clickear lo lleva a una ficha que no existe.
     *
     * Se prueban las dos formas de "no se muestra" que aplica `checkOnline()`, y en la misma
     * llamada que devuelve el que SI corresponde: asi el test no puede dar verde porque el
     * endpoint devolvio la lista vacia por cualquier otro motivo.
     */
    public function test_u_los_articulos_que_la_tienda_no_muestra_no_aparecen()
    {
        $visible  = $this->crearArticulo(['final_price' => 1000]);
        $apagado  = $this->crearArticulo(['final_price' => 1000, 'online' => 0]);
        $de_baja  = $this->crearArticulo(['final_price' => 1000, 'status' => 'inactive']);

        foreach ([$visible, $apagado, $de_baja] as $article_id) {
            $this->insertarOferta([
                'user_id'    => $this->comercio->id,
                'client_id'  => self::CLIENT_ID,
                'article_id' => $article_id,
                'porcentaje' => 15,
            ]);
        }

        $ids = $this->actingAs($this->comprador, 'buyer')
            ->getJson('/api/client-offers/'.$this->comercio->id)
            ->assertStatus(200)
            ->json('articles.*.id');

        $ids = is_array($ids) ? $ids : [];

        $this->assertContains($visible, $ids, 'el articulo publicado si tiene que aparecer');
        $this->assertNotContains($apagado, $ids, 'un articulo con online = 0 no se muestra en la tienda');
        $this->assertNotContains($de_baja, $ids, 'un articulo dado de baja no se muestra en la tienda');
    }

    /**
     * Crea un articulo del comercio y lo anota para borrarlo en el tearDown.
     *
     * Se crean articulos propios en vez de tocar los 46 que ya tiene la base del slot porque este
     * caso necesita apagarlos: mutar los sembrados dejaria la base distinta si el test se corta
     * por la mitad, y de ahi salen los verdes que despues nadie entiende.
     *
     * @param array $atributos
     * @return int
     */
    private function crearArticulo(array $atributos = [])
    {
        $articulo = Article::create(array_merge([
            'user_id'     => $this->comercio->id,
            'name'        => 'Articulo Oferta Test '.Str::random(8),
            'status'      => 'active',
            'online'      => 1,
            'stock'       => 10,
            'final_price' => 1000,
        ], $atributos));

        $this->articulos_creados[] = $articulo->id;

        return $articulo->id;
    }
}
