<?php

declare(strict_types=1);

namespace Politsin\Mcp\Contract;

use Politsin\Mcp\Dto\McpRequest;
use Politsin\Mcp\Dto\McpResponse;

/**
 * Интерфейс для транспортного слоя MCP.
 */
interface TransportInterface {

  /**
   * Отправляет запрос и получает ответ.
   *
   * @param \Politsin\Mcp\Dto\McpRequest $request
   *   Запрос для отправки.
   *
   * @return \Politsin\Mcp\Dto\McpResponse
   *   Ответ от сервера.
   *
   * @throws \Politsin\Mcp\Exception\McpException
   *   При ошибке транспорта.
   */
  public function send(McpRequest $request): McpResponse;

  /**
   * Проверяет доступность сервера.
   *
   * @return bool
   *   TRUE если сервер доступен.
   */
  public function isAvailable(): bool;

  /**
   * Устанавливает таймаут для операций.
   *
   * @param float $timeout
   *   Таймаут в секундах.
   */
  public function setTimeout(float $timeout): void;

  /**
   * Получает текущий таймаут.
   */
  public function getTimeout(): float;

}
