<?php

namespace Tests\Feature\CuotasMercadoPago;

use MercadoPago\Entity;
use MercadoPago\Http\HttpRequest;
use MercadoPago\SDK;
use ReflectionProperty;

/**
 * El transporte del SDK de Mercado Pago, sin red (mision `tienda-cuotas-mercado-pago`, 15/10/2026).
 *
 * ── Por que existe ────────────────────────────────────────────────────────────────────────────
 *
 * `MercadoPagoController@preference` hace DOS pedidos que no pasan por la fachada `Http`: el
 * `SDK::setAccessToken()` consulta `GET /users/me` y `$preference->save()` hace `POST
 * /checkout/preferences`, los dos por el curl propio del SDK. Los tests de la tienda no pueden
 * salir a internet, asi que hasta ahora `preference()` entero NO se probaba: solo sus partes por
 * reflexion.
 *
 * Reemplazando el `HttpRequest` del `RestClient` del SDK por este doble, `preference()` corre de
 * punta a punta —lo que el servidor le arma a Mercado Pago, la serializacion real a JSON de la
 * entidad `Preference`, la respuesta al SPA— sin que nada salga: el POST queda anotado en
 * `$pedidos` y se le contesta una preferencia inventada.
 *
 * Cualquier pedido que no sea uno de esos dos hace FALLAR al test (excepcion): este doble no
 * deja pasar nada que no haya sido previsto.
 *
 * ── Estado global ─────────────────────────────────────────────────────────────────────────────
 *
 * El SDK guarda su configuracion en estaticas. `instalar()` la arma limpia y `desinstalar()` la
 * deja como estaba antes de cualquier uso (sin inicializar), para que un test posterior del mismo
 * proceso que llame a `SDK::setAccessToken()` vuelva a inicializarla.
 */
class TransporteDelSdkSinRed implements HttpRequest
{
    /** @var array<int, array{verbo: string, url: string, cuerpo: string|null}> Los pedidos recibidos. */
    public static $pedidos = [];

    /** @var array<int, mixed> Opciones de curl seteadas desde el ultimo `execute()`. */
    private $opciones = [];

    /** @var int Codigo HTTP de la ultima respuesta. */
    private $codigo = 200;

    /**
     * Inicializa el SDK con este transporte y vacia los pedidos anotados.
     *
     * @return void
     */
    public static function instalar()
    {
        self::$pedidos = [];

        SDK::initialize();

        $rest = (new ReflectionProperty(SDK::class, '_restClient'))->getValue();
        $rest->setHttpRequest(new self());
    }

    /**
     * Deja el SDK sin inicializar, como estaba antes de `instalar()`.
     *
     * @return void
     */
    public static function desinstalar()
    {
        foreach (['_config', '_manager', '_restClient'] as $propiedad) {
            (new ReflectionProperty(SDK::class, $propiedad))->setValue(null, null);
        }

        Entity::unSetManager();

        self::$pedidos = [];
    }

    /**
     * Los pedidos `POST /checkout/preferences` recibidos, con el cuerpo ya decodificado.
     *
     * @return array<int, array>
     */
    public static function preferenciasCreadas()
    {
        $creadas = [];

        foreach (self::$pedidos as $pedido) {
            if ($pedido['verbo'] === 'POST' && substr($pedido['url'], -strlen('/checkout/preferences')) === '/checkout/preferences') {
                $creadas[] = json_decode($pedido['cuerpo'], true);
            }
        }

        return $creadas;
    }

    /**
     * El cuerpo CRUDO (el string JSON que viajaria) del POST de la preferencia, para mirar la
     * forma exacta de lo que serializa el SDK (objeto vs lista).
     *
     * @return string|null
     */
    public static function cuerpoCrudoDeLaPreferencia()
    {
        foreach (self::$pedidos as $pedido) {
            if ($pedido['verbo'] === 'POST' && substr($pedido['url'], -strlen('/checkout/preferences')) === '/checkout/preferences') {
                return $pedido['cuerpo'];
            }
        }

        return null;
    }

    public function setOption($name, $value)
    {
        $this->opciones[$name] = $value;
    }

    public function execute()
    {
        $url    = isset($this->opciones[CURLOPT_URL]) ? $this->opciones[CURLOPT_URL] : '';
        $verbo  = isset($this->opciones[CURLOPT_CUSTOMREQUEST]) ? $this->opciones[CURLOPT_CUSTOMREQUEST] : 'GET';
        $cuerpo = isset($this->opciones[CURLOPT_POSTFIELDS]) ? $this->opciones[CURLOPT_POSTFIELDS] : null;

        // Una conexion real conserva las opciones entre pedidos; aca no, para que cada pedido se
        // vea solo con lo que le puso el SDK.
        $this->opciones = [];

        self::$pedidos[] = ['verbo' => $verbo, 'url' => $url, 'cuerpo' => $cuerpo];

        if ($verbo === 'GET' && substr($url, -strlen('/users/me')) === '/users/me') {
            $this->codigo = 200;

            return json_encode(['id' => 1234567, 'country_id' => 'AR']);
        }

        if ($verbo === 'POST' && substr($url, -strlen('/checkout/preferences')) === '/checkout/preferences') {
            $this->codigo = 201;

            return json_encode([
                'id'                 => 'PREFERENCIA-DE-PRUEBA',
                'init_point'         => 'https://www.mercadopago.test/checkout/v1/redirect?pref_id=PREFERENCIA-DE-PRUEBA',
                'sandbox_init_point' => 'https://sandbox.mercadopago.test/checkout/v1/redirect?pref_id=PREFERENCIA-DE-PRUEBA',
            ]);
        }

        throw new \RuntimeException('TransporteDelSdkSinRed: el SDK intento un pedido que el test no previo: '.$verbo.' '.$url);
    }

    public function getInfo($name)
    {
        return $this->codigo;
    }

    public function close()
    {
    }

    public function error()
    {
        return '';
    }
}
