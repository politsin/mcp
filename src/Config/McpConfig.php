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
   */
  public function __construct(array $tools = [], array $resources = [], ?callable $authCallback = NULL, string $basePath = '/mcp') {
    $this->tools = $tools;
    $this->resources = $resources;
    $this->authCallback = $authCallback;
    $this->basePath = $basePath;
  }

}
