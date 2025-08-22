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
   * HTTP сервер ReactPHP.
   *
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

        // CORS headers (на все ответы) - разрешаем подключения откуда угодно.
        $originHeader = $request->getHeaderLine('Origin');
        $cors = [
          'Access-Control-Allow-Origin' => '*',
          'Access-Control-Allow-Credentials' => 'false',
          'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-API-Key, Cache-Control, Pragma, DNT, User-Agent, X-Requested-With, If-Modified-Since, Cache-Control, Content-Type, Range',
          'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
          'Vary' => 'Origin, Accept',
        ];

        // HTTP/2 заголовки (если включены).
        if ($this->config->http2Enabled) {
          $cors['X-Content-Type-Options'] = 'nosniff';
          $cors['X-Frame-Options'] = 'DENY';
          $cors['X-XSS-Protection'] = '1; mode=block';
          $cors['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
          $cors['Referrer-Policy'] = 'strict-origin-when-cross-origin';
        }

        // Preflight OPTIONS.
        if ($method === 'OPTIONS') {
          $acrHeaders = $request->getHeaderLine('Access-Control-Request-Headers');
          $acrMethod = $request->getHeaderLine('Access-Control-Request-Method');
          $preflight = $cors;
          if ($acrHeaders !== '') {
            $preflight['Access-Control-Allow-Headers'] = $acrHeaders;
          }
          if ($acrMethod !== '') {
            $preflight['Access-Control-Allow-Methods'] = $acrMethod;
          }
          $preflight['Access-Control-Max-Age'] = '600';
          return new ReactResponse(204, [...$preflight, 'Content-Type' => 'application/json']);
        }

        // Логи запросов при info/debug.
        if ($this->config->logLevel === 'info' || $this->config->logLevel === 'debug') {
          $this->write(sprintf('[REQ] ip=%s ua=%s %s %s', $clientIp, $ua, $method, $path));
        }

        // /mcp — JSON манифест (совместимость клиентов).
        if ($method === 'GET' && $path === $base) {
          // Формируем список tools для манифеста.
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

          $manifest = [
            'protocolVersion' => '2025-06-18',
            'serverInfo' => ['name' => 'Politsin MCP Server', 'version' => '1.0.0'],
            'capabilities' => ['tools' => new \stdClass(), 'prompts' => new \stdClass(), 'resources' => new \stdClass()],
            'endpoints' => ['messages' => 'sse', 'requests' => 'mcp/requests'],
            'tools' => $toolsOut,
          ];
          return new ReactResponse(200, [...$cors, 'Content-Type' => 'application/json; charset=utf-8'], json_encode($manifest, JSON_UNESCAPED_UNICODE));
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
          $body = json_encode(['ok' => TRUE, 'query' => $params], JSON_UNESCAPED_UNICODE);
          return new ReactResponse(200, [...$cors, 'Content-Type' => 'application/json; charset=utf-8'], $body);
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
                  'capabilities' => [
                    'tools' => new \stdClass(),
                    'prompts' => new \stdClass(),
                    'resources' => new \stdClass(),
                  ],
                  'session' => ['id' => 'simple-mcp-session'],
                  'endpoints' => ['messages' => 'sse', 'requests' => 'mcp/requests'],
                ],
              ];
              $body = json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
              return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], $body);
            }

            // Ping — проверка связи.
            if ($rpcMethod === 'ping') {
              $resp = ['jsonrpc' => '2.0', 'id' => $id, 'result' => new \stdClass()];
              return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], json_encode($resp, JSON_UNESCAPED_UNICODE) . "\n");
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
                $err = [
                  'jsonrpc' => '2.0',
                  'id' => $id,
                  'error' => [
                    'code' => -32602,
                    'message' => 'Unknown tool: ' . $name,
                  ],
                ];
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
                $err = [
                  'jsonrpc' => '2.0',
                  'id' => $id,
                  'error' => [
                    'code' => -32000,
                    'message' => $e->getMessage(),
                  ],
                ];
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
                $err = [
                  'jsonrpc' => '2.0',
                  'id' => $id,
                  'error' => [
                    'code' => -32004,
                    'message' => 'Resource not found: ' . $uri,
                  ],
                ];
                return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], json_encode($err, JSON_UNESCAPED_UNICODE) . "\n");
              }
              $val = $this->config->resources[$uri];
              $isStructured = is_array($val) || is_object($val);
              $mime = $isStructured ? 'application/json' : 'text/plain';
              $text = $isStructured ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string) $val;
              $resp = [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                  'contents' => [
                  [
                    'uri' => $uri,
                    'mimeType' => $mime,
                    'text' => $text,
                  ],
                  ],
                ],
              ];
              return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], json_encode($resp, JSON_UNESCAPED_UNICODE) . "\n");
            }

            // По умолчанию: пустая строка NDJSON.
            return new ReactResponse(200, ['Content-Type' => 'application/x-ndjson; charset=utf-8'], "\n");
          }
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
                    ...$cors,
                    'Content-Type' => 'application/x-ndjson; charset=utf-8',
                    'Cache-Control' => 'no-cache',
                    'Connection' => 'keep-alive',
                  ];
                  if ($this->config->logLevel === 'debug') {
                    $this->write('[HTTP] stream opened ip=' . $clientIp . ' ua=' . $ua);
                  }
                  return new ReactResponse(200, $headers, $stream);
        }

              // /mcp/sse — улучшенный SSE стрим с полной MCP совместимостью.
        if ($method === 'GET' && $path === $base . '/sse') {
          // Контент-негациация: если клиент просит JSON, возвращаем манифест.
          $acceptHeader = $request->getHeaderLine('Accept');
          $acceptLower = is_string($acceptHeader) ? strtolower($acceptHeader) : '';
          if ($acceptLower !== '' && str_contains($acceptLower, 'application/json')) {
            // Формируем список tools для манифеста.
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

            $manifest = [
              'protocolVersion' => '2025-06-18',
              'serverInfo' => ['name' => 'Politsin MCP Server', 'version' => '1.0.0'],
              'capabilities' => [
                'tools' => new \stdClass(),
                'prompts' => new \stdClass(),
                'resources' => new \stdClass(),
              ],
              'endpoints' => ['messages' => 'sse', 'requests' => 'mcp/requests'],
              'tools' => $toolsOut,
            ];
            $body = json_encode($manifest, JSON_UNESCAPED_UNICODE);
            return new ReactResponse(200, ['Content-Type' => 'application/json; charset=utf-8'], $body);
          }

          $stream = new ThroughStream();

          // Генерируем session ID.
          try {
            $sessionId = bin2hex(random_bytes(16));
          }
          catch (\Throwable $e) {
            $sessionId = uniqid('mcp_', TRUE);
          }

          // Отправляем начальные кадры в futureTick.
          Loop::futureTick(function () use ($stream, $sessionId) {
            // Рекомендуем интервал реконнекта.
            $stream->write("retry: 3000\n");

            // Отправляем endpoint с sessionId.
            $stream->write("event: endpoint\n");
            $stream->write("data: /sse/message?sessionId={$sessionId}\n\n");

            // Инициализация.
            $stream->write("event: message\n");
            $stream->write("data: {\"type\":\"initialize\",\"protocolVersion\":\"2025-06-18\"}\n\n");

            // Padding для раннего флаша (небольшой).
            for ($i = 0; $i < 5; $i++) {
              $stream->write(": padding " . str_repeat('x', 20) . "\n");
            }
            $stream->write("\n");

            // Формируем список tools для манифеста.
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
            // Манифест в разных форматах для совместимости.
            $manifest = [
              'protocolVersion' => '2025-06-18',
              'serverInfo' => ['name' => 'Politsin MCP Server', 'version' => '1.0.0'],
              'capabilities' => [
                'tools' => new \stdClass(),
                'prompts' => new \stdClass(),
                'resources' => new \stdClass(),
              ],
              'endpoints' => ['messages' => 'sse', 'requests' => 'mcp/requests'],
              'tools' => $toolsOut,
            ];
            $manifestJson = json_encode(['manifest' => $manifest], JSON_UNESCAPED_UNICODE);
            $manifestTypedJson = json_encode(['type' => 'manifest', 'manifest' => $manifest], JSON_UNESCAPED_UNICODE);

            $stream->write("event: message\n");
            $stream->write("data: {$manifestJson}\n\n");
            $stream->write("event: message\n");
            $stream->write("data: {$manifestTypedJson}\n\n");
            $stream->write("event: manifest\n");
            $stream->write("data: {$manifestJson}\n\n");

            // Bootstrap запрос tools/list.
            $toolsListRequest = [
              'jsonrpc' => '2.0',
              'id' => 'bootstrap',
              'method' => 'tools/list',
            ];
            $toolsListJson = json_encode($toolsListRequest, JSON_UNESCAPED_UNICODE);
            $stream->write("event: message\n");
            $stream->write("data: {\"type\":\"request\",\"request\":{$toolsListJson}}\n\n");
          });

          // Ранние keep-alive каждую секунду первые 10 секунд.
          $earlyTimer = Loop::addPeriodicTimer(1.0, function () use ($stream) {
            if ($stream->isWritable()) {
              $stream->write(": keep-alive\n\n");
            }
          });
          Loop::addTimer(10.0, function () use ($earlyTimer) {
            Loop::cancelTimer($earlyTimer);
          });

          // Периодические пинги каждые 10 секунд.
          $timer = Loop::addPeriodicTimer(10.0, function () use ($stream) {
            if ($stream->isWritable()) {
              $stream->write(": ping " . date('c') . "\n\n");
            }
          });

          // Heartbeat каждые 30 секунд.
          $heartbeatTimer = Loop::addPeriodicTimer(30.0, function () use ($stream) {
            if ($stream->isWritable()) {
              $stream->write("event: message\n");
              $stream->write("data: {\"type\":\"heartbeat\",\"ts\":\"" . date('c') . "\"}\n\n");
            }
          });

          // Очистка при закрытии соединения.
          $stream->on('close', function () use ($timer, $earlyTimer, $heartbeatTimer) {
            Loop::cancelTimer($timer);
            Loop::cancelTimer($earlyTimer);
            Loop::cancelTimer($heartbeatTimer);
          });

                  $headers = [
                    ...$cors,
                    'Content-Type' => 'text/event-stream; charset=utf-8',
                    'Cache-Control' => 'no-cache, no-transform',
                    'X-Accel-Buffering' => 'no',
                    'Connection' => 'keep-alive',
                    'mcp-session-id' => $sessionId,
                  ];

                  if ($this->config->logLevel === 'debug') {
                    $this->write('[SSE] connection opened ip=' . $clientIp . ' ua=' . $ua . ' session=' . $sessionId);
                  }

                  return new ReactResponse(200, $headers, $stream);
        }

        // /mcp/sse/message — обработка POST сообщений для существующих сессий.
        if ($method === 'POST' && str_starts_with($path, $base . '/sse/message')) {
          $query = $request->getUri()->getQuery();
          $params = [];
          if ($query !== '') {
            parse_str($query, $params);
          }
          $sessionId = $params['sessionId'] ?? '';

          if ($sessionId === '') {
            return new ReactResponse(400, [...$cors, 'Content-Type' => 'text/plain; charset=utf-8'], 'Missing sessionId. Expected POST to /sse to initiate new one');
          }

          $raw = (string) $request->getBody();
          $payload = json_decode($raw, TRUE);

          if ($this->config->logLevel === 'debug') {
            $this->write('[SSE-MSG] session=' . $sessionId . ' payload=' . $raw);
          }

          // Простая обработка сообщений - возвращаем echo.
          $response = [
            'jsonrpc' => '2.0',
            'id' => $payload['id'] ?? NULL,
            'result' => [
              'echo' => $payload,
              'sessionId' => $sessionId,
            ],
          ];

          return new ReactResponse(200, [...$cors, 'Content-Type' => 'application/json; charset=utf-8'], json_encode($response, JSON_UNESCAPED_UNICODE));
        }

        if ($this->config->logLevel === 'debug') {
          $this->write('[RESP] 404 not_found');
        }
        return new ReactResponse(404, [...$cors, 'Content-Type' => 'application/json; charset=utf-8'], json_encode(['error' => 'not_found'], JSON_UNESCAPED_UNICODE));
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
   *   Function(string $line): void.
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
