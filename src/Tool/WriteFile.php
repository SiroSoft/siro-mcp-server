<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tool;

use SiroSoft\McpServer\Security\PathValidator;

/**
 * write_file — Write content to a file in the project.
 *
 * @package SiroSoft\McpServer\Tool
 */
final class WriteFile implements ToolInterface
{
    private PathValidator $pathValidator;
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');
        $this->pathValidator = new PathValidator($this->basePath);
    }

    public function getName(): string
    {
        return 'write_file';
    }

    public function getDescription(): string
    {
        return 'Write content to a file in the project. Supports creating new files and overwriting existing ones. Use patch_file for surgical edits.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'File path relative to project root (e.g. app/Models/Product.php)',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'File content to write',
                ],
                'force' => [
                    'type' => 'boolean',
                    'description' => 'Overwrite existing file without confirmation',
                    'default' => false,
                ],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function execute(array $arguments): string
    {
        $userPath = $arguments['path'] ?? '';
        $content = $arguments['content'] ?? '';
        $force = $arguments['force'] ?? false;

        if (!is_string($userPath) || trim($userPath) === '') {
            return 'Error: path parameter is required.';
        }
        if (!is_string($content)) {
            return 'Error: content parameter must be a string.';
        }

        try {
            $resolvedPath = $this->pathValidator->resolve($userPath);
        } catch (\RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }

        // Ensure parent directory exists
        $dir = dirname($resolvedPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
            if (!is_dir($dir)) {
                return "Error: Could not create directory: {$dir}";
            }
        }

        // Check if file exists
        if (is_file($resolvedPath) && !$force) {
            $existing = file_get_contents($resolvedPath);
            return "Warning: File already exists at {$userPath}. Set force=true to overwrite.\n"
                . "Current file size: " . strlen($existing !== false ? $existing : '') . " bytes.";
        }

        // Write the file
        $bytesWritten = @file_put_contents($resolvedPath, $content);
        if ($bytesWritten === false) {
            return "Error: Failed to write file: {$userPath}";
        }

        return "OK: Written {$bytesWritten} bytes to {$userPath}";
    }
}
