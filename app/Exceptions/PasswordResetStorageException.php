<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PasswordResetStorageException extends RuntimeException
{
    public const MENSAJE = 'No podemos guardar de forma segura el código de recuperación. Probá más tarde o avisale al comercio para que contacte a soporte.';

    public function respuesta(Request $request)
    {
        // La excepción original puede incluir rutas, credenciales o datos del pedido: no se publica.
        Log::warning('Recuperar clave: almacenamiento persistente no disponible', ['operacion' => $this->getMessage()]);

        $campo = $request->is('password-reset/send-verification-code') ? 'email_send'
            : ($request->is('password-reset/check-verification-code') ? 'verified' : 'password_updated');

        return response()->json([
            $campo => false,
            'message' => self::MENSAJE,
            'error_code' => 'password_reset_storage_unavailable',
        ], 422);
    }
}
