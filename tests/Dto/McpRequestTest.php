<?php

declare(strict_types=1);

namespace Politsin\Mcp\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Politsin\Mcp\Dto\McpRequest;

/**
 * Тесты для DTO запросов.
 */
final class McpRequestTest extends TestCase {

  /**
   * Тест создания запроса для инициализации.
   */
  public function testInitialize(): void {
    $request = McpRequest::initialize(['protocolVersion' => '2024-11-05']);

    $this->assertEquals('initialize', $request->method);
    $this->assertEquals(['protocolVersion' => '2024-11-05'], $request->params);
    $this->assertNull($request->id);
  }

  /**
   * Тест создания запроса для списка инструментов.
   */
  public function testListTools(): void {
    $request = McpRequest::listTools();

    $this->assertEquals('tools/list', $request->method);
    $this->assertEquals([], $request->params);
    $this->assertNull($request->id);
  }

  /**
   * Тест создания запроса для вызова инструмента.
   */
  public function testCallTool(): void {
    $request = McpRequest::callTool('foo', ['arg1' => 'value1']);

    $this->assertEquals('tools/call', $request->method);
    $this->assertEquals([
      'name' => 'foo',
      'arguments' => ['arg1' => 'value1'],
    ], $request->params);
    $this->assertNull($request->id);
  }

  /**
   * Тест создания ping запроса.
   */
  public function testPing(): void {
    $request = McpRequest::ping();

    $this->assertEquals('ping', $request->method);
    $this->assertEquals([], $request->params);
    $this->assertNull($request->id);
  }

  /**
   * Тест преобразования в массив.
   */
  public function testToArray(): void {
    $request = new McpRequest('test', ['param' => 'value'], 123);
    $array = $request->toArray();

    $this->assertEquals([
      'jsonrpc' => '2.0',
      'method' => 'test',
      'params' => ['param' => 'value'],
      'id' => 123,
    ], $array);
  }

  /**
   * Тест преобразования в массив без ID.
   */
  public function testToArrayWithoutId(): void {
    $request = new McpRequest('test', ['param' => 'value']);
    $array = $request->toArray();

    $this->assertEquals([
      'jsonrpc' => '2.0',
      'method' => 'test',
      'params' => ['param' => 'value'],
    ], $array);
    $this->assertArrayNotHasKey('id', $array);
  }

}
