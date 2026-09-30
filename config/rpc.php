<?php

declare(strict_types=1);

/**
 * RPC service discovery + retry for loongs/loongs.
 *
 * RPC discovery / retry settings for multi-instance deployments.
 * Instance weight drives WeightedInstancePicker (equal weights → stable order;
 * unequal → weighted shuffle). Backoff uses coroutine-friendly Sleeper.
 *
 * Hot switch (no restart): `services` is re-read by every worker when this file or the
 * runtime override file changes (see `hot_reload`). Change targets with:
 *   ./loongs rpc:show [service]
 *   ./loongs rpc:switch user loopback http://127.0.0.1:9502
 *   ./loongs rpc:switch user remote   http://10.0.0.12:9502
 *   ./loongs rpc:set    user '{"transport":"remote","instances":[{"endpoint":"http://10.0.0.1:9502","weight":1}]}'
 *   ./loongs rpc:reset  user            (or --all) → back to the values below
 * Invalid changes are rejected and workers keep the previous map (logged as [rpc-hot]).
 * CLI details: ./loongs help rpc:switch  (symfony/console; add --no-ansi for plain output).
 *
 * Code API (same implementation as the CLI) — e.g. from an admin controller / crontab task:
 *   use Loongs\Rpc\HotReload\RpcServiceManager;          // container-injectable, or rpc_services()
 *   rpc_services()->switch('user', 'remote', 'http://10.0.0.12:9502');   // returns applied config
 *   rpc_services()->switch('user', 'loopback');                          // → http://127.0.0.1:$RPC_PORT
 *   rpc_services()->set('user', ['transport' => 'remote', 'instances' => [['endpoint' => 'http://10.0.0.1:9502', 'weight' => 3]]]);
 *   rpc_services()->reset('user'); rpc_services()->resetAll();
 *   rpc_services()->show('user');  // effective config + source (config|override) + this process's loaded map
 *   rpc_services()->reload(); rpc_services()->version();
 * Invalid input throws RpcException (400; unknown service 404) and leaves the override file untouched.
 * Applied in the calling process immediately; other workers of the same server reload on their
 * next RpcClient call (shared Swoole\Atomic); other roles/nodes and CLI writes: within interval_ms.
 */
return [
    'path' => '/rpc',
    'default_timeout_ms' => 3000,
    'register_demo_handlers' => true,

    // Optional instance marker: when set, responses carry meta.served_by {node, pid}.
    'node' => (string) \Loongs\Support\Env::get('RPC_NODE', ''),

    // Runtime hot switch of `services` (only services; retry/iouring/node need a restart).
    'hot_reload' => [
        'enabled' => filter_var(\Loongs\Support\Env::get('RPC_HOT_RELOAD', true), FILTER_VALIDATE_BOOLEAN),
        // Per-worker Swoole timer; also throttles the call-path check in RpcClient.
        'interval_ms' => (int) \Loongs\Support\Env::get('RPC_HOT_RELOAD_INTERVAL_MS', 1000),
        // Written atomically by `loongs rpc:*` (gitignored under runtime/).
        'override_file' => (string) \Loongs\Support\Env::get('RPC_HOT_RELOAD_FILE', 'runtime/rpc_services.json'),
    ],
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
