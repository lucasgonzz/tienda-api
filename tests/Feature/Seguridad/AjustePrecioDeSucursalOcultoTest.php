<?php

namespace Tests\Feature\Seguridad;

use App\Address;
use App\Buyer;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El recargo/descuento por sucursal es una politica interna del ERP y no sale en ninguna respuesta.
 *
 * ── Que se vigila ─────────────────────────────────────────────────────────────────────────────
 *
 * `empresa-api` le agrega dos columnas a la tabla `addresses` (que esta tienda comparte):
 * `ajuste_precio_tipo` ('recargo' | 'descuento' | NULL) y `ajuste_precio_porcentaje`. Es el % que el
 * ERP le suma o resta al precio cuando se vende desde esa sucursal: un dato comercial del dueño que
 * la tienda ni lee ni escribe. Pero `GET /api/commerce/{id}` es publica (sin auth) y serializa la
 * relacion `addresses` entera, y `Buyer::withAll()` hace lo mismo con las del comprador. Sin
 * `App\Address::$hidden`, cualquier visitante las leeria.
 *
 * ── Por que NO depende de que las columnas existan en la base ─────────────────────────────────
 *
 * `tienda-api` no tiene database/migrations: el esquema lo gobierna `empresa-api`, asi que la base
 * del slot (`tienda_testing_s5`) puede no tener todavia estas dos columnas. Agregarlas desde un
 * test con ALTER TABLE seria mala idea: el DDL hace commit implicito en MySQL, o sea que rompe el
 * rollback de DatabaseTransactions y deja la base cambiada para el resto de la suite.
 *
 * En cambio se SIMULA una base que las tiene: un listener del evento `retrieved` de Address les
 * inyecta los dos atributos cada vez que Eloquent hidrata una sucursal, exactamente como lo haria
 * un SELECT * sobre una tabla con las columnas. Asi el endpoint real (rutas, controller, proyeccion,
 * JSON) se prueba de punta a punta sin tocar el esquema. El listener vive en el dispatcher de la
 * aplicacion de cada test (TestCase la reconstruye en cada setUp), asi que no se filtra a otros.
 *
 * Si la base ya tiene las columnas de verdad (cuando se aplique la migracion de empresa-api al
 * slot), el listener simplemente pisa el valor del registro y todo sigue valiendo igual.
 *
 * ⚠️ Cada test incluye un CONTROL (`makeVisible`) que demuestra que la simulacion de verdad
 * inyecta los atributos: sin eso, un test que solo mira que "no aparezca algo" seria verde aunque
 * la simulacion estuviera rota. Si se saca el $hidden de Address, los controles siguen verdes y
 * lo que se pone rojo es la asercion de que no se serializan.
 */
class AjustePrecioDeSucursalOcultoTest extends TestCase
{
    use DatabaseTransactions;

    /** Las dos columnas que agrega empresa-api y que no pueden salir de esta API. */
    const COLUMNAS_INTERNAS = ['ajuste_precio_tipo', 'ajuste_precio_porcentaje'];

    /** Valores sembrados. Distintos de cualquier default para que el control sea inequivoco. */
    const TIPO = 'recargo';
    const PORCENTAJE = 17.35;

    /**
     * Simula una base cuya tabla `addresses` ya tiene las dos columnas, con valores cargados.
     *
     * Se engancha en `retrieved`, que Eloquent dispara en newFromBuilder() cada vez que hidrata una
     * fila (find, get, with(), relaciones). No hace DDL ni escribe nada en la base.
     *
     * @return void
     */
    private function simular_base_con_ajuste_de_precio()
    {
        Address::retrieved(function (Address $sucursal) {
            $sucursal->setRawAttributes(array_merge($sucursal->getAttributes(), [
                'ajuste_precio_tipo'       => self::TIPO,
                'ajuste_precio_porcentaje' => self::PORCENTAJE,
            ]), true);
        });
    }

    /**
     * El modelo declara las dos columnas como ocultas.
     *
     * Es la aserción mas directa y la que no depende de ninguna simulacion: si alguien borra el
     * $hidden, este es el primer test que lo dice.
     */
    public function test_address_declara_ocultas_las_columnas_de_ajuste_de_precio()
    {
        $ocultas = (new Address())->getHidden();

        foreach (self::COLUMNAS_INTERNAS as $columna) {
            $this->assertContains($columna, $ocultas,
                'App\Address::$hidden tiene que incluir '.$columna.': es la politica interna de precios del ERP.');
        }
    }

