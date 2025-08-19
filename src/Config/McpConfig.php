<?php

declare(strict_types=1);

namespace Politsin\Mcp\Config;

final class McpConfig
{
    /** @var array<string, callable> */
    public array $tools;

    /** @var array<string, mixed> */
    public array $resources;

    /** @var callable|null */
    public $authCallback;

    public string $basePath;

    /**
     * @param array<string, callable> $tools
     * @param array<string, mixed> $resources
     * @param callable|null $authCallback function(?array $request): bool
     */
    public function __construct(array $tools = array(), array $resources = array(), ?callable $authCallback = NULL, string $basePath = '/mcp')
    {
        $this->tools = $tools;
        $this->resources = $resources;
        $this->authCallback = $authCallback;
        $this->basePath = $basePath;
    }
}


