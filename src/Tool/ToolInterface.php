<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tool;

/**
 * Contract for all MCP tools.
 *
 * @package SiroSoft\McpServer\Tool
 */
interface ToolInterface
{
    /**
     * Tool name (snake_case, e.g. read_documentation).
     */
    public function getName(): string;

    /**
     * Human-readable description of what this tool does.
     */
    public function getDescription(): string;

    /**
     * JSON Schema for input parameters.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * Execute the tool with given arguments.
     *
     * @param array<string, mixed> $arguments
     * @return string Result text
     */
    public function execute(array $arguments): string;
}
