<?php

namespace Tests\Feature\Seguridad;

use App\Address;
use App\Buyer;
use App\ExtencionEmpresa;
use App\OnlineConfiguration;
use App\User;
use App\UserConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Que ningun secreto viaje en una respuesta de la API.
 *
 * ── Por que este test es distinto de los otros ────────────────────────────────────────────────
 *
 * Los demas tests de seguridad fijan lo que se arreglo. Este esta escrito para fallar por algo que
 * TODAVIA NO PASO, y esa es toda su razon de ser.
 *
 * El esquema de esta base no lo gobierna este repo: lo gobierna `empresa-api`, que le agrega
 * columnas a `users` y a `online_configurations` sin pasar por aca. O sea que la lista negra de
 * $hidden envejece sola: el dia que el ERP agregue `nueva_api_key`, nadie en este repo se entera,
 * y esa columna se publica en la vidriera del cliente por GET /api/commerce/{id}, que no tiene
 * auth.
 *
 * Ya paso exactamente eso: `mp_access_token`, `zippin_access_token` y `google_custom_search_api_key`
 * llegaron desde empresa-api y estuvieron filtrando hasta el 15/8/2026 sin que nada avisara.
 *
 * Entonces este test no mira una lista escrita a mano: lee las columnas REALES de la base y falla
 * si aparece alguna con pinta de secreto que se serialice. Es la "deteccion" que pide
 * APRENDER_NO_PARCHEAR: un comando que encuentra al proximo de la familia, no una nota que dice
 * "hay que tener cuidado".
 *
 * 🔴 Si este test se pone rojo, la respuesta correcta casi nunca es agregar la columna a
 * EXCEPCIONES. Es agregarla al $hidden del modelo. EXCEPCIONES es para columnas que matchean el
 * patron y son publicas de verdad.
 */
class SerializacionDeSecretosTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Pedazos de nombre que delatan una columna que no puede salir en un JSON.
     */
    const PATRON_SECRETO = '/(token|secret|password|_key$|^key_|clave|credential|passwd)/i';

    /**
     * Columnas que matchean el patron y SI son publicas, cada una con su motivo.
     *
     * Cada entrada de esta lista es una decision, no un permiso general.
     */
    const EXCEPCIONES = [
        // La public key de Mercado Pago es publica por diseño del propio Mercado Pago: el brick de
        // pago del navegador la necesita. Igual se oculta en online_configurations porque el SPA
        // de la tienda NO la lee de ahi (la toma de payment_methods), asi que no hace falta
        // publicarla dos veces. Queda declarada por si alguien la desoculta a conciencia.
        'mp_public_key',
        // Fecha de vencimiento del token, no el token.
        'mp_token_expires_at',
        'zippin_token_expires_at',
        // Booleanos de "esta conectado", sin valor de credencial.
        'mp_enabled',
        'zippin_enabled',
    ];

    /**
     * Ningun modelo que viaje por la API serializa una columna con pinta de secreto.
     *
     * Se recorren las columnas reales de cada tabla, no una lista escrita a mano: esa es la parte
     * que hace que el test siga sirviendo cuando empresa-api agregue algo.
     */
    public function test_ningun_modelo_serializa_una_columna_con_pinta_de_secreto()
    {
        $modelos = [
            'users'                  => new User(),
            'buyers'                 => new Buyer(),
            'online_configurations'  => new OnlineConfiguration(),
            /*
             * Las relaciones hijas del comercio viajan ENTERAS en la respuesta publica de
             * CommerceController@commerce: la lista blanca recorta `users`, no a sus hijos. Hoy
             * ninguna de estas tablas tiene columnas sensibles (se midio: user_configurations son
             * flags de negocio, addresses son las sucursales que la tienda muestra, y
             * extencion_empresas es el catalogo de funcionalidades). Se vigilan igual, porque el
             * esquema lo gobierna empresa-api y el dia que alguna sume un secreto nadie en este
             * repo se va a enterar — que es exactamente como llegaron mp_access_token y
             * google_custom_search_api_key.
             */
            'user_configurations'    => new UserConfiguration(),
            'addresses'              => new Address(),
            'extencion_empresas'     => new ExtencionEmpresa(),
        ];

        $filtradas = [];

        foreach ($modelos as $tabla => $modelo) {
            $ocultas = $modelo->getHidden();

            foreach (Schema::getColumnListing($tabla) as $columna) {
                if (!preg_match(self::PATRON_SECRETO, $columna)) {
                    continue;
                }

                if (in_array($columna, self::EXCEPCIONES, true)) {
                    continue;
                }

                if (!in_array($columna, $ocultas, true)) {
                    $filtradas[] = $tabla.'.'.$columna;
                }
            }
        }

        $this->assertSame([], $filtradas,
            "Estas columnas tienen pinta de secreto y se serializan. Agregalas al \$hidden de su modelo:\n  ".
            implode("\n  ", $filtradas)."\n".
            "Si alguna es publica de verdad, agregala a SerializacionDeSecretosTest::EXCEPCIONES con el motivo escrito.");
    }

    /**
     * El endpoint publico del comercio no manda ni una de las columnas que Lucas nombro, ni el
     * resto de lo que se encontro midiendo.
     *
     * Este es el test de punta a punta del punto 1 del pedido: no mira el modelo, mira la
     * respuesta HTTP real.
     */
    public function test_el_endpoint_publico_del_comercio_no_manda_secretos()
    {
        $comercio = User::first();
        $this->assertNotNull($comercio);

        $respuesta = $this->json('GET', '/api/commerce/'.$comercio->id)->assertStatus(200);

        $crudo = $respuesta->getContent();

        $prohibidas = [
            // Las cinco que nombro Lucas.
            'mp_access_token', 'mp_refresh_token', 'zippin_access_token', 'zippin_refresh_token', 'mp_user_id',
            // Lo que aparecio midiendo, del modelo User.
            'google_custom_search_api_key', 'visible_password', 'prev_password', 'articles_export_key',
            'clave_eliminar_article', 'base_de_datos',
            // Lo que ComercioCity le cobra al cliente, en la vidriera del cliente.
            'precio_por_cuenta', 'precio_plan', 'total_a_pagar', 'total_mensualidad',
        ];

        foreach ($prohibidas as $columna) {
            $this->assertStringNotContainsString('"'.$columna.'"', $crudo,
                'La respuesta publica del comercio no puede incluir '.$columna);
        }
    }

    /**
     * Y lo que el SPA SI necesita sigue estando.
     *
     * Es la otra mitad del mismo requisito ("que reciba solo lo que necesita"): la lista blanca de
     * CommerceController no puede haberse comido algo que la tienda usa. Cada clave de esta lista
     * salio de un grep sobre tienda-spa/src, y la de api_url tiene nombre y apellido:
     * views/CuentaCorriente.vue:170 y components/cuenta-corriente/Table.vue:144 la usan para abrir
     * los PDF, y los dos hacen `if (!base) return`, o sea que si falta no se rompe nada visible —
     * simplemente el boton deja de hacer algo.
     */
    public function test_el_endpoint_publico_del_comercio_sigue_mandando_lo_que_el_spa_usa()
    {
        $comercio = User::first();

        $respuesta = $this->json('GET', '/api/commerce/'.$comercio->id)->assertStatus(200);

        $necesarias = [
            'id', 'name', 'company_name', 'email', 'phone', 'image_url', 'from_cloudinary',
            'percentage_card', 'show_buyer_messages', 'api_url',
        ];

        foreach ($necesarias as $clave) {
            $respuesta->assertJsonStructure(['commerce' => [$clave]]);
        }

        // Y las relaciones, sin las cuales la tienda no renderiza.
        $respuesta->assertJsonStructure(['commerce' => ['online_configuration', 'addresses', 'extencions']]);
    }

    /**
     * El comprador nunca ve su propio hash de contraseña, y menos el de otro.
     */
    public function test_el_comprador_no_serializa_credenciales()
    {
        $comercio = User::first();

        $buyer = Buyer::create([
            'name'     => 'Serializacion Test',
            'email'    => 'serializacion-test-'.uniqid().'@example.com',
            'password' => bcrypt('secreta'),
            'user_id'  => $comercio->id,
        ]);

        $serializado = $buyer->toArray();

        foreach (['password', 'remember_token', 'verification_code', 'visible_password'] as $clave) {
            $this->assertArrayNotHasKey($clave, $serializado);
        }

        // Y el acceso desde PHP sigue funcionando: $hidden no puede romper el login.
        $this->assertNotNull($buyer->password, '$hidden no puede impedir leer el atributo desde PHP: Hash::check lo necesita.');
    }
}
