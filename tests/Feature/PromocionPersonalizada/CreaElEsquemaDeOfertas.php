<?php

namespace Tests\Feature\PromocionPersonalizada;

use App\Http\Controllers\Helpers\ClientOfferHelper;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea las dos tablas del contrato con `empresa-api` si no estan, copiando la forma EXACTA de
 * 2026_08_17_100200_create_client_offers_table.php y 2026_08_17_100300_create_client_offer_ranges_table.php
 * (tipos, nullables y nombres de indice incluidos).
 *
 * Por que a mano y no con migraciones: `tienda-api` NO tiene database/migrations —el dueño del
 * esquema es `empresa-api` y el deploy de la tienda no corre `migrate`— y la rama
 * `motor-de-ofertas-por-cliente` de `empresa-api` todavia no mergeo, asi que la base del slot no
 * las tiene. Medido el 15/8/2026 contra information_schema de `tienda_testing_s5`: las dos
 * faltan.
 *
 * Se declara con el Blueprint de Laravel y no con un CREATE TABLE escrito a mano a proposito: es
 * el mismo generador que va a correr la migracion de verdad, asi que los tipos no pueden
 * divergir por una transcripcion. La forma sale de las migraciones de `empresa-api`, y las dos
 * cosas que mas importan del contrato son las que mas facil se escriben mal:
 * `client_offers.user_id` es `integer` (NO unsigned, porque `users.id` es `increments()`),
 * mientras que `client_id` y `article_id` son `unsignedBigInteger`; y
 * `client_offer_ranges.max` es NULLABLE, donde NULL significa SIN TECHO.
 *
 * 🔴 Idempotente y NO destructivo: si las tablas ya estan —porque llego el esquema de verdad—
 * NO se tocan. Y NO se dropean al terminar: son parte del esquema que `empresa-api` va a crear
 * igual, dejarlas es lo correcto. Lo que si se limpia son las FILAS que inserto el caso.
 *
 * ⚠️ El CREATE TABLE es DDL y MySQL le hace commit implicito a la transaccion abierta, asi que
 * las clases que usan este trait NO pueden usar DatabaseTransactions: el rollback del trait no
 * revertiria nada y daria una falsa sensacion de limpieza. Limpian a mano, explicitamente, igual
 * que ContratoConEmpresaApiTest.
 */
trait CreaElEsquemaDeOfertas
{
    /**
     * Ids de `client_offers` insertados por el caso, para poder borrarlos sin depender de un
     * rollback que no existe. Incluye las ofertas de OTRO comercio (test E), que por definicion
     * no las alcanza limpiarOfertasDe().
     *
     * @var array
     */
    protected $ofertas_creadas = [];

    /**
     * Crea las dos tablas del contrato si no estan. Idempotente.
     *
     * @return void
     */
    protected function crearEsquemaDeOfertasSiFalta()
    {
        if (!Schema::hasTable(ClientOfferHelper::TABLA)) {
            Schema::create(ClientOfferHelper::TABLA, function (Blueprint $table) {

                $table->id();

                /* integer y NO unsigned: `users.id` es increments(). Copiado tal cual. */
                $table->integer('user_id');

                /* unsignedBigInteger las dos: `clients.id` y `articles.id` son bigIncrements.
                   Angostarlas aca seria la clase de error "columna derivada mas angosta que su
                   fuente" que la migracion de empresa-api documenta en rojo. */
                $table->unsignedBigInteger('client_id');
                $table->unsignedBigInteger('article_id');

                /* 'unidad' usa `porcentaje`; 'cantidad' usa los tramos y lo deja NULL. */
                $table->string('tipo_descuento', 20);
                $table->decimal('porcentaje', 6, 2)->nullable();

                /* La vigencia de verdad: la query de la tienda las compara contra CURDATE(). */
                $table->date('desde');
                $table->date('hasta');

                /* 'activa' | 'vencida' | 'cancelada' */
                $table->string('estado', 20)->default('activa');

                $table->unsignedBigInteger('offer_suggestion_line_id')->nullable();
                $table->string('email_destino', 191)->nullable();
                $table->dateTime('notificada_email_at')->nullable();
                $table->string('whatsapp_telefono', 32)->nullable();
                $table->text('whatsapp_url')->nullable();

                $table->timestamps();

                /* Los dos indices, con el nombre exacto: si el de la migracion real cambiara de
                   nombre o de orden de columnas, el contrato de rendimiento con la tienda se
                   rompe y esta copia dejaria de representar lo que corre en produccion. */
                $table->index(
                    ['user_id', 'client_id', 'article_id', 'estado'],
                    'client_offers_user_client_article_estado_index'
                );
                $table->index(
                    ['user_id', 'estado', 'hasta'],
                    'client_offers_user_estado_hasta_index'
                );
            });
        }

        if (!Schema::hasTable(ClientOfferHelper::TABLA_RANGOS)) {
            Schema::create(ClientOfferHelper::TABLA_RANGOS, function (Blueprint $table) {

                $table->id();

                $table->unsignedBigInteger('client_offer_id');

                $table->integer('min');

                /* 🔴 NULLABLE, y NULL = SIN TECHO. Es la convencion que la tienda ya sabe leer
                   de category_price_type_ranges. Ponerlo NOT NULL aca haria pasar tests que en
                   la base de verdad fallarian. */
                $table->integer('max')->nullable();

                $table->decimal('porcentaje', 6, 2);

                $table->timestamps();

                $table->index(['client_offer_id', 'min'], 'client_offer_ranges_offer_min_index');
            });
        }
    }

