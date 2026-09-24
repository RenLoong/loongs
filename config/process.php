<?php

declare(strict_types=1);

use Loongs\Process\Example\ExampleCustomProcess;
use Loongs\Process\Websocket\EchoHandler;

$cpuNum = function_exists('swoole_cpu_num') ? swoole_cpu_num() : 1;

return [
    'name' => (string) env('APP_NAME', 'loong-swoole'),
    'pid_file' => (string) env('PROCESS_PID_FILE', 'runtime/loong-swoole.pid'),
    'log_file' => (string) env('PROCESS_LOG_FILE', 'runtime/loong-swoole.log'),
    'daemonize' => filter_var(env('PROCESS_DAEMONIZE', false), FILTER_VALIDATE_BOOLEAN),

    'processes' => [
        'http' => [
            'type' => 'http',
            'enabled' => filter_var(env('HTTP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'count' => 1,
            'host' => (string) env('HTTP_HOST', '0.0.0.0'),
            'port' => (int) env('HTTP_PORT', 9501),
            'settings' => [
                'worker_num' => (int) env('HTTP_WORKER_NUM', $cpuNum),
                'reload_async' => true,
                'max_wait_time' => 60,
                'package_max_length' => 2 * 1024 * 1024,
            ],
        ],

        'rpc' => [
            'type' => 'rpc',
            'enabled' => filter_var(env('RPC_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'count' => 1,
            'host' => (string) env('RPC_HOST', '0.0.0.0'),
            'port' => (int) env('RPC_PORT', 9502),
            'settings' => [
                'worker_num' => (int) env('RPC_WORKER_NUM', 1),
                'reload_async' => true,
                'max_wait_time' => 60,
                'package_max_length' => 2 * 1024 * 1024,
            ],
        ],

        'websocket' => [
            'type' => 'websocket',
            'enabled' => filter_var(env('WS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'count' => 1,
            'host' => (string) env('WS_HOST', '0.0.0.0'),
            'port' => (int) env('WS_PORT', 9503),
            'handler' => (string) env('WS_HANDLER', EchoHandler::class),
            'settings' => [
                'worker_num' => (int) env('WS_WORKER_NUM', 1),
                'reload_async' => true,
            ],
        ],

        'queue' => [
            'type' => 'queue',
            'enabled' => filter_var(env('QUEUE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'count' => (int) env('QUEUE_COUNT', 1),
            'connection' => (string) env('QUEUE_CONNECTION', 'default'),
            'queues' => array_values(array_filter(array_map('trim', explode(',', (string) env('QUEUE_QUEUES', 'default'))))),
            'prefix' => (string) env('QUEUE_PREFIX', 'loong:queue:'),
            'timeout' => (int) env('QUEUE_TIMEOUT', 1),
        ],

        'crontab' => [
            'type' => 'crontab',
            'enabled' => filter_var(env('CRONTAB_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'count' => 1,
            'tasks' => [],  // global tasks only; app tasks go in apps/<App>/config/processes.php
        ],

        'custom-example' => [
            'type' => 'custom',
            'enabled' => filter_var(env('CUSTOM_EXAMPLE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'count' => 1,
            'class' => ExampleCustomProcess::class,
            'interval' => (int) env('CUSTOM_EXAMPLE_INTERVAL', 2),
            'heartbeat_key' => (string) env('CUSTOM_EXAMPLE_KEY', 'loong:process:custom:heartbeat'),
            'boot_pools' => true,
        ],
    ],
];
