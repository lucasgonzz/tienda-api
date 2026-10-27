<?php

namespace App\Http\Controllers\Helpers;

use App\User;

/** Lecturas compartidas de configuración, nunca artículos, precios ni stock. */
class ContextoDeComercio
{
    const CLAVE = 'tienda.contexto_de_comercio';

    public static function olvidar()
    {
        request()->attributes->remove(self::CLAVE);
    }

    public static function comercio($commerce_id)
    {
        // Los comandos pueden modificar configuración y releerla dentro de la misma ejecución.
        // En HTTP la memoria pertenece al Request: también muere en workers persistentes.
        if (app()->runningInConsole()) {
            return User::find($commerce_id);
        }

        $conexion = (new User())->getConnection();
        $clave = implode('|', [
            $conexion->getName(), $conexion->getConfig('host'),
            $conexion->getConfig('port'), $conexion->getDatabaseName(), (string) $commerce_id,
        ]);
        $atributos = request()->attributes;
        $memoria = $atributos->get(self::CLAVE, []);

        if (!array_key_exists($clave, $memoria)) {
            $memoria[$clave] = User::find($commerce_id);
            $atributos->set(self::CLAVE, $memoria);
        }

        return $memoria[$clave];
    }
}
