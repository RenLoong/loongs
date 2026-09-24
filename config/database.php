<?php

declare(strict_types=1);

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'loong_swoole'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'unix_socket' => env('DB_SOCKET', ''),
            'options' => [],
            'pool' => [
                'size' => (int) env('DB_POOL_SIZE', 16),
                'wait_timeout' => (float) env('DB_POOL_WAIT_TIMEOUT', -1),
            ],
        ],
    ],
];
