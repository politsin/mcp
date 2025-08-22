<?php

declare(strict_types=1);

use Politsin\Mcp\Config\McpConfig;
use Politsin\Mcp\Server\ReactMcpServer;
use Politsin\Mcp\Tool\ToolInterface;
use React\EventLoop\Loop;

// Minimal example Foo tool.
final class FooTool implements ToolInterface {
  public function getName(): string { return 'foo'; }
  public function getDescription(): string { return 'Return "bar" or 2*n if numeric argument provided.'; }
  public function getInputSchema(): array {
    return [
      'type' => 'object',
      'properties' => ['n' => ['type' => 'number', 'description' => 'Optional number to double']],
      'required' => [],
      'additionalProperties' => FALSE,
    ];
  }
  public function execute(array $arguments): string {
    if (isset($arguments['n']) && is_numeric($arguments['n'])) {
      $n = (float) $arguments['n'];
      $res = $n * 2.0;
      return (string) ($res == (int) $res ? (int) $res : $res);
    }
    return 'bar';
  }
}

// Configure and run server.
$config = McpConfig::create([
  // Register a class-based tool.
  'tools' => [FooTool::class],
  // Example resources.
  'resources' => [
    'hello_world' => 'Hello, World!'
  ],
  'basePath' => '/mcp',
  'logFile' => __DIR__ . '/../../var/mcp-react-example.log',
  'logLevel' => 'info',
  'http2Enabled' => TRUE,
  'sessionStorage' => 'file',
  'sessionPath' => sys_get_temp_dir() . '/mcp-sessions',
  // Absolute endpoints in manifest/initialize (optional).
  'absoluteEndpoints' => FALSE,
  'endpointBaseUrl' => NULL,
]);

$server = new ReactMcpServer($config);
// Listen on UNIX socket (adjust path for your environment) and optional TCP.
$server->listenUnixSocket('/tmp/mcp-react-example.sock');
// $server->listenTcp('0.0.0.0', 8090);
Loop::run();