    /**
     * Una sucursal con el ajuste cargado no lo serializa, ni a array ni a JSON.
     *
     * Y el acceso desde PHP sigue andando: $hidden no puede romper la lectura del atributo.
     */
    public function test_una_sucursal_con_ajuste_de_precio_no_lo_serializa()
    {
        $sucursal = new Address();
        $sucursal->forceFill([
            'street'                   => 'Calle Sin Salida',
            'ajuste_precio_tipo'       => self::TIPO,
            'ajuste_precio_porcentaje' => self::PORCENTAJE,
        ]);

        // Control: sin el $hidden los dos atributos estarian en el array (la carga funciono).
        $visible = (clone $sucursal)->makeVisible(self::COLUMNAS_INTERNAS)->toArray();
        foreach (self::COLUMNAS_INTERNAS as $columna) {
            $this->assertArrayHasKey($columna, $visible, 'Control: el atributo '.$columna.' tendria que estar cargado.');
        }

        $array = $sucursal->toArray();
        $json  = $sucursal->toJson();

        foreach (self::COLUMNAS_INTERNAS as $columna) {
            $this->assertArrayNotHasKey($columna, $array, $columna.' no puede salir en toArray().');
            $this->assertStringNotContainsString('"'.$columna.'"', $json, $columna.' no puede salir en toJson().');
        }

        // Lo que la tienda SI muestra de una sucursal sigue viajando.
        $this->assertSame('Calle Sin Salida', $array['street']);

        // Y desde PHP el atributo se lee igual: $hidden solo afecta la serializacion.
        $this->assertSame(self::TIPO, $sucursal->ajuste_precio_tipo);
        $this->assertEquals(self::PORCENTAJE, $sucursal->ajuste_precio_porcentaje);
    }

    /**
     * GET /api/commerce/{id}, la ruta publica sin auth, no devuelve el ajuste de precio.
     *
     * Este es el test de punta a punta del hallazgo: mira la respuesta HTTP real, con las sucursales
     * del comercio hidratadas como si la base tuviera las dos columnas.
     */
    public function test_el_endpoint_publico_del_comercio_no_publica_el_ajuste_de_precio_de_las_sucursales()
    {
        $comercio = User::first();
        $this->assertNotNull($comercio, 'La base del slot tiene que tener al menos un comercio sembrado.');

        // Una sucursal propia, para no depender de lo que traiga sembrado el comercio.
        Address::create([
            'street'  => 'Sucursal Ajuste Test',
            'user_id' => $comercio->id,
        ]);

        $this->simular_base_con_ajuste_de_precio();

        // Control: las sucursales del comercio salen hidratadas CON el ajuste, y sin $hidden
        // viajarian. Si esto fallara, la simulacion estaria rota y el resto del test no probaria nada.
        $sucursales = $comercio->addresses()->get();
        $this->assertGreaterThan(0, $sucursales->count());
        $this->assertSame(self::TIPO, $sucursales->first()->ajuste_precio_tipo, 'Control: la simulacion tiene que inyectar el ajuste.');
        $this->assertArrayHasKey('ajuste_precio_porcentaje',
            $sucursales->first()->makeVisible(self::COLUMNAS_INTERNAS)->toArray(),
            'Control: sin $hidden el ajuste viajaria.');

        $respuesta = $this->json('GET', '/api/commerce/'.$comercio->id)->assertStatus(200);

        // La relacion viaja (la tienda la necesita) y trae la sucursal que se creo arriba...
        $direcciones = $respuesta->json('commerce.addresses');
        $this->assertIsArray($direcciones);
        $calles = array_column($direcciones, 'street');
        $this->assertContains('Sucursal Ajuste Test', $calles);

        // ...pero ni en el JSON crudo ni en cada sucursal aparece el ajuste.
        $crudo = $respuesta->getContent();

        foreach (self::COLUMNAS_INTERNAS as $columna) {
            $this->assertStringNotContainsString('"'.$columna.'"', $crudo,
                'La respuesta publica del comercio no puede incluir '.$columna);

            foreach ($direcciones as $direccion) {
                $this->assertArrayNotHasKey($columna, $direccion);
            }
        }
    }

    /**
     * Las sucursales del comprador (`Buyer::withAll()`) usan la misma clase y tampoco lo publican.
     *
     * Buyer::addresses() devuelve App\Address, asi que el $hidden del modelo cubre las dos puntas.
     * Se prueba con el mismo scope que usa BuyerController para armar la cuenta.
     */
    public function test_las_sucursales_del_comprador_tampoco_publican_el_ajuste_de_precio()
    {
        $comercio = User::first();
        $this->assertNotNull($comercio);

        $buyer = Buyer::create([
            'name'     => 'Ajuste Sucursal Test',
            'email'    => 'ajuste-sucursal-test-'.uniqid().'@example.com',
            'password' => bcrypt('secreta'),
            'user_id'  => $comercio->id,
        ]);

        Address::create([
            'street'   => 'Domicilio del Comprador',
            'buyer_id' => $buyer->id,
        ]);

        $this->simular_base_con_ajuste_de_precio();

        $cargado = Buyer::withAll()->find($buyer->id);
        $this->assertNotNull($cargado);
        $this->assertCount(1, $cargado->addresses);

        // Control: la simulacion inyecto el ajuste en la relacion del comprador.
        $this->assertSame(self::TIPO, $cargado->addresses->first()->ajuste_precio_tipo, 'Control: la simulacion tiene que inyectar el ajuste.');

        $serializado = $cargado->toArray();
        $this->assertSame('Domicilio del Comprador', $serializado['addresses'][0]['street']);

        foreach (self::COLUMNAS_INTERNAS as $columna) {
            $this->assertArrayNotHasKey($columna, $serializado['addresses'][0],
                $columna.' no puede salir en las sucursales del comprador.');
            $this->assertStringNotContainsString('"'.$columna.'"', $cargado->toJson());
        }
    }
}
