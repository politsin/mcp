<?php

declare(strict_types=1);

namespace Politsin\Mcp\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Politsin\Mcp\Dto\McpResponse;

/**
 * Тесты для DTO ответов.
 */
final class McpResponseTest extends TestCase {

  /**
   * Тест создания успешного ответа.
   */
  public function testSuccess(): void {
    $result = ['tools' => []];
    $response = McpResponse::success($result, 123);

    $this->assertTrue($response->isSuccess());
    $this->assertEquals($result, $response->getResult());
    $this->assertNull($response->getError());
    $this->assertEquals(123, $response->id);
  }

  /**
   * Тест создания ответа с ошибкой.
   */
  public function testError(): void {
    $error = ['code' => -32601, 'message' => 'Method not found'];
    $response = McpResponse::error($error, 123);

    $this->assertFalse($response->isSuccess());
    $this->assertNull($response->getResult());
    $this->assertEquals($error, $response->getError());
    $this->assertEquals(123, $response->id);
  }

  /**
   * Тест создания ответа с ошибкой по коду и сообщению.
   */
  public function testErrorWithCode(): void {
    $response = McpResponse::errorWithCode(-32601, 'Method not found', 123);

    $this->assertFalse($response->isSuccess());
    $this->assertNull($response->getResult());
    $this->assertEquals(-32601, $response->getErrorCode());
    $this->assertEquals('Method not found', $response->getErrorMessage());
    $this->assertEquals(123, $response->id);
  }

  /**
   * Тест получения кода ошибки.
   */
  public function testGetErrorCode(): void {
    $response = McpResponse::errorWithCode(-32601, 'Method not found');
    $this->assertEquals(-32601, $response->getErrorCode());

    $response = McpResponse::success([]);
    $this->assertNull($response->getErrorCode());
  }

  /**
   * Тест получения сообщения об ошибке.
   */
  public function testGetErrorMessage(): void {
    $response = McpResponse::errorWithCode(-32601, 'Method not found');
    $this->assertEquals('Method not found', $response->getErrorMessage());

    $response = McpResponse::success([]);
    $this->assertNull($response->getErrorMessage());
  }

  /**
   * Тест преобразования в массив для успешного ответа.
   */
  public function testToArraySuccess(): void {
    $result = ['tools' => []];
    $response = McpResponse::success($result, 123);
    $array = $response->toArray();

    $this->assertEquals([
      'jsonrpc' => '2.0',
      'id' => 123,
      'result' => $result,
    ], $array);
  }

  /**
   * Тест преобразования в массив для ответа с ошибкой.
   */
  public function testToArrayError(): void {
    $error = ['code' => -32601, 'message' => 'Method not found'];
    $response = McpResponse::error($error, 123);
    $array = $response->toArray();

    $this->assertEquals([
      'jsonrpc' => '2.0',
      'id' => 123,
      'error' => $error,
    ], $array);
  }

  /**
   * Тест преобразования в массив без ID.
   */
  public function testToArrayWithoutId(): void {
    $response = McpResponse::success(['result' => 'ok']);
    $array = $response->toArray();

    $this->assertEquals([
      'jsonrpc' => '2.0',
      'result' => ['result' => 'ok'],
    ], $array);
    $this->assertArrayNotHasKey('id', $array);
  }

}
