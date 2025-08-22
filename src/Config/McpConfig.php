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
   */
  public function __construct(array $tools = [], array $resources = [], ?callable $authCallback = NULL, string $basePath = '/mcp', ?string $logFile = NULL, string $logLevel = 'info', bool $http2Enabled = FALSE) {
    $this->tools = $tools;
    $this->resources = $resources;
    $this->authCallback = $authCallback;
    $this->basePath = $basePath;
    $this->logFile = $logFile;
    $this->logLevel = $logLevel;
    $this->http2Enabled = $http2Enabled;
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
      $options['http2Enabled'] ?? FALSE
    );
  }

}
