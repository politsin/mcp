<?php

declare(strict_types=1);

namespace Examples\Cmd;

use Examples\Tools\FooTool;
use Politsin\Mcp\Config\McpConfig;
use Politsin\Mcp\Server\ReactMcpServer;
use React\EventLoop\Loop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

require_once __DIR__ . '/../Tools/FooTool.php';

/**
 * Пример Symfony-команды для запуска MCP-сервера из библиотеки.
 */
#[AsCommand(name: 'example:mcp-react', description: 'Пример MCP http+sse сервера из politsin/mcp.')]
final class ReactServer extends Command {

  /**
   * Точка входа: инициализирует и запускает сервер, блокируя процесс.
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $tools = [
      FooTool::class,
    ];

    $resources = [
      'hello_world' => 'Hello, World!',
    ];

    $config = McpConfig::create([
      'tools' => $tools,
      'resources' => $resources,
      'basePath' => '/mcp',
      'logFile' => __DIR__ . '/../../var/mcp-react-example.log',
      'logLevel' => 'info',
      'http2Enabled' => TRUE,
      'sessionStorage' => 'file',
      'sessionPath' => sys_get_temp_dir() . '/mcp-sessions',
      'absoluteEndpoints' => FALSE,
      'endpointBaseUrl' => NULL,
    ]);

    $server = new ReactMcpServer($config);
    $server->setPrintListenLogs(TRUE);
    $server->setOutputWriter(function (string $line) use ($output): void {
      $output->writeln($line);
    });

    $server->listenUnixSocket('/tmp/mcp-react-example.sock');
    // $server->listenTcp('0.0.0.0', 8090);
    Loop::run();
    return Command::SUCCESS;
  }

}
