<?php

namespace App\Services\Zipnova;

/**
 * El comercio no puede cotizar por correo: no tiene Zipnova conectado, o la base todavía no
 * tiene el esquema de envíos (`ZipnovaEsquemaHelper::disponible()` en false). Es el caso normal
 * de un comercio sin la integración, no una falla: se responde 422 `sin_zipnova` sin loguear.
 *
 * Extiende `ZipnovaException` para que quien atrapa la familia entera lo haga con un solo catch;
 * el orden de los catch específicos va primero.
 */
class SinZipnovaException extends ZipnovaException
{
}
