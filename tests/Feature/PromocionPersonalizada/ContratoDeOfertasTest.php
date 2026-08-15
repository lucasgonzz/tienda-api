<?php

namespace Tests\Feature\PromocionPersonalizada;

use App\Article;
use App\Buyer;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\ClientOfferHelper;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El contrato de lectura de `client_offers` / `client_offer_ranges` visto desde el embudo de
 * precios de la tienda (mision promocion-personalizada-tienda).
 *
 * ── Que modos de falla cubre esta clase ───────────────────────────────────────────────────────
 *
 *   - que la oferta de OTRO cliente del ERP, o la de OTRO comercio, se le muestre a este
 *     comprador: la tienda comparte base con el ERP, asi que las ofertas de todos los clientes
 *     de todos los comercios estan en la misma tabla y lo unico que las separa es el WHERE;
 *   - que una oferta pasada de fecha siga descontando porque `estado` quedo en 'activa' (el
 *     barrido de higiene de `empresa-api` puede no haber corrido);
 *   - que un comprador sin cliente del ERP, o un visitante anonimo, pague una query por pagina
 *     para responder que no tiene ofertas;
 *   - que el costo de la funcion crezca con el tamaño de la pagina, que es lo que pasaria si la
 *     guarda o las queries se metieran adentro del loop de articulos;
 *   - y que un dato roto del ERP (un porcentaje de 150, una oferta 'cantidad' sin tramos)
 *     termine cobrando un precio absurdo, porque esto esta en el camino de la plata.
 *
 * ⚠️ Por que NO usa DatabaseTransactions: el trait CreaElEsquemaDeOfertas hace CREATE TABLE, que
 * es DDL, y MySQL le hace commit implicito a la transaccion abierta. Se limpia a mano.
 *
 * ⚠️ Por que el comprador tiene `comercio_city_client_id` apuntando a un cliente que NO existe en
 * `clients`: no hay foreign key fisica (decision del esquema de `empresa-api`), la query del
 * contrato solo compara el numero, y ademas asi `checkPriceTypes()` no entra en su caso 3 —el de
 * la lista de precios del cliente del ERP— y el `final_price` que fija el test llega intacto al
 * enganche. Lo que se mide aca es la oferta, no la cascada de precios.
 */
class ContratoDeOfertasTest extends TestCase
{
    use CreaElEsquemaDeOfertas;

    /** El cliente del ERP del comprador de los casos. */
    const CLIENT_ID = 987654;

    /** Otro cliente del mismo comercio: el del test D. */
    const OTRO_CLIENT_ID = 123456;

    /** Precio base de los casos. Tiene decimales a proposito: fija el redondeo a 2. */
    const PRECIO_BASE = 1234.57;

    const SLUG_RANGOS = ClientOfferHelper::SLUG_RANGOS_POR_CANTIDAD_VENDIDA;

    /** @var \App\User */
    private $comercio;

    /** @var array Ids de articulos del comercio, para poder usar varios distintos. */
    private $article_ids = [];

    /** @var \App\Buyer|null Comprador CON cliente del ERP. */
    private $comprador = null;

    /** @var \App\Buyer|null Comprador SIN cliente del ERP (test I). */
    private $comprador_sin_cliente = null;

    /** @var int|null Fila de catalogo de la extension, si la creo este test. */
    private $extencion_creada = null;

