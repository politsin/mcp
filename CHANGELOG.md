# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2024-12-19

### Added
- Основной интерфейс `McpClientInterface` для взаимодействия с MCP сервером
- Реализация `McpClient` с поддержкой всех основных операций
- HTTP транспорт `HttpTransport` для связи с сервером
- DTO классы `McpRequest` и `McpResponse` для типизированных запросов и ответов
- Иерархия исключений: `McpException`, `McpTransportException`, `McpServerException`
- Фабрика `McpClientFactory` для удобного создания клиентов
- Полное покрытие тестами всех компонентов
- CI/CD конфигурация с GitHub Actions
- Документация с примерами интеграции в Symfony

### Features
- `initialize()` - инициализация соединения с MCP сервером
- `listTools()` - получение списка доступных инструментов
- `callTool()` - вызов инструмента с параметрами
- `listResources()` - получение списка ресурсов
- `ping()` - проверка доступности сервера
- `sendRequest()` - отправка произвольного запроса

### Technical
- PHP 8.3+ поддержка
- Строгая типизация всех методов
- PHPDoc аннотации для всех параметров
- PSR-4 автозагрузка
- Совместимость с Symfony DI контейнером

## [0.1.29] - 2024-12-19

### Added
- Базовая структура библиотеки
- `McpConfig` и `McpConfigProviderInterface`
- `ReactMcpServer` для запуска MCP сервера
- Тестовые инструменты и ресурсы
- Базовая документация

### Technical
- ReactPHP интеграция
- HTTP/SSE поддержка
- Unix socket поддержка
