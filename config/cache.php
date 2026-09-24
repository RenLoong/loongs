<?php

declare(strict_types=1);

/**
 * Cache stores for loongs/cache.
 *
 * memory  = in-process per worker (not shared across Swoole workers)
 * table   = optional Swoole\Table cross-worker shared memory (fixed size)
 * file    = runtime directory, hashed shards
 * redis   = pooled via Loongs\Redis\RedisManager when framework is present
 */
return [
    'default' => env('CACHE_STORE', 'file'),
    'prefix' => env('CACHE_PREFIX', 'loong:cache:'),
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => env('CACHE_FILE_PATH', 'runtime/cache'),
            'prefix' => env('CACHE_PREFIX', 'loong:cache:'),
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION', 'default'),
            'prefix' => env('CACHE_PREFIX', 'loong:cache:'),
        ],
        'memory' => [
            'driver' => 'memory',
            'prefix' => env('CACHE_PREFIX', 'loong:cache:'),
            'max_items' => (int) env('CACHE_MEMORY_MAX_ITEMS', 10000),
        ],
        'table' => [
            'driver' => 'table',
            'prefix' => env('CACHE_PREFIX', 'loong:cache:'),
            // Fixed row capacity — cannot grow after create().
            'size' => (int) env('CACHE_TABLE_SIZE', 1024),
            // Max serialized payload bytes per row.
            'value_size' => (int) env('CACHE_TABLE_VALUE_SIZE', 4096),
        ],
    ],
];
