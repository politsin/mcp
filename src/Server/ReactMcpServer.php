<?php

declare(strict_types=1);

namespace Politsin\Mcp\Server;

use Politsin\Mcp\Config\McpConfig;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response as ReactResponse;
use React\Socket\SocketServer;

final class ReactMcpServer
{
    private McpConfig $config;

    public function __construct(McpConfig $config)
    {
        $this->config = $config;
    }

    public function run(string $host = '0.0.0.0', int $port = 8088): void
    {
        $base = rtrim($this->config->basePath, '/');

        $server = new HttpServer(function ($request) use ($base) {
            $method = $request->getMethod();
            $path = $request->getUri()->getPath();

            // /mcp/api — JSON RPC совместимый endpoint (минимум echo для MVP).
            if ($method === 'POST' && $path === $base . '/api') {
                $raw = (string) $request->getBody();
                $data = json_decode($raw, TRUE);
                if (!is_array($data)) {
                    return ReactResponse::json(array('error' => 'invalid_json'), 400);
                }
                return ReactResponse::json(array('ok' => TRUE, 'echo' => $data));
            }

            // /mcp/http — вспомогательные HTTP запросы, пока отдаем 204.
            if ($path === $base . '/http') {
                return new ReactResponse(204, array('X-MCP' => 'http'));
            }

            // /mcp/sse — простой SSE стрим (hello + пульс).
            if ($method === 'GET' && $path === $base . '/sse') {
                $stream = new \React\Stream\ThroughStream();
                Loop::futureTick(function () use ($stream) {
                    $stream->write("data: {\"type\":\"connected\",\"message\":\"MCP SSE\"}\n\n");
                });
                $headers = array(
                    'Content-Type' => 'text/event-stream',
                    'Cache-Control' => 'no-cache',
                    'Connection' => 'keep-alive',
                    'Access-Control-Allow-Origin' => '*',
                );
                return new ReactResponse(200, $headers, $stream);
            }

            return ReactResponse::json(array('error' => 'not_found'), 404);
        });

        $socket = new SocketServer($host . ':' . $port);
        $server->listen($socket);
    }
}


