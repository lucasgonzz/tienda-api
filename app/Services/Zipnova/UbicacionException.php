<?php

namespace App\Services\Zipnova;

/**
 * Zipnova no reconoció el destino: un código postal que no existe o que cubre varias
 * localidades. Se traduce de `ZipnovaException::esDeUbicacion()` y el controller responde 422
 * `ubicacion` con `needs_location: true`, que es lo que hace que el SPA pida localidad y
 * provincia y reintente.
 */
class UbicacionException extends ZipnovaException
{
}
