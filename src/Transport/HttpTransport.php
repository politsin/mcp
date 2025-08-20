<?php

declare(strict_types=1);

namespace Politsin\Mcp\Transport;

use Politsin\Mcp\Contract\TransportInterface;
use Politsin\Mcp\Dto\McpRequest;
use Politsin\Mcp\Dto\McpResponse;
use Politsin\Mcp\Exception\McpTransportException;

/**
 * HTTP транспорт для MCP.
 */
final class HttpTransport implements TransportInterface {

  /**
   * Constructor.
   *
   * @param string $baseUrl
   *   Базовый URL сервера.
   * @param array<string, string> $headers
   *   Дополнительные заголовки.
   * @param float $timeout
   *   Таймаут в секундах.
   */
  public function __construct(
    private readonly string $baseUrl,
    private readonly array $headers = [],
    private float $timeout = 30.0,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function send(McpRequest $request): McpResponse {
    $url = rtrim($this->baseUrl, '/') . '/mcp';
    $payload = json_encode($request->toArray(), JSON_UNESCAPED_UNICODE);

    if ($payload === FALSE) {
      throw new McpTransportException('Failed to encode request to JSON', -32700);
    }

    $context = stream_context_create([
      'http' => [
        'method' => 'POST',
        'header' => array_merge([
          'Content-Type: application/json',
          'Content-Length: ' . strlen($payload),
          'Accept: application/json',
        ], $this->headers),
        'content' => $payload,
        'timeout' => $this->timeout,
      ],
    ]);

    $response = @file_get_contents($url, FALSE, $context);

    if ($response === FALSE) {
      $error = error_get_last();
      $message = $error['message'] ?? 'HTTP request failed';
      throw new McpTransportException($message, -32603);
    }

    $data = json_decode($response, TRUE);

    if (!is_array($data)) {
      throw new McpTransportException('Invalid JSON response from server', -32700);
    }

    $id = $data['id'] ?? NULL;
    $result = $data['result'] ?? NULL;
    $error = $data['error'] ?? NULL;

    if (isset($error)) {
      return McpResponse::error($error, $id);
    }

    return McpResponse::success($result ?? [], $id);
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    $context = stream_context_create([
      'http' => [
        'method' => 'GET',
        'timeout' => 5.0,
      ],
    ]);

    $url = rtrim($this->baseUrl, '/') . '/mcp';
    $response = @file_get_contents($url, FALSE, $context);

    return $response !== FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function setTimeout(float $timeout): void {
    $this->timeout = $timeout;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimeout(): float {
    return $this->timeout;
  }

}
