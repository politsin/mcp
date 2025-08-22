<?php

declare(strict_types=1);

namespace Politsin\Mcp\Tool;

/**
 * Контракт для классовых MCP-тулзов.
 */
interface ToolInterface {

  /**
   * Машинное имя тулзы (tools/call name).
   */
  public function getName(): string;

  /**
   * Короткое описание тулзы для клиента.
   */
  public function getDescription(): string;

  /**
   * JSON Schema входных параметров (object; properties: {}, required: []).
   */
  public function getInputSchema(): array;

  /**
   * Выполнение тулзы.
   *
   * @param array<string, mixed> $arguments
   *   Входные аргументы.
   *
   * @return mixed
   *   Результат выполнения (будет сериализован в JSON текст).
   */
  public function execute(array $arguments): mixed;

}
