<?php

declare(strict_types=1);

namespace Politsin\Mcp\Server;

use Politsin\Mcp\Config\McpConfig;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response as ReactResponse;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;

/**
 * Лёгкий HTTP/SSE сервер MCP на базе ReactPHP.
 */
final class ReactMcpServer {
  /**
   * Конфигурация MCP.
   *
   * @var \Politsin\Mcp\Config\McpConfig
   */
  private McpConfig $config;

  public function __construct(McpConfig $config) {
    $this->config = $config;
  }

  /**
   * Запускает сервер и начинает слушать TCP и опционально UNIX‑сокет.
   *
   * @param string $host
   *   Хост для TCP‑прослушивания.
   * @param int $port
   *   Порт для TCP‑прослушивания.
   * @param string|null $unixSocketPath
   *   Путь к UNIX‑сокету, либо NULL, чтобы не слушать сокет.
   */
  public function run(string $host = '0.0.0.0', int $port = 8088, ?string $unixSocketPath = '/var/run/php/mcp-react.sock'): void {
    $base = rtrim($this->config->basePath, '/');

    $server = new HttpServer(function ($request) use ($base) {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        // /mcp/api — JSON RPC совместимый endpoint (минимум echo для MVP).
      if ($method === 'POST' && $path === $base . '/api') {
            $raw = (string) $request->getBody();
            $data = json_decode($raw, TRUE);
        if (!is_array($data)) {
          return ReactResponse::json(['error' => 'invalid_json'], 400);
        }
              return ReactResponse::json(['ok' => TRUE, 'echo' => $data]);
      }

        // /mcp/http — вспомогательные HTTP запросы, пока отдаем 204.
      if ($path === $base . '/http') {
              return new ReactResponse(204, ['X-MCP' => 'http']);
      }

              // /mcp/sse — простой SSE стрим (hello + пульс).
      if ($method === 'GET' && $path === $base . '/sse') {
        $stream = new ThroughStream();
        Loop::futureTick(function () use ($stream) {
            $stream->write("data: {\"type\":\"connected\",\"message\":\"MCP SSE\"}\n\n");
        });
          $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'Access-Control-Allow-Origin' => '*',
          ];
          return new ReactResponse(200, $headers, $stream);
      }

              return ReactResponse::json(['error' => 'not_found'], 404);
    });

    $tcpAddress = $host . ':' . $port;
    $socketTcp = new SocketServer($tcpAddress);
    $server->listen($socketTcp);
    // Сообщаем, что слушаем TCP.
    echo "[MCP] Listening TCP on http://{$tcpAddress}\n";

    if (is_string($unixSocketPath) && $unixSocketPath !== '') {
      // Префикс unix:/// обязателен для React Socket.
      $unixAddress = str_starts_with($unixSocketPath, 'unix://') ? $unixSocketPath : ('unix://' . $unixSocketPath);
      $socketUnix = new SocketServer($unixAddress);
      $server->listen($socketUnix);
      // Сообщаем, что слушаем UNIX-сокет.
      echo "[MCP] Listening UNIX socket on {$unixAddress}\n";
    }
  }

}
