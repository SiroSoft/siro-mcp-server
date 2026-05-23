<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Context;

/**
 * Analyzes a Siro project structure: routes, models, controllers, services, middleware, DB.
 *
 * @package SiroSoft\McpServer\Context
 */
final class ProjectAnalyzer
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');
    }

    /**
     * Get project metadata.
     *
     * @return array<string, mixed>
     */
    public function getProjectInfo(): array
    {
        $composerFile = $this->basePath . '/composer.json';
        $info = ['name' => basename($this->basePath), 'version' => 'unknown', 'php_version' => PHP_VERSION];

        if (file_exists($composerFile)) {
            $content = file_get_contents($composerFile);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $info['name'] = $data['name'] ?? $info['name'];
                }
            }
        }

        return $info;
    }

    /**
     * Scan application directory structure.
     *
     * @return array<string, mixed>
     */
    public function analyze(string $depth = 'summary'): array
    {
        return [
            'project' => $this->getProjectInfo(),
            'routes' => $this->getRouteSummary(),
            'models' => $this->getModelSummary(),
            'controllers' => $this->getControllerSummary(),
            'services' => $this->getServiceSummary(),
            'middleware' => $this->getMiddlewareSummary(),
            'architecture' => $this->getArchitectureSummary(),
        ];
    }

    /**
     * @return array{total: int, list: array<int, array{name: string, methods: string, prefix: string}>}
     */
    private function getRouteSummary(): array
    {
        $routeFile = $this->basePath . '/routes/api.php';
        $routes = [];
        $total = 0;

        if (file_exists($routeFile)) {
            $content = file_get_contents($routeFile);
            if ($content !== false) {
                // Parse route registrations: $router->get(...), $router->post(...), $router->resource(...)
                $pattern = '/\$router->(get|post|put|patch|delete|resource)\([\'\"](\/[^\'\"\s]*)[\'\"]/';
                preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $routes[] = [
                        'methods' => $match[1],
                        'prefix' => $match[2],
                    ];
                    $total++;
                }
            }
        }

        return ['total' => $total, 'list' => $routes];
    }

    /**
     * @return array{total: int, list: array<int, array{name: string, table: string, fillable: array<int, string>, casts: array<string, string>}>}
     */
    private function getModelSummary(): array
    {
        $modelsDir = $this->basePath . '/app/Models';
        $models = [];

        if (is_dir($modelsDir)) {
            $files = glob($modelsDir . '/*.php');
            if ($files !== false) {
                foreach ($files as $file) {
                    $model = $this->parseModelFile($file);
                    if ($model !== null) {
                        $models[] = $model;
                    }
                }
            }
        }

        return ['total' => count($models), 'list' => $models];
    }

    /**
     * @return array{name: string, table: string, fillable: array<int, string>, casts: array<string, string>}|null
     */
    private function parseModelFile(string $filePath): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $name = pathinfo($filePath, PATHINFO_FILENAME);

        // Extract table
        $table = '';
        if (preg_match('/protected\s+string\s+\$table\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $content, $m)) {
            $table = $m[1];
        }

        // Extract fillable
        $fillable = [];
        if (preg_match('/protected\s+array\s+\$fillable\s*=\s*\[([^\]]*)\]/s', $content, $m)) {
            preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/', $m[1], $items);
            $fillable = $items[1] ?? [];
        }

        // Extract casts
        $casts = [];
        if (preg_match('/protected\s+array\s+\$casts\s*=\s*\[([^\]]*)\]/s', $content, $m)) {
            preg_match_all('/[\'\"]([^\'\"]+)[\'\"]\s*=>\s*[\'\"]([^\'\"]+)[\'\"]/', $m[1], $items, PREG_SET_ORDER);
            foreach ($items as $item) {
                $casts[$item[1]] = $item[2];
            }
        }

        return [
            'name' => $name,
            'table' => $table ?: strtolower($name) . 's',
            'fillable' => $fillable,
            'casts' => $casts,
        ];
    }

    /**
     * @return array{total: int, list: array<int, string>}
     */
    private function getControllerSummary(): array
    {
        $controllersDir = $this->basePath . '/app/Controllers';
        $controllers = [];

        if (is_dir($controllersDir)) {
            $files = glob($controllersDir . '/*.php');
            if ($files !== false) {
                foreach ($files as $file) {
                    $controllers[] = pathinfo($file, PATHINFO_FILENAME);
                }
            }
        }

        sort($controllers);
        return ['total' => count($controllers), 'list' => $controllers];
    }

    /**
     * @return array{total: int, list: array<int, string>}
     */
    private function getServiceSummary(): array
    {
        $servicesDir = $this->basePath . '/app/Services';
        $services = [];

        if (is_dir($servicesDir)) {
            $files = glob($servicesDir . '/*.php');
            if ($files !== false) {
                foreach ($files as $file) {
                    $services[] = pathinfo($file, PATHINFO_FILENAME);
                }
            }
        }

        sort($services);
        return ['total' => count($services), 'list' => $services];
    }

    /**
     * @return array{total: int, list: array<int, string>}
     */
    private function getMiddlewareSummary(): array
    {
        $middlewareDir = $this->basePath . '/app/Middleware';
        $middleware = [];

        if (is_dir($middlewareDir)) {
            $files = glob($middlewareDir . '/*.php');
            if ($files !== false) {
                foreach ($files as $file) {
                    $middleware[] = pathinfo($file, PATHINFO_FILENAME);
                }
            }
        }

        return ['total' => count($middleware), 'list' => $middleware];
    }

    /**
     * @return array{pattern: string, has_auth: bool, has_soft_deletes: bool}
     */
    private function getArchitectureSummary(): array
    {
        // Detect architecture pattern from imports in controllers
        $hasServices = false;
        $hasRepositories = false;

        $controllersDir = $this->basePath . '/app/Controllers';
        if (is_dir($controllersDir)) {
            $files = glob($controllersDir . '/*.php');
            if ($files !== false) {
                foreach ($files as $file) {
                    $content = file_get_contents($file);
                    if ($content !== false) {
                        if (str_contains($content, 'App\\Services\\')) {
                            $hasServices = true;
                        }
                        if (str_contains($content, 'App\\Repositories\\')) {
                            $hasRepositories = true;
                        }
                    }
                }
            }
        }

        $pattern = 'Controller';
        if ($hasServices) {
            $pattern .= ' → Service';
        }
        if ($hasRepositories) {
            $pattern .= ' → Repository';
        }
        $pattern .= ' → Model';

        return [
            'pattern' => $pattern,
            'has_auth' => $this->hasAuth(),
            'has_soft_deletes' => $this->hasSoftDeletes(),
        ];
    }

    private function hasAuth(): bool
    {
        $routeFile = $this->basePath . '/routes/api.php';
        if (!file_exists($routeFile)) {
            return false;
        }
        $content = file_get_contents($routeFile);
        return $content !== false && str_contains($content, 'AuthController');
    }

    private function hasSoftDeletes(): bool
    {
        $modelsDir = $this->basePath . '/app/Models';
        if (!is_dir($modelsDir)) {
            return false;
        }
        $files = glob($modelsDir . '/*.php');
        if ($files === false) {
            return false;
        }
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content !== false && str_contains($content, 'SoftDeletes')) {
                return true;
            }
        }
        return false;
    }
}
