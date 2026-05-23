<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Resource;

use SiroSoft\McpServer\Context\RouteGraph;
use SiroSoft\McpServer\Context\ProjectAnalyzer;

/**
 * siro://app/* resources — live project data.
 *
 * @package SiroSoft\McpServer\Resource
 */
final class AppResource implements ResourceInterface
{
    private string $basePath;

    private const RESOURCES = [
        'siro://app/routes' => 'Registered API routes',
        'siro://app/schema' => 'Database schema dump (requires DB connection)',
        'siro://app/models' => 'All models with fillable, casts, and relations',
        'siro://app/controllers' => 'All controllers with actions',
        'siro://app/middleware' => 'All registered middleware classes',
        'siro://app/services' => 'All service classes',
        'siro://app/config' => 'Application configuration',
        'siro://app/structure' => 'Project directory tree',
        'siro://app/openapi' => 'OpenAPI spec (generated from routes)',
    ];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');
    }

    public function getUriPrefix(): string
    {
        return 'siro://app/';
    }

    public function listResources(): array
    {
        return self::RESOURCES;
    }

    public function readResource(string $uri): ?array
    {
        return match ($uri) {
            'siro://app/routes' => $this->getRoutes(),
            'siro://app/models' => $this->getModels(),
            'siro://app/controllers' => $this->getControllers(),
            'siro://app/services' => $this->getServices(),
            'siro://app/middleware' => $this->getMiddleware(),
            'siro://app/structure' => $this->getStructure(),
            'siro://app/config' => $this->getConfig(),
            'siro://app/openapi' => $this->getOpenApi(),
            default => null,
        };
    }

    /**
     * @return array{text: string}
     */
    private function getRoutes(): array
    {
        $routeGraph = new RouteGraph($this->basePath);
        $routes = $routeGraph->getRoutes();

        $text = "=== API Routes ===\n\n";
        $text .= sprintf("%-8s %-40s %s\n", 'Method', 'Path', 'Handler');
        $text .= str_repeat('-', 80) . "\n";

        foreach ($routes as $route) {
            $text .= sprintf("%-8s %-40s %s\n", $route['method'], $route['path'], $route['handler']);
        }

        $text .= "\nTotal: " . count($routes) . " routes\n";
        return ['text' => $text];
    }

    /**
     * @return array{text: string}
     */
    private function getModels(): array
    {
        $analyzer = new ProjectAnalyzer($this->basePath);
        $modelsDir = $this->basePath . '/app/Models';

        $text = "=== Models ===\n\n";
        if (!is_dir($modelsDir)) {
            $text .= "No models directory found.\n";
            return ['text' => $text];
        }

        $files = glob($modelsDir . '/*.php');
        if ($files === false) {
            $text .= "No models found.\n";
            return ['text' => $text];
        }

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $name = pathinfo($file, PATHINFO_FILENAME);
            $text .= "### {$name}\n";
            $text .= "File: app/Models/{$name}.php\n";

            // Extract table
            if (preg_match('/protected\s+string\s+\$table\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $content, $m)) {
                $text .= "Table: {$m[1]}\n";
            }

            // Extract fillable
            if (preg_match('/protected\s+array\s+\$fillable\s*=\s*\[([^\]]*)\]/s', $content, $m)) {
                preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/', $m[1], $items);
                if ($items[1] !== []) {
                    $text .= "Fillable: " . implode(', ', $items[1]) . "\n";
                }
            }

            // Extract casts
            if (preg_match('/protected\s+array\s+\$casts\s*=\s*\[([^\]]*)\]/s', $content, $m)) {
                preg_match_all('/[\'\"]([^\'\"]+)[\'\"]\s*=>\s*[\'\"]([^\'\"]+)[\'\"]/', $m[1], $items, PREG_SET_ORDER);
                if ($items !== []) {
                    $castStr = '';
                    foreach ($items as $item) {
                        $castStr .= "{$item[1]}: {$item[2]}, ";
                    }
                    $text .= "Casts: " . rtrim($castStr, ', ') . "\n";
                }
            }

            // Extract hidden
            if (preg_match('/protected\s+array\s+\$hidden\s*=\s*\[([^\]]*)\]/s', $content, $m)) {
                preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/', $m[1], $items);
                if ($items[1] !== []) {
                    $text .= "Hidden: " . implode(', ', $items[1]) . "\n";
                }
            }

            $text .= "\n";
        }

        $text .= "Total: " . (is_array($files) ? count($files) : 0) . " models\n";
        return ['text' => $text];
    }

    /**
     * @return array{text: string}
     */
    private function getControllers(): array
    {
        $controllersDir = $this->basePath . '/app/Controllers';
        $text = "=== Controllers ===\n\n";

        if (!is_dir($controllersDir)) {
            $text .= "No controllers directory found.\n";
            return ['text' => $text];
        }

        $files = glob($controllersDir . '/*.php');
        if ($files === false || $files === []) {
            $text .= "No controllers found.\n";
            return ['text' => $text];
        }

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $content = file_get_contents($file);
            $methods = 0;

            if ($content !== false) {
                preg_match_all('/public\s+function\s+(\w+)\s*\(/', $content, $m);
                $methods = count($m[1]);
            }

            $text .= "- {$name} ({$methods} public methods)\n";
        }

        $text .= "\nTotal: " . count($files) . " controllers\n";
        return ['text' => $text];
    }

    /**
     * @return array{text: string}
     */
    private function getServices(): array
    {
        $servicesDir = $this->basePath . '/app/Services';
        $text = "=== Services ===\n\n";

        if (!is_dir($servicesDir)) {
            $text .= "No services directory found.\nSiro services pattern not detected.\n";
            return ['text' => $text];
        }

        $files = glob($servicesDir . '/*.php');
        if ($files === false || $files === []) {
            $text .= "No services found.\n";
            return ['text' => $text];
        }

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $text .= "- {$name}\n";
        }

        $text .= "\nTotal: " . count($files) . " services\n";
        return ['text' => $text];
    }

    /**
     * @return array{text: string}
     */
    private function getMiddleware(): array
    {
        $text = "=== Middleware ===\n\n";

        // Core middleware
        $coreMw = $this->basePath . '/vendor/sirosoft/core/src/Middleware';
        if (is_dir($coreMw)) {
            $text .= "--- Core Middleware ---\n";
            $files = glob($coreMw . '/*.php');
            if ($files !== false) {
                foreach ($files as $file) {
                    $text .= "- " . pathinfo($file, PATHINFO_FILENAME) . " (core)\n";
                }
            }
        }

        // App middleware
        $appMw = $this->basePath . '/app/Middleware';
        if (is_dir($appMw)) {
            $text .= "\n--- App Middleware ---\n";
            $files = glob($appMw . '/*.php');
            if ($files !== false) {
                foreach ($files as $file) {
                    $text .= "- " . pathinfo($file, PATHINFO_FILENAME) . " (app)\n";
                }
            }
        }

        return ['text' => $text];
    }

    /**
     * @return array{text: string}
     */
    private function getStructure(): array
    {
        $text = "=== Project Structure ===\n";
        $this->buildTree($this->basePath, $text, 0, 3);
        return ['text' => $text];
    }

    private function buildTree(string $dir, string &$output, int $depth, int $maxDepth): void
    {
        if ($depth > $maxDepth) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        $skipDirs = ['.', '..', '.git', 'vendor', 'node_modules', 'storage', 'coverage', '.phpunit.cache'];

        foreach ($items as $item) {
            if (in_array($item, $skipDirs, true)) {
                continue;
            }

            $path = $dir . '/' . $item;
            $indent = str_repeat('  ', $depth);
            $prefix = is_dir($path) ? '📁' : '📄';

            $output .= "{$indent}{$prefix} {$item}\n";

            if (is_dir($path) && $depth < $maxDepth) {
                // Only scan app/, config/, routes/, database/, public/
                $allowedDirs = ['app', 'config', 'routes', 'database', 'public', 'resources', 'tests'];
                if (in_array($item, $allowedDirs, true)) {
                    $this->buildTree($path, $output, $depth + 1, $maxDepth);
                }
            }
        }
    }

    /**
     * @return array{text: string}
     */
    private function getConfig(): array
    {
        $configDir = $this->basePath . '/config';
        $text = "=== Configuration ===\n\n";

        if (!is_dir($configDir)) {
            $text .= "No config directory found.\n";
            return ['text' => $text];
        }

        $files = glob($configDir . '/*.php');
        if ($files === false) {
            return ['text' => $text . "No config files found.\n"];
        }

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $content = file_get_contents($file);
            $text .= "--- {$name} ---\n";
            if ($content !== false) {
                // Only show first 30 lines of config
                $lines = explode("\n", $content);
                $lines = array_slice($lines, 0, 30);
                $text .= implode("\n", $lines) . "\n\n";
            }
        }

        return ['text' => $text];
    }

    /**
     * @return array{text: string}
     */
    private function getOpenApi(): array
    {
        return ['text' => "OpenAPI spec: Available via `php siro make:openapi` or see public/openapi.json\n"];
    }
}
