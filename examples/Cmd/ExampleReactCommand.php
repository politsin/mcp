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

/**
 * Пример Symfony-команды запуска MCP сервера (SSE + Streamable HTTP).
 */
#[AsCommand(name: 'examples:mcp-react', description: 'Пример MCP http+sse сервера на ReactPHP (из politsin/mcp).')]
final class ExampleReactCommand extends Command {

  /**
   * Точка входа: инициализирует и запускает сервер (блокирующая).
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    // Тулзы (классовые через ToolInterface) и пример ресурсов.
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
      'logFile' => sys_get_temp_dir() . '/mcp-react-example.log',
      'logLevel' => 'info',
      'http2Enabled' => TRUE,
      'sessionStorage' => 'file',
      'sessionPath' => sys_get_temp_dir() . '/mcp-sessions',
      // Для публичного домена можно включить absoluteEndpoints и указать endpointBaseUrl.
      'absoluteEndpoints' => FALSE,
      'endpointBaseUrl' => NULL,
    ]);

    $server = new ReactMcpServer($config);
    $server->setPrintListenLogs(TRUE);
    $server->setOutputWriter(function (string $line) use ($output): void {
      $output->writeln($line);
    });

    // UNIX-сокет для прокси nginx (см. examples/nginx.conf).
    $server->listenUnixSocket('/tmp/mcp-react-example.sock');
    // Дополнительно можно слушать TCP:
    // $server->listenTcp('0.0.0.0', 8090);.
    Loop::run();
    return Command::SUCCESS;
  }

}
