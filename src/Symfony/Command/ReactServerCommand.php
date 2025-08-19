<?php

declare(strict_types=1);

namespace Politsin\Mcp\Symfony\Command;

use Politsin\Mcp\Config\McpConfig;
use Politsin\Mcp\Server\ReactMcpServer;
use React\EventLoop\Loop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Symfony-команда для запуска ReactPHP MCP сервера из библиотеки.
 */
#[AsCommand(name: 'app:react', description: 'Запускает MCP http+sse сервер на ReactPHP (библиотека politsin/mcp).')]
final class ReactServerCommand extends Command {

  /**
   * Конфигурация опций запуска: host, port, base-path, socket.
   */
  protected function configure(): void {
    $this->addOption('host', NULL, InputOption::VALUE_REQUIRED, 'Host to listen', '0.0.0.0');
    $this->addOption('port', NULL, InputOption::VALUE_REQUIRED, 'Port to listen', '8090');
    $this->addOption('base-path', NULL, InputOption::VALUE_REQUIRED, 'Base path', '/mcp');
    $this->addOption('socket', NULL, InputOption::VALUE_REQUIRED, 'Unix socket path', '/var/run/php/mcp-react.sock');
  }

  /**
   * Точка входа: запускает сервер и блокирует процесс до остановки.
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $host = (string) $input->getOption('host');
    $port = (int) $input->getOption('port');
    $basePath = (string) $input->getOption('base-path');
    $socketPath = (string) $input->getOption('socket');

    $config = new McpConfig([], [], NULL, $basePath);
    $server = new ReactMcpServer($config);
    $server->run($host, $port, $socketPath);

    $output->writeln('React MCP server started on ' . $host . ':' . $port . ' with base ' . $basePath);
    // Блокируем процесс.
    Loop::run();
    return Command::SUCCESS;
  }

}
