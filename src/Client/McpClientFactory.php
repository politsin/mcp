<?php

declare(strict_types=1);

namespace Politsin\Mcp\Client;

use Politsin\Mcp\Contract\McpClientInterface;
use Politsin\Mcp\Contract\TransportInterface;
use Politsin\Mcp\Transport\HttpTransport;

/**
 * Фабрика для создания MCP клиентов.
 */
final class McpClientFactory {

  /**
   * Создает HTTP клиент.
   *
   * @param string $baseUrl
   *   Базовый URL сервера.
   * @param array<string, string> $headers
   *   Дополнительные заголовки.
   * @param float $timeout
   *   Таймаут в секундах.
   *
   * @return \Politsin\Mcp\Contract\McpClientInterface
   *   MCP клиент.
   */
  public static function createHttpClient(string $baseUrl, array $headers = [], float $timeout = 30.0): McpClientInterface {
    $transport = new HttpTransport($baseUrl, $headers, $timeout);
    return new McpClient($transport);
  }

  /**
   * Создает клиент с кастомным транспортом.
   *
   * @param \Politsin\Mcp\Contract\TransportInterface $transport
   *   Транспорт для связи с сервером.
   *
   * @return \Politsin\Mcp\Contract\McpClientInterface
   *   MCP клиент.
   */
  public static function createClient(TransportInterface $transport): McpClientInterface {
    return new McpClient($transport);
  }

}
