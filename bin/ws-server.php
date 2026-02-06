#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalehurley\Phpbot\Realtime\BotWebSocket;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\Datagram\Factory as DatagramFactory;
use React\Socket\SocketServer;
use React\EventLoop\Loop;

$host = '0.0.0.0';
$wsPort = (int) (getenv('PHPBOT_WS_PORT') ?: 8788);
$udpPort = (int) (getenv('PHPBOT_WS_UDP_PORT') ?: 8789);

$loop = Loop::get();
$wsComponent = new BotWebSocket();

$socket = new SocketServer($host . ':' . $wsPort, [], $loop);
$server = new IoServer(
    new HttpServer(new WsServer($wsComponent)),
    $socket,
    $loop
);

$factory = new DatagramFactory($loop);
$factory->createServer($host . ':' . $udpPort)->then(
    function ($server) use ($wsComponent) {
        $server->on('message', function ($message) use ($wsComponent) {
            $wsComponent->handleUdpMessage((string) $message);
        });
    },
    function (Throwable $e): void {
        fwrite(STDERR, "Failed to start UDP listener: {$e->getMessage()}\n");
    }
);

fwrite(STDOUT, "PhpBot WebSocket server listening on ws://{$host}:{$wsPort} (UDP {$udpPort})\n");
$loop->run();
