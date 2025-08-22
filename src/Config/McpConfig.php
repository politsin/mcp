<?php

declare(strict_types=1);

namespace Politsin\Mcp\Config;

/**
 * Конфигурация MCP-сервера: разрешённые тулзы, ресурсы и базовый путь.
 */
final class McpConfig {
  // phpcs:disable
  /** @var array<string, callable> */
  public array $tools;

  /** @var array<string, mixed> */
  public array $resources;

  /** @var callable|null */
  public $authCallback;

  /** @var string */
  public string $basePath;

  /** @var string|null */
  public ?string $logFile = NULL;

  /** @var string */
  public string $logLevel = 'info';

  /** @var bool */
  public bool $http2Enabled = FALSE;

  /** @var string */
  public string $sessionStorage = 'file';

  /** @var string|null */
  public ?string $sessionPath = NULL;

  /** @var string|null */
  public ?string $redisHost = NULL;

  /** @var int */
  public int $redisPort = 6379;

  /** @var int */
  public int $redisDb = 0;

  /** @var bool */
  public bool $absoluteEndpoints = FALSE;

  /** @var string|null */
  public ?string $endpointBaseUrl = NULL;
  // phpcs:enable

  /**
   * Создаёт конфигурацию MCP.
   *
   * @param array<string, callable> $tools
   *   Реестр тулзов: имя → callable.
   * @param array<string, mixed> $resources
   *   Описание доступных ресурсов.
   * @param callable|null $authCallback
   *   Коллбэк авторизации: function(?array $request): bool.
   * @param string $basePath
   *   Базовый путь HTTP‑эндпоинтов (например, "/mcp").
   * @param string|null $logFile
   *   Путь к лог‑файлу для сообщений сервера (или NULL для отключения записи в файл).
   * @param string $logLevel
   *   Уровень логирования: 'error' | 'info' | 'debug'.
   * @param bool $http2Enabled
   *   Включить поддержку HTTP/2 заголовков.
   * @param string $sessionStorage
   *   Тип хранилища сессий: 'file' | 'redis'.
   * @param string|null $sessionPath
   *   Путь для файловых сессий (по умолчанию /tmp/mcp-sessions).
   * @param string|null $redisHost
   *   Хост Redis для сессий.
   * @param int $redisPort
   *   Порт Redis для сессий.
   * @param int $redisDb
   *   База данных Redis для сессий.
   * @param bool $absoluteEndpoints
   *   Если TRUE, сервер возвращает абсолютные endpoints в манифесте/initialize.
   * @param string|null $endpointBaseUrl
   *   Базовый URL (например, "https://react.politsin.ru/mcp") для построения абсолютных endpoints.
   */
  public function __construct(array $tools = [], array $resources = [], ?callable $authCallback = NULL, string $basePath = '/mcp', ?string $logFile = NULL, string $logLevel = 'info', bool $http2Enabled = FALSE, string $sessionStorage = 'file', ?string $sessionPath = NULL, ?string $redisHost = NULL, int $redisPort = 6379, int $redisDb = 0, bool $absoluteEndpoints = FALSE, ?string $endpointBaseUrl = NULL) {
    $this->tools = $tools;
    $this->resources = $resources;
    $this->authCallback = $authCallback;
    $this->basePath = $basePath;
    $this->logFile = $logFile;
    $this->logLevel = $logLevel;
    $this->http2Enabled = $http2Enabled;
    $this->sessionStorage = $sessionStorage;
    $this->sessionPath = $sessionPath ?? '/tmp/mcp-sessions';
    $this->redisHost = $redisHost;
    $this->redisPort = $redisPort;
    $this->redisDb = $redisDb;
    $this->absoluteEndpoints = $absoluteEndpoints;
    $this->endpointBaseUrl = $endpointBaseUrl;
  }

  /**
   * Создаёт конфигурацию с именованными параметрами.
   */
  public static function create(array $options = []): self {
    return new self(
      $options['tools'] ?? [],
      $options['resources'] ?? [],
      $options['authCallback'] ?? NULL,
      $options['basePath'] ?? '/mcp',
      $options['logFile'] ?? NULL,
      $options['logLevel'] ?? 'info',
      $options['http2Enabled'] ?? FALSE,
      $options['sessionStorage'] ?? 'file',
      $options['sessionPath'] ?? NULL,
      $options['redisHost'] ?? NULL,
      $options['redisPort'] ?? 6379,
      $options['redisDb'] ?? 0,
      $options['absoluteEndpoints'] ?? FALSE,
      $options['endpointBaseUrl'] ?? NULL
    );
  }

}
