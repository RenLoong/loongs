<?php

declare(strict_types=1);

use Loongs\Http\Controllers\HealthController;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Rpc\Server\RpcServer;
use Loongs\Routing\Router;

/** @var Router $router */
/** @var \Loongs\Http\Application $app */
/** @var \Loongs\Container\Container $container */

// Server-level infrastructure routes (not product apps; apps are auto-discovered).

$router->get('/health', [HealthController::class, 'index']);

$router->post('/rpc', static function (Request $request) use ($container): Response {
    /** @var RpcServer $server */
    $server = $container->make(RpcServer::class);
    $rpcResponse = $server->handleJson($request->body());

    return (new Response())->raw(
        $rpcResponse->toJson(),
        'application/json; charset=utf-8',
    );
});
