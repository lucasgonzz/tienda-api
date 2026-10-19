<?php

namespace Tests\Feature\Direcciones;

use App\Buyer;
use App\BuyerAddress;
use App\Http\Controllers\Helpers\DireccionesEsquemaHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Mis direcciones": el ABM `/api/buyer/addresses` (misión formas-envio-direcciones-maps,
 * 19/10/2026).
 *
 * Lo que fija: que cada comprador ve y toca SOLO las suyas (las de otro son 404), la regla de la
 * principal (la primera lo es sola, marcar otra desmarca, borrar la principal pasa la marca a la más
 * nueva), la normalización (trim, largos recortados, coordenadas fuera de rango descartadas, la línea
 * armada cuando no hay la de Google), los obligatorios (422 `direccion`) y el tope de 20.
 *
 * El caso "la base todavía no tiene la tabla" va en `SinEsquemaDeDireccionesTest` (DDL afuera de la
 * transacción).
 */
class DireccionesDelCompradorTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaDirecciones;

    const RUTA = '/api/buyer/addresses';

    /** @var \App\User */
    private $comercio;

    /** @var \App\Buyer */
    private $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        DireccionesEsquemaHelper::olvidar();
        $this->assertTrue(DireccionesEsquemaHelper::disponible(), 'La base del slot tiene que tener el esquema de direcciones.');

        $this->comercio = $this->comercioDeDirecciones();
        $this->comprador = $this->compradorConCuenta($this->comercio);
    }

    public function test_sin_sesion_de_cuenta_devuelve_401()
    {
        $this->getJson(self::RUTA)->assertStatus(401);
        $this->postJson(self::RUTA, $this->direccionDeGoogle())->assertStatus(401);
    }

    public function test_la_primera_direccion_queda_como_principal_y_trae_el_comercio_del_comprador()
    {
        $respuesta = $this->actingAs($this->comprador, 'buyer')
            ->postJson(self::RUTA, $this->direccionDeGoogle(['is_default' => false]))
            ->assertStatus(201);

        $modelo = $respuesta->json('model');

        $this->assertTrue($modelo['is_default'], 'la primera es la principal aunque no lo pida');
        $this->assertSame($this->comprador->id, $modelo['buyer_id']);
        $this->assertSame($this->comercio->id, $modelo['user_id']);
        $this->assertSame('Av. Colón 123, X5000 Córdoba, Argentina', $modelo['formatted_address']);
        $this->assertSame('4 B', $modelo['apartment']);
        $this->assertSame(-31.4135, $modelo['lat']);
        $this->assertSame(-64.1811, $modelo['lng']);
        $this->assertSame('ChIJ-prueba-123', $modelo['place_id']);

        /* La segunda no es principal salvo que lo pida. */
        $segunda = $this->actingAs($this->comprador, 'buyer')
            ->postJson(self::RUTA, $this->direccionDeGoogle(['street' => 'San Martín', 'formatted_address' => null]))
            ->assertStatus(201)
            ->json('model');

        $this->assertFalse($segunda['is_default']);
    }

    public function test_crear_una_principal_desmarca_a_la_anterior()
    {
        $vieja = $this->direccionGuardada($this->comprador, ['is_default' => true]);

        $nueva = $this->actingAs($this->comprador, 'buyer')
            ->postJson(self::RUTA, $this->direccionDeGoogle(['street' => 'Belgrano', 'is_default' => true]))
            ->assertStatus(201)
            ->json('model');

        $this->assertTrue($nueva['is_default']);
        $this->assertFalse($vieja->fresh()->is_default);
        $this->assertSame(1, BuyerAddress::where('buyer_id', $this->comprador->id)->where('is_default', 1)->count());
    }

    public function test_el_listado_trae_solo_las_del_comprador_con_la_principal_primero()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00'));
        $vieja = $this->direccionGuardada($this->comprador, ['street' => 'Vieja']);

        Carbon::setTestNow(Carbon::parse('2026-10-19 11:00:00'));
        $principal = $this->direccionGuardada($this->comprador, ['street' => 'Principal', 'is_default' => true]);

        Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00'));
        $nueva = $this->direccionGuardada($this->comprador, ['street' => 'Nueva']);
        Carbon::setTestNow();

        /* La de otro comprador del mismo comercio no aparece. */
        $otro = $this->compradorConCuenta($this->comercio);
        $this->direccionGuardada($otro, ['street' => 'Ajena', 'is_default' => true]);

        $modelos = $this->actingAs($this->comprador, 'buyer')
            ->getJson(self::RUTA)
            ->assertStatus(200)
            ->json('models');

        $this->assertSame([$principal->id, $nueva->id, $vieja->id], array_column($modelos, 'id'));
    }

    public function test_normaliza_los_textos_recorta_los_largos_y_descarta_coordenadas_fuera_de_rango()
    {
        $modelo = $this->actingAs($this->comprador, 'buyer')
            ->postJson(self::RUTA, [
                'street'            => "  Av.   Colón \n",
                'street_number'     => ' 123 ',
                'apartment'         => '',
                'city'              => ' Córdoba ',
                'province'          => 'Córdoba',
                'reference'         => str_repeat('a', 300),
                'formatted_address' => '   ',
                'lat'               => 123.5,
                'lng'               => -64.18,
            ])
            ->assertStatus(201)
            ->json('model');

        $this->assertSame('Av. Colón', $modelo['street']);
        $this->assertSame('123', $modelo['street_number']);
        $this->assertNull($modelo['apartment'], 'vacío es null');
        $this->assertSame('Córdoba', $modelo['city']);
        $this->assertSame(255, mb_strlen($modelo['reference']), 'recortada al largo de la columna');
        $this->assertSame('Av. Colón 123, Córdoba, Córdoba', $modelo['formatted_address'], 'sin la línea de Google se arma con los campos');
        $this->assertNull($modelo['lat'], 'una latitud fuera de rango no se guarda');
        $this->assertNull($modelo['lng'], 'y van de a dos: sin latitud tampoco longitud');
    }

    public function test_sin_calle_ni_localidad_ni_provincia_es_422_direccion_con_los_campos()
    {
        $respuesta = $this->actingAs($this->comprador, 'buyer')
            ->postJson(self::RUTA, ['street_number' => '123', 'zip_code' => '5000'])
            ->assertStatus(422);

        $this->assertSame('direccion', $respuesta->json('codigo'));
        $this->assertNotEmpty($respuesta->json('message'));
        $this->assertSame(['street', 'city', 'province'], array_keys($respuesta->json('errors')));
        $this->assertSame(0, BuyerAddress::where('buyer_id', $this->comprador->id)->count());

        /* Con la línea de Google alcanza en lugar de la calle. */
        $this->actingAs($this->comprador, 'buyer')
            ->postJson(self::RUTA, ['formatted_address' => 'Ruta 9 km 700, Córdoba', 'city' => 'Córdoba', 'province' => 'Córdoba'])
            ->assertStatus(201);
    }

    public function test_el_tope_es_de_20_direcciones_por_comprador()
    {
        for ($i = 0; $i < BuyerAddress::MAXIMO_POR_COMPRADOR; $i++) {
            $this->direccionGuardada($this->comprador, ['street' => 'Calle '.$i]);
        }

        $respuesta = $this->actingAs($this->comprador, 'buyer')
            ->postJson(self::RUTA, $this->direccionDeGoogle())
            ->assertStatus(422);

        $this->assertSame('direccion', $respuesta->json('codigo'));
        $this->assertStringContainsString('20', $respuesta->json('message'));
        $this->assertSame(20, BuyerAddress::where('buyer_id', $this->comprador->id)->count());
    }

    public function test_editar_pisa_solo_los_campos_que_vienen_y_marcar_principal_desmarca_la_otra()
    {
        $principal = $this->direccionGuardada($this->comprador, ['is_default' => true]);
        $otra = $this->direccionGuardada($this->comprador, ['street' => 'Belgrano', 'formatted_address' => 'Belgrano 50, Córdoba']);

        /* Editar el piso no toca nada más. */
        $modelo = $this->actingAs($this->comprador, 'buyer')
            ->putJson(self::RUTA.'/'.$otra->id, ['apartment' => ' PB '])
            ->assertStatus(200)
            ->json('model');

        $this->assertSame('PB', $modelo['apartment']);
        $this->assertSame('Belgrano', $modelo['street']);
        $this->assertSame('Belgrano 50, Córdoba', $modelo['formatted_address']);
        $this->assertFalse($modelo['is_default']);

        /* "Marcar como principal" manda solo el flag. */
        $modelo = $this->actingAs($this->comprador, 'buyer')
            ->putJson(self::RUTA.'/'.$otra->id, ['is_default' => true])
            ->assertStatus(200)
            ->json('model');

        $this->assertTrue($modelo['is_default']);
        $this->assertFalse($principal->fresh()->is_default);

        /* Cambiar la calle a mano sin línea nueva: la línea vieja ya no sirve y se vuelve a armar. */
        $modelo = $this->actingAs($this->comprador, 'buyer')
            ->putJson(self::RUTA.'/'.$otra->id, ['street' => 'Rivadavia', 'street_number' => '900'])
            ->assertStatus(200)
            ->json('model');

        $this->assertSame('Rivadavia 900, Córdoba, Córdoba', $modelo['formatted_address']);

        /* Y una edición que deja sin localidad es 422, sin tocar la fila. */
        $this->actingAs($this->comprador, 'buyer')
            ->putJson(self::RUTA.'/'.$otra->id, ['city' => ''])
            ->assertStatus(422)
            ->assertJson(['codigo' => 'direccion']);
        $this->assertSame('Córdoba', $otra->fresh()->city);
    }

    public function test_la_direccion_de_otro_comprador_es_404_al_editar_y_al_borrar()
    {
        $otro = $this->compradorConCuenta($this->comercio);
        $ajena = $this->direccionGuardada($otro, ['is_default' => true]);

        $this->actingAs($this->comprador, 'buyer')
            ->putJson(self::RUTA.'/'.$ajena->id, ['street' => 'Hackeada'])
            ->assertStatus(404);

        $this->actingAs($this->comprador, 'buyer')
            ->deleteJson(self::RUTA.'/'.$ajena->id)
            ->assertStatus(404);

        $this->assertSame('Avenida Colón', $ajena->fresh()->street);

        $this->actingAs($this->comprador, 'buyer')
            ->putJson(self::RUTA.'/999999999', ['street' => 'X'])
            ->assertStatus(404);
    }

    public function test_borrar_la_principal_pasa_la_marca_a_la_mas_nueva_y_devuelve_la_lista()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00'));
        $vieja = $this->direccionGuardada($this->comprador, ['street' => 'Vieja']);

        Carbon::setTestNow(Carbon::parse('2026-10-19 11:00:00'));
        $nueva = $this->direccionGuardada($this->comprador, ['street' => 'Nueva']);

        Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00'));
        $principal = $this->direccionGuardada($this->comprador, ['street' => 'Principal', 'is_default' => true]);
        Carbon::setTestNow();

        $modelos = $this->actingAs($this->comprador, 'buyer')
            ->deleteJson(self::RUTA.'/'.$principal->id)
            ->assertStatus(200)
            ->json('models');

        $this->assertNull(BuyerAddress::find($principal->id));
        $this->assertSame([$nueva->id, $vieja->id], array_column($modelos, 'id'));
        $this->assertTrue($modelos[0]['is_default'], 'la más nueva pasa a ser la principal');
        $this->assertFalse($vieja->fresh()->is_default);

        /* Borrar una que no es la principal no mueve la marca. */
        $this->actingAs($this->comprador, 'buyer')
            ->deleteJson(self::RUTA.'/'.$vieja->id)
            ->assertStatus(200);
        $this->assertTrue($nueva->fresh()->is_default);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Solo las cuentas tienen libreta (ronda de revisión, 19/10/2026)
    |---------------------------------------------------------------------------------------------
    */

    public function test_una_ficha_de_invitado_logueada_no_guarda_ni_lista_direcciones()
    {
        $ficha = $this->fichaDeInvitado($this->comercio);
        $this->assertFalse($ficha->es_cuenta());

        /* Una dirección suya que hubiera quedado en la base: igual no la ve ni la toca. */
        $suya = $this->direccionGuardada($ficha, ['is_default' => true]);

        $respuestas = [
            $this->actingAs($ficha, 'buyer')->getJson(self::RUTA),
            $this->actingAs($ficha, 'buyer')->postJson(self::RUTA, $this->direccionDeGoogle()),
            $this->actingAs($ficha, 'buyer')->putJson(self::RUTA.'/'.$suya->id, ['street' => 'Otra']),
            $this->actingAs($ficha, 'buyer')->deleteJson(self::RUTA.'/'.$suya->id),
        ];

        foreach ($respuestas as $respuesta) {
            $respuesta->assertStatus(422)->assertExactJson([
                'codigo'  => 'sin_cuenta',
                'message' => 'Iniciá sesión con tu cuenta para guardar direcciones.',
            ]);
        }

        $this->assertSame(1, BuyerAddress::where('buyer_id', $ficha->id)->count());
        $this->assertSame('Avenida Colón', $suya->fresh()->street);
    }

    public function test_una_cuenta_sin_contrasena_pero_con_provider_id_si_tiene_libreta()
    {
        /* Las cuentas que creó el login con Google (sacado el 14/10/2026) no tienen contraseña. */
        $de_google = Buyer::create([
            'name'        => 'Cuenta Google',
            'email'       => 'google-'.Str::random(8).'@test.local',
            'user_id'     => $this->comercio->id,
            'provider_id' => 'google-123',
        ]);
        $this->assertTrue($de_google->es_cuenta());

        $this->actingAs($de_google, 'buyer')->postJson(self::RUTA, $this->direccionDeGoogle())->assertStatus(201);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | Concurrencia y coordenadas (ronda de revisión, 19/10/2026)
    |---------------------------------------------------------------------------------------------
    */

    public function test_alta_cambio_de_principal_y_baja_toman_el_candado_del_comprador()
    {
        $principal = $this->direccionGuardada($this->comprador, ['is_default' => true]);
        $otra = $this->direccionGuardada($this->comprador, ['street' => 'Belgrano']);

        $consultas = [];
        DB::listen(function ($query) use (&$consultas) {
            $consultas[] = strtolower($query->sql);
        });

        $pedidos = [
            function () { return $this->actingAs($this->comprador, 'buyer')->postJson(self::RUTA, $this->direccionDeGoogle(['street' => 'Nueva'])); },
            function () use ($otra) { return $this->actingAs($this->comprador, 'buyer')->putJson(self::RUTA.'/'.$otra->id, ['is_default' => true]); },
            function () use ($principal) { return $this->actingAs($this->comprador, 'buyer')->deleteJson(self::RUTA.'/'.$principal->id); },
        ];

        foreach ($pedidos as $pedido) {
            $consultas = [];
            $pedido()->assertSuccessful();

            $candado_comprador = array_filter($consultas, function ($sql) {
                return strpos($sql, 'from `buyers`') !== false && strpos($sql, 'for update') !== false;
            });
            $candado_direcciones = array_filter($consultas, function ($sql) {
                return strpos($sql, 'from `buyer_addresses`') !== false && strpos($sql, 'for update') !== false;
            });

            $this->assertNotEmpty($candado_comprador, 'el comprador se bloquea antes de contar o desmarcar');
            $this->assertNotEmpty($candado_direcciones, 'y sus direcciones también');
        }

        /* Y al final hay una sola principal. */
        $this->assertSame(1, BuyerAddress::where('buyer_id', $this->comprador->id)->where('is_default', 1)->count());
    }

    public function test_cambiar_la_direccion_sin_coordenadas_nuevas_borra_las_del_lugar_viejo()
    {
        $direccion = $this->direccionGuardada($this->comprador, ['is_default' => true]);

        /* Cambiar solo el piso no toca lo de Google. */
        $modelo = $this->actingAs($this->comprador, 'buyer')
            ->putJson(self::RUTA.'/'.$direccion->id, ['apartment' => '7 A'])
            ->assertStatus(200)
            ->json('model');

        $this->assertSame(-31.4135, $modelo['lat']);
        $this->assertSame('ChIJ-prueba-123', $modelo['place_id']);

        /* Cambiar la calle a mano, sin coordenadas: las del lugar viejo se van. */
        $modelo = $this->actingAs($this->comprador, 'buyer')
            ->putJson(self::RUTA.'/'.$direccion->id, ['street' => 'Rivadavia', 'street_number' => '900'])
            ->assertStatus(200)
            ->json('model');

        $this->assertNull($modelo['lat']);
        $this->assertNull($modelo['lng']);
        $this->assertNull($modelo['place_id']);
        $this->assertSame('Rivadavia 900, Córdoba, Córdoba', $modelo['formatted_address']);

        /* Elegida de nuevo en Google: vienen las coordenadas nuevas y se guardan. */
        $modelo = $this->actingAs($this->comprador, 'buyer')
            ->putJson(self::RUTA.'/'.$direccion->id, [
                'street'            => 'San Juan',
                'street_number'     => '50',
                'formatted_address' => 'San Juan 50, Córdoba, Argentina',
                'lat'               => -31.42,
                'lng'               => -64.19,
                'place_id'          => 'ChIJ-otro-lugar',
            ])
            ->assertStatus(200)
            ->json('model');

        $this->assertSame(-31.42, $modelo['lat']);
        $this->assertSame(-64.19, $modelo['lng']);
        $this->assertSame('ChIJ-otro-lugar', $modelo['place_id']);
    }
}
