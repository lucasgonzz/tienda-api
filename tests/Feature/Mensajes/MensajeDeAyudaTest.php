<?php

namespace Tests\Feature\Mensajes;

use App\Message;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * `POST /api/help/message` ("Ayuda → Escribinos", type = 'help') con comprador autenticado.
 * Mision mensajes-tienda-online: mismo arreglo que MessageController@store. El user_id sale del
 * comprador y no del commerce_id del navegador, y el mensaje avisa en vivo al sistema de gestion
 * con TiendaChatActualizado (contrato C1).
 *
 * El camino del invitado NO se prueba aca: sigue respondiendo 500 (messages.buyer_id es NOT NULL)
 * y es una decision de producto pendiente, anotada en routes/api.php.
 *
 * Un solo POST por test, por lo mismo que en EnviarMensajeDelCompradorTest: terminate() no vacia
 * los callbacks y un segundo pedido volveria a correr el aviso del primero.
 */
class MensajeDeAyudaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaChatDeTienda;

    const RUTA = '/api/help/message';

    public function test_guarda_el_user_id_del_comprador_aunque_commerce_id_diga_otro()
    {
        $this->capturarBroadcasts();

        $duenio    = $this->comercio();
        $otro      = $this->comercio();
        $comprador = $this->comprador($duenio);

        $respuesta = $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['message' => 'necesito ayuda con mi pedido', 'commerce_id' => $otro->id])
            ->assertStatus(201);

        $fila = Message::find($respuesta->json('message.id'));

        $this->assertNotNull($fila);
        $this->assertSame((int) $duenio->id, (int) $fila->user_id);
        $this->assertSame((int) $comprador->id, (int) $fila->buyer_id);
        $this->assertSame('help', $fila->type);
        $this->assertSame(1, (int) $fila->from_buyer);
    }

    public function test_emite_c1_al_canal_del_duenio_del_comprador_con_type_help()
    {
        $capturados = $this->capturarBroadcasts();

        $duenio    = $this->comercio();
        $otro      = $this->comercio();
        $comprador = $this->comprador($duenio);

        $respuesta = $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['message' => 'necesito ayuda con mi pedido', 'commerce_id' => $otro->id])
            ->assertStatus(201);

        $c1 = $this->eventosC1($capturados);

        $this->assertCount(1, $c1, 'tiene que salir un solo TiendaChatActualizado');
        $this->assertSame(['private-tienda-mensajes.'.$duenio->id], $c1[0]['canales']);

        $mensaje = $c1[0]['payload']['message'];
        $this->assertSame((int) $respuesta->json('message.id'), $mensaje['id']);
        $this->assertSame('help', $mensaje['type']);
        $this->assertTrue($mensaje['from_buyer']);
        $this->assertFalse($mensaje['read']);
        $this->assertSame((int) $duenio->id, $mensaje['user_id']);
        $this->assertSame((int) $comprador->id, $mensaje['buyer_id']);
        $this->assertSame('Necesito ayuda con mi pedido', $mensaje['text']);
        $this->assertSame(1, $c1[0]['payload']['chat']['unread_count']);
        $this->assertSame((int) $comprador->id, $c1[0]['payload']['buyer']['id']);

        // Ni el aviso nuevo ni el notify viejo (MessageSend, que sale a
        // message.from_buyer.{messages.user_id}) pueden ir a un canal del commerce_id del request.
        foreach ($capturados as $capturado) {
            foreach ($capturado['canales'] as $canal) {
                $this->assertStringEndsNotWith('.'.$otro->id, $canal, 'nada puede salir a un canal del commerce_id del request: '.$canal);
            }
        }
    }

    /**
     * Pusher rechaza el aviso C1 (y solo ese): el comprador igual recibe su 201 y el mensaje queda.
     */
    public function test_si_falla_el_aviso_c1_igual_responde_201_y_el_mensaje_queda_guardado()
    {
        $capturados = $this->capturarBroadcasts('TiendaChatActualizado');

        Log::spy();

        $duenio    = $this->comercio();
        $comprador = $this->comprador($duenio);

        $respuesta = $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['message' => 'necesito ayuda', 'commerce_id' => $duenio->id])
            ->assertStatus(201);

        $this->assertNotNull(Message::find($respuesta->json('message.id')), 'el mensaje tiene que quedar en la base');

        // El aviso se intento de verdad (y fallo): sin esto el test daria verde aunque no se
        // despachara nada.
        $this->assertCount(1, $this->eventosC1($capturados));

        Log::shouldHaveReceived('error')
            ->withArgs(function ($mensaje, $contexto = []) {
                return strpos($mensaje, 'BroadcastMensajeDelComprador: fallo el aviso') !== false;
            })
            ->once();
    }

    /**
     * El aviso se registra ANTES del notify viejo. Si ese notify revienta (aca, con un commerce_id
     * que no existe: notify() sobre null), el aviso C1 sale igual, porque el terminate del kernel
     * corre tambien despues de una respuesta de error.
     *
     * No se afirma el codigo de la respuesta a proposito: ese error es del notify viejo, que la
     * mision deja como estaba, y un test no tiene por que fijarlo.
     */
    public function test_si_el_notify_viejo_revienta_el_aviso_c1_sale_igual()
    {
        $capturados = $this->capturarBroadcasts();

        $duenio    = $this->comercio();
        $comprador = $this->comprador($duenio);

        $this->actingAs($comprador, 'buyer')
            ->postJson(self::RUTA, ['message' => 'necesito ayuda', 'commerce_id' => 999999999]);

        $fila = Message::where('buyer_id', $comprador->id)->first();
        $this->assertNotNull($fila, 'el mensaje se guarda antes del notify');

        $c1 = $this->eventosC1($capturados);
        $this->assertCount(1, $c1);
        $this->assertSame(['private-tienda-mensajes.'.$duenio->id], $c1[0]['canales']);
        $this->assertSame((int) $fila->id, $c1[0]['payload']['message']['id']);
    }

    /**
     * @return array  solo los TiendaChatActualizado de lo capturado
     */
    private function eventosC1(\ArrayObject $capturados)
    {
        return array_values(array_filter($capturados->getArrayCopy(), function ($capturado) {
            return $capturado['evento'] === 'TiendaChatActualizado';
        }));
    }
}
