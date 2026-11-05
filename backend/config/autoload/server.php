<?php

declare(strict_types=1);

use Hyperf\Server\Event;
use Hyperf\Server\Server;
use Swoole\Constant;
use function Hyperf\Support\env;

return [
    'mode' => SWOOLE_PROCESS,
    'servers' => [[
        'name' => 'http',
        'type' => Server::SERVER_HTTP,
        'host' => (string) env('HTTP_HOST', '0.0.0.0'),
        'port' => (int) env('HTTP_PORT', 9501),
        'sock_type' => SWOOLE_SOCK_TCP,
        'callbacks' => [Event::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest']],
    ]],
    'settings' => [
        Constant::OPTION_ENABLE_COROUTINE => true,
        Constant::OPTION_WORKER_NUM => 1,
        Constant::OPTION_PID_FILE => '/tmp/mcp-gateway-hyperf.pid',
        Constant::OPTION_MAX_REQUEST => 100000,
        Constant::OPTION_OPEN_TCP_NODELAY => true,
    ],
    'callbacks' => [
        Event::ON_WORKER_START => [Hyperf\Framework\Bootstrap\WorkerStartCallback::class, 'onWorkerStart'],
        Event::ON_PIPE_MESSAGE => [Hyperf\Framework\Bootstrap\PipeMessageCallback::class, 'onPipeMessage'],
        Event::ON_WORKER_EXIT => [Hyperf\Framework\Bootstrap\WorkerExitCallback::class, 'onWorkerExit'],
    ],
];
