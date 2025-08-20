<?php

declare(strict_types=1);

namespace Politsin\Mcp\Dto;

/**
 * DTO для MCP ответов.
 */
final class McpResponse {

  /**
   * Constructor.
   *
   * @param string|int|null $id
   *   Идентификатор запроса.
   * @param array<string, mixed>|null $result
   *   Результат выполнения (при успехе).
   * @param array<string, mixed>|null $error
   *   Информация об ошибке (при неудаче).
   * @param bool $isError
   *   Флаг наличия ошибки.
   */
  public function __construct(
    public readonly string|int|null $id = NULL,
    public readonly ?array $result = NULL,
    public readonly ?array $error = NULL,
    public readonly bool $isError = FALSE,
  ) {
  }

  /**
   * Создает успешный ответ.
   */
  public static function success(array $result, string|int|null $id = NULL): self {
    return new self($id, $result, NULL, FALSE);
  }

  /**
   * Создает ответ с ошибкой.
   */
  public static function error(array $error, string|int|null $id = NULL): self {
    return new self($id, NULL, $error, TRUE);
  }

  /**
   * Создает ответ с ошибкой по коду и сообщению.
   */
  public static function errorWithCode(int $code, string $message, string|int|null $id = NULL): self {
    return new self($id, NULL, [
      'code' => $code,
      'message' => $message,
    ], TRUE);
  }

  /**
   * Проверяет, является ли ответ успешным.
   */
  public function isSuccess(): bool {
    return !$this->isError && $this->result !== NULL;
  }

  /**
   * Получает результат выполнения.
   *
   * @return array<string, mixed>|null
   *   Результат или NULL при ошибке.
   */
  public function getResult(): ?array {
    return $this->result;
  }

  /**
   * Получает информацию об ошибке.
   *
   * @return array<string, mixed>|null
   *   Информация об ошибке или NULL при успехе.
   */
  public function getError(): ?array {
    return $this->error;
  }

  /**
   * Получает код ошибки.
   */
  public function getErrorCode(): ?int {
    return $this->error['code'] ?? NULL;
  }

  /**
   * Получает сообщение об ошибке.
   */
  public function getErrorMessage(): ?string {
    return $this->error['message'] ?? NULL;
  }

  /**
   * Преобразует в массив для JSON-RPC.
   *
   * @return array<string, mixed>
   *   Массив для JSON-RPC ответа.
   */
  public function toArray(): array {
    $result = [
      'jsonrpc' => '2.0',
    ];

    if ($this->id !== NULL) {
      $result['id'] = $this->id;
    }

    if ($this->isError) {
      $result['error'] = $this->error;
    }
    else {
      $result['result'] = $this->result;
    }

    return $result;
  }

}
