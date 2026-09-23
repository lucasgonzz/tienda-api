<?php

namespace Tests\Feature\Seo;

use App\Http\Controllers\Helpers\Seo\SeoContexto;
use Tests\TestCase;

/**
 * El corte de la meta description nunca deja UTF-8 roto.
 *
 * POR QUE (hallazgo del chequeo independiente de seo-tiendas): el corte limpiaba el final con
 * rtrim(), que trabaja por bytes, y la lista incluia la raya y el guion largo. Sus bytes son
 * tambien la cola de otros caracteres multibyte: "ELABORÓ" quedaba con un byte suelto, el
 * json_encode de la respuesta fallaba y esa pagina se quedaba sin capa SEO (seo.php cae al
 * index.html ante un 500) sin que nada lo avisara.
 */
class CorteDeLaDescripcionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function casos()
    {
        $relleno = str_repeat('a', 140);
        $cola = ' '.str_repeat('b', 30);

        return [
            'mayuscula con tilde al final' => [$relleno.' ELABORÓ'.$cola, 'ELABORÓ…'],
            'emoji al final' => [$relleno.' Regalo 😀'.$cola, 'Regalo 😀…'],
            'raya al final se limpia' => [$relleno.' fin —'.$cola, 'fin…'],
        ];
    }

    /**
     * @dataProvider casos
     *
     * @param  string  $texto
     * @param  string  $final_esperado
     * @return void
     */
    public function test_el_corte_deja_utf8_valido($texto, $final_esperado)
    {
        $cortado = SeoContexto::cortar($texto);

        $this->assertTrue(mb_check_encoding($cortado, 'UTF-8'), 'El corte dejo UTF-8 invalido: '.bin2hex(substr($cortado, -8)));
        $this->assertNotFalse(json_encode(['descripcion' => $cortado]));
        $this->assertStringEndsWith($final_esperado, $cortado);
        $this->assertLessThanOrEqual(160, mb_strlen($cortado, 'UTF-8'));
    }
}
