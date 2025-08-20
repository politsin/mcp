<?php

declare(strict_types=1);

namespace Politsin\Mcp\Exception;

/**
 * Исключение для ошибок MCP сервера.
 */
class McpServerException extends McpException {

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
  }

}
