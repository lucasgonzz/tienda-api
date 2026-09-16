<?php

namespace Tests\Feature\CombosYRangos;

use App\Http\Controllers\Helpers\ArticlePriceRangeHelper;
use Tests\TestCase;

/**
 * Los CUATRO CRITERIOS de `ArticlePriceRangeHelper` — el matcheo de los tramos de precio por
 * cantidad (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * ── Por que esto merece una clase entera y no dos asserts sueltos ─────────────────────────────
 *
 * Este precio se decide DOS VECES: en el navegador para MOSTRARLO y en este helper para COBRARLO.
 * Los criterios estan alineados a proposito con dos gemelos —`empresa-spa/src/mixins/vender/
 * article_price_range.js` (el del ERP) y el espejo de `tienda-spa/src/mixins/generals.js`— y si
 * difieren en un solo borde, el comprador ve un numero en la pantalla y le cobran otro. Es la
 * clase de error que ya esta documentada en `APRENDER_NO_PARCHEAR.md:1026` ("el mismo invariante
 * decidido con dos criterios distintos en front y back").
 *
 * O sea que estos cuatro criterios NO son detalles de implementacion que alguien pueda "mejorar"
 * de a uno: son un contrato de tres puntas. Cada caso de esta clase es un borde de ese contrato,
 * y ponerse rojo es exactamente lo que tiene que pasar si alguien lo mueve de un lado solo.
 *
 * ⚠️ Sin base: `precio()` y `rango()` reciben arrays y son puras. La unica parte del helper que
 * toca la base es `precio_de_articulo()`, y esa se ejercita de punta a punta —por el endpoint del
 * carrito— en `PrecioDelCarritoPorCantidadTest`.
 */
class MatcheoDeTramosTest extends TestCase
{
    /** El precio de lista del articulo con el que se razona en toda la mision. */
    const PRECIO_NORMAL = 3948.00;

    /**
     * Un tramo tal como lo devuelve la base (array asociativo; el helper tambien acepta modelos
     * y stdClass, y eso lo cubre el ultimo caso).
     *
     * @param  string  $modo
     * @param  mixed  $amount
     * @param  mixed  $price
     * @return array
     */
    private function tramo($modo, $amount, $price)
    {
        return ['modo' => $modo, 'amount' => $amount, 'price' => $price];
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Criterio 1 — 'Mayor o igual' es >=, 'Igual' es igualdad estricta
    |---------------------------------------------------------------------------------------------
    */

    /**
     * `'Mayor o igual'` matchea DESDE el borde, no despues: con `amount = 10`, la cantidad 10 ya
     * cobra el tramo.
     *
     * El borde exacto es la mitad del criterio: un `>` en vez de `>=` en cualquiera de las tres
     * puntas le cobra de mas justo al comprador que compro la cantidad que el cartel anuncia.
     */
    public function test_mayor_o_igual_matchea_desde_el_borde_exacto()
    {
        $tramos = [$this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, 3000)];

        $this->assertNull(ArticlePriceRangeHelper::precio($tramos, 9),
            'con 9 unidades el tramo de 10 todavia no corresponde');

        $this->assertSame(3000.0, ArticlePriceRangeHelper::precio($tramos, 10),
            'con la cantidad exacta del tramo ya corresponde: el criterio es >=, no >');

        $this->assertSame(3000.0, ArticlePriceRangeHelper::precio($tramos, 25),
            'y por encima del borde tambien');
    }

    /**
     * `'Igual'` es igualdad estricta: ni uno menos ni uno mas.
     *
     * Es el modo que usa el comerciante para un pack armado ("llevando exactamente 3"), y
     * confundirlo con un `>=` regalaria ese precio a cualquier cantidad mayor.
     */
    public function test_igual_solo_matchea_la_cantidad_exacta()
    {
        $tramos = [$this->tramo(ArticlePriceRangeHelper::MODO_IGUAL, 3, 3700)];

        $this->assertNull(ArticlePriceRangeHelper::precio($tramos, 2));
        $this->assertSame(3700.0, ArticlePriceRangeHelper::precio($tramos, 3));
        $this->assertNull(ArticlePriceRangeHelper::precio($tramos, 4),
            'Igual no es Mayor o igual: con 4 unidades el tramo de 3 no aplica');
    }

