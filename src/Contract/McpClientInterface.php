<?php

declare(strict_types=1);

namespace Politsin\Mcp\Contract;

use Politsin\Mcp\Dto\McpRequest;
use Politsin\Mcp\Dto\McpResponse;

/**
 * Основной интерфейс для взаимодействия с MCP сервером.
 */
interface McpClientInterface {

  /**
   * Инициализирует соединение с MCP сервером.
   *
   * @param array<string, mixed> $params
   *   Параметры инициализации (protocolVersion, clientInfo, capabilities).
   *
   * @return \Politsin\Mcp\Dto\McpResponse
   *   Ответ сервера с информацией о протоколе и возможностях.
   *
   * @throws \Politsin\Mcp\Exception\McpException
   *   При ошибке инициализации.
   */
  public function initialize(array $params = []): McpResponse;

  /**
   * Получает список доступных инструментов.
   *
   * @return \Politsin\Mcp\Dto\McpResponse
   *   Список инструментов с их описаниями и схемами.
   *
   * @throws \Politsin\Mcp\Exception\McpException
   *   При ошибке получения списка.
   */
  public function listTools(): McpResponse;

  /**
   * Вызывает инструмент с заданными параметрами.
   *
   * @param string $toolName
   *   Имя инструмента для вызова.
   * @param array<string, mixed> $arguments
   *   Аргументы для передачи инструменту.
   *
   * @return \Politsin\Mcp\Dto\McpResponse
   *   Результат выполнения инструмента.
   *
   * @throws \Politsin\Mcp\Exception\McpException
   *   При ошибке вызова инструмента.
   */
  public function callTool(string $toolName, array $arguments = []): McpResponse;

  /**
   * Получает список доступных ресурсов.
   *
   * @return \Politsin\Mcp\Dto\McpResponse
   *   Список ресурсов с их описаниями.
   *
   * @throws \Politsin\Mcp\Exception\McpException
   *   При ошибке получения списка.
   */
  public function listResources(): McpResponse;

  /**
   * Выполняет произвольный MCP запрос.
   *
   * @param \Politsin\Mcp\Dto\McpRequest $request
   *   Запрос для отправки серверу.
   *
   * @return \Politsin\Mcp\Dto\McpResponse
   *   Ответ от сервера.
   *
   * @throws \Politsin\Mcp\Exception\McpException
   *   При ошибке выполнения запроса.
   */
  public function sendRequest(McpRequest $request): McpResponse;

  /**
   * Проверяет соединение с сервером (ping).
   *
   * @return \Politsin\Mcp\Dto\McpResponse
   *   Ответ сервера на ping.
   *
   * @throws \Politsin\Mcp\Exception\McpException
   *   При ошибке соединения.
   */
  public function ping(): McpResponse;

}
