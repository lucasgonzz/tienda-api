<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\GoogleLoginHelper;
use App\User;
use App\Workday;
use Illuminate\Http\Request;

class CommerceController extends Controller
{

    /**
     * Columnas de `users` que la tienda publica de un comercio.
     *
     * 🔴 Esto es una lista BLANCA a proposito, y la decision merece explicacion porque el modelo
     * de al lado (OnlineConfiguration) usa lista negra y parece una inconsistencia.
     *
     * `users` es la tabla cajon de sastre del ERP: 122 columnas escalares, de las cuales el SPA
     * de la tienda usa 19 (medido con grep sobre todo tienda-spa/src el 15/8/2026). Y crece con
     * cada funcionalidad de empresa-api, un repo que no pasa por aca. Una lista negra sobre una
     * tabla asi se pudre sola: la proxima columna secreta que agregue el ERP se publica en la
     * vidriera del cliente y nadie se entera.
     *
     * `online_configurations`, en cambio, ES la tabla de configuracion de la tienda: el SPA usa
     * casi todas sus columnas y le agregan columnas de presentacion seguido. Ahi una lista blanca
     * seria el error inverso — el SPA ya referencia `font_family` y `category_color_text`
     * (helpers/online_configuration_theme.js:42,69), que todavia no existen en el esquema: con
     * lista blanca, el dia que empresa-api las agregue no llegarian hasta que alguien se acuerde
     * de redesplegar tienda-api. Por eso alla va $hidden.
     *
     * Si el SPA necesita una columna nueva de `users`, se agrega aca. Es una linea, y es
     * deliberado que haya que escribirla.
     */
    const COLUMNAS_PUBLICAS = [
        'id',
        'name',
        'company_name',
        'email',
        'phone',
        'image_url',
        'hosting_image_url',
        'from_cloudinary',
        'online',
        'api_url',
        'app_url',
        'dollar',
        'percentage_card',
        'show_buyer_messages',
        'iva_included',
        'type',
        'status',
        /*
         * Estas cuatro las lee el SPA hoy y NO son columnas de la tabla: quedaron de versiones
         * anteriores del esquema y hoy resuelven a undefined. Se dejan declaradas igual porque la
         * proyeccion las ignora si no existen, y porque sacarlas de la lista escondería que el
         * SPA las sigue leyendo. Si alguna vuelve a existir en `users`, esto la deja pasar sola.
         */
        'delivery_price',
        'dolar',
        'dolar_plus',
        'online_prices',
    ];

    /**
     * Relaciones que el SPA necesita del comercio.
     *
     * `online_configuration` viaja completa menos su $hidden (ver el comentario de
     * COLUMNAS_PUBLICAS); las otras tres son chicas y no tienen columnas sensibles.
     */
    const RELACIONES_PUBLICAS = [
        'addresses',
        'extencions',
        'configuration',
        'online_configuration',
    ];

    function commerce($commerce_id) {
        $commerce = User::where('id', $commerce_id)
                        ->with('addresses')
                        ->with('extencions')
                        ->with('configuration')
                        ->with('online_configuration.online_price_type')
                        ->with('online_configuration.online_template')
                        ->first();

        // El SPA muestra el boton de "Continuar con Google" segun
        // commerce.configuration.show_google_login (prompt 590, grupo 164). Antes esa columna
        // era un master switch generico en user_configurations (default true para todos los
        // comercios, sin relacion con si el comercio tenia o no credenciales cargadas). Ahora se
        // sobreescribe en runtime con la disponibilidad REAL: solo true si el comercio tiene el
        // login con Google habilitado Y credenciales cargadas en su online_configuration. Esto
        // evita mostrar un boton roto para los comercios que ya tenian el switch viejo en true
        // pero nunca cargaron client_id/client_secret.
        if (!is_null($commerce) && !is_null($commerce->configuration)) {
            $commerce->configuration->show_google_login = GoogleLoginHelper::isAvailable($commerce_id);
        }

        return response()->json(['commerce' => $this->proyectar($commerce)], 200);
    }

    /**
     * Arma la respuesta publica del comercio con las columnas de COLUMNAS_PUBLICAS y nada mas.
     *
     * Por que existe: esta ruta no tiene auth (routes/api.php) y devolvia el modelo User entero,
     * o sea las 122 columnas de la tabla `users` del ERP. El $hidden de App\User tapa lo peor,
     * pero es una lista negra sobre una tabla que crece desde otro repo. Esta proyeccion es la
     * defensa que no envejece: lo que no esta declarado, no sale.
     *
     * Se serializa el modelo primero (`toArray()`) en vez de leer los atributos crudos, para que
     * los casts y el $hidden se apliquen igual que antes y la forma de cada valor no cambie: el
     * SPA viejo cacheado tiene que seguir viendo exactamente los mismos tipos.
     *
     * @param  \App\User|null  $commerce
     * @return array|null
     */
    private function proyectar($commerce) {
        if (is_null($commerce)) {
            return null;
        }

        $completo = $commerce->toArray();
        $publico  = [];

        foreach (self::COLUMNAS_PUBLICAS as $columna) {
            // array_key_exists y no isset: una columna con valor null tiene que seguir viajando
            // como null, no desaparecer. El SPA distingue "null" de "ausente" en varios lugares.
            if (array_key_exists($columna, $completo)) {
                $publico[$columna] = $completo[$columna];
            }
        }

        foreach (self::RELACIONES_PUBLICAS as $relacion) {
            if (array_key_exists($relacion, $completo)) {
                $publico[$relacion] = $completo[$relacion];
            }
        }

        return $publico;
    }

    function workdays($commerce_id) {
        $workdays = Workday::with(['schedules' => function($q) use ($commerce_id) {
                                $q->where('user_id', $commerce_id);
                            }])
                            ->get();
        return response()->json(['workdays' => $workdays], 200);
    }
}
