# Пример интеграции politsin/mcp в Symfony

Минимальный пример команды `app:react` в приложении Symfony.

## Регистрация команды

Если у вас включён `autoconfigure: true`, достаточно положить файл команды в `src/Command/ReactServerCommand.php`.
Иначе можно явно зарегистрировать сервис:

```yaml
# config/services.yaml
services:
  App\Command\ReactServerCommand:
    tags: ['console.command']
```

## Запуск

```bash
php app/symfony app:react
```

Поднимутся пути:
- GET /mcp/sse
- POST /mcp/api
- ANY /mcp/http
