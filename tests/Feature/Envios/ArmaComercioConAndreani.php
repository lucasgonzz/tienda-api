<?php

namespace Tests\Feature\Envios;

use App\Platform;
use App\PlatformConnector;
use App\User;
use Illuminate\Support\Facades\Http;

/**
 * Fixtures de los tests de envíos con Andreani directo (misión envios-andreani, 25/11/2026).
 *
 * Se usa JUNTO con `ArmaComercioConZipnova` (que arma el comercio, los artículos con medidas, el conector de
 * Zipnova, el destino y los fixtures de Zipnova): este trait agrega el conector de Andreani tal como lo deja el ERP
 * (PLAN §2.1 y §10.1) y UN fake único que atiende a Zipnova y a Andreani a la vez.
 *
 * Por qué un solo fake y no dos `Http::fake()`: el cliente HTTP de los tests resuelve cada pedido con la primera
 * respuesta no nula de la lista de stubs, así que un fake comodín de un lado taparía al otro. Acá una sola
 * función decide por URL, y todo lo que no reconoce responde 599 (un llamado inesperado se nota).
 *
 * El conector de Andreani se arma así (contrato con empresa-api, `AndreaniConexionService`):
 *   - `platform_user_id`  = número de cliente;
 *   - `access_token`      = JSON `{"usuario":null,"password":null}` (cifrado por el cast del modelo);
 *   - `extra_config`      = contratos, sucursal de origen, bulto por defecto, declarar valor, envío gratis desde y
 *                           `ofrecer_en_tienda`.
 */
trait ArmaComercioConAndreani
{
    /** Número de cliente y contrato de domicilio del ejemplo público de la documentación de Andreani. */
    const ANDREANI_CLIENTE = 'CL0003750';
    const ANDREANI_CONTRATO_DOMICILIO = '400006709';

    /** Contrato de sucursal de los tests: el fake de tarifa le devuelve 650.00 en vez de 779.51. */
    const ANDREANI_CONTRATO_SUCURSAL = 'SUC-TEST';

    /** Keys de las dos opciones de Andreani (PLAN §2.2). */
    const KEY_ANDREANI_DOMICILIO = 'andreani|domicilio';
    const KEY_ANDREANI_SUCURSAL = 'andreani|sucursal';

    /** Precios de la respuesta real medida de Andreani (con IVA) y del fake del contrato de sucursal. */
    const PRECIO_ANDREANI_DOMICILIO = 779.51;
    const PRECIO_ANDREANI_SUCURSAL = 650.0;

    /** Sucursal de Andreani que sirve para retiro en el CP 5000 (de las 4 de la respuesta real). */
    const ANDREANI_SUCURSAL_ID = 40476;

    /** Fragmentos de URL que distinguen cada servicio. */
    const URL_ANDREANI_TARIFAS = 'apis.andreani.com/v1/tarifas';
    const URL_ANDREANI_SUCURSALES = 'apis.andreani.com/v2/sucursales';

    /**
     * Conector de Andreani del comercio, conectado, tal como lo deja el ERP.
     *
     * @param \App\User $comercio
     * @param array $extra_config Cambios sobre la config por defecto del conector.
     * @return \App\PlatformConnector
     */
    protected function conectorAndreani(User $comercio, array $extra_config = [])
    {
        $plataforma = Platform::firstOrCreate(
            ['slug' => 'andreani'],
            ['name' => 'Andreani']
        );

        return PlatformConnector::create([
            'user_id'          => $comercio->id,
            'platform_id'      => $plataforma->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => json_encode(['usuario' => null, 'password' => null]),
            'platform_user_id' => self::ANDREANI_CLIENTE,
            'expires_at'       => null,
            'extra_config'     => array_merge([
                'contrato_domicilio' => self::ANDREANI_CONTRATO_DOMICILIO,
                'contrato_sucursal'  => null,
                'sucursal_origen'    => null,
                'bulto_default'      => ['peso' => 0.5, 'alto' => 10, 'ancho' => 10, 'profundidad' => 10],
                'declarar_valor'     => true,
                'envio_gratis_desde' => null,
                'ofrecer_en_tienda'  => true,
                'conectado_en'       => '2026-11-25T15:00:00-03:00',
            ], $extra_config),
        ]);
    }

