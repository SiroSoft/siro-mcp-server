<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Resource;

/**
 * Contract for MCP resource providers.
 *
 * @package SiroSoft\McpServer\Resource
 */
interface ResourceInterface
{
    /**
     * URI prefix this handler responds to (e.g. 'siro://docs/').
     */
    public function getUriPrefix(): string;

    /**
     * List all available resources under this prefix.
     *
     * @return array<string, string> uri => description
     */
    public function listResources(): array;

    /**
     * Read a specific resource by URI.
     *
     * @return array{text: string}|null
     */
    public function readResource(string $uri): ?array;
}
