<?php

declare(strict_types=1);

namespace Examples\Tools;

use Politsin\Mcp\Tool\ToolInterface;

/**
 *
 */
final class FooTool implements ToolInterface {

  /**
   *
   */
  public function getName(): string {
    return 'foo';
  }

  /**
   *
   */
  public function getDescription(): string {
    return 'Return "bar" or 2*n if numeric argument provided.';
  }

  /**
   *
   */
  public function getInputSchema(): array {
    return [
      'type' => 'object',
      'properties' => ['n' => ['type' => 'number', 'description' => 'Optional number to double']],
      'required' => [],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   *
   */
  public function execute(array $arguments): string {
    if (isset($arguments['n']) && is_numeric($arguments['n'])) {
      $n = (float) $arguments['n'];
      $res = $n * 2.0;
      return (string) ($res == (int) $res ? (int) $res : $res);
    }
    return 'bar';
  }

}
