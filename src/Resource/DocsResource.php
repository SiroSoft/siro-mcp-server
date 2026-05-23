<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Resource;

/**
 * siro://docs/* resources — Siro framework documentation.
 *
 * @package SiroSoft\McpServer\Resource
 */
final class DocsResource implements ResourceInterface
{
    private string $coreDocsPath;

    /** @var array<string, string> */
    private const RESOURCES = [
        'siro://docs/quickstart' => 'Quick Start Guide',
        'siro://docs/database' => 'Database / Schema / Migration docs',
        'siro://docs/model' => 'Model ORM documentation',
        'siro://docs/cli' => 'CLI reference (72 commands)',
        'siro://docs/route' => 'Router documentation',
        'siro://docs/auth' => 'Authentication / JWT docs',
        'siro://docs/validation' => 'Validation rules',
        'siro://docs/cache' => 'Caching docs',
        'siro://docs/queue' => 'Queue / Mail docs',
        'siro://docs/security' => 'Security best practices',
        'siro://docs/deployment' => 'Deployment guide',
        'siro://docs/architecture' => 'Framework architecture',
        'siro://docs/performance' => 'Performance & benchmark',
        'siro://docs/logger' => 'Logger & debugging',
    ];

    /** @var array<string, string> */
    private const FILE_MAP = [
        'siro://docs/database' => 'DATABASE.md',
        'siro://docs/cli' => 'CLI.md',
        'siro://docs/route' => 'ROUTER.md',
        'siro://docs/auth' => 'JWT.md',
        'siro://docs/validation' => 'VALIDATION.md',
        'siro://docs/cache' => 'CACHE.md',
        'siro://docs/security' => 'SECURITY.md',
        'siro://docs/architecture' => 'ARCHITECTURE.md',
        'siro://docs/performance' => 'PERFORMANCE.md',
        'siro://docs/logger' => 'LOGGER.md',
    ];

    public function __construct(string $basePath)
    {
        $basePath = rtrim($basePath, '\\/');

        // Try project docs first, fallback to vendor core docs
        $projectDocs = $basePath . '/docs';
        $this->coreDocsPath = is_dir($projectDocs) ? $projectDocs : ($basePath . '/vendor/sirosoft/core/docs');
    }

    public function getUriPrefix(): string
    {
        return 'siro://docs/';
    }

    public function listResources(): array
    {
        return self::RESOURCES;
    }

    public function readResource(string $uri): ?array
    {
        if (!isset(self::RESOURCES[$uri])) {
            return null;
        }

        if ($uri === 'siro://docs/quickstart') {
            // Try project-level quickstart
            $quickstartPaths = [
                $this->coreDocsPath . '/guides/QUICKSTART.md',
                dirname($this->coreDocsPath) . '/docs/guides/QUICKSTART.md', // SiroPHP project
            ];
            foreach ($quickstartPaths as $path) {
                if (file_exists($path)) {
                    $content = file_get_contents($path);
                    if ($content !== false) {
                        return ['text' => $content];
                    }
                }
            }
        }

        // Map URI to file
        $file = self::FILE_MAP[$uri] ?? null;
        if ($file === null) {
            return ['text' => "Documentation for '{$uri}' is not available as a file. Use the read_documentation tool instead."];
        }

        $path = $this->coreDocsPath . '/' . $file;
        if (!file_exists($path)) {
            return ['text' => "Documentation file not found: {$file}"];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return ['text' => "Error reading documentation: {$file}"];
        }

        // Truncate to 300 lines for resource mode
        $lines = explode("\n", $content);
        if (count($lines) > 300) {
            $lines = array_slice($lines, 0, 300);
            $lines[] = "\n... (truncated. Use read_documentation tool for full content)";
            $content = implode("\n", $lines);
        }

        return ['text' => $content];
    }
}
