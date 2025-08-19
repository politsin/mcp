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
        $serverParams = $request->getServerParams();
        $clientIp = $request->getHeaderLine('X-Forwarded-For') ?: $request->getHeaderLine('X-Real-IP') ?: ($serverParams['REMOTE_ADDR'] ?? 'unknown');
        $ua = $request->getHeaderLine('User-Agent') ?: 'unknown';
        // Логи запросов при info/debug.
      if ($this->config->logLevel === 'info' || $this->config->logLevel === 'debug') {
        $this->write(sprintf('[REQ] ip=%s ua=%s %s %s', $clientIp, $ua, $method, $path));
      }

        // /mcp — JSON манифест (совместимость клиентов).
      if ($method === 'GET' && $path === $base) {
        $manifest = [
          'protocolVersion' => '2024-11-05',
          'serverInfo' => ['name' => 'Politsin MCP Server', 'version' => '1.0.0'],
          'capabilities' => ['tools' => new \stdClass(), 'prompts' => new \stdClass(), 'resources' => new \stdClass()],
          'endpoints' => ['messages' => 'sse', 'requests' => 'http'],
        ];
        return ReactResponse::json($manifest);
      }

        // /mcp/api — обычные GET‑запросы, возвращаем JSON с query‑параметрами.
      if ($method === 'GET' && $path === $base . '/api') {
        $query = $request->getUri()->getQuery();
        $params = [];
        if ($query !== '') {
          parse_str($query, $params);
        }
        if ($this->config->logLevel === 'debug') {
          $this->write('[API] GET params=' . json_encode($params));
        }
        return ReactResponse::json(['ok' => TRUE, 'query' => $params]);
      }

      // /mcp/http — JSON-RPC по POST: базовая поддержка initialize.
      if ($method === 'POST' && $path === $base . '/http') {
        $raw = (string) $request->getBody();
        $payload = json_decode($raw, TRUE);
        if (is_array($payload)) {
          $rpcMethod = (string) ($payload['method'] ?? '');
          $id = $payload['id'] ?? NULL;
          if ($rpcMethod === 'initialize') {
            $params = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
            $proto = (string) ($params['protocolVersion'] ?? '');
            $client = isset($params['clientInfo']) && is_array($params['clientInfo']) ? $params['clientInfo'] : [];
            $clientName = (string) ($client['name'] ?? '');
            $clientVer = (string) ($client['version'] ?? '');
            $caps = isset($params['capabilities']) && is_array($params['capabilities']) ? array_keys($params['capabilities']) : [];
            $this->write('[INIT] ip=' . $clientIp . ' ua=' . $ua . ' protocol=' . ($proto ?: 'n/a') . ' client=' . ($clientName ?: 'n/a') . ' v=' . ($clientVer ?: 'n/a') . ' caps=' . json_encode($caps));

            $result = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => [
                'protocolVersion' => $proto !== '' ? $proto : '2024-11-05',
                'serverInfo' => ['name' => 'Politsin MCP Server', 'version' => '1.0.0'],
                'capabilities' => ['tools' => new \stdClass(), 'prompts' => new \stdClass(), 'resources' => new \stdClass()],
                'session' => ['id' => 'simple-mcp-session'],
                'endpoints' => ['messages' => 'sse', 'requests' => 'http'],
              ],
            ];
            $body = json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
            return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], $body);
          }
        }
          // tools/list — перечислить доступные тулзы из конфигурации.
        if ($rpcMethod === 'tools/list') {
          $toolsOut = [];
          foreach (array_keys($this->config->tools) as $toolName) {
            $toolsOut[] = [
              'name' => $toolName,
              'description' => 'Tool ' . $toolName,
              'inputSchema' => [
                'type' => 'object',
                'properties' => [],
                'required' => [],
                'additionalProperties' => FALSE,
              ],
            ];
          }
          $resp = ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $toolsOut]];
          return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], json_encode($resp, JSON_UNESCAPED_UNICODE) . "\n");
        }

          // tools/call — вызвать зарегистрированный тул.
        if ($rpcMethod === 'tools/call') {
          $paramsIn = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
          $name = (string) ($paramsIn['name'] ?? ($paramsIn['tool'] ?? ''));
          $arguments = isset($paramsIn['arguments']) && is_array($paramsIn['arguments']) ? $paramsIn['arguments'] : [];
          if ($name === '' || !isset($this->config->tools[$name])) {
            $err = ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Unknown tool: ' . $name]];
            return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], json_encode($err, JSON_UNESCAPED_UNICODE) . "\n");
          }
          try {
            $callable = $this->config->tools[$name];
            $resultVal = empty($arguments) ? $callable() : $callable($arguments);
            $resultJson = json_encode($resultVal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $resp = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => [
                'content' => [['type' => 'text', 'text' => $resultJson]],
                'isError' => FALSE,
              ],
            ];
            return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], json_encode($resp, JSON_UNESCAPED_UNICODE) . "\n");
          }
          catch (\Throwable $e) {
            $err = ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32000, 'message' => $e->getMessage()]];
            return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], json_encode($err, JSON_UNESCAPED_UNICODE) . "\n");
          }
        }

          // resources/list — список доступных ресурсов.
        if ($rpcMethod === 'resources/list') {
          $resourcesOut = [];
          foreach ($this->config->resources as $key => $value) {
            $isStructured = is_array($value) || is_object($value);
            $resourcesOut[] = [
              'uri' => (string) $key,
              'name' => $key === 'hello_world' ? 'Hello World' : ('Resource ' . (string) $key),
              'description' => $key === 'random_numbers' ? 'Array of 5 random ints' : 'Sample resource',
              'mimeType' => $isStructured ? 'application/json' : 'text/plain',
            ];
          }
          $resp = ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => $resourcesOut]];
          return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], json_encode($resp, JSON_UNESCAPED_UNICODE) . "\n");
        }

          // resources/read — чтение контента ресурса по uri.
        if ($rpcMethod === 'resources/read') {
          $paramsIn = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
          $uri = (string) ($paramsIn['uri'] ?? '');
          if ($uri === '' || !array_key_exists($uri, $this->config->resources)) {
            $err = ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32004, 'message' => 'Resource not found: ' . $uri]];
            return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], json_encode($err, JSON_UNESCAPED_UNICODE) . "\n");
          }
          $val = $this->config->resources[$uri];
          $isStructured = is_array($val) || is_object($val);
          $mime = $isStructured ? 'application/json' : 'text/plain';
          $text = $isStructured ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string) $val;
          $resp = ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['contents' => [['uri' => $uri, 'mimeType' => $mime, 'text' => $text]]]];
          return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], json_encode($resp, JSON_UNESCAPED_UNICODE) . "\n");
        }

        // По умолчанию: пустая строка NDJSON.
        return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], "\n");
      }

      // /mcp/http — потоковый HTTP (NDJSON).
      if ($method === 'GET' && $path === $base . '/http') {
      $stream = new ThroughStream();
      // Начальный фрейм.
      Loop::futureTick(function () use ($stream) {
        $stream->write("{\"type\":\"open\",\"ts\":\"" . date('c') . "\"}\n");
      });
      // Периодические пинги.
      $timer = Loop::addPeriodicTimer(10.0, function () use ($stream) {
        if (method_exists($stream, 'isWritable') && $stream->isWritable()) {
          $stream->write("{\"type\":\"ping\",\"ts\":\"" . date('c') . "\"}\n");
        }
      });
      $stream->on('close', function () use ($timer) {
        Loop::cancelTimer($timer);
      });
      $headers = [
        'Content-Type' => 'application/x-ndjson; charset=utf-8',
        'Cache-Control' => 'no-cache',
        'Connection' => 'keep-alive',
        'Access-Control-Allow-Origin' => '*',
      ];
      if ($this->config->logLevel === 'debug') {
        $this->write('[HTTP] stream opened ip=' . $clientIp . ' ua=' . $ua);
      }
      return new ReactResponse(200, $headers, $stream);
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
        if ($this->config->logLevel === 'debug') {
          $this->write('[SSE] connection opened ip=' . $clientIp . ' ua=' . $ua);
        }
        return new ReactResponse(200, $headers, $stream);
    }

    if ($this->config->logLevel === 'debug') {
      $this->write('[RESP] 404 not_found');
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
  $ts = date('Y-m-d H:i:s');
  $lineOut = sprintf('[%s] %s', $ts, $line);
  // Пишем в лог‑файл, если задан.
  if (is_string($this->config->logFile) && $this->config->logFile !== '') {
    @file_put_contents($this->config->logFile, $lineOut . "\n", FILE_APPEND | LOCK_EX);
  }
  if (is_callable($this->outputWriter)) {
    ($this->outputWriter)($lineOut);
    return;
  }
  echo $lineOut . "\n";
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
