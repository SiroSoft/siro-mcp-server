<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Context;

/**
 * Builds a route → controller → model dependency graph.
 *
 * @package SiroSoft\McpServer\Context
 */
final class RouteGraph
{
    private string $basePath;

    /** @var array<int, array{method: string, path: string, handler: string, middleware: array<int, string>}> */
    private array $routes = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');
    }

    /**
     * Parse routes from api.php and extract structured route definitions.
     *
     * @return array<int, array{method: string, path: string, handler: string, middleware: array<int, string>}>
     */
    public function parse(): array
    {
        $routeFile = $this->basePath . '/routes/api.php';
        if (!file_exists($routeFile)) {
            return [];
        }

        $content = file_get_contents($routeFile);
        if ($content === false) {
            return [];
        }

        $this->routes = [];

        // Parse group prefix
        $groupPrefix = '';
        if (preg_match('/\$router->group\(\s*[\'\"]([^\'\"]+)[\'\"]/', $content, $gm)) {
            $groupPrefix = '/' . trim($gm[1], '/');
        }

        // Parse resource routes: $router->resource('products', Controller::class, [...])
        $resourcePattern = '/\$router->resource\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*([^,\s\)]+)[^\)]*\)/';
        preg_match_all($resourcePattern, $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = $match[1];
            $controller = trim($match[2]);
            $handler = $this->resolveHandlerClass($controller ?: '');

            $this->routes[] = ['method' => 'GET', 'path' => "{$groupPrefix}/{$name}", 'handler' => "{$handler}::index", 'middleware' => []];
            $this->routes[] = ['method' => 'GET', 'path' => "{$groupPrefix}/{$name}/{id}", 'handler' => "{$handler}::show", 'middleware' => []];
            $this->routes[] = ['method' => 'POST', 'path' => "{$groupPrefix}/{$name}", 'handler' => "{$handler}::store", 'middleware' => []];
            $this->routes[] = ['method' => 'PUT', 'path' => "{$groupPrefix}/{$name}/{id}", 'handler' => "{$handler}::update", 'middleware' => []];
            $this->routes[] = ['method' => 'DELETE', 'path' => "{$groupPrefix}/{$name}/{id}", 'handler' => "{$handler}::delete", 'middleware' => []];
        }

        // Parse explicit routes: $router->get(...), $router->post(...)
        $explicitPattern = '/\$router->(get|post|put|patch|delete)\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*([^,]+?)\s*\)/';
        preg_match_all($explicitPattern, $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $method = strtoupper($match[1]);
            $path = $match[2];

            // Only add if path starts with /
            if (!str_starts_with($path, '/')) {
                $path = $groupPrefix . '/' . $path;
            }

            $this->routes[] = [
                'method' => $method,
                'path' => $path,
                'handler' => $this->resolveHandler($match[3]),
                'middleware' => [],
            ];
        }

        return $this->routes;
    }

    private function resolveHandler(string $handlerExpr): string
    {
        $handlerExpr = trim($handlerExpr);

        // [Controller::class, 'method']
        if (preg_match('/\[([^,\]]+)\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\]/', $handlerExpr, $m)) {
            return trim($m[1]) . '::' . $m[2];
        }

        // Closure - mark as Closure
        if (str_starts_with($handlerExpr, 'function') || str_starts_with($handlerExpr, 'fn ')) {
            return 'Closure';
        }

        return $handlerExpr;
    }

    private function resolveHandlerClass(string $expr): string
    {
        $expr = trim($expr);
        // \App\Controllers\ProductController::class → App\Controllers\ProductController
        // Or string like '\App\Controllers\ProductController'
        return str_replace(['::class', '\\\\'], ['', '\\'], $expr);
    }

    /**
     * @return array<int, array{method: string, path: string, handler: string, middleware: array<int, string>}>
     */
    public function getRoutes(): array
    {
        if ($this->routes === []) {
            $this->parse();
        }
        return $this->routes;
    }
}
