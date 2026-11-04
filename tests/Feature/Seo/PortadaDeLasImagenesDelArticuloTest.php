<?php

namespace Tests\Feature\Seo;

use App\Article;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PortadaDeLasImagenesDelArticuloTest extends TestCase
{
    public static function esquemas(): array
    {
        return [
            'base anterior a la portada' => [false, [1, 2, 3]],
            'base con portada explícita' => [true, [3, 1, 2]],
        ];
    }

    #[DataProvider('esquemas')]
    public function test_orden_de_las_imagenes_en_la_ficha(bool $hay_portada, array $ids): void
    {
        // Tienda no migra el ERP. Estas tablas efímeras prueban las dos versiones del esquema sin alterarlo.
        $conexion = 'portadas_articulo';
        config(['database.connections.' . $conexion => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        Schema::connection($conexion)->create('images', function (Blueprint $table) use ($hay_portada) {
            $table->id();
            $table->morphs('imageable');
            $table->string('hosting_url');
            if ($hay_portada) {
                $table->boolean('first')->nullable()->default(false);
            }
            $table->timestamps();
        });

        $articulo = new Article();
        $articulo->id = 17;
        $articulo->exists = true;
        $articulo->setConnection($conexion);
        $primera_foto = ['hosting_url' => 'https://img.test/uno.webp'];
        if ($hay_portada) {
            $primera_foto['first'] = null;
        }
        $articulo->images()->create($primera_foto);
        $articulo->images()->create(['hosting_url' => 'https://img.test/dos.webp']);
        $portada = ['hosting_url' => 'https://img.test/tres.webp'];
        if ($hay_portada) {
            $portada['first'] = true;
        }
        $articulo->images()->create($portada);

        $articulo->load('images');

        $this->assertSame($ids, array_column($articulo->toArray()['images'], 'id'));
    }
}
