# politsin/mcp

PHP-библиотека для взаимодействия с MCP (Model Context Protocol) для PHP 8.3+.

## Установка

```bash
composer require politsin/mcp
```

## Быстрый старт

### Создание HTTP клиента

```php
<?php

use Politsin\Mcp\Client\McpClientFactory;

// Создаем HTTP клиент
$client = McpClientFactory::createHttpClient('https://your-mcp-server.com');

// Инициализируем соединение
$response = $client->initialize([
    'protocolVersion' => '2024-11-05',
    'clientInfo' => [
        'name' => 'My Client',
        'version' => '1.0.0',
    ],
]);

if ($response->isSuccess()) {
    echo "Соединение установлено\n";
}

// Получаем список инструментов
$toolsResponse = $client->listTools();
if ($toolsResponse->isSuccess()) {
    $tools = $toolsResponse->getResult()['tools'] ?? [];
    foreach ($tools as $tool) {
        echo "Инструмент: {$tool['name']}\n";
    }
}

// Вызываем инструмент
$callResponse = $client->callTool('foo', ['param' => 'value']);
if ($callResponse->isSuccess()) {
    $result = $callResponse->getResult();
    echo "Результат: " . json_encode($result) . "\n";
}
```

### Интеграция с Symfony

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

### Обработка ошибок

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

## Архитектура

### Основные компоненты

- **McpClientInterface** - основной интерфейс для взаимодействия с MCP сервером
- **McpClient** - реализация клиента
- **TransportInterface** - интерфейс для транспортного слоя
- **HttpTransport** - HTTP транспорт
- **McpRequest/McpResponse** - DTO для запросов и ответов
- **McpException** - иерархия исключений

### Поддерживаемые операции

- `initialize()` - инициализация соединения
- `listTools()` - получение списка инструментов
- `callTool()` - вызов инструмента
- `listResources()` - получение списка ресурсов
- `ping()` - проверка соединения
- `sendRequest()` - отправка произвольного запроса

## Тестирование

```bash
# Запуск тестов
composer test

# Запуск тестов с покрытием
composer test:coverage
```

## Лицензия

MIT
