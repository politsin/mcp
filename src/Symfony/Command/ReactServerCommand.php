<?php

declare(strict_types=1);

namespace Politsin\Mcp\Symfony\Command;

use Politsin\Mcp\Config\McpConfig;
use Politsin\Mcp\Server\ReactMcpServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:react', description: 'Запускает MCP http+sse сервер на ReactPHP (библиотека politsin/mcp).')]
final class ReactServerCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to listen', '0.0.0.0');
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port to listen', '8090');
        $this->addOption('base-path', null, InputOption::VALUE_REQUIRED, 'Base path', '/mcp');
        $this->addOption('socket', null, InputOption::VALUE_REQUIRED, 'Unix socket path', '/var/run/php/mcp-react.sock');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');
        $basePath = (string) $input->getOption('base-path');
        $socketPath = (string) $input->getOption('socket');

        $config = new McpConfig(array(), array(), NULL, $basePath);
        $server = new ReactMcpServer($config);
        $server->run($host, $port, $socketPath);

        $output->writeln('React MCP server started on ' . $host . ':' . $port . ' with base ' . $basePath);
        // Блокируем процесс.
        \React\EventLoop\Loop::run();
        return Command::SUCCESS;
    }
}


