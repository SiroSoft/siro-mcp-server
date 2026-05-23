<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Context;

/**
 * Builds a model dependency graph: relations, casts, fillable.
 *
 * @package SiroSoft\McpServer\Context
 */
final class ModelGraph
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');
    }

    /**
     * Parse relations from all model files.
     *
     * @return array<string, array<int, array{type: string, target: string, method: string}>>
     */
    public function parseRelations(): array
    {
        $modelsDir = $this->basePath . '/app/Models';
        $graph = [];

        if (!is_dir($modelsDir)) {
            return $graph;
        }

        $files = glob($modelsDir . '/*.php');
        if ($files === false) {
            return $graph;
        }

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $relations = [];
            // Match relation methods: HasMany, BelongsTo, BelongsToMany, HasOne
            $pattern = '/function\s+(\w+)\(\s*\)\s*:\s*(HasMany|BelongsTo|BelongsToMany|HasOne|HasManyThrough)\b/';
            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $method = $match[1];
                $type = $match[2];

                // Extract the related class from the return statement
                $target = '';
                $returnPattern = '/return\s+\$this->' . lcfirst($type) . '\s*\(\s*([^:\s,)]+)/';
                if (preg_match($returnPattern, $content, $rm)) {
                    $target = trim($rm[1]);
                    $target = str_replace(['::class', '\\\\'], ['', '\\'], $target);
                    // Extract short class name
                    if (str_contains($target, '\\')) {
                        $parts = explode('\\', $target);
                        $target = end($parts);
                    }
                }

                $relations[] = [
                    'type' => lcfirst($type),
                    'target' => $target ?: 'unknown',
                    'method' => $method,
                ];
            }

            if ($relations !== []) {
                $graph[$name] = $relations;
            }
        }

        return $graph;
    }
}
