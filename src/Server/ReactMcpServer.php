<?php

declare(strict_types=1);

namespace Politsin\Mcp\Server;

use Politsin\Mcp\Config\McpConfig;
use Politsin\Mcp\Session\SessionManager;
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

  /**
   * Менеджер сессий.
   *
   * @var \Politsin\Mcp\Session\SessionManager
   */
  private SessionManager $sessionManager;

  public function __construct(McpConfig $config) {
    $this->config = $config;
    $this->sessionManager = new SessionManager($config);
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
   * Создает ответ с автоматическими CORS заголовками.
   */
  private function createResponse(int $statusCode, array $headers = [], $body = ''): ReactResponse {
    $cors = [
      'Access-Control-Allow-Origin' => '*',
      'Access-Control-Allow-Headers' => 'Content-Type, mcp-session-id, mcp-protocol-version',
      'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
      'Access-Control-Allow-Credentials' => 'false',
      'Access-Control-Expose-Headers' => 'mcp-session-id',
      'Access-Control-Max-Age' => '0',
    ];

    $allHeaders = [...$cors, ...$headers];

    // Логируем все заголовки ответа.
    if ($this->config->logLevel === 'debug' || $this->config->logLevel === 'info') {
      $headerLog = [];
      foreach ($allHeaders as $name => $value) {
        $headerLog[] = "{$name}: {$value}";
      }
      $this->write('[RESP-HEADERS] ' . implode(' | ', $headerLog));
    }

    return new ReactResponse($statusCode, $allHeaders, $body);
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

        // Получаем Origin для preflight запросов.
        $originHeader = $request->getHeaderLine('Origin');

                // Preflight OPTIONS.
      if ($method === 'OPTIONS') {
        $acrHeaders = $request->getHeaderLine('Access-Control-Request-Headers');
        $acrMethod = $request->getHeaderLine('Access-Control-Request-Method');
        $headers = [];
        if ($acrHeaders !== '') {
          $headers['Access-Control-Allow-Headers'] = $acrHeaders;
        }
        if ($acrMethod !== '') {
          $headers['Access-Control-Allow-Methods'] = $acrMethod;
        }
        return $this->createResponse(204, $headers);
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
          'protocolVersion' => '2024-11-05',
          'serverInfo' => ['name' => 'Politsin MCP Server', 'version' => '1.0.0'],
          'capabilities' => ['tools' => ['listChanged' => TRUE]],
          'endpoints' => ['messages' => 'sse', 'requests' => 'mcp/requests'],
          'tools' => $toolsOut,
        ];
        return $this->createResponse(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($manifest, JSON_UNESCAPED_UNICODE));
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
          return $this->createResponse(200, ['Content-Type' => 'application/json; charset=utf-8'], $body);
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
          return $this->createResponse(200, ['Content-Type' => 'application/json; charset=utf-8'], $body);
        }

        $stream = new ThroughStream();

        // Генерируем session ID.
        try {
          $sessionId = bin2hex(random_bytes(16));
        }
        catch (\Throwable $e) {
          $sessionId = uniqid('mcp_', TRUE);
        }

        // Создаем сессию.
        $this->sessionManager->createSession($sessionId, [
          'client_ip' => $clientIp,
          'user_agent' => $ua,
          'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Отправляем начальные кадры в futureTick.
        Loop::futureTick(function () use ($stream, $sessionId) {
          // Отправляем endpoint с sessionId.
          $stream->write("event: endpoint\n");
          $stream->write("data: /mcp/sse/message?sessionId={$sessionId}\n\n");

          // Инициализация (JSON-RPC ответ).
          $initResponse = [
            'jsonrpc' => '2.0',
            'id' => 'init-1',
            'result' => [
              'protocolVersion' => '2024-11-05',
              'capabilities' => ['tools' => ['listChanged' => TRUE]],
              'serverInfo' => ['name' => 'Politsin MCP Server', 'version' => '1.0.0'],
            ],
          ];
          $stream->write("event: message\n");
          $stream->write("data: " . json_encode($initResponse, JSON_UNESCAPED_UNICODE) . "\n\n");

          // Формируем список tools.
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

          // Tools/list ответ (JSON-RPC).
          $toolsListResponse = [
            'jsonrpc' => '2.0',
            'id' => 'tool-list-1',
            'result' => ['tools' => $toolsOut],
          ];
          $stream->write("event: message\n");
          $stream->write("data: " . json_encode($toolsListResponse, JSON_UNESCAPED_UNICODE) . "\n\n");

          // Дублируем tools/list ответ (как в demo-day).
          $stream->write("event: message\n");
          $stream->write("data: " . json_encode($toolsListResponse, JSON_UNESCAPED_UNICODE) . "\n\n");
        });

        // Простой keep-alive каждые 30 секунд.
        $timer = Loop::addPeriodicTimer(30.0, function () use ($stream) {
          if ($stream->isWritable()) {
            $stream->write(": ping\n\n");
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
        $stream->on('close', function () use ($timer) {
          Loop::cancelTimer($timer);
        });

                $headers = [
                  'Content-Type' => 'text/event-stream; charset=utf-8',
                  'Cache-Control' => 'no-cache, no-transform',
                  'X-Accel-Buffering' => 'no',
                  'Connection' => 'keep-alive',
                  'mcp-session-id' => $sessionId,
                ];

                if ($this->config->logLevel === 'debug') {
                  $this->write('[SSE] connection opened ip=' . $clientIp . ' ua=' . $ua . ' session=' . $sessionId);
                }

                return $this->createResponse(200, $headers, $stream);
      }

        // /mcp/sse/message и /sse/message — обработка POST сообщений для существующих сессий.
      if ($method === 'POST' && (str_starts_with($path, $base . '/sse/message') || $path === '/sse/message')) {
        $query = $request->getUri()->getQuery();
        $params = [];
        if ($query !== '') {
          parse_str($query, $params);
        }
        $sessionId = $params['sessionId'] ?? '';

        if ($sessionId === '') {
          return $this->createResponse(400, ['Content-Type' => 'text/plain; charset=utf-8'], 'Missing sessionId. Expected POST to /sse to initiate new one');
        }

        // Проверяем существование сессии.
        if (!$this->sessionManager->sessionExists($sessionId)) {
          return $this->createResponse(404, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(['error' => 'session_not_found'], JSON_UNESCAPED_UNICODE));
        }

        $raw = (string) $request->getBody();
        $payload = json_decode($raw, TRUE);

        if ($this->config->logLevel === 'debug') {
          $this->write('[SSE-MSG] session=' . $sessionId . ' payload=' . $raw);
        }

        // Обновляем активность сессии.
        $this->sessionManager->updateSession($sessionId, [
          'last_message' => $payload,
          'message_count' => ($this->sessionManager->getSession($sessionId)['data']['message_count'] ?? 0) + 1,
        ]);

        // POST на /sse/message должен возвращать SSE поток (как demo-day).
        $stream = new ThroughStream();

        // Отправляем SSE поток с обработкой сообщения.
        Loop::futureTick(function () use ($stream, $payload, $sessionId) {
          // Обрабатываем JSON-RPC сообщение.
          $rpcMethod = (string) ($payload['method'] ?? '');
          $id = $payload['id'] ?? NULL;

          if ($rpcMethod === 'initialize') {
            // Отправляем initialize response.
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => ['listChanged' => TRUE]],
                'serverInfo' => ['name' => 'Politsin MCP Server', 'version' => '1.0.0'],
              ],
            ];
            $stream->write("data: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n\n");
          }
          elseif ($rpcMethod === 'tools/list') {
            // Отправляем tools/list response.
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
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => ['tools' => $toolsOut],
            ];
            $stream->write("data: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n\n");
          }
          elseif ($rpcMethod === 'notifications/initialized') {
            // Отправляем пустой ответ для notifications/initialized.
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => new \stdClass(),
            ];
            $stream->write("data: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n\n");
          }
          else {
            // Неизвестный метод - отправляем ошибку.
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'error' => [
                'code' => -32601,
                'message' => 'Method not found: ' . $rpcMethod,
              ],
            ];
            $stream->write("data: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n\n");
          }
        });

        return $this->createResponse(202, ['Content-Type' => 'text/event-stream; charset=utf-8'], $stream);
      }

      // /mcp/requests — HTTP JSON-RPC endpoint (как в манифесте).
      if ($method === 'POST' && $path === $base . '/requests') {
        $raw = (string) $request->getBody();
        $payload = json_decode($raw, TRUE);

        if ($this->config->logLevel === 'debug') {
          $this->write('[REQUESTS] payload=' . $raw);
        }

        if (!is_array($payload)) {
          return $this->createResponse(400, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(['error' => 'invalid_json'], JSON_UNESCAPED_UNICODE));
        }

        $rpcMethod = (string) ($payload['method'] ?? '');
        $id = $payload['id'] ?? NULL;

        if ($rpcMethod === 'initialize') {
          $params = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
          $proto = (string) ($params['protocolVersion'] ?? '');
          $client = isset($params['clientInfo']) && is_array($params['clientInfo']) ? $params['clientInfo'] : [];
          $clientName = (string) ($client['name'] ?? '');
          $clientVer = (string) ($client['version'] ?? '');

          $this->write('[REQUESTS-INIT] ip=' . $clientIp . ' ua=' . $ua . ' protocol=' . ($proto ?: 'n/a') . ' client=' . ($clientName ?: 'n/a') . ' v=' . ($clientVer ?: 'n/a'));

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
              'session' => ['id' => 'http-session'],
              'endpoints' => ['messages' => 'sse', 'requests' => 'mcp/requests'],
            ],
          ];
          return $this->createResponse(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($result, JSON_UNESCAPED_UNICODE));
        }
        elseif ($rpcMethod === 'tools/list') {
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
          $response = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => $toolsOut],
          ];
          return $this->createResponse(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($response, JSON_UNESCAPED_UNICODE));
        }
        else {
          $response = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
              'code' => -32601,
              'message' => 'Method not found: ' . $rpcMethod,
            ],
          ];
          return $this->createResponse(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($response, JSON_UNESCAPED_UNICODE));
        }
      }

      // /mcp/sessions — статистика сессий.
      if ($method === 'GET' && $path === $base . '/sessions') {
        $stats = $this->sessionManager->getSessionStats();
        return $this->createResponse(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($stats, JSON_UNESCAPED_UNICODE));
      }

      // /mcp/sessions/{sessionId} — информация о конкретной сессии.
      if ($method === 'GET' && preg_match('#^' . preg_quote($base . '/sessions/', '#') . '([a-f0-9]+)$#', $path, $matches)) {
        $sessionId = $matches[1];
        $session = $this->sessionManager->getSession($sessionId);
        if ($session === NULL) {
          return $this->createResponse(404, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(['error' => 'session_not_found'], JSON_UNESCAPED_UNICODE));
        }
        return $this->createResponse(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($session, JSON_UNESCAPED_UNICODE));
      }

      if ($this->config->logLevel === 'debug') {
        $this->write('[RESP] 404 not_found');
      }
        return $this->createResponse(404, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(['error' => 'not_found'], JSON_UNESCAPED_UNICODE));
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
