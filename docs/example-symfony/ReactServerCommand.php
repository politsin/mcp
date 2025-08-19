<?php

declare(strict_types=1);

namespace App\Command;

use Politsin\Mcp\Config\McpConfig;
use Politsin\Mcp\Server\ReactMcpServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Пример Symfony-команды, использующей библиотеку politsin/mcp.
 *
 * Команда без параметров, запускает сервер с дефолтными значениями.
 */
#[AsCommand(name: 'app:react', description: 'MCP http+sse сервер (дефолтные порт и сокет).')]
final class ReactServerCommand extends Command {

  /**
   * Запускает сервер с дефолтными параметрами.
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $config = new McpConfig([], [], NULL, '/mcp');
    $server = new ReactMcpServer($config);
    $server->run('0.0.0.0', 8090, '/var/run/php/mcp-react.sock');
    return Command::SUCCESS;
  }

}
