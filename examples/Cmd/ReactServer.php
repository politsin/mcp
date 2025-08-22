<?php

declare(strict_types=1);

require_once __DIR__ . '/../Tools/FooTool.php';

use Examples\Tools\FooTool;
use Politsin\Mcp\Config\McpConfig;
use Politsin\Mcp\Server\ReactMcpServer;
use React\EventLoop\Loop;

// Configure and run server.
$config = McpConfig::create([
  // Register a class-based tool.
  'tools' => [FooTool::class],
  // Example resources.
  'resources' => [
    'hello_world' => 'Hello, World!',
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
