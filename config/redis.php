<?php

declare(strict_types=1);

return [
    'default' => env('REDIS_CONNECTION', 'default'),
    'connections' => [
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'auth' => env('REDIS_AUTH', ''),
            'db' => (int) env('REDIS_DB', 0),
            'timeout' => (float) env('REDIS_TIMEOUT', 3.0),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 3.0),
            // Non-zero required: Swoole RedisPool packs read_timeout as connect() arg#4
            // unless retry_interval is set (phpredis 6 expects ?string persistent_id there).
            'retry_interval' => (int) env('REDIS_RETRY_INTERVAL', 100),
            'pool' => [
                'size' => (int) env('REDIS_POOL_SIZE', 16),
                'wait_timeout' => (float) env('REDIS_POOL_WAIT_TIMEOUT', -1),
            ],
        ],
    ],
];
