<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Security;

/**
 * Path traversal protection.
 *
 * Ensures all file operations are restricted to the project directory.
 *
 * @package SiroSoft\McpServer\Security
 */
final class PathValidator
{
    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    }

    /**
     * Validate and resolve a user-supplied path.
     *
     * @throws \RuntimeException if path is outside project root
     */
    public function resolve(string $userPath): string
    {
        // Normalize separators
        $userPath = str_replace('\\', '/', $userPath);

        // Remove any leading slashes
        $userPath = ltrim($userPath, '/');

        // Block path traversal patterns
        if (str_contains($userPath, '..')) {
            throw new \RuntimeException('Path traversal detected: ".." is not allowed');
        }

        // Resolve to absolute path
        $fullPath = $this->projectRoot . '/' . $userPath;
        $realPath = realpath($fullPath);

        if ($realPath === false) {
            // Path doesn't exist yet (e.g. for write operations)
            // Still verify the parent directory is within project
            $dirName = dirname($fullPath);
            $realDir = realpath($dirName);
            if ($realDir === false || !str_starts_with(str_replace('\\', '/', $realDir), $this->projectRoot)) {
                throw new \RuntimeException('Path traversal detected: parent directory outside project');
            }
            return str_replace('\\', '/', $fullPath);
        }

        $realPath = str_replace('\\', '/', $realPath);
        if (!str_starts_with($realPath, $this->projectRoot)) {
            throw new \RuntimeException('Path traversal detected: resolved path outside project');
        }

        return $realPath;
    }

    /**
     * Quickly check if a path is within the project.
     */
    public function isWithinProject(string $path): bool
    {
        try {
            $this->resolve($path);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
