<?php

return [
    // Sin credenciales (p. ej. al construir la imagen Docker, cuando aún no hay variables de entorno)
    // se usa el driver "null" para no romper el arranque. En producción Render define PUSHER_APP_KEY.
    'default' => env('PUSHER_APP_KEY') ? env('BROADCAST_CONNECTION', 'pusher') : 'null',

    'connections' => [
        // Pusher (servicio gestionado): Render gratis no admite un proceso WebSocket propio.
        'pusher' => [
            'driver' => 'pusher',
            'key' => (string) env('PUSHER_APP_KEY', ''),
            'secret' => (string) env('PUSHER_APP_SECRET', ''),
            'app_id' => (string) env('PUSHER_APP_ID', ''),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'us2'),
                'useTLS' => true,
            ],
            'client_options' => ['timeout' => 5],
        ],

        'log' => ['driver' => 'log'],
        'null' => ['driver' => 'null'],
    ],
];
