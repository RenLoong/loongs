<?php

declare(strict_types=1);

/**
 * RPC service discovery + retry for loongs/loongs.
 *
 * RPC discovery / retry settings for multi-instance deployments.
 * Instance weight drives WeightedInstancePicker (equal weights → stable order;
 * unequal → weighted shuffle). Backoff uses coroutine-friendly Sleeper.
 */
return [
    'path' => '/rpc',
    'default_timeout_ms' => 3000,
    'register_demo_handlers' => true,
    'retry' => [
        'max_attempts' => 3,
        'timeout_ms' => 3000,
        'backoff_ms' => 100,
        'backoff_multiplier' => 2.0,
        'failover' => true,
    ],
    'services' => [
        'demo' => [
            'transport' => 'local',
            'timeout_ms' => 3000,
        ],
        // User RPC service app (server/apps/User). Default Local (same process).
        // For Loopback/Remote point endpoint at the RPC process port (default 9502).
        'user' => [
            'transport' => 'local',
            'timeout_ms' => 3000,
        ],
        // Example loopback (same machine, RPC process on RPC_PORT):
        // 'user' => [
        //     'transport' => 'loopback',
        //     'endpoint' => 'http://127.0.0.1:9502',
        //     'timeout_ms' => 3000,
        // ],
        // Example remote:
        // 'order' => [
        //     'transport' => 'remote',
        //     'endpoint' => 'http://10.0.0.12:9501',
        //     'timeout_ms' => 5000,
        // ],
        // Example multi-instance (weight used for weighted shuffle when unequal):
        // 'catalog' => [
        //     'transport' => 'remote',
        //     'instances' => [
        //         ['endpoint' => 'http://10.0.0.1:9501', 'weight' => 1],
        //         ['endpoint' => 'http://10.0.0.2:9501', 'weight' => 1],
        //     ],
        // ],
    ],

    /**
     * Swoole io_uring (Linux). See Loongs\Rpc\Support\IoUringSupport.
     *
     * mode: auto|on|off via RPC_IOURING
     * - File async path: requires Swoole built with --enable-iouring + liburing
     * - Network RPC (Swoole\Http\Server + curl client) stays on epoll/curl even when
     *   file io_uring is active. --enable-uring-socket only affects Coroutine\Http\Server.
     */
    'iouring' => [
        'mode' => (string) (\Loongs\Support\Env::get('RPC_IOURING', 'auto')),
        'entries' => (int) (\Loongs\Support\Env::get('RPC_IOURING_ENTRIES', 8192)),
        'workers' => (int) (\Loongs\Support\Env::get('RPC_IOURING_WORKERS', 0)),
        // default | sqpoll  → SWOOLE_IOURING_DEFAULT | SWOOLE_IOURING_SQPOLL
        'flag' => (string) (\Loongs\Support\Env::get('RPC_IOURING_FLAG', 'default')),
    ],
];
