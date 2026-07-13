<?php

namespace App\Http\Controllers;

use App\Advise;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;

class AdviseController extends Controller
{

    /**
     * Suscribe un email para que le avisen cuando un artículo tenga stock.
     * Endpoint público (sin auth): lo usa gente sin sesión que ve un producto agotado.
     *
     * @param Request $request Debe traer article_id (int, debe existir en articles) y email (string, formato válido).
     * @return \Illuminate\Http\Response 201 siempre que la validación pase (creado o ya existía, no se distingue
     *                                   para no filtrar si un email ya estaba suscripto).
     *
     * Notas de seguridad (prompt 355):
     * - Se valida article_id y email para cortar filas huérfanas / basura.
     * - Se normaliza el email (trim + minúsculas) y se usa firstOrCreate sobre (article_id, email)
     *   para deduplicar: suscribirse dos veces al mismo artículo no debe generar dos filas ni dos mails.
     * - La ruta tiene throttle:10,1 (ver routes/api.php) para mitigar abuso/email bombing.
     * - Si dos requests concurrentes chocan contra el índice único advises_article_email_unique
     *   (prompt 354), se trata como éxito: el objetivo del usuario ya está cumplido.
     */
    function store(Request $request) {
        // Validación de entrada: article_id debe existir realmente y email debe tener formato válido.
        // Esto es lo que corta de raíz las filas huérfanas y los emails rotos que rompían el envío
        // de mail en empresa-api cuando entra stock (QUEUE_CONNECTION=sync).
        $request->validate([
            'article_id' => 'required|integer|exists:articles,id',
            'email'      => 'required|email|max:255',
        ]);

        // Normalizamos el email antes de guardar (trim + minúsculas) para que
        // "Foo@Bar.com " y "foo@bar.com" no generen dos suscripciones distintas.
        $email = strtolower(trim($request->email));

        try {
            // firstOrCreate evita duplicar la fila (y el mail) si el mismo email ya estaba
            // suscripto al mismo artículo.
            Advise::firstOrCreate([
                'article_id' => $request->article_id,
                'email'      => $email,
            ]);
        } catch (QueryException $e) {
            // Carrera entre dos requests simultáneos contra el índice único
            // advises_article_email_unique (prompt 354): lo tratamos como éxito,
            // el objetivo del usuario ("quiero que me avisen") ya está cumplido.
            // No filtramos el mensaje de error de la base al cliente.
        }

        // Se responde 201 tanto si se creó como si ya existía, para no exponer
        // si un email ya estaba suscripto (evitar oráculo de enumeración).
        return response(null, 201);
    }
}
