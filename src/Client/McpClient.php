<?php

declare(strict_types=1);

namespace Politsin\Mcp\Client;

use Politsin\Mcp\Contract\McpClientInterface;
use Politsin\Mcp\Contract\TransportInterface;
use Politsin\Mcp\Dto\McpRequest;
use Politsin\Mcp\Dto\McpResponse;

/**
 * Основная реализация MCP клиента.
 */
final class McpClient implements McpClientInterface {

  /**
   * Constructor.
   *
   * @param \Politsin\Mcp\Contract\TransportInterface $transport
   *   Транспорт для связи с сервером.
   */
  public function __construct(
    private readonly TransportInterface $transport,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function initialize(array $params = []): McpResponse {
    $request = McpRequest::initialize($params);
    return $this->transport->send($request);
  }

  /**
   * {@inheritdoc}
   */
  public function listTools(): McpResponse {
    $request = McpRequest::listTools();
    return $this->transport->send($request);
  }

  /**
   * {@inheritdoc}
   */
  public function callTool(string $toolName, array $arguments = []): McpResponse {
    $request = McpRequest::callTool($toolName, $arguments);
    return $this->transport->send($request);
  }

  /**
   * {@inheritdoc}
   */
  public function listResources(): McpResponse {
    $request = McpRequest::listResources();
    return $this->transport->send($request);
  }

  /**
   * {@inheritdoc}
   */
  public function sendRequest(McpRequest $request): McpResponse {
    return $this->transport->send($request);
  }

  /**
   * {@inheritdoc}
   */
  public function ping(): McpResponse {
    $request = McpRequest::ping();
    return $this->transport->send($request);
  }

}
