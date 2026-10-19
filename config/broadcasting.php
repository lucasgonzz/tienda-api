<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_DRIVER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Espacio de nombres de los canales en vivo (pusher-canales-por-sistema, 19/10/2026)
    |--------------------------------------------------------------------------
    |
    | Toda la flota usa UNA app de Pusher: cada canal sale con el sufijo `@espacio` propio del
    | sistema (App\Broadcasting\EspacioDeCanales), calculado con la base, APP_ENV y el secret de
    | Pusher. empresa-api y tienda-api del mismo cliente leen la misma base y dan el mismo espacio.
    |
    | 'espacio' (BROADCAST_ESPACIO): override opcional. Si no está vacío se usa ese valor en vez del
    | calculado, saneado a minúscula, solo [a-z0-9] y hasta 32 caracteres. Es para tests y para
    | alinear a mano las dos mitades de un mismo sistema si algún día no coinciden. Normalmente
    | NO se carga.
    |
    | 'canales_viejos_de_tienda' (BROADCAST_CANALES_VIEJOS_DE_TIENDA, default true = transición):
    | en transición todo lo que emite tienda-api sale con Y sin espacio, para que un ERP viejo siga
    | oyendo a esta tienda y un ERP nuevo también. En false (estricto) sale solo con espacio. Se
    | pone en false en las DOS mitades del mismo sistema a la vez (empresa-api y tienda-api).
    | 🔴 Solo un falso EXPLÍCITO (`false`, `0`, `off`, `no`) pasa a estricto. Una línea presente pero
    | vacía (`BROADCAST_CANALES_VIEJOS_DE_TIENDA=`), un `null`/`(null)` escrito o un valor mal tipeado
    | quedan en transición: con un filter_var pelado, el vacío y el null daban estricto en silencio y
    | la tienda dejaba de oírse con un ERP viejo. Es la MISMA expresión que en empresa-api: las dos
    | mitades leen igual el mismo valor.
    |
    */

    'espacio' => env('BROADCAST_ESPACIO'),

    'canales_viejos_de_tienda' => in_array(env('BROADCAST_CANALES_VIEJOS_DE_TIENDA'), [null, ''], true)
        || filter_var(env('BROADCAST_CANALES_VIEJOS_DE_TIENDA'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false,

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over websockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'useTLS' => true,
                'curl_options' => [
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => 0,
                ],
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
