<?php

declare(strict_types=1);

namespace Politsin\Mcp\Server;

use Politsin\Mcp\Config\McpConfig;
use Politsin\Mcp\Session\SessionManager;
use Politsin\Mcp\Tool\ToolInterface;
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
   * Активные SSE-потоки по sessionId.
   *
   * @var array<string, \React\Stream\ThroughStream>
   */
  private array $sseStreams = [];

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
    // CORS заголовки как в демо-сервере.
    $cors = [
      'Access-Control-Allow-Origin' => '*',
      'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers' => 'Content-Type, mcp-session-id, mcp-protocol-version',
      'Access-Control-Allow-Credentials' => 'false',
      'Access-Control-Expose-Headers' => 'mcp-session-id',
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

                // Preflight OPTIONS - отвечаем как демо-сервер.
      if ($method === 'OPTIONS') {
        // Возвращаем 200 как демо-сервер для OPTIONS /sse.
        return $this->createResponse(200, []);
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
              'properties' => new \stdClass(),
              'required' => [],
              'additionalProperties' => FALSE,
            ],
          ];
        }

        // Формируем endpoints: относительные по умолчанию, абсолютные при включённой опции.
        $endpoints = [
          'messages' => 'sse',
          'requests' => 'mcp/requests',
          'http' => 'mcp/http',
        ];
        if ($this->config->absoluteEndpoints) {
          $xfProto = $request->getHeaderLine('X-Forwarded-Proto');
          $protoOut = $this->config->endpointBaseUrl ? '' : ($xfProto !== '' ? $xfProto : ($request->getUri()->getScheme() ?: 'https'));
          $hostOut = $this->config->endpointBaseUrl ? '' : ($request->getHeaderLine('Host') ?: $request->getUri()->getHost());
          $baseUrl = $this->config->endpointBaseUrl ?: ($protoOut . '://' . $hostOut . $base);
          $endpoints = [
            'messages' => $baseUrl . '/sse',
            'requests' => $baseUrl . '/requests',
            'http' => $baseUrl . '/http',
          ];
        }

        $manifest = [
          'protocolVersion' => '2024-11-05',
          'serverInfo' => ['name' => 'Politsin MCP Server', 'version' => '1.0.0'],
          'capabilities' => ['tools' => ['listChanged' => TRUE]],
          'endpoints' => $endpoints,
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

              // Готовим endpoints в initialize результате: относительные/абсолютные.
              $endpointsInit = ['messages' => 'sse', 'requests' => 'mcp/requests'];
              if ($this->config->absoluteEndpoints) {
                $xfProto = $request->getHeaderLine('X-Forwarded-Proto');
                $protoOut = $this->config->endpointBaseUrl ? '' : ($xfProto !== '' ? $xfProto : ($request->getUri()->getScheme() ?: 'https'));
                $hostOut = $this->config->endpointBaseUrl ? '' : ($request->getHeaderLine('Host') ?: $request->getUri()->getHost());
                $baseUrl = $this->config->endpointBaseUrl ?: ($protoOut . '://' . $hostOut . $base);
                $endpointsInit = ['messages' => $baseUrl . '/sse', 'requests' => $baseUrl . '/requests'];
              }

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
                  'endpoints' => $endpointsInit,
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
              foreach ($this->config->tools as $key => $def) {
                $toolName = is_string($key) ? $key : (is_object($def) && $def instanceof ToolInterface ? $def->getName() : (is_string($def) ? $def : ''));
                $desc = 'Tool ' . $toolName;
                $schema = [
                  'type' => 'object',
                  'properties' => new \stdClass(),
                  'required' => [],
                  'additionalProperties' => FALSE,
                ];
                if (is_object($def) && $def instanceof ToolInterface) {
                  $desc = $def->getDescription();
                  $schema = $def->getInputSchema();
                }
                elseif (is_string($def) && class_exists($def) && is_subclass_of($def, ToolInterface::class)) {
                  try {
                    $inst = new $def();
                    $desc = $inst->getDescription();
                    $schema = $inst->getInputSchema();
                  }
                  catch (\Throwable $e) {
                  }
                }
                $toolsOut[] = [
                  'name' => $toolName,
                  'description' => $desc,
                  'inputSchema' => $schema,
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

        // ИСПРАВЛЕНИЕ: Убираем неправильные сообщения типа {"type":"..."}.
        // Клиент ожидает только JSON-RPC 2.0 сообщения.
        // Отправляем только ping для keep-alive.
        Loop::futureTick(function () use ($stream) {
          $stream->write(": ping " . date('c') . "\n\n");
        });

        // ИСПРАВЛЕНИЕ: Убираем неправильные heartbeat сообщения типа {"type":"..."}.
        // Клиент ожидает только JSON-RPC 2.0 сообщения.
        // Оставляем только ping комментарии для keep-alive.
        $timer = Loop::addPeriodicTimer(30.0, function () use ($stream) {
          if ($stream->isWritable()) {
            $stream->write(": ping " . date('c') . "\n\n");
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
        // ИСПРАВЛЕНИЕ: Игнорируем Accept заголовок и всегда возвращаем SSE поток
        // как это делает демо-сервер. Клиент ChatMCP отправляет application/json,
        // но ожидает SSE поток.
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

        // Сохраняем поток для ответов по sessionId.
        $this->sseStreams[$sessionId] = $stream;

        // Отправляем только endpoint; JSON-RPC ответы будут транслироваться
        // в этот же поток при POST /mcp/sse/message.
        Loop::futureTick(function () use ($stream, $sessionId) {
          $stream->write("event: endpoint\n");
          $stream->write("data: /mcp/sse/message?sessionId={$sessionId}\n\n");
        });

        // Простой keep-alive каждые 30 секунд.
        $timer = Loop::addPeriodicTimer(30.0, function () use ($stream) {
          if ($stream->isWritable()) {
            $stream->write(": ping\n\n");
          }
        });

        // ИСПРАВЛЕНИЕ: Убираем неправильные heartbeat сообщения типа {"type":"..."}.
        // Клиент ожидает только JSON-RPC 2.0 сообщения.
        // Оставляем только ping комментарии для keep-alive.
        $heartbeatTimer = Loop::addPeriodicTimer(30.0, function () use ($stream) {
          if ($stream->isWritable()) {
            $stream->write(": ping " . date('c') . "\n\n");
          }
        });

        // Очистка при закрытии соединения.
        $stream->on('close', function () use ($timer, $sessionId) {
          Loop::cancelTimer($timer);
          if (isset($this->sseStreams[$sessionId])) {
            unset($this->sseStreams[$sessionId]);
          }
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

        // Сформируем ответ и отправим его в существующий SSE-поток сессии,
        // как ожидают клиенты (demo-day поведение).
        $target = $this->sseStreams[$sessionId] ?? NULL;

        if ($target instanceof ThroughStream && $target->isWritable()) {
          $rpcMethod = (string) ($payload['method'] ?? '');
          $id = $payload['id'] ?? NULL;
          $response = NULL;

          if ($rpcMethod === 'initialize') {
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => ['listChanged' => TRUE]],
                'serverInfo' => ['name' => 'Politsin MCP Server', 'version' => '1.0.0'],
              ],
            ];
          }
          elseif ($rpcMethod === 'ping') {
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => new \stdClass(),
            ];
          }
          elseif ($rpcMethod === 'tools/list') {
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
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => ['tools' => $toolsOut],
            ];
          }
          elseif ($rpcMethod === 'tools/call') {
            $paramsIn = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
            $name = (string) ($paramsIn['name'] ?? ($paramsIn['tool'] ?? ''));
            $arguments = isset($paramsIn['arguments']) && is_array($paramsIn['arguments']) ? $paramsIn['arguments'] : [];

            if ($name === '' || !isset($this->config->tools[$name])) {
              $response = [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                  'code' => -32602,
                  'message' => 'Unknown tool: ' . $name,
                ],
              ];
            }
            else {
              try {
                $def = $this->config->tools[$name];
                if (is_object($def) && $def instanceof ToolInterface) {
                  $resultVal = $def->execute($arguments);
                }
                elseif (is_string($def) && class_exists($def) && is_subclass_of($def, ToolInterface::class)) {
                  $inst = new $def();
                  $resultVal = $inst->execute($arguments);
                }
                else {
                  $callable = $def;
                  $resultVal = empty($arguments) ? $callable() : $callable($arguments);
                }
                $resultJson = json_encode($resultVal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $response = [
                  'jsonrpc' => '2.0',
                  'id' => $id,
                  'result' => [
                    'content' => [['type' => 'text', 'text' => $resultJson]],
                    'isError' => FALSE,
                  ],
                ];
              }
              catch (\Throwable $e) {
                $response = [
                  'jsonrpc' => '2.0',
                  'id' => $id,
                  'error' => [
                    'code' => -32000,
                    'message' => $e->getMessage(),
                  ],
                ];
              }
            }
          }
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
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => ['resources' => $resourcesOut],
            ];
          }
          elseif ($rpcMethod === 'resources/read') {
            $paramsIn = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
            $uri = (string) ($paramsIn['uri'] ?? '');
            if ($uri === '' || !array_key_exists($uri, $this->config->resources)) {
              $response = [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                  'code' => $uri === '' ? -32602 : -32004,
                  'message' => $uri === '' ? 'Param uri is required' : ('Resource not found: ' . $uri),
                ],
              ];
            }
            else {
              $val = $this->config->resources[$uri];
              $isStructured = is_array($val) || is_object($val);
              $mime = $isStructured ? 'application/json' : 'text/plain';
              $text = $isStructured ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string) $val;
              $response = [
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
            }
          }
          elseif ($rpcMethod === 'notifications/initialized') {
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => new \stdClass(),
            ];
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
          }

          // Пишем в основной SSE‑поток клиента.
          $target->write("event: message\n");
          $target->write("data: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n\n");

          // Возвращаем 202 Accepted коротким SSE‑ответом (совместимость с demo-day).
          return $this->createResponse(202, ['Content-Type' => 'text/event-stream; charset=utf-8'], "data: {}\n\n");
        }

        // Fallback: если SSE‑поток не найден (например, клиент не держит GET),
        // возвращаем ответ как раньше — в SSE‑теле POST ответа.
        $stream = new ThroughStream();
        Loop::futureTick(function () use ($stream, $payload) {
          $rpcMethod = (string) ($payload['method'] ?? '');
          $id = $payload['id'] ?? NULL;
          $response = NULL;
          if ($rpcMethod === 'initialize') {
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => ['listChanged' => TRUE]],
                'serverInfo' => ['name' => 'Politsin MCP Server', 'version' => '1.0.0'],
              ],
            ];
          }
          elseif ($rpcMethod === 'ping') {
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => new \stdClass(),
            ];
          }
          elseif ($rpcMethod === 'tools/list') {
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
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => ['tools' => $toolsOut],
            ];
          }
          elseif ($rpcMethod === 'tools/call') {
            $paramsIn = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
            $name = (string) ($paramsIn['name'] ?? ($paramsIn['tool'] ?? ''));
            $arguments = isset($paramsIn['arguments']) && is_array($paramsIn['arguments']) ? $paramsIn['arguments'] : [];

            if ($name === '' || !isset($this->config->tools[$name])) {
              $response = [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                  'code' => -32602,
                  'message' => 'Unknown tool: ' . $name,
                ],
              ];
            }
            else {
              try {
                $callable = $this->config->tools[$name];
                $resultVal = empty($arguments) ? $callable() : $callable($arguments);
                $resultJson = json_encode($resultVal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $response = [
                  'jsonrpc' => '2.0',
                  'id' => $id,
                  'result' => [
                    'content' => [['type' => 'text', 'text' => $resultJson]],
                    'isError' => FALSE,
                  ],
                ];
              }
              catch (\Throwable $e) {
                $response = [
                  'jsonrpc' => '2.0',
                  'id' => $id,
                  'error' => [
                    'code' => -32000,
                    'message' => $e->getMessage(),
                  ],
                ];
              }
            }
          }
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
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => ['resources' => $resourcesOut],
            ];
          }
          elseif ($rpcMethod === 'resources/read') {
            $paramsIn = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
            $uri = (string) ($paramsIn['uri'] ?? '');
            if ($uri === '' || !array_key_exists($uri, $this->config->resources)) {
              $response = [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                  'code' => $uri === '' ? -32602 : -32004,
                  'message' => $uri === '' ? 'Param uri is required' : ('Resource not found: ' . $uri),
                ],
              ];
            }
            else {
              $val = $this->config->resources[$uri];
              $isStructured = is_array($val) || is_object($val);
              $mime = $isStructured ? 'application/json' : 'text/plain';
              $text = $isStructured ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string) $val;
              $response = [
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
            }
          }
          elseif ($rpcMethod === 'notifications/initialized') {
            $response = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'result' => new \stdClass(),
            ];
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
          }
          $stream->write("data: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n\n");
          $stream->end();
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
          foreach ($this->config->tools as $key => $def) {
            $toolName = is_string($key) ? $key : (is_object($def) && $def instanceof \Politsin\Mcp\Tool\ToolInterface ? $def->getName() : (is_string($def) ? (class_exists($def) && is_subclass_of($def, \Politsin\Mcp\Tool\ToolInterface::class) ? (new $def())->getName() : $def) : ''));
            $desc = 'Tool ' . (string) $toolName;
            $schema = [
              'type' => 'object',
              'properties' => new \stdClass(),
              'required' => [],
              'additionalProperties' => FALSE,
            ];
            if (is_object($def) && $def instanceof \Politsin\Mcp\Tool\ToolInterface) {
              $desc = $def->getDescription();
              $schema = $def->getInputSchema();
            }
            elseif (is_string($def) && class_exists($def) && is_subclass_of($def, \Politsin\Mcp\Tool\ToolInterface::class)) {
              try {
                $inst = new $def();
                $desc = $inst->getDescription();
                $schema = $inst->getInputSchema();
              }
              catch (\Throwable $e) {
              }
            }
            $toolsOut[] = [
              'name' => (string) $toolName,
              'description' => $desc,
              'inputSchema' => $schema,
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