    /**
     * Inserta una oferta y devuelve su id, anotandola para el tearDown.
     *
     * Los defaults son los de una oferta 'unidad' vigente hoy: cada caso pisa SOLO lo que esta
     * probando, asi se lee de un vistazo que es lo distinto de ese caso.
     *
     * @param array $atributos Al menos user_id, client_id y article_id.
     * @return int
     */
    protected function insertarOferta(array $atributos)
    {
        $fila = array_merge([
            'tipo_descuento' => ClientOfferHelper::TIPO_UNIDAD,
            'porcentaje'     => 15,
            'desde'          => Carbon::today()->subDays(10)->toDateString(),
            'hasta'          => Carbon::today()->addDays(10)->toDateString(),
            'estado'         => ClientOfferHelper::ESTADO_ACTIVA,
            'created_at'     => Carbon::now(),
            'updated_at'     => Carbon::now(),
        ], $atributos);

        $id = DB::table(ClientOfferHelper::TABLA)->insertGetId($fila);

        $this->ofertas_creadas[] = $id;

        return $id;
    }

    /**
     * Inserta los tramos de una oferta 'cantidad'.
     *
     * Se insertan DESORDENADOS a proposito en los casos que lo piden: el contrato dice que la
     * tienda los devuelve ordenados por `min`, y si el test los cargara ya ordenados no probaria
     * el ORDER BY, probaria el orden de insercion.
     *
     * @param int $offer_id
     * @param array $tramos Cada uno [min, max, porcentaje]; max null = sin techo.
     * @return void
     */
    protected function insertarRangos($offer_id, array $tramos)
    {
        foreach ($tramos as $tramo) {
            DB::table(ClientOfferHelper::TABLA_RANGOS)->insert([
                'client_offer_id' => $offer_id,
                'min'             => $tramo[0],
                'max'             => $tramo[1],
                'porcentaje'      => $tramo[2],
                'created_at'      => Carbon::now(),
                'updated_at'      => Carbon::now(),
            ]);
        }
    }

    /**
     * Borra las ofertas del comercio y las que anoto el caso, con sus tramos.
     *
     * Va por las dos vias porque no se pisan: `limpiarOfertasDe($comercio)` alcanza lo normal, y
     * `$ofertas_creadas` alcanza lo que el caso cargo a nombre de OTRO comercio para probar el
     * filtro por user_id. Sin la segunda, ese caso dejaria basura en la base del slot.
     *
     * @param int|null $user_id
     * @return void
     */
    protected function limpiarOfertasDe($user_id = null)
    {
        if (!Schema::hasTable(ClientOfferHelper::TABLA)) {
            return;
        }

        $ids = $this->ofertas_creadas;

        if (!is_null($user_id)) {
            $ids = array_merge(
                $ids,
                DB::table(ClientOfferHelper::TABLA)->where('user_id', $user_id)->pluck('id')->all()
            );
        }

        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            return;
        }

        if (Schema::hasTable(ClientOfferHelper::TABLA_RANGOS)) {
            DB::table(ClientOfferHelper::TABLA_RANGOS)->whereIn('client_offer_id', $ids)->delete();
        }

        DB::table(ClientOfferHelper::TABLA)->whereIn('id', $ids)->delete();

        $this->ofertas_creadas = [];
    }
}
