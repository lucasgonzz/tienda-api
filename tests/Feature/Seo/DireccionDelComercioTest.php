<?php

namespace Tests\Feature\Seo;

use App\Address;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La direccion del comercio que se publica para los buscadores (mision tienda-seo-direccion-de-sucursal).
 *
 * 🔴 En empresa `addresses.street` de una sucursal es su NOMBRE («Principal», «Salon»); el domicilio
 * va en `street_number` y despues `city` y `province`. La direccion del footer del HTML y la del
 * JSON-LD `Store.address` salen de la sucursal principal y no pueden publicar el nombre como si
 * fuera una calle. Sin domicilio ni ciudad, no se publica direccion.
 */
class DireccionDelComercioTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaTiendaParaSeo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->armarComercio();
        // La base del slot ya puede traer sucursales de ese comercio: se parte de cero (se deshace con la transaccion).
        Address::where('user_id', $this->comercio->id)->delete();
    }

    /**
     * @param  array  $columnas
     * @return \App\Address
     */
    protected function sucursal(array $columnas)
    {
        return Address::create(array_merge(['user_id' => $this->comercio->id], $columnas));
    }

    /**
     * El `<p>` de la direccion en el footer, o null si el footer no tiene ninguno.
     * Los de nombre, telefono y mail se descartan por contenido conocido.
     */
    protected function parrafosDelFooter(array $pagina)
    {
        $this->assertSame(1, preg_match('#<footer>(.*?)</footer>#s', $pagina['cuerpo_html'], $m), 'La pagina tiene footer.');
        preg_match_all('#<p>(.*?)</p>#s', $m[1], $p);
        return $p[1];
    }

    public function test_el_nombre_de_la_sucursal_no_se_publica_como_calle_ni_en_el_footer_ni_en_el_json_ld()
    {
        $this->sucursal([
            'street'         => 'Principal',
            'street_number'  => 'Av. San Martin 123',
            'city'           => 'Rosario',
            'province'       => 'Santa Fe',
            'default_address' => 1,
        ]);

        $pagina = $this->pagina('/');

        $this->assertContains('Av. San Martin 123, Rosario, Santa Fe', $this->parrafosDelFooter($pagina));
        $this->assertStringNotContainsString('Principal', $pagina['cuerpo_html']);

        $address = $this->jsonLd($pagina, 'Store')['address'];
        $this->assertSame([
            '@type'           => 'PostalAddress',
            'addressCountry'  => 'AR',
            'streetAddress'   => 'Av. San Martin 123',
            'addressLocality' => 'Rosario',
            'addressRegion'   => 'Santa Fe',
        ], $address);
        $this->assertStringNotContainsString('Principal', json_encode($pagina['json_ld']));
    }

    public function test_una_sucursal_sin_domicilio_ni_ciudad_no_publica_direccion_aunque_tenga_nombre_y_provincia()
    {
        $this->sucursal([
            'street'          => 'Principal',
            'province'        => 'Santa Fe',
            'default_address' => 1,
        ]);

        $pagina = $this->pagina('/');

        $this->assertStringNotContainsString('Principal', $pagina['cuerpo_html']);
        $this->assertStringNotContainsString('Santa Fe', $pagina['cuerpo_html']);
        $this->assertArrayNotHasKey('address', $this->jsonLd($pagina, 'Store'));
        $this->assertStringNotContainsString('Principal', json_encode($pagina['json_ld']));
    }

    public function test_sin_sucursales_no_se_publica_direccion()
    {
        $pagina = $this->pagina('/');

        $this->assertCount(3, $this->parrafosDelFooter($pagina), 'Solo nombre, telefono y mail.');
        $this->assertArrayNotHasKey('address', $this->jsonLd($pagina, 'Store'));
    }

    public function test_sin_domicilio_pero_con_ciudad_se_publica_la_ciudad_y_la_provincia()
    {
        $this->sucursal([
            'street'          => 'Salon',
            'city'            => 'Rafaela',
            'province'        => 'Santa Fe',
            'default_address' => 1,
        ]);

        $pagina = $this->pagina('/');

        $this->assertContains('Rafaela, Santa Fe', $this->parrafosDelFooter($pagina));
        $address = $this->jsonLd($pagina, 'Store')['address'];
        $this->assertArrayNotHasKey('streetAddress', $address);
        $this->assertSame('Rafaela', $address['addressLocality']);
        $this->assertSame('Santa Fe', $address['addressRegion']);
        $this->assertStringNotContainsString('Salon', $pagina['cuerpo_html']);
    }

    public function test_con_varias_sucursales_manda_la_principal_y_no_la_primera()
    {
        $this->sucursal(['street' => 'Deposito', 'street_number' => 'Calle Vieja 1', 'city' => 'Santa Fe', 'province' => 'Santa Fe', 'default_address' => 0]);
        $this->sucursal(['street' => 'Principal', 'street_number' => 'Bv. Oroño 900', 'city' => 'Rosario', 'province' => 'Santa Fe', 'default_address' => 1]);

        $pagina = $this->pagina('/');

        $this->assertContains('Bv. Oroño 900, Rosario, Santa Fe', $this->parrafosDelFooter($pagina));
        $this->assertStringNotContainsString('Calle Vieja', $pagina['cuerpo_html']);
        $this->assertSame('Bv. Oroño 900', $this->jsonLd($pagina, 'Store')['address']['streetAddress']);
    }

    public function test_sin_principal_marcada_se_usa_la_primera()
    {
        $this->sucursal(['street' => 'Centro', 'street_number' => 'Mitre 55', 'city' => 'Parana', 'province' => 'Entre Rios', 'default_address' => 0]);
        $this->sucursal(['street' => 'Norte', 'street_number' => 'Otra 77', 'city' => 'Parana', 'province' => 'Entre Rios', 'default_address' => 0]);

        $pagina = $this->pagina('/');

        $this->assertContains('Mitre 55, Parana, Entre Rios', $this->parrafosDelFooter($pagina));
    }

    public function test_la_direccion_va_escapada_en_el_html()
    {
        $this->sucursal(['street' => 'Principal', 'street_number' => 'Calle <b>1</b>', 'city' => 'Rosario', 'province' => 'Santa Fe', 'default_address' => 1]);

        $pagina = $this->pagina('/');

        $this->assertStringNotContainsString('<b>1</b>', $pagina['cuerpo_html']);
        $this->assertStringContainsString('Calle &lt;b&gt;1&lt;/b&gt;', $pagina['cuerpo_html']);
    }
}
