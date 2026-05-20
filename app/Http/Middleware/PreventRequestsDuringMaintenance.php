<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;

/**
 * Middleware de modo mantenimiento (reemplazo de CheckForMaintenanceMode en Laravel 8+).
 */
class PreventRequestsDuringMaintenance extends Middleware
{
    /**
     * Rutas accesibles aunque el modo mantenimiento esté activo.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];
}