    /** Y `3` contra `3.00` (que es como sale el decimal(10,2) de la base) es el mismo numero. */
    public function test_igual_compara_numeros_y_no_cadenas()
    {
        $tramos = [$this->tramo(ArticlePriceRangeHelper::MODO_IGUAL, '3.00', '3700.00')];

        $this->assertSame(3700.0, ArticlePriceRangeHelper::precio($tramos, 3),
            'amount y cantidad llegan como cadenas decimales desde MySQL: la comparacion es numerica');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Criterio 2 — cualquier otro modo NO matchea
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 Un `modo` que no es exactamente una de las dos cadenas conocidas NO matchea nunca.
     *
     * Nada de default permisivo: un modo que nadie escribio todavia no puede empezar a descontar
     * plata solo. Y la comparacion es sensible a mayusculas a proposito —el ABM del ERP guarda
     * `'Mayor o igual'` con esa capitalizacion exacta— porque aflojarla de un lado y no de los
     * otros dos es, otra vez, pantalla y servidor diciendo numeros distintos.
     *
     * ⚠️ `'Menor o igual'` no es un invento del test: la base del slot tiene una fila con ese
     * modo. Si alguien lo "agrega" a este helper sin agregarlo a los dos gemelos, este caso se
     * pone rojo, que es lo que se quiere.
     */
    public function test_un_modo_desconocido_no_matchea_nunca()
    {
        $desconocidos = [
            'mayor o igual',
            'MAYOR O IGUAL',
            'Mayor O Igual',
            'igual',
            'Menor o igual',
            'Mayor',
            '>=',
            '',
        ];

        foreach ($desconocidos as $modo) {
            $tramos = [$this->tramo($modo, 1, 1.00)];

            $this->assertNull(ArticlePriceRangeHelper::precio($tramos, 50),
                'el modo "'.$modo.'" no puede cobrar nada: no es ninguno de los dos que existen');
        }
    }

    /** Un tramo sin `amount` numerico se saltea sin tumbar al resto de la lista. */
    public function test_un_tramo_con_cantidad_invalida_se_saltea_y_los_demas_siguen_jugando()
    {
        $tramos = [
            $this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, null, 1.00),
            $this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 'diez', 2.00),
            $this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 5, 3500),
        ];

        $this->assertSame(3500.0, ArticlePriceRangeHelper::precio($tramos, 10));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Criterio 3 — gana el de mayor cantidad; ante empate, el PRIMERO del array
    |---------------------------------------------------------------------------------------------
    */

    /** Entre los que matchean gana el de mayor `amount`, venga en el orden que venga. */
    public function test_entre_los_que_matchean_gana_el_de_mayor_cantidad()
    {
        $tramos = [
            $this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 5, 3500),
            $this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, 3000),
        ];

        $this->assertSame(3000.0, ArticlePriceRangeHelper::precio($tramos, 12));

        /* Y al reves en el array: el ganador no depende del orden, salvo en el empate. */
        $this->assertSame(3000.0, ArticlePriceRangeHelper::precio(array_reverse($tramos), 12));
    }

    /**
     * 🔴 EMPATE DE `amount`: gana EL PRIMERO DEL ARRAY.
     *
     * No es una arbitrariedad de este lado: el gemelo del SPA resuelve el ganador con un `reduce`
     * que usa `>` estricto, o sea que ante empate se queda con el acumulador — el primero. Si acá
     * se usara `>=`, ganaria el ultimo y los dos lados cobrarian distinto con el mismo dato.
     *
     * Y el dato existe: la base del slot tiene DOS tramos `Mayor o igual 10` para el mismo
     * articulo, uno a $3.000 y otro a $2.500. El ABM del ERP no lo impide.
     *
     * ⚠️ Por eso `precargar()` ordena por `id ASC` y la relacion `Article::article_price_ranges()`
     * tambien: "el primero del array" solo significa lo mismo en los tres lados si las tres listas
     * vienen en el mismo orden.
     */
    public function test_ante_empate_de_cantidad_gana_el_primero_del_array()
    {
        $tramos = [
            $this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, 3000),
            $this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, 2500),
        ];

        $this->assertSame(3000.0, ArticlePriceRangeHelper::precio($tramos, 15),
            'con dos tramos de la misma cantidad gana el primero, igual que el reduce del gemelo');

        $this->assertSame(2500.0, ArticlePriceRangeHelper::precio(array_reverse($tramos), 15),
            'y dado vuelta el array gana el otro: lo que decide el empate es la posicion, no el precio');
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Criterio 4 — el descarte por `price` va SOBRE EL GANADOR, nunca antes de elegir
    |---------------------------------------------------------------------------------------------
    */

    /**
     * 🔴 EL CRITERIO QUE MAS IMPORTA, Y EL UNICO QUE YA SE MIDIO ROTO.
     *
     * El 16/9/2026 se midio esto: un articulo de $3.948 con dos tramos —`>=10` a $3.000 y `>=20`
     * con `price` NULL— y un comprador llevando 25 unidades.
     *
     *   - Filtrando los `price` nulos ANTES de elegir: el unico candidato que queda es el de 10,
     *     y la pantalla mostraba $3.000.
     *   - Eligiendo primero y mirando el `price` DESPUES (que es lo que hace el gemelo, porque
     *     `Number(null) === 0` es falsy y su `reduce` ya se quedo con el de 20): gana el tramo de
     *     20, se queda sin precio usable, y la linea cae al precio NORMAL de $3.948.
     *
     * O sea: el comprador veia $3.000 y el servidor le cobraba $3.948. El orden de los criterios 3
     * y 4 no es un detalle de estilo — es la diferencia entre mostrar un precio y cobrar otro.
     *
     * Por eso el helper tiene `rango()` separado de `precio()`: para que el orden quede explicito
     * y no se pueda invertir sin querer. Este caso lo clava por las dos puntas — quien gana, y que
     * el ganador sin precio NO le deja el lugar al segundo.
     */
    public function test_el_ganador_sin_precio_usable_no_deja_competir_al_de_abajo_y_cae_al_precio_normal()
    {
        $tramos = [
            $this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, 3000),
            $this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 20, null),
        ];

        /* Primero: quien gana. Con 25 unidades el ganador es el de 20, el del price nulo. */
        $ganador = ArticlePriceRangeHelper::rango($tramos, 25);

        $this->assertNotNull($ganador, 'con 25 unidades los dos tramos matchean, tiene que haber ganador');
        $this->assertEquals(20, $ganador['amount'],
            'gana el de MAYOR cantidad, y se elige antes de mirarle el precio');

        /* Y despues: ese ganador no tiene precio usable, asi que no hay precio de tramo. El
           segundo NO hereda el lugar. */
        $this->assertNull(ArticlePriceRangeHelper::precio($tramos, 25),
            'el tramo de 20 gana y se queda sin precio: la linea cae al precio normal, NO a los $3.000 del de 10');

        /* La contraprueba del mismo dato: por debajo de 20 el tramo de 10 si cobra. Sin esto, un
           helper que devolviera null siempre daria verde arriba sin probar nada. */
        $this->assertSame(3000.0, ArticlePriceRangeHelper::precio($tramos, 15),
            'con 15 unidades el ganador es el de 10, que si tiene precio');
    }

    /** Cero tampoco es un precio usable: `Number(0)` es falsy en el gemelo, igual que `null`. */
    public function test_un_tramo_con_precio_cero_no_aplica()
    {
        $tramos = [$this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, 0)];

        $this->assertNull(ArticlePriceRangeHelper::precio($tramos, 12),
            'un tramo a $0 no regala el articulo: no aplica y se cobra el precio normal');
    }

    /**
     * Y negativo menos todavia.
     *
     * Un precio negativo es un dato imposible de cargar con sentido, pero si llegara a existir,
     * aceptarlo seria cobrar plata al reves — y el gemelo del SPA ya lo descarta. Los dos
     * descartan, y descartan hacia el mismo lado.
     */
    public function test_un_tramo_con_precio_negativo_no_aplica()
    {
        $tramos = [$this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, -500)];

        $this->assertNull(ArticlePriceRangeHelper::precio($tramos, 12));
    }

    /** Un `price` que no es numero se descarta igual que el nulo. */
    public function test_un_tramo_con_precio_no_numerico_no_aplica()
    {
        $tramos = [$this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, 'gratis')];

        $this->assertNull(ArticlePriceRangeHelper::precio($tramos, 12));
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Bordes de entrada: nada de esto puede tumbar el carrito
    |---------------------------------------------------------------------------------------------
    */

    /** Sin tramos no hay precio de tramo, y no importa como venga el vacio. */
    public function test_sin_tramos_no_hay_precio_de_tramo()
    {
        $this->assertNull(ArticlePriceRangeHelper::precio([], 10));
        $this->assertNull(ArticlePriceRangeHelper::precio(null, 10));
        $this->assertNull(ArticlePriceRangeHelper::precio(collect([]), 10));
    }

    /** Una cantidad que no es numero tampoco: el carrito sigue al precio normal. */
    public function test_una_cantidad_no_numerica_no_matchea_ningun_tramo()
    {
        $tramos = [$this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 1, 3000)];

        $this->assertNull(ArticlePriceRangeHelper::precio($tramos, null));
        $this->assertNull(ArticlePriceRangeHelper::precio($tramos, ''));
        $this->assertNull(ArticlePriceRangeHelper::precio($tramos, 'tres'));
    }

    /**
     * Los tres transportes que llegan a este helper dan el mismo resultado: array asociativo (la
     * base cruda), objeto (el payload del SPA decodificado) y coleccion de Eloquent (la relacion).
     *
     * `CartHelper::get_price()` y `resincronizar_precios_por_rango()` lo llaman por caminos
     * distintos y con formas distintas; que una sola de las tres se resuelva mal seria, de nuevo,
     * dos precios para el mismo dato.
     */
    public function test_los_tres_transportes_dan_el_mismo_precio()
    {
        $como_array = [$this->tramo(ArticlePriceRangeHelper::MODO_MAYOR_O_IGUAL, 10, 3000)];

        $como_objeto = [(object) $como_array[0]];

        $como_coleccion = collect([(object) $como_array[0]]);

        $this->assertSame(3000.0, ArticlePriceRangeHelper::precio($como_array, 12));
        $this->assertSame(3000.0, ArticlePriceRangeHelper::precio($como_objeto, 12));
        $this->assertSame(3000.0, ArticlePriceRangeHelper::precio($como_coleccion, 12));
    }
}
