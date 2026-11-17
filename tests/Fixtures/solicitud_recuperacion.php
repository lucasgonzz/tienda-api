<?php

// Proceso independiente por solicitud. Los secretos viajan por pipes capturados, nunca por argv,
// archivos ni logs. No se usa Mail::fake: el Mailable recorre el transporte local ArrayTransport.
require __DIR__.'/../../vendor/autoload.php';

$entrada = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!preg_match('/^tienda_testing_s[0-9]+$/', $entrada['database'] ?? '')) {
    throw new RuntimeException('La regresión multiproceso exige una base de testing de slot.');
}
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
\Tests\Fakes\EntornoSinCredenciales::aplicar();
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'database.connections.mysql.database' => $entrada['database'],
    'cache.default' => 'array',
    'cache.password_reset_store' => 'password_reset',
    'cache.stores.password_reset.path' => $entrada['cache_path'],
    'mail.default' => 'array',
    'session.driver' => 'array',
    'app.key' => $entrada['app_key'],
    'queue.default' => 'sync',
]);
\Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::createFromTimestamp($entrada['ahora']));
\Tests\Fakes\StreamHttpSinSalida::instalar();
$app->instance('http', new \Tests\Fakes\HttpFactorySinSalida($app['events']));
$codigo = null;
\Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, function ($evento) use (&$codigo, $entrada) {
    $codigo = isset($evento->data['code']) ? (string) $evento->data['code'] : null;
    if (!empty($entrada['bloquear_smtp'])) {
        file_put_contents($entrada['cache_path'].'/smtp-esperando', 'marca no secreta');
        $limite = microtime(true) + 10;
        while (!file_exists($entrada['cache_path'].'/smtp-liberar') && microtime(true) < $limite) {
            usleep(20000);
        }
        throw new \Symfony\Component\Mailer\Exception\TransportException('Rechazo SMTP simulado después de esperar.');
    }
});
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$request = \Illuminate\Http\Request::create('/password-reset/'.$entrada['paso'], 'POST', [], [], [], [
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
    'REMOTE_ADDR' => $entrada['ip'] ?? '10.20.30.40',
], json_encode($entrada['payload']));
$response = $kernel->handle($request);
$salida = [
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true),
    'sesion' => \Illuminate\Support\Facades\Auth::guard('buyer')->id(),
    'codigo_para_otro_proceso' => $codigo,
    'pid' => getmypid(),
];
$kernel->terminate($request, $response);
echo json_encode($salida, JSON_THROW_ON_ERROR);
