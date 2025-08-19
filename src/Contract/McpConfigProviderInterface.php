<?php

declare(strict_types=1);

namespace Politsin\Mcp\Contract;

use Politsin\Mcp\Config\McpConfig;

interface McpConfigProviderInterface
{
    public function provideConfig(): McpConfig;
}


