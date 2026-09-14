<?php

namespace Tests\Feature\Envios;

use App\Article;
use App\Buyer;
use App\OnlineConfiguration;
use App\Platform;
use App\PlatformConnector;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fixtures de los tests de envíos por correo (misión zipnova-envios, 14/9/2026).
 *
 * Un comercio propio por test (no `User::first()`): estos tests crean conectores y artículos
 * con peso y medidas, y compartir el comercio sembrado del slot con el resto de la suite haría
 * que el orden de los tests importe. Todo se va con `DatabaseTransactions`.
 *
 * El conector de Zipnova se arma tal como lo deja `ZipnovaIntegracionController` del ERP:
 * `access_token = base64(token:secret)` (cifrado por el cast), `platform_user_id` = cuenta,
 * `extra_config` con el depósito y el bulto por defecto. Es el contrato de §2.3 del plan.
 */
trait ArmaComercioConZipnova
{
    /** Token y secret con los que se arma la credencial Basic del conector. */
    const API_TOKEN = 'tok-de-prueba-zipnova-1234';
    const API_SECRET = 'sec-de-prueba-zipnova-5678';

    /** Cuenta de Zipnova del comercio (la de las fixtures). */
    const ACCOUNT_ID = '3355';

    /** Depósito de origen (el de las fixtures). */
    const ORIGIN_ID = 9323;

    /** Keys de las tres opciones seleccionables de la fixture, en el orden por precio. */
    const KEY_RETIRO_CORREO_ARG = '12|pickup_point|carrier_dropoff';
    const KEY_DOMICILIO_CORREO_ARG = '12|standard_delivery|carrier_dropoff';
    const KEY_DOMICILIO_ANDREANI = '56|standard_delivery|carrier_dropoff';

    /** Precios (`price_incl_tax`) de esas tres opciones en la fixture. */
    const PRECIO_RETIRO_CORREO_ARG = 3993.0;
    const PRECIO_DOMICILIO_CORREO_ARG = 4840.0;
    const PRECIO_DOMICILIO_ANDREANI = 6473.5;

    /** URL de cotización que se falsea. */
    const URL_QUOTE = 'api.zipnova.com.ar/v2/shipments/quote';

    /**
     * Comercio nuevo, con su configuración online (la necesita `OrderHelper::attachArticles`).
     *
     * @return \App\User
     */
    protected function comercioConTienda()
    {
        $comercio = User::create([
            'name'     => 'Comercio Envios Test',
            'email'    => 'envios-'.Str::random(10).'@test.local',
            'password' => bcrypt('secreto'),
            'status'   => 'commerce',
        ]);

        OnlineConfiguration::create([
            'user_id' => $comercio->id,
        ]);

        return $comercio;
    }

    /**
     * Artículo del comercio con peso (kg) y medidas (cm) cargados, como los deja Tienda Nube.
     *
     * @param \App\User $comercio
     * @param array $atributos Overrides (`peso`, `free_shipping`, `requires_shipping`, `final_price`...).
     * @return \App\Article
     */
    protected function articuloConMedidas(User $comercio, array $atributos = [])
    {
        return Article::create(array_merge([
            'name'        => 'Articulo Envios Test',
            'slug'        => 'envios-test-'.uniqid(),
            'user_id'     => $comercio->id,
            'status'      => 'active',
            'online'      => 1,
            'stock'       => 10,
            'final_price' => 1000,
            'peso'        => 1.5,
            'alto'        => 20,
            'ancho'       => 15,
            'profundidad' => 30,
        ], $atributos));
    }

    /**
     * Conector de Zipnova del comercio, conectado, tal como lo deja el ERP.
     *
     * @param \App\User $comercio
     * @param array $extra_config Overrides sobre la config por defecto del conector.
     * @return \App\PlatformConnector
     */
    protected function conectorZipnova(User $comercio, array $extra_config = [])
    {
        $plataforma = Platform::firstOrCreate(
            ['slug' => Platform::SLUG_ZIPNOVA],
            ['name' => 'Zipnova']
        );

        return PlatformConnector::create([
            'user_id'          => $comercio->id,
            'platform_id'      => $plataforma->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => base64_encode(self::API_TOKEN.':'.self::API_SECRET),
            'platform_user_id' => self::ACCOUNT_ID,
            'expires_at'       => null,
            'extra_config'     => array_merge([
                'account_name'       => 'Comercio Envios Test',
                'origin_id'          => self::ORIGIN_ID,
                'bulto_default'      => ['peso' => 0.5, 'alto' => 10, 'ancho' => 10, 'profundidad' => 10],
                'declarar_valor'     => true,
                'envio_gratis_desde' => null,
            ], $extra_config),
        ]);
    }

    /**
     * Comprador del comercio (identidad de checkout de los tests de pedido).
     *
     * @param \App\User $comercio
     * @return \App\Buyer
     */
    protected function compradorDe(User $comercio)
    {
        return Buyer::create([
            'name'    => 'Comprador Envios Test',
            'email'   => 'comprador-envios-'.Str::random(8).'@test.local',
            'phone'   => '3511234567',
            'user_id' => $comercio->id,
        ]);
    }

    /**
     * Una línea del carrito tal cual la manda el SPA (el modelo del artículo con su pivot).
     *
     * @param \App\Article $articulo
     * @param int $amount
     * @param array $atributos
     * @return array
     */
    protected function lineaDelPayload(Article $articulo, $amount = 1, array $atributos = [])
    {
        return array_merge([
            'id'          => $articulo->id,
            'user_id'     => $articulo->user_id,
            'name'        => $articulo->name,
            'final_price' => $articulo->final_price,
            'cost'        => null,
            'amount'      => $amount,
            'pivot'       => ['amount' => $amount, 'notes' => null, 'variant_id' => null],
        ], $atributos);
    }

    /**
     * Un destino completo, como lo carga el comprador en el checkout.
     *
     * @param array $atributos
     * @return array
     */
    protected function destinoCompleto(array $atributos = [])
    {
        return array_merge([
            'nombre'        => 'Juan',
            'apellido'      => 'Perez',
            'documento'     => '30111222',
            'email'         => 'juan@example.com',
            'telefono'      => '+5493511234567',
            'calle'         => 'Av. Colon',
            'numero'        => '123',
            'piso_depto'    => '4 B',
            'localidad'     => 'Cordoba',
            'provincia'     => 'Cordoba',
            'codigo_postal' => '5000',
            'referencia'    => 'porton negro',
            'lat'           => -31.41,
            'lng'           => -64.18,
            'point_id'      => null,
        ], $atributos);
    }

    /**
     * Fixture JSON de esta carpeta, decodificada.
     *
     * @param string $nombre
     * @return array
     */
    protected function fixture($nombre)
    {
        $ruta = __DIR__.'/fixtures/'.$nombre;
        $this->assertFileExists($ruta);

        return json_decode(file_get_contents($ruta), true);
    }

    /**
     * Zipnova responde la cotización de la fixture a todo `POST /shipments/quote`.
     *
     * @return void
     */
    protected function zipnovaCotiza()
    {
        Http::fake([
            self::URL_QUOTE => Http::response($this->fixture('zipnova_quote.json'), 200),
        ]);
    }

    /**
     * El estado inicial del pedido, que `OrderController@store` busca por nombre.
     *
     * @return void
     */
    protected function asegurarEstadoSinConfirmar()
    {
        if (!DB::table('order_statuses')->where('name', 'Sin confirmar')->exists()) {
            DB::table('order_statuses')->insert([
                'name'       => 'Sin confirmar',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
