<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\CommerceHelper;
use App\Order;
use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Le avisa al sistema del comercio (empresa-api) que entro un pedido nuevo en la tienda, para que el
 * asistente personal del duenio le escriba por WhatsApp (mision whatsapp-aviso-pedido, contrato C2).
 *
 * REGLA DE ORO, la misma que SendOrderEmails y BroadcastOrderCreated: este Job NUNCA tira una
 * excepcion hacia arriba. Se despacha con dispatchAfterResponse(), o sea que corre dentro de
 * Application::terminate(), que ejecuta sus callbacks en un while sin try/catch: si este escapara,
 * se llevaria puestos a los que van despues, y el pedido YA esta guardado a esta altura.
 *
 * Recibe el id del pedido (escalar) y no el modelo, para no depender de la re-hidratacion de
 * SerializesModels si el pedido se borrara entre la respuesta y el despacho.
 *
 * Privacidad del log: el unico mensaje es 'Aviso de pedido al asistente' y su contexto tiene SOLO las
 * claves order_id, owner_id, motivo y (si hubo respuesta) http_status. Jamas nombre, telefono, mail,
 * importe, URL ni el cuerpo de la respuesta de empresa.
 */
class AvisarPedidoAlAsistente implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Mensaje fijo de todos los registros de este aviso. */
    const MENSAJE_DE_LOG = 'Aviso de pedido al asistente';

    /** Slug de la extension "Agentes de IA y asistente personal". */
    const SLUG_AGENTES_IA = 'agentes_ia';

    /**
     * Id del pedido recien creado.
     *
     * @var int
     */
    public $order_id;

    /**
     * Sin reintentos: un aviso tardio ya no sirve y la consulta de respaldo del admin lo cubre.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * @param int $order_id Id del pedido.
     */
    public function __construct($order_id)
    {
        $this->order_id = $order_id;
    }

    /**
     * Decide si corresponde avisar y, si si, hace el POST a empresa. Nunca tira.
     *
     * @return void
     */
    public function handle()
    {
        // Duenio del pedido, para el log de cada salida (se completa apenas se lee el pedido).
        $owner_id = null;

        try {
            // Con una transaccion abierta el pedido puede no estar commiteado: empresa no lo veria.
            if (DB::transactionLevel() !== 0) {
                $this->registrar('order_not_committed', $owner_id);
                return;
            }

            $order = Order::find($this->order_id);
            if (is_null($order)) {
                return;
            }
            $owner_id = $order->user_id;

            $comercio = User::find($owner_id);
            if (is_null($comercio)) {
                return;
            }

            // Sin el paquete de Agentes de IA no hay asistente que avise.
            if (!CommerceHelper::hasExtencion(self::SLUG_AGENTES_IA, $comercio)) {
                $this->registrar('sin_agentes_ia', $owner_id);
                return;
            }

            // La casilla la crea empresa-api: en una base de empresa vieja no existe la columna.
            if (!Schema::hasColumn('online_configurations', 'notificar_pedido_por_whatsapp')) {
                $this->registrar('empresa_sin_la_funcion', $owner_id);
                return;
            }

            // Casilla del duenio. Sin fila de configuracion se toma el default de empresa (prendida).
            $casilla = DB::table('online_configurations')
                ->where('user_id', $owner_id)
                ->value('notificar_pedido_por_whatsapp');
            if (!is_null($casilla) && !(int) $casilla) {
                $this->registrar('apagado_por_el_dueno', $owner_id);
                return;
            }

            // Base publica de la API de empresa del comercio.
            $api_url = trim((string) $comercio->api_url);
            if ($api_url === '') {
                $this->registrar('sin_api_url', $owner_id);
                return;
            }

            // Contrato C2: POST sin body ni clave (la ruta de empresa es publica con throttle).
            $respuesta = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(4)
                ->post(rtrim($api_url, '/').'/api/tienda/pedidos/'.$this->order_id.'/aviso-al-asistente');

            // Solo se registra el status: el cuerpo de la respuesta de empresa se descarta (puede traer datos).
            $this->registrar('empresa_respondio', $owner_id, $respuesta->status());
        } catch (\Throwable $e) {
            // Timeout, DNS, excepcion cualquiera: solo se registra el tipo, sin mensaje (puede traer la URL).
            $this->registrar('error_al_avisar', $owner_id);
        }
    }

    /**
     * Deja un registro del aviso con la lista cerrada de claves. Nunca tira (el logger puede fallar).
     *
     * @param string      $motivo      Por que se salio o que paso.
     * @param int|null    $owner_id    Duenio del pedido, si ya se conoce.
     * @param int|null    $http_status Status de la respuesta de empresa, si hubo.
     * @return void
     */
    protected function registrar($motivo, $owner_id = null, $http_status = null)
    {
        try {
            $contexto = [
                'order_id' => $this->order_id,
                'owner_id' => $owner_id,
                'motivo'   => $motivo,
            ];

            if (!is_null($http_status)) {
                $contexto['http_status'] = $http_status;
            }

            Log::info(self::MENSAJE_DE_LOG, $contexto);
        } catch (\Throwable $ignorado) {
            // Si ni el log funciona, no queda nada por hacer: el aviso no puede tocar al pedido.
        }
    }
}
