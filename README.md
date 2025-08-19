# politsin/mcp

Библиотека для взаимодействия с MCP (Model Context Protocol) для PHP 8.3+.

## Установка



## Минимальный пример

Пример собственной Symfony-команды `app:react` в проекте, использующей библиотеку. Команда без параметров (дефолты для хоста/порта/сокета):

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Politsin\Mcp\Config\McpConfig;
use Politsin\Mcp\Server\ReactMcpServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:react', description: 'Запускает MCP http+sse сервер (дефолтные порт и сокет).')]
final class ReactServerCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new McpConfig([], [], null, '/mcp');
        $server = new ReactMcpServer($config);

        // Дефолтные: host=0.0.0.0, port=8090, socket=/var/run/php/mcp-react.sock
        $server->run('0.0.0.0', 8090, '/var/run/php/mcp-react.sock');
        return Command::SUCCESS;
    }
}
```

Запуск:
```bash
php app/symfony app:react
```

Доступные пути:
- `GET /mcp/sse` — SSE поток.
- `POST /mcp/api` — JSON endpoint (MVP: echo).
- `ANY /mcp/http` — вспомогательные HTTP-запросы (MVP: 204).

## Лицензия
MIT