    protected function setUp(): void
    {
        parent::setUp();

        ClientOfferHelper::olvidarMemoria();

        $this->crearEsquemaDeOfertasSiFalta();

        $this->comercio = User::first();
        $this->assertNotNull($this->comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        $this->article_ids = Article::where('user_id', $this->comercio->id)
                                    ->orderBy('id')
                                    ->pluck('id')
                                    ->all();

        $this->assertGreaterThanOrEqual(2, count($this->article_ids),
            'La base del slot tiene que tener al menos dos articulos del comercio.');

        $this->comprador = Buyer::create([
            'name'                    => 'Comprador Con Cliente ERP',
            'email'                   => 'con-cliente-'.Str::random(10).'@test.local',
            'comercio_city_client_id' => self::CLIENT_ID,
            'user_id'                 => $this->comercio->id,
        ]);

        $this->comprador_sin_cliente = Buyer::create([
            'name'                    => 'Comprador Sin Cliente ERP',
            'email'                   => 'sin-cliente-'.Str::random(10).'@test.local',
            'comercio_city_client_id' => null,
            'user_id'                 => $this->comercio->id,
        ]);

        /* Arranca limpio aunque una corrida anterior se haya cortado por la mitad. */
        $this->limpiarOfertasDe($this->comercio->id);
    }

    protected function tearDown(): void
    {
        $this->limpiarOfertasDe($this->comercio->id);

        DB::table('extencion_empresa_user')->where('user_id', $this->comercio->id)->delete();

        if (!is_null($this->extencion_creada)) {
            DB::table('extencion_empresas')->where('id', $this->extencion_creada)->delete();
        }

        foreach ([$this->comprador, $this->comprador_sin_cliente] as $comprador) {
            if (!is_null($comprador)) {
                DB::table('buyers')->where('id', $comprador->id)->delete();
            }
        }

        ClientOfferHelper::olvidarMemoria();

        parent::tearDown();
    }

    /**
     * TEST C — el camino feliz de 'unidad': el precio queda ya descontado y la base viaja aparte.
     *
     * El redondeo se prueba de verdad: 1234.57 * 0.85 = 1049.3845, que solo da 1049.38 si hay un
     * round a 2. Sin el round el SPA mostraria un precio con cuatro decimales y el carrito
     * cobraria un numero que no es el que se mostro.
     */
    public function test_c_una_oferta_por_unidad_vigente_descuenta_el_precio()
    {
        $article_id = $this->article_ids[0];

        $offer_id = $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $article_id,
            'porcentaje' => 15,
        ]);

        $articulo = $this->aplicarSobre($this->comprador, [$this->articulo($article_id)])[0];

        $this->assertSame(1049.38, (float) $articulo->final_price,
            'final_price tiene que venir YA descontado y redondeado a 2 decimales');
        $this->assertSame(self::PRECIO_BASE, (float) $articulo->precio_sin_oferta,
            'precio_sin_oferta es la base para el tachado: el precio de antes');

        $oferta = $articulo->oferta_personalizada;

