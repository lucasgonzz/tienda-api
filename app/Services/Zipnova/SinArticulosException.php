<?php

namespace App\Services\Zipnova;

/**
 * No hay nada que enviar: la lista de líneas está vacía, ningún artículo pertenece al comercio, o
 * todos tienen `requires_shipping = 0` (digitales, servicios). Sin ítems Zipnova no cotiza y no
 * tiene sentido llamarlo. Se responde 422 `sin_articulos`.
 */
class SinArticulosException extends ZipnovaException
{
}
