<?php

declare(strict_types=1);

namespace Politsin\Mcp\Dto;

/**
 * DTO для MCP запросов.
 */
final class McpRequest {

  /**
   * Constructor.
   *
   * @param string $method
   *   Метод запроса (например, 'tools/call', 'tools/list').
   * @param array<string, mixed> $params
   *   Параметры запроса.
   * @param string|int|null $id
   *   Идентификатор запроса для JSON-RPC.
   */
  public function __construct(
    public readonly string $method,
    public readonly array $params = [],
    public readonly string|int|null $id = NULL,
  ) {
  }

  /**
   * Создает запрос для инициализации.
   */
  public static function initialize(array $params = []): self {
    return new self('initialize', $params);
  }

  /**
   * Создает запрос для получения списка инструментов.
   */
  public static function listTools(): self {
    return new self('tools/list');
  }

  /**
   * Создает запрос для вызова инструмента.
   */
  public static function callTool(string $toolName, array $arguments = []): self {
    return new self('tools/call', [
      'name' => $toolName,
      'arguments' => $arguments,
    ]);
  }

  /**
   * Создает запрос для получения списка ресурсов.
   */
  public static function listResources(): self {
    return new self('resources/list');
  }

  /**
   * Создает ping запрос.
   */
  public static function ping(): self {
    return new self('ping');
  }

  /**
   * Преобразует в массив для JSON-RPC.
   *
   * @return array<string, mixed>
   *   Массив для JSON-RPC запроса.
   */
  public function toArray(): array {
    $result = [
      'jsonrpc' => '2.0',
      'method' => $this->method,
      'params' => $this->params,
    ];

    if ($this->id !== NULL) {
      $result['id'] = $this->id;
    }

    return $result;
  }

}
