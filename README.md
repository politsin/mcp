# politsin/mcp

PHP-библиотека MCP (Model Context Protocol) для PHP 8.3+ с готовым сервером на ReactPHP.

## Установка

```bash
composer require politsin/mcp
```

## Быстрый старт (сервер)

Минимальный сервер на ReactPHP (HTTP Stream и SSE), классическая регистрация тулзов и ресурсов:

См. примеры в `examples/`:
- `examples/Cmd/ReactServer.php` — запуск сервера, конфигурация.
- `examples/Tools/FooTool.php` — классовая тулза с параметром `n` (optional).
- `examples/nginx.conf` — пример проксирования nginx для `/mcp/http` и `/mcp/sse`.
- `examples/Controller/SseTestController.php` — простая страница для проверки SSE (`/test/sse`).

### Интеграция с приложением (Symfony)

Создайте сервис в `config/services.yaml`:

```yaml
services:
    mcp.client:
        class: Politsin\Mcp\Contract\McpClientInterface
        factory: [Politsin\Mcp\Client\McpClientFactory, createHttpClient]
        arguments:
            - '%env(MCP_SERVER_URL)%'
            - []
            - 30.0
```

Используйте в контроллере:

```php
<?php

namespace App\Controller;

use Politsin\Mcp\Contract\McpClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class McpController extends AbstractController
{
    public function __construct(
        private readonly McpClientInterface $mcpClient,
    ) {}

    #[Route('/api/mcp/tools', methods: ['GET'])]
    public function listTools(): JsonResponse
    {
        $response = $this->mcpClient->listTools();

        if ($response->isSuccess()) {
            return $this->json($response->getResult());
        }

        return $this->json([
            'error' => $response->getError(),
        ], 400);
    }

    #[Route('/api/mcp/call', methods: ['POST'])]
    public function callTool(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $toolName = $data['tool'] ?? '';
        $arguments = $data['arguments'] ?? [];

        $response = $this->mcpClient->callTool($toolName, $arguments);

        if ($response->isSuccess()) {
            return $this->json($response->getResult());
        }

        return $this->json([
            'error' => $response->getError(),
        ], 400);
    }
}
```

### Что уже реализовано

- ReactPHP сервер (`Politsin\Mcp\Server\ReactMcpServer`) с эндпоинтами:
  - Streamable HTTP: `POST /mcp/http`
  - SSE: `GET /mcp/sse`, `POST /mcp/sse/message?sessionId=...`
- Инициализация (initialize) с поддержкой относительных/абсолютных endpoints.
- Тулы (tools):
  - Список тулзов (tools/list) — экспорт `name`, `description`, `inputSchema` (JSON Schema object).
  - Вызов тулзы (tools/call) — поддержка callable и классовых тулзов через `ToolInterface`.
  - Поиск тулзы по `getName()`, FQCN и исходному ключу конфигурации.
- Ресурсы (resources): resources/list, resources/read.
- Ping (ping).
- SSE: трансляция ответов tools/* в открытый поток по sessionId, keep-alive, CORS.

### Параметры конфигурации (`McpConfig::create`)

- `tools`: массив тулзов — callable | объект `ToolInterface` | класс `ToolInterface` | `name => def`.
- `resources`: массив ресурсов (`uri => string|array|object`).
- `basePath`: базовый путь (по умолчанию `/mcp`).
- `logFile`, `logLevel`: логирование (`error|info|debug`).
- `http2Enabled`: признак HTTP/2.
- `sessionStorage`, `sessionPath`: хранение сессий (по умолчанию file в /tmp).
- `absoluteEndpoints`: если TRUE — в манифесте/initialize будут абсолютные URL.
- `endpointBaseUrl`: базовый URL для абсолютных endpoints (опционально).

```php
<?php

use Politsin\Mcp\Client\McpClientFactory;
use Politsin\Mcp\Exception\McpException;

$client = McpClientFactory::createHttpClient('https://your-mcp-server.com');

try {
    $response = $client->callTool('unknown_tool');

    if (!$response->isSuccess()) {
        echo "Ошибка: " . $response->getErrorMessage() . "\n";
        echo "Код: " . $response->getErrorCode() . "\n";
    }
} catch (McpException $e) {
    echo "Исключение: " . $e->getMessage() . "\n";
    echo "Код: " . $e->getErrorCode() . "\n";
}
```

## ToolInterface (классовые тулзы)

```php
interface ToolInterface {
  public function getName(): string;
  public function getDescription(): string;
  public function getInputSchema(): array; // JSON Schema object
  public function execute(array $arguments): mixed;
}
```

## Тестирование

```bash
# Запуск тестов
composer test

# Запуск тестов с покрытием
composer test:coverage
```

## Лицензия

MIT
