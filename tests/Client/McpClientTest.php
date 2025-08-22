<?php

declare(strict_types=1);

namespace Politsin\Mcp\Tests\Client;

use PHPUnit\Framework\TestCase;
use Politsin\Mcp\Client\McpClient;
use Politsin\Mcp\Contract\TransportInterface;
use Politsin\Mcp\Dto\McpRequest;
use Politsin\Mcp\Dto\McpResponse;
use Politsin\Mcp\Exception\McpException;

/**
 * Тесты для MCP клиента.
 */
final class McpClientTest extends TestCase {

  /**
   * Тест инициализации клиента.
   */
  public function testInitialize(): void {
    $transport = $this->createMock(TransportInterface::class);
    $client = new McpClient($transport);

    $expectedResponse = McpResponse::success(['protocolVersion' => '2024-11-05']);
    $transport->expects($this->once())
      ->method('send')
      ->with($this->callback(function (McpRequest $request) {
        return $request->method === 'initialize';
      }))
      ->willReturn($expectedResponse);

    $response = $client->initialize();
    $this->assertTrue($response->isSuccess());
  }

  /**
   * Тест получения списка инструментов.
   */
  public function testListTools(): void {
    $transport = $this->createMock(TransportInterface::class);
    $client = new McpClient($transport);

    $expectedResponse = McpResponse::success(['tools' => []]);
    $transport->expects($this->once())
      ->method('send')
      ->with($this->callback(function (McpRequest $request) {
        return $request->method === 'tools/list';
      }))
      ->willReturn($expectedResponse);

    $response = $client->listTools();
    $this->assertTrue($response->isSuccess());
  }

  /**
   * Тест вызова инструмента.
   */
  public function testCallTool(): void {
    $transport = $this->createMock(TransportInterface::class);
    $client = new McpClient($transport);

    $expectedResponse = McpResponse::success(['content' => [['type' => 'text', 'text' => 'bar']]]);
    $transport->expects($this->once())
      ->method('send')
      ->with($this->callback(function (McpRequest $request) {
        return $request->method === 'tools/call' && $request->params['name'] === 'foo';
      }))
      ->willReturn($expectedResponse);

    $response = $client->callTool('foo', []);
    $this->assertTrue($response->isSuccess());
  }

  /**
   * Тест ping.
   */
  public function testPing(): void {
    $transport = $this->createMock(TransportInterface::class);
    $client = new McpClient($transport);

    $expectedResponse = McpResponse::success([]);
    $transport->expects($this->once())
      ->method('send')
      ->with($this->callback(function (McpRequest $request) {
        return $request->method === 'ping';
      }))
      ->willReturn($expectedResponse);

    $response = $client->ping();
    $this->assertTrue($response->isSuccess());
  }

  /**
   * Тест обработки ошибок.
   */
  public function testErrorHandling(): void {
    $transport = $this->createMock(TransportInterface::class);
    $client = new McpClient($transport);

    $errorResponse = McpResponse::errorWithCode(-32601, 'Method not found');
    $transport->expects($this->once())
      ->method('send')
      ->willReturn($errorResponse);

    $response = $client->listTools();
    $this->assertFalse($response->isSuccess());
    $this->assertEquals(-32601, $response->getErrorCode());
  }

}