        $this->assertIsArray($oferta, 'oferta_personalizada es un array asociativo de PHP');
        $this->assertSame($offer_id, $oferta['id']);
        $this->assertSame(ClientOfferHelper::TIPO_UNIDAD, $oferta['tipo_descuento']);
        $this->assertSame(15.0, $oferta['porcentaje']);
        $this->assertTrue($oferta['precio_aplicado']);
        $this->assertSame([], $oferta['rangos'], "una oferta 'unidad' no tiene tramos");
    }

    /**
     * TEST D — la oferta de OTRO cliente del ERP no se ve.
     *
     * 🔴 Es la separacion mas importante de la mision. En la base compartida con el ERP conviven
     * las ofertas de TODOS los clientes del comercio, y lo unico que las separa es el filtro por
     * `client_id`, que sale de `buyers.comercio_city_client_id` de la SESION. Si se cayera, cada
     * comprador veria —y pagaria— el descuento negociado con otra persona.
     */
    public function test_d_la_oferta_de_otro_cliente_del_erp_no_se_aplica()
    {
        $article_id = $this->article_ids[0];

        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::OTRO_CLIENT_ID,
            'article_id' => $article_id,
            'porcentaje' => 40,
        ]);

        $this->assertSinOferta($this->aplicarSobre($this->comprador, [$this->articulo($article_id)])[0]);
    }

    /**
     * TEST E — la oferta de OTRO comercio tampoco.
     *
     * El mismo `client_id`, pero cargada a nombre de otro `user_id`. Sin el filtro por comercio,
     * un cliente que le compra a dos comercios de la misma instalacion se llevaria el descuento
     * del otro.
     */
    public function test_e_la_oferta_de_otro_comercio_no_se_aplica()
    {
        $article_id = $this->article_ids[0];

        $this->insertarOferta([
            'user_id'    => $this->comercio->id + 99999,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $article_id,
            'porcentaje' => 40,
        ]);

        $this->assertSinOferta($this->aplicarSobre($this->comprador, [$this->articulo($article_id)])[0]);
    }

    /**
     * TEST F — vencida por fecha con `estado = 'activa'`: NO aplica.
     *
     * 🔴 El contrato lo dice textual: la verdad de la vigencia la dan las FECHAS, no `estado`.
     * `estado` es el gesto del comerciante y la marca del barrido de higiene de `empresa-api`, y
     * ese barrido puede no haber corrido —o puede fallar— sin que la tienda se entere. Si la
     * tienda mirara solo `estado`, una promocion de la semana pasada seguiria descontando.
     */
    public function test_f_una_oferta_pasada_de_fecha_no_se_aplica_aunque_este_activa()
    {
        $article_id = $this->article_ids[0];

        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $article_id,
            'porcentaje' => 40,
            'desde'      => Carbon::today()->subDays(30)->toDateString(),
            'hasta'      => Carbon::yesterday()->toDateString(),
            'estado'     => ClientOfferHelper::ESTADO_ACTIVA,
        ]);

        $this->assertSinOferta($this->aplicarSobre($this->comprador, [$this->articulo($article_id)])[0]);
    }

    /** TEST G — todavia no arranco: `desde` es mañana. */
    public function test_g_una_oferta_que_todavia_no_arranco_no_se_aplica()
    {
        $article_id = $this->article_ids[0];

        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $article_id,
            'porcentaje' => 40,
            'desde'      => Carbon::tomorrow()->toDateString(),
            'hasta'      => Carbon::today()->addDays(30)->toDateString(),
        ]);

        $this->assertSinOferta($this->aplicarSobre($this->comprador, [$this->articulo($article_id)])[0]);
    }

    /** TEST H — cancelada por el comerciante, dentro de fechas: tampoco. */
    public function test_h_una_oferta_cancelada_no_se_aplica_aunque_este_en_fecha()
    {
        $article_id = $this->article_ids[0];

        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $article_id,
            'porcentaje' => 40,
            'estado'     => 'cancelada',
        ]);

        $this->assertSinOferta($this->aplicarSobre($this->comprador, [$this->articulo($article_id)])[0]);
    }

    /**
     * TEST I — comprador sin cliente del ERP: no se aplica NI SE CONSULTA.
     *
     * La segunda mitad es la que importa y es de costo, no de correccion: es el caso FRECUENTE
     * —la mayoria de los compradores de una tienda no tienen ficha en el ERP— y corre en cada
     * pagina que muestre un precio. Si la guarda estuviera despues de la query en vez de antes,
     * la tienda pagaria una consulta por pagina de cada visitante para responder que no.
     */
    public function test_i_un_comprador_sin_cliente_del_erp_no_dispara_ninguna_query()
    {
        $article_id = $this->article_ids[0];

        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $article_id,
        ]);

        $articulos = null;

        $queries = $this->queriesDeOfertasDurante(function () use ($article_id, &$articulos) {
            $articulos = $this->aplicarSobre($this->comprador_sin_cliente, [$this->articulo($article_id)]);
        });

        $this->assertSinOferta($articulos[0]);
        $this->assertSame([], $queries,
            'sin cliente del ERP no se puede tocar client_offers: '.implode(' | ', $queries));
    }

    /**
     * TEST J — visitante anonimo: idem, y cortado un escalon antes todavia.
     *
     * El anonimo nunca ve una oferta personalizada: es intencional y esta en el contrato.
     */
    public function test_j_un_visitante_anonimo_no_dispara_ninguna_query()
    {
        $article_id = $this->article_ids[0];

        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $article_id,
        ]);

        $articulos = null;

        $queries = $this->queriesDeOfertasDurante(function () use ($article_id, &$articulos) {
            $articulos = ArticleHelper::checkPriceTypes([$this->articulo($article_id)]);
        });

        $this->assertSinOferta($articulos[0]);
        $this->assertSame([], $queries,
            'un visitante anonimo no puede tocar client_offers: '.implode(' | ', $queries));
    }

    /**
     * TEST K — 'cantidad': el precio NO se toca y los tramos viajan ordenados.
     *
     * El servidor no conoce la cantidad en un listado, asi que `final_price` queda como estaba y
     * el descuento lo resuelve el tramo: en la ficha lo calcula el SPA y en el carrito lo vuelve
     * a calcular el servidor (ver PrecioDelCarritoTest).
     *
     * Las tres aserciones que fijan el contrato: el orden por `min` (los tramos se insertan
     * desordenados a proposito), el `max` null del ultimo —SIN TECHO, y convertirlo a un numero
     * grande rompe la lectura del SPA— y el `porcentaje` de la oferta en null, que esta asi para
     * que nadie lo lea por accidente y aplique el descuento equivocado.
     */
    public function test_k_una_oferta_por_cantidad_no_toca_el_precio_y_manda_los_tramos()
    {
        $article_id = $this->article_ids[0];

        $offer_id = $this->insertarOferta([
            'user_id'        => $this->comercio->id,
            'client_id'      => self::CLIENT_ID,
            'article_id'     => $article_id,
            'tipo_descuento' => ClientOfferHelper::TIPO_CANTIDAD,
            'porcentaje'     => null,
        ]);

        /* Desordenados: si se insertaran ordenados, el test probaria el orden de insercion. */
        $this->insertarRangos($offer_id, [
            [12, null, 18],
            [1, 5, 5],
            [6, 11, 10],
        ]);

        $articulo = $this->aplicarSobre($this->comprador, [$this->articulo($article_id)])[0];

        $this->assertSame(self::PRECIO_BASE, (float) $articulo->final_price,
            "con tipo 'cantidad' el servidor no toca final_price: no conoce la cantidad");
        $this->assertSame(self::PRECIO_BASE, (float) $articulo->precio_sin_oferta,
            'precio_sin_oferta lleva la misma cifra, para que el SPA tenga la base en un solo nombre');

        $oferta = $articulo->oferta_personalizada;

        $this->assertSame(ClientOfferHelper::TIPO_CANTIDAD, $oferta['tipo_descuento']);
        $this->assertNull($oferta['porcentaje'], "con 'cantidad' el porcentaje vive en los tramos");
        $this->assertTrue($oferta['precio_aplicado']);

        $this->assertSame([1, 6, 12], array_column($oferta['rangos'], 'min'),
            'los tramos tienen que venir ordenados por min ASC');
        $this->assertSame([5, 11, null], array_column($oferta['rangos'], 'max'),
            'el max del ultimo tramo es NULL = sin techo');
        $this->assertSame([5.0, 10.0, 18.0], array_column($oferta['rangos'], 'porcentaje'));
    }

    /**
     * TEST L — el costo NO crece con el tamaño de la pagina.
     *
     * 🔴 Este es el test que fija la decision de diseño: la guarda `Schema::hasTable` y las dos
     * queries van ANTES del loop de articulos y nunca adentro. Metidas adentro, la funcion
     * seguiria dando el mismo resultado —todos los otros casos de esta clase seguirian verdes— y
     * lo unico que cambiaria es que cada pagina de la tienda pasaria de 2 queries a 2 por
     * articulo. Un listado de 46 articulos son 92 consultas de mas por visita.
     *
     * Se mide con DB::listen contra `client_offers` / `client_offer_ranges`: 1 articulo y N
     * articulos tienen que dar exactamente la misma cuenta.
     */
    public function test_l_la_cantidad_de_queries_no_crece_con_la_cantidad_de_articulos()
    {
        $article_id = $this->article_ids[0];

        $offer_id = $this->insertarOferta([
            'user_id'        => $this->comercio->id,
            'client_id'      => self::CLIENT_ID,
            'article_id'     => $article_id,
            'tipo_descuento' => ClientOfferHelper::TIPO_CANTIDAD,
            'porcentaje'     => null,
        ]);

        $this->insertarRangos($offer_id, [[1, null, 10]]);

        /* Los articulos se arman ANTES de medir, en los dos casos: lo que se mide es lo que hace
           el helper, no los Article::find del fixture. */
        $uno = [$this->articulo($article_id)];

        $con_uno = $this->queriesDeOfertasDurante(function () use ($uno) {
            $this->aplicarSobre($this->comprador, $uno);
        });

        /* Todos los articulos del comercio: 46 en la base del slot, medidos el 15/8/2026. El plan
           hablaba de 50; lo que la afirmacion necesita es "muchos contra uno", no un numero. */
        $muchos = [];
        foreach ($this->article_ids as $id) {
            $muchos[] = $this->articulo($id);
        }

        $this->assertGreaterThanOrEqual(20, count($muchos),
            'con pocos articulos este test no probaria nada');

        $con_muchos = $this->queriesDeOfertasDurante(function () use ($muchos) {
            $this->aplicarSobre($this->comprador, $muchos);
        });

        $this->assertSame(2, count($con_uno),
            'con una oferta de tipo cantidad son exactamente 2 queries: ofertas y tramos');

        $this->assertSame(count($con_uno), count($con_muchos),
            'la cantidad de queries no puede depender de cuantos articulos tenga la pagina. '
            .'Con 1: '.count($con_uno).'. Con '.count($muchos).': '.count($con_muchos));
    }

    /**
     * Un porcentaje fuera de (0, 100] es basura del ERP y NO puede tocar el precio.
     *
     * La columna es decimal(6,2): admite hasta 9999.99. Un 150 daria un precio negativo y un 0 un
     * descuento nulo — y esto esta en el camino de la plata, asi que la oferta se muestra pero no
     * se anuncia como aplicada.
     */
    public function test_un_porcentaje_fuera_de_rango_no_toca_el_precio()
    {
        $article_id = $this->article_ids[0];

        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $article_id,
            'porcentaje' => 150,
        ]);

        $articulo = $this->aplicarSobre($this->comprador, [$this->articulo($article_id)])[0];

        $this->assertSame(self::PRECIO_BASE, (float) $articulo->final_price,
            'un porcentaje de 150 daria un precio negativo: no se aplica');
        $this->assertArrayNotHasKey('precio_sin_oferta', $articulo->getAttributes(),
            'sin descuento aplicado no hay nada que tachar');
        $this->assertFalse($articulo->oferta_personalizada['precio_aplicado']);
    }

    /**
     * Una oferta 'cantidad' sin tramos cargados se muestra pero no se anuncia como aplicada.
     *
     * Es un dato a medio escribir del ERP (la oferta se inserto y los tramos no). Sin esta
     * guarda el SPA mostraria "llevá más y pagás menos" sin un solo tramo que mostrar.
     */
    public function test_una_oferta_por_cantidad_sin_tramos_no_se_anuncia_como_aplicada()
    {
        $article_id = $this->article_ids[0];

        $this->insertarOferta([
            'user_id'        => $this->comercio->id,
            'client_id'      => self::CLIENT_ID,
            'article_id'     => $article_id,
            'tipo_descuento' => ClientOfferHelper::TIPO_CANTIDAD,
            'porcentaje'     => null,
        ]);

        $articulo = $this->aplicarSobre($this->comprador, [$this->articulo($article_id)])[0];

        $this->assertSame(self::PRECIO_BASE, (float) $articulo->final_price);
        $this->assertSame([], $articulo->oferta_personalizada['rangos']);
        $this->assertFalse($articulo->oferta_personalizada['precio_aplicado']);
        $this->assertArrayNotHasKey('precio_sin_oferta', $articulo->getAttributes());
    }

    /**
     * 🔴 Con la extension `lista_de_precios_por_rango_de_cantidad_vendida` prendida, la oferta se
     * MUESTRA pero no toca ningun precio. Es una limitacion deliberada, no un olvido.
     *
     * Esa extension ya pisa el precio por dos lados: en el servidor `checkPriceTypes()` entra en
     * `set_ranges()` y ni siquiera setea `final_price`, y en el SPA `articlePriceEfectivo()`
     * ignora `final_price` y usa `article.ranges[].price`. Componer los dos mecanismos sin
     * haberlo medido es cambiar precios en tiendas ya publicadas.
     *
     * Este test existe para que la limitacion no se "arregle" por distraccion: si alguien
     * hiciera que la oferta tambien descontara acá, se pone rojo y obliga a leer el porque.
     */
    public function test_con_la_extension_de_rangos_por_cantidad_vendida_no_se_toca_el_precio()
    {
        $article_id = $this->article_ids[0];

        $this->prenderLaExtencionDeRangos();

        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $article_id,
            'porcentaje' => 15,
        ]);

        $articulo = $this->aplicarSobre($this->comprador, [$this->articulo($article_id)])[0];

        $this->assertSame(self::PRECIO_BASE, (float) $articulo->final_price,
            'con esa extension prendida la oferta no puede tocar el precio');
        $this->assertArrayNotHasKey('precio_sin_oferta', $articulo->getAttributes());

        $oferta = $articulo->oferta_personalizada;

        $this->assertIsArray($oferta, 'la oferta igual se muestra: el comerciante la creo a proposito');
        $this->assertFalse($oferta['precio_aplicado'],
            'precio_aplicado = false es como el SPA sabe que no hay tachado que mostrar');
    }

    /**
     * Un articulo con el precio pausado no se toca: no hay importe que descontar.
     *
     * El SPA muestra el texto de configuracion en lugar del precio y bloquea el carrito; ese
     * flujo ya esta resuelto y esta mision no lo toca.
     */
    public function test_un_articulo_con_precio_pausado_no_se_toca()
    {
        $article_id = $this->article_ids[0];

        $this->insertarOferta([
            'user_id'    => $this->comercio->id,
            'client_id'  => self::CLIENT_ID,
            'article_id' => $article_id,
            'porcentaje' => 15,
        ]);

        $articulo = $this->articulo($article_id);
        $articulo->precio_pausado = 1;

        $resultado = $this->aplicarSobre($this->comprador, [$articulo])[0];

        $this->assertSame(self::PRECIO_BASE, (float) $resultado->final_price);
        $this->assertArrayNotHasKey('precio_sin_oferta', $resultado->getAttributes());
        $this->assertFalse($resultado->oferta_personalizada['precio_aplicado']);
    }

    /**
     * Corre el embudo de precios como el comprador dado.
     *
     * @param \App\Buyer $comprador
     * @param array $articulos
     * @return array
     */
    private function aplicarSobre($comprador, $articulos)
    {
        $this->actingAs($comprador, 'buyer');

        return ArticleHelper::checkPriceTypes($articulos);
    }

    /**
     * Una copia limpia de un articulo real del comercio, con el precio base fijado a mano.
     *
     * Se usa una fila REAL para que `checkPriceTypes()` recorra lo mismo que en produccion, y el
     * precio se fija a mano porque es el numero contra el que se mide el descuento.
     *
     * @param int $article_id
     * @return \App\Article
     */
    private function articulo($article_id)
    {
        $articulo = Article::find($article_id);
        $articulo->final_price = self::PRECIO_BASE;

        return $articulo;
    }

    /**
     * El articulo volvio intacto: sin oferta colgada, sin tachado y con el mismo precio.
     *
     * @param \App\Article $articulo
     * @return void
     */
    private function assertSinOferta($articulo)
    {
        $atributos = $articulo->getAttributes();

        $this->assertArrayNotHasKey('oferta_personalizada', $atributos,
            'no corresponde ninguna oferta para este comprador');
        $this->assertArrayNotHasKey('precio_sin_oferta', $atributos);
        $this->assertSame(self::PRECIO_BASE, (float) $articulo->final_price,
            'el precio no se puede tocar cuando no hay oferta que aplique');
    }

    /**
     * Las queries a las tablas del contrato que dispara la accion.
     *
     * Se filtra por el nombre de las tablas en el SQL y se descarta information_schema: el
     * `Schema::hasTable` de la guarda 5 pasa el nombre de la tabla como BINDING, no en el texto
     * de la consulta, asi que no se cuela por el filtro. Lo que queda son las dos queries del
     * contrato y nada mas.
     *
     * ⚠️ El listener NO se puede desregistrar en Laravel, asi que lleva su propio interruptor: sin
     * el, la segunda medicion del test L le seguiria sumando queries al array de la primera y las
     * dos cuentas terminarian iguales por construccion — un test que no puede fallar.
     *
     * @param callable $accion
     * @return array Los SQL, para poder mostrarlos en el mensaje de la asercion.
     */
    private function queriesDeOfertasDurante(callable $accion)
    {
        $queries  = [];
        $midiendo = true;

        DB::listen(function ($query) use (&$queries, &$midiendo) {
            if (!$midiendo) {
                return;
            }

            if (
                strpos($query->sql, 'client_offer') !== false
                && strpos($query->sql, 'information_schema') === false
            ) {
                $queries[] = $query->sql;
            }
        });

        $accion();

        $midiendo = false;

        return $queries;
    }

    /**
     * Prende para el comercio la extension que ya pisa el precio con sus propios rangos,
     * creando la fila de catalogo si no estaba (y anotandola para borrarla en el tearDown, que
     * esta clase no tiene transaccion que la revierta).
     *
     * @return void
     */
    private function prenderLaExtencionDeRangos()
    {
        $extencion_id = DB::table('extencion_empresas')->where('slug', self::SLUG_RANGOS)->value('id');

        if (is_null($extencion_id)) {
            $extencion_id = DB::table('extencion_empresas')->insertGetId([
                'name'       => 'Lista de precios por rango de cantidad vendida',
                'slug'       => self::SLUG_RANGOS,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $this->extencion_creada = $extencion_id;
        }

        DB::table('extencion_empresa_user')->insert([
            'extencion_empresa_id' => $extencion_id,
            'user_id'              => $this->comercio->id,
            'created_at'           => Carbon::now(),
            'updated_at'           => Carbon::now(),
        ]);
    }
}
