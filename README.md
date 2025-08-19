# politsin/mcp

Библиотека для взаимодействия с MCP (Model Context Protocol) для PHP 8.3+.

## Установка



## Минимальный пример

Пример регистрации Symfony-команды `app:react` из библиотеки и поднятие эндпоинтов `/mcp/api`, `/mcp/sse`, `/mcp/http`:

```php
// config/services.php или YAML-эквивалент
use Politsin\Mcp\Symfony\Command\ReactServerCommand;

return static function (Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container) {
    $services = $container->services();
    $services->set(ReactServerCommand::class)->tag('console.command');
};
```

Запуск:
```bash
php app/symfony app:react --host=0.0.0.0 --port=8090 --base-path=/mcp
```

В результате будут доступны пути:
- `GET /mcp/sse` — SSE поток.
- `POST /mcp/api` — JSON endpoint (MVP: echo).
- `ANY /mcp/http` — вспомогательные HTTP-запросы (MVP: 204).

## Лицензия
MIT
