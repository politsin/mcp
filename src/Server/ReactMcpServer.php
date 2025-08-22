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
   * Создает ответ с максимально разрешающими CORS заголовками для всех запросов.
   */
  private function createResponse(int $statusCode, array $headers = [], $body = ''): ReactResponse {
    // Максимально разрешающие CORS заголовки - разрешаем ВСЁ.
    $cors = [
      'Access-Control-Allow-Origin' => '*',
      'Access-Control-Allow-Methods' => '*',
      'Access-Control-Allow-Headers' => '*',
      'Access-Control-Allow-Credentials' => 'false',
      'Access-Control-Expose-Headers' => '*',
      'Access-Control-Max-Age' => '86400',
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

                // Preflight OPTIONS - отвечаем на ВСЕ OPTIONS запросы с максимальными разрешениями.
      if ($method === 'OPTIONS') {
        // Разрешаем абсолютно всё что запрашивается.
        return $this->createResponse(204, []);
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
          'endpoints' => [
            'messages' => 'sse',
            'requests' => 'mcp/requests',
            'http' => 'mcp/http',
          ],
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

      // /mcp/http — Streamable HTTP transport (новая спецификация MCP).
      if ($method === 'POST' && $path === $base . '/http') {
        // Проверяем Accept заголовок.
        $acceptHeader = $request->getHeaderLine('Accept');
        $acceptsJson = str_contains($acceptHeader, 'application/json');
        $acceptsSse = str_contains($acceptHeader, 'text/event-stream');

        if (!$acceptsJson && !$acceptsSse) {
          return $this->createResponse(400, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(['error' => 'missing_accept_header'], JSON_UNESCAPED_UNICODE));
        }

        $raw = (string) $request->getBody();
        $payload = json_decode($raw, TRUE);

        // Поддержка как одиночных сообщений, так и батчей.
        $messages = is_array($payload) && isset($payload[0]) ? $payload : [$payload];
        $hasRequests = FALSE;
        $hasResponses = FALSE;
        $hasNotifications = FALSE;

        // Анализируем содержимое.
        foreach ($messages as $msg) {
          if (!is_array($msg)) {
            continue;
          }
          if (isset($msg['method'])) {
            $hasRequests = TRUE;
          }
          elseif (isset($msg['result']) || isset($msg['error'])) {
            $hasResponses = TRUE;
          }
          else {
            $hasNotifications = TRUE;
          }
        }

        // Если только responses/notifications - возвращаем 202 Accepted.
        if (!$hasRequests && ($hasResponses || $hasNotifications)) {
          return $this->createResponse(202);
        }

                // Если есть requests - обрабатываем и возвращаем ответ.
        if ($hasRequests) {
          $responses = [];
          $sessionId = $request->getHeaderLine('Mcp-Session-Id');

          // Если есть session ID, проверяем сессию.
          if ($sessionId !== '') {
            $session = $this->sessionManager->getSession($sessionId);
            if ($session === NULL) {
              return $this->createResponse(404, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(['error' => 'session_not_found'], JSON_UNESCAPED_UNICODE));
            }
          }

          foreach ($messages as $msg) {
            if (!is_array($msg) || !isset($msg['method'])) {
              continue;
            }

            $rpcMethod = (string) ($msg['method'] ?? '');
            $id = $msg['id'] ?? NULL;

            $this->write('[JSON-RPC] method=' . $rpcMethod . ' id=' . ($id ?: 'null') . ' ip=' . $clientIp);

            if ($rpcMethod === 'initialize') {
              $params = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : [];
              $proto = (string) ($params['protocolVersion'] ?? '');
              $client = isset($params['clientInfo']) && is_array($params['clientInfo']) ? $params['clientInfo'] : [];
              $clientName = (string) ($client['name'] ?? '');
              $clientVer = (string) ($client['version'] ?? '');
              $caps = isset($params['capabilities']) && is_array($params['capabilities']) ? array_keys($params['capabilities']) : [];

              $this->write('[INIT] ip=' . $clientIp . ' ua=' . $ua . ' protocol=' . ($proto ?: 'n/a') . ' client=' . ($clientName ?: 'n/a') . ' v=' . ($clientVer ?: 'n/a') . ' caps=' . json_encode($caps));

              // Генерируем session ID.
              $sessionId = bin2hex(random_bytes(32));

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
                  'session' => ['id' => $sessionId],
                ],
              ];

              $responses[] = $result;

              // Создаем сессию.
              $this->sessionManager->createSession($sessionId, [
                'client_ip' => $clientIp,
                'user_agent' => $ua,
                'created_at' => date('Y-m-d H:i:s'),
              ]);
            }

            // Ping — проверка связи.
            elseif ($rpcMethod === 'ping') {
              $resp = ['jsonrpc' => '2.0', 'id' => $id, 'result' => new \stdClass()];
              $responses[] = $resp;
            }

            // tools/list — перечислить доступные тулзы.
            elseif ($rpcMethod === 'tools/list') {
              $this->write('[TOOLS-LIST] requested by ip=' . $clientIp . ' ua=' . $ua);

              $toolsOut = [];
              foreach (array_keys($this->config->tools) as $toolName) {
                $toolsOut[] = [
                  'name' => $toolName,
                  'description' => 'Tool ' . $toolName,
                  'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                    'additionalProperties' => FALSE,
                  ],
                ];
              }

              $resp = ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $toolsOut]];
              $responses[] = $resp;

              $this->write('[TOOLS-LIST] returned ' . count($toolsOut) . ' tools');
            }

            // tools/call — вызвать зарегистрированный тул.
            elseif ($rpcMethod === 'tools/call') {
              $paramsIn = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : [];
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
                $responses[] = $err;
              }
              else {
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
                  $responses[] = $resp;
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
                  $responses[] = $err;
                }
              }
            }

            // resources/list — список доступных ресурсов.
            elseif ($rpcMethod === 'resources/list') {
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
              $responses[] = $resp;
            }

            // resources/read — чтение контента ресурса по uri.
            elseif ($rpcMethod === 'resources/read') {
              $paramsIn = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : [];
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
                $responses[] = $err;
              }
              else {
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
                $responses[] = $resp;
              }
            }
          }

          // Возвращаем ответы в зависимости от Accept заголовка.
          if ($acceptsSse) {
            // SSE поток.
            $stream = new ThroughStream();
            Loop::futureTick(function () use ($stream, $responses) {
              foreach ($responses as $response) {
                $stream->write("data: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n\n");
              }
              $stream->end();
            });
            return new ReactResponse(200, ['Content-Type' => 'text/event-stream; charset=utf-8'], $stream);
          }
          else {
            // JSON ответ.
            $headers = ['Content-Type' => 'application/json; charset=utf-8'];

            // Добавляем Mcp-Session-Id заголовок для initialize ответа.
            foreach ($responses as $response) {
              if (isset($response['result']['session']['id'])) {
                $headers['Mcp-Session-Id'] = $response['result']['session']['id'];
                break;
              }
            }

            if (count($responses) === 1) {
              return $this->createResponse(200, $headers, json_encode($responses[0], JSON_UNESCAPED_UNICODE));
            }
            else {
              return $this->createResponse(200, $headers, json_encode($responses, JSON_UNESCAPED_UNICODE));
            }
          }
        }
      }

      // /mcp/http — GET для SSE потока (Streamable HTTP).
      if ($method === 'GET' && $path === $base . '/http') {
        // Проверяем Accept заголовок.
        $acceptHeader = $request->getHeaderLine('Accept');
        if (!str_contains($acceptHeader, 'text/event-stream')) {
          return $this->createResponse(405, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE));
        }

        // Проверяем session ID.
        $sessionId = $request->getHeaderLine('Mcp-Session-Id');
        if ($sessionId === '') {
          return $this->createResponse(400, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(['error' => 'missing_session_id'], JSON_UNESCAPED_UNICODE));
        }

        $session = $this->sessionManager->getSession($sessionId);
        if ($session === NULL) {
          return $this->createResponse(404, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(['error' => 'session_not_found'], JSON_UNESCAPED_UNICODE));
        }

        $stream = new ThroughStream();

        // Отправляем начальные сообщения.
        Loop::futureTick(function () use ($stream) {
          $stream->write("event: message\n");
          $stream->write("data: {\"type\":\"stream_opened\",\"ts\":\"" . date('c') . "\"}\n\n");
        });

        // Периодические heartbeat сообщения.
        $timer = Loop::addPeriodicTimer(30.0, function () use ($stream) {
          if ($stream->isWritable()) {
            $stream->write("event: message\n");
            $stream->write("data: {\"type\":\"heartbeat\",\"ts\":\"" . date('c') . "\"}\n\n");
          }
        });

        $stream->on('close', function () use ($timer) {
          Loop::cancelTimer($timer);
        });

        $headers = [
          'Content-Type' => 'text/event-stream; charset=utf-8',
          'Cache-Control' => 'no-cache, no-transform',
          'X-Accel-Buffering' => 'no',
          'Connection' => 'keep-alive',
        ];

        if ($this->config->logLevel === 'debug') {
          $this->write('[HTTP-SSE] stream opened ip=' . $clientIp . ' ua=' . $ua . ' session=' . $sessionId);
        }

        return $this->createResponse(200, $headers, $stream);
      }

      // /mcp/http — DELETE для завершения сессии.
      if ($method === 'DELETE' && $path === $base . '/http') {
        $sessionId = $request->getHeaderLine('Mcp-Session-Id');
        if ($sessionId === '') {
          return $this->createResponse(400, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(['error' => 'missing_session_id'], JSON_UNESCAPED_UNICODE));
        }

        $session = $this->sessionManager->getSession($sessionId);
        if ($session === NULL) {
          return $this->createResponse(404, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(['error' => 'session_not_found'], JSON_UNESCAPED_UNICODE));
        }

        // Удаляем сессию.
        $this->sessionManager->deleteSession($sessionId);

        if ($this->config->logLevel === 'debug') {
          $this->write('[HTTP-DELETE] session terminated ip=' . $clientIp . ' ua=' . $ua . ' session=' . $sessionId);
        }

        return $this->createResponse(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(['ok' => TRUE], JSON_UNESCAPED_UNICODE));
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

          // Инициализация (тип initialize).
          $initMessage = [
            'type' => 'initialize',
            'protocolVersion' => '2024-11-05',
          ];
          $stream->write("event: message\n");
          $stream->write("data: " . json_encode($initMessage, JSON_UNESCAPED_UNICODE) . "\n\n");

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

          // Манифест.
          $manifest = [
            'protocolVersion' => '2024-11-05',
            'capabilities' => ['tools' => ['listChanged' => TRUE]],
            'serverInfo' => ['name' => 'Politsin MCP Server', 'version' => '1.0.0'],
            'endpoints' => ['messages' => 'sse', 'requests' => 'mcp/requests'],
            'tools' => $toolsOut,
          ];

          // Манифест в формате data.
          $stream->write("event: message\n");
          $stream->write("data: " . json_encode(['manifest' => $manifest], JSON_UNESCAPED_UNICODE) . "\n\n");

          // Манифест в формате type=manifest.
          $stream->write("event: message\n");
          $stream->write("data: " . json_encode(['type' => 'manifest', 'manifest' => $manifest], JSON_UNESCAPED_UNICODE) . "\n\n");

          // Манифест в формате event: manifest.
          $stream->write("event: manifest\n");
          $stream->write("data: " . json_encode(['manifest' => $manifest], JSON_UNESCAPED_UNICODE) . "\n\n");

          // Bootstrap запрос tools/list.
          $bootstrapRequest = [
            'type' => 'request',
            'request' => [
              'jsonrpc' => '2.0',
              'id' => 'bootstrap',
              'method' => 'tools/list',
            ],
          ];
          $stream->write("event: message\n");
          $stream->write("data: " . json_encode($bootstrapRequest, JSON_UNESCAPED_UNICODE) . "\n\n");
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
        $this->write('[RESP] 404 not_found - but with CORS');
      }

      // На ВСЕ запросы (даже 404) отвечаем с полными CORS заголовками.
      $errorData = [
        'error' => 'not_found',
        'message' => 'Endpoint not found, but CORS headers are provided',
      ];
      return $this->createResponse(404, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($errorData, JSON_UNESCAPED_UNICODE));
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