    /**
     * Deja el conector de Andreani como lo deja «Desconectar» en el ERP: sin credenciales ni cliente ni config.
     *
     * @param \App\PlatformConnector $conector
     * @return void
     */
    protected function desconectarAndreani(PlatformConnector $conector)
    {
        $conector->access_token = null;
        $conector->platform_user_id = null;
        $conector->extra_config = null;
        $conector->status = 'sin_conectar';
        $conector->save();
    }

    /**
     * Fake único de los tres servicios externos de la cotización.
     *
     * Opciones (todas con default):
     *   - `zipnova`:    'quote' (la fixture con 3 seleccionables) · 'sin_seleccionables' (200 con todo
     *                   `selectable:false`, el paquete grande) · 'ubicacion' (400 de destino desconocido) ·
     *                   un entero (400, 500...: error de Zipnova que NO es de ubicación).
     *   - `andreani`:   200 (la tarifa real medida) o un estado de error (400 = contrato o CP que no reconoce,
     *                   500 = caído).
     *   - `sucursales`: 'reales' (la respuesta real del CP 5000) o 'vacias' (lista vacía).
     *
     * @param array<string, mixed> $opciones
     * @return void
     */
    protected function fakearEnvios(array $opciones = [])
    {
        $opciones = array_merge(['zipnova' => 'quote', 'andreani' => 200, 'sucursales' => 'reales'], $opciones);

        $tarifa = $this->fixture('andreani_tarifa.json');
        $sucursales = $this->fixture('andreani_sucursales.json');

        $zipnova_quote = $this->fixture('zipnova_quote.json');
        $zipnova_sin_seleccionables = $this->fixture('zipnova_quote_sin_seleccionables.json');

        Http::fake(function ($request) use ($opciones, $tarifa, $sucursales, $zipnova_quote, $zipnova_sin_seleccionables) {

            $url = $request->url();

            // Zipnova: POST /shipments/quote.
            if (strpos($url, self::URL_QUOTE) !== false) {
                $zipnova = $opciones['zipnova'];

                if ($zipnova === 'quote') {
                    return Http::response($zipnova_quote, 200);
                }

                if ($zipnova === 'sin_seleccionables') {
                    return Http::response($zipnova_sin_seleccionables, 200);
                }

                if ($zipnova === 'ubicacion') {
                    return Http::response(['message' => 'Destination location not found', 'errors' => ['destination' => ['unknown zipcode']]], 400);
                }

                if ((int) $zipnova === 400) {
                    return Http::response(['message' => 'Package dimensions out of range', 'errors' => ['items.0.height' => ['El alto supera el máximo que acepta el transporte.']]], 400);
                }

                return Http::response(['message' => 'Zipnova no disponible'], (int) $zipnova);
            }

            // Andreani: GET /v1/tarifas (el contrato de sucursal cuesta 650.00).
            if (strpos($url, self::URL_ANDREANI_TARIFAS) !== false) {
                if ((int) $opciones['andreani'] !== 200) {
                    return Http::response(['title' => 'Error en la validacion de su pedido', 'detail' => 'No se pudo obtener la tarifa', 'status' => (int) $opciones['andreani']], (int) $opciones['andreani']);
                }

                parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);

                if (isset($consulta['contrato']) && $consulta['contrato'] === self::ANDREANI_CONTRATO_SUCURSAL) {
                    $tarifa['tarifaConIva']['total'] = '650.00';
                }

                return Http::response($tarifa, 200);
            }

            // Andreani: GET /v2/sucursales?codigoPostal=...
            if (strpos($url, self::URL_ANDREANI_SUCURSALES) !== false) {
                return Http::response($opciones['sucursales'] === 'vacias' ? [] : $sucursales, 200);
            }

            return Http::response(['message' => 'ruta no fakeada en el test'], 599);
        });
    }

    /**
     * Las consultas de tarifa que salieron hacia Andreani, ya parseadas (`cliente`, `contrato`, `cpDestino`,
     * `bultos[i][kilos|volumen|valorDeclarado]`).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function consultasDeTarifa()
    {
        $consultas = [];

        foreach (Http::recorded() as $par) {
            $url = $par[0]->url();

            if (strpos($url, self::URL_ANDREANI_TARIFAS) !== false) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
                $consultas[] = $consulta;
            }
        }

        return $consultas;
    }

    /**
     * Cantidad de pedidos que salieron hacia una URL (por fragmento).
     *
     * @param string $fragmento
     * @return int
     */
    protected function pedidosA($fragmento)
    {
        $cantidad = 0;

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), $fragmento) !== false) {
                $cantidad++;
            }
        }

        return $cantidad;
    }
}
