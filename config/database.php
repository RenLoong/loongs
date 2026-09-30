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
                'wait_timeout' => (float) env('DB_POOL_WAIT_TIMEOUT', 3), // seconds; -1 = wait forever
            ],
        ],
    ],

    // loongs/orm pool: tenant connections (config arrays / DSNs), and named connections when the
    // framework pools are not booted. Per worker; buckets keyed by the full config incl. password.
    'tenant_pool' => [
        'size' => (int) env('ORM_POOL_SIZE', 8),                     // max connections per tenant config
        'max_tenants' => (int) env('ORM_POOL_MAX_TENANTS', 64),      // LRU-evicted beyond this
        'idle_seconds' => (float) env('ORM_POOL_IDLE_SECONDS', 60),
        'ttl' => (float) env('ORM_POOL_TTL', 600),                   // max connection age (0 = unlimited)
        'wait_timeout' => (float) env('ORM_POOL_WAIT_TIMEOUT', 3),   // then PoolExhaustedException
        'validate' => filter_var(env('ORM_POOL_VALIDATE', true), FILTER_VALIDATE_BOOL),
    ],
];
