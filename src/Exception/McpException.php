<?php

declare(strict_types=1);

namespace Politsin\Mcp\Exception;

/**
 * Базовое исключение для MCP операций.
 */
class McpException extends \Exception {

  /**
   * Код ошибки.
   */
  protected int $errorCode = 0;

  /**
   * Constructor.
   *
   * @param string $message
   *   Сообщение об ошибке.
   * @param int $code
   *   Код ошибки.
   * @param \Throwable|null $previous
   *   Предыдущее исключение.
   */
  public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
    $this->errorCode = $code;
  }

  /**
   * Получает код ошибки.
   */
  public function getErrorCode(): int {
    return $this->errorCode;
  }

}
