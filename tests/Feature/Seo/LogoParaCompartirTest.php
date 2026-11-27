<?php

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Mision tienda-vista-previa-y-titulo (CA1): el og:image de las paginas que usan el logo como
 * imagen para compartir tiene que pasar por SeoContexto::paraCompartir(), igual que las fichas.
 * Un logo .webp se reescribe a /api/seo/imagen-compartir/<token>.jpg (WhatsApp no siempre decodifica webp ni
 * un archivo de 3590x3590); un logo jpg/png queda tal cual. El JSON-LD (`logo` del Store) NO cambia.
 */
class LogoParaCompartirTest extends TestCase
{
    use DatabaseTransactions;
    use ArmaTiendaParaSeo;

    /** @var string  Logo webp absoluto de prueba. */
    const LOGO_WEBP = 'https://api-imgtest.comerciocity.com/public/storage/logo.webp';

    /** @var string  Logo png absoluto de prueba. */
    const LOGO_PNG = 'https://api-imgtest.comerciocity.com/public/storage/logo.png';

    protected function setUp(): void
    {
        parent::setUp();
        $this->armarComercio();
    }

    /**
     * La URL que tiene que salir en og:image para un logo webp.
     *
     * @param  string  $logo
     * @return string
     */
    private function urlParaCompartir($logo)
    {
        /* Mision og-imagen-android: URL limpia con el src en base64url y terminada en .jpg. */
        return url('/api/seo/imagen-compartir/'.rtrim(strtr(base64_encode($logo), '+/', '-_'), '=').'.jpg');
    }

    /**
     * Rutas de cada clase de pagina cuyo og:image cae al logo: inicio, pagina generica,
     * privada (carrito) y 404.
     *
     * @return array
     */
    private function rutasConLogo()
    {
        return [
            '/',
            '/contacto',
            '/carrito',
            '/inicio/no-existe-'.$this->sufijo,
        ];
    }

    public function test_con_logo_webp_el_og_image_de_cada_pagina_apunta_a_imagen_compartir()
    {
        $this->configurar(['logo_url' => self::LOGO_WEBP]);

        foreach ($this->rutasConLogo() as $ruta) {
            $pagina = $this->pagina($ruta);
            $this->assertSame($this->urlParaCompartir(self::LOGO_WEBP), $pagina['imagen'], 'og:image de '.$ruta);
        }
    }

    public function test_con_logo_png_el_og_image_queda_directo()
    {
        $this->configurar(['logo_url' => self::LOGO_PNG]);

        foreach ($this->rutasConLogo() as $ruta) {
            $pagina = $this->pagina($ruta);
            $this->assertSame(self::LOGO_PNG, $pagina['imagen'], 'og:image de '.$ruta);
        }
    }

    public function test_el_json_ld_del_store_sigue_con_el_logo_crudo()
    {
        $this->configurar(['logo_url' => self::LOGO_WEBP]);

        $pagina = $this->pagina('/');

        $this->assertStringContainsString(
            self::LOGO_WEBP,
            json_encode($pagina['json_ld'], JSON_UNESCAPED_SLASHES),
            'El logo del JSON-LD no se reescribe.'
        );
        $this->assertStringNotContainsString('imagen-compartir', json_encode($pagina['json_ld']));
    }
}
