<?php

declare(strict_types=1);

namespace Politsin\Mcp\Contract;

use Politsin\Mcp\Config\McpConfig;

/**
 * Поставщик конфигурации MCP для интеграции приложения.
 */
interface McpConfigProviderInterface {

  /**
   * Возвращает конфигурацию MCP-сервера.
   */
  public function provideConfig(): McpConfig;

}
