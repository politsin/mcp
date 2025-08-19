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

  /**
   * @var \React\Http\HttpServer|null
   */
  private ?HttpServer $server = NULL;

  /**
   * Управляет печатью сообщений о прослушивании адресов.
   *
   * @var bool
   */
  private bool $printListenLogs = TRUE;

  /**
   * Пользовательский выводчик строк (если не задан — используется echo).
   *
   * @var callable|null
   */
  private $outputWriter = NULL;

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
    // Совместимость: старый метод запускает оба слушателя.
    $this->listenTcp($host, $port);
    if (is_string($unixSocketPath) && $unixSocketPath !== '') {
      $this->listenUnixSocket($unixSocketPath);
    }
  }

  /**
   * Возвращает (или создаёт) HttpServer с основным обработчиком MCP.
   */
  private function getServer(): HttpServer {
    if ($this->server instanceof HttpServer) {
      return $this->server;
    }
    $base = rtrim($this->config->basePath, '/');
    $this->server = new HttpServer(function ($request) use ($base) {
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
    return $this->server;
  }

  /**
   * Включает/выключает печать логов прослушивания.
   */
  public function setPrintListenLogs(bool $enabled): void {
    $this->printListenLogs = $enabled;
  }

  /**
   * Устанавливает обработчик вывода строк.
   *
   * @param callable|null $writer
   *   function(string $line): void.
   */
  public function setOutputWriter(?callable $writer): void {
    $this->outputWriter = $writer;
  }

  /**
   * Печатает строку, если логи включены.
   */
  private function write(string $line): void {
    if (!$this->printListenLogs) {
      return;
    }
    // Пишем в лог‑файл, если задан.
    if (is_string($this->config->logFile) && $this->config->logFile !== '') {
      @file_put_contents($this->config->logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }
    if (is_callable($this->outputWriter)) {
      ($this->outputWriter)($line);
      return;
    }
    echo $line . "\n";
  }

  /**
   * Слушать TCP адрес.
   */
  public function listenTcp(string $host, int $port): void {
    $server = $this->getServer();
    $tcpAddress = $host . ':' . $port;
    $socketTcp = new SocketServer($tcpAddress);
    $server->listen($socketTcp);
    $this->write("[MCP] Listening TCP on http://{$tcpAddress}");
  }

  /**
   * Слушать UNIX-сокет.
   */
  public function listenUnixSocket(string $unixSocketPath): void {
    if ($unixSocketPath === '') {
      return;
    }
    $server = $this->getServer();
    $dir = dirname($unixSocketPath);
    if (!is_dir($dir)) {
      @mkdir($dir, 0777, TRUE);
    }
    if (file_exists($unixSocketPath)) {
      @unlink($unixSocketPath);
    }
    $unixAddress = str_starts_with($unixSocketPath, 'unix://') ? $unixSocketPath : ('unix://' . $unixSocketPath);
    $socketUnix = new SocketServer($unixAddress);
    $server->listen($socketUnix);
    $this->write("[MCP] Listening UNIX socket on {$unixAddress}");
  }

}
