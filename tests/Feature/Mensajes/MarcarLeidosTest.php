<?php

namespace Tests\Feature\Mensajes;

use App\Message;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET /api/messages/set-read`: el comprador marca como leidos los mensajes que le mando el
 * comercio. Mision mensajes-tienda-online: paso de un loop de save() a un solo UPDATE, con el
 * mismo filtro y el mismo resultado.
 */
class MarcarLeidosTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaChatDeTienda;

    const RUTA = '/api/messages/set-read';

    public function test_sin_sesion_devuelve_401()
    {
        $this->getJson(self::RUTA)->assertStatus(401);
    }

    public function test_marca_solo_los_mensajes_del_comercio_a_ese_comprador()
    {
        $comercio  = $this->comercio();
        $comprador = $this->comprador($comercio);
        $vecino    = $this->comprador($comercio);

        $hace_un_mes = now()->subMonth()->startOfSecond();

        $del_comercio_1 = $this->mensaje($comprador, ['from_buyer' => 0, 'read' => 0, 'updated_at' => $hace_un_mes]);
        $del_comercio_2 = $this->mensaje($comprador, ['from_buyer' => 0, 'read' => 0, 'updated_at' => $hace_un_mes]);
        $del_comprador  = $this->mensaje($comprador, ['from_buyer' => 1, 'read' => 0, 'updated_at' => $hace_un_mes]);
        $del_vecino     = $this->mensaje($vecino, ['from_buyer' => 0, 'read' => 0, 'updated_at' => $hace_un_mes]);

        $this->actingAs($comprador, 'buyer')
            ->getJson(self::RUTA)
            ->assertStatus(200);

        foreach ([$del_comercio_1, $del_comercio_2] as $mensaje) {
            $fila = Message::find($mensaje->id);
            $this->assertSame(1, (int) $fila->read, 'los mensajes del comercio a este comprador quedan leidos');
            // Mismo resultado que el save() de antes: updated_at queda al dia.
            $this->assertTrue($fila->updated_at->gt($hace_un_mes), 'updated_at tiene que actualizarse, como con el save() de antes');
        }

        $this->assertSame(0, (int) Message::find($del_comprador->id)->read, 'los mensajes DEL comprador no se tocan: esos los lee el comercio');
        $this->assertSame(0, (int) Message::find($del_vecino->id)->read, 'los de otro comprador no se tocan');
        $this->assertTrue(Message::find($del_vecino->id)->updated_at->eq($hace_un_mes), 'ni su updated_at');
    }

    public function test_lo_hace_con_un_solo_update_y_sin_leer_los_mensajes()
    {
        $comercio  = $this->comercio();
        $comprador = $this->comprador($comercio);

        for ($i = 0; $i < 5; $i++) {
            $this->mensaje($comprador, ['from_buyer' => 0, 'read' => 0]);
        }

        $consultas = [];
        DB::listen(function ($consulta) use (&$consultas) {
            if (preg_match('/\bmessages\b/', $consulta->sql)) {
                $consultas[] = $consulta->sql;
            }
        });

        $this->actingAs($comprador, 'buyer')
            ->getJson(self::RUTA)
            ->assertStatus(200);

        $this->assertCount(1, $consultas, 'tiene que ser una sola consulta sobre messages: '.implode(' | ', $consultas));
        $this->assertStringStartsWith('update', strtolower(ltrim($consultas[0])));
        $this->assertSame(0, Message::where('buyer_id', $comprador->id)->where('read', 0)->count());
    }
}
