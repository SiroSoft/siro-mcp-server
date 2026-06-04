<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tool;

use SiroSoft\McpServer\Context\ProjectAnalyzer;

/**
 * analyze_project — Deep analysis of the entire Siro project.
 *
 * @package SiroSoft\McpServer\Tool
 */
final class AnalyzeProject implements ToolInterface
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');
    }

    public function getName(): string
    {
        return 'analyze_project';
    }

    public function getDescription(): string
    {
        return 'Deep analysis of the entire Siro project: routes, models, controllers, services, middleware, database schema, and architecture summary. AI uses this to understand project context before generating code.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'depth' => [
                    'type' => 'string',
                    'description' => 'Analysis depth: summary (overview), full (detailed), graph (dependency graph)',
                    'enum' => ['summary', 'full', 'graph'],
                    'default' => 'summary',
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        $depth = $arguments['depth'] ?? 'summary';
        if (!in_array($depth, ['summary', 'full', 'graph'], true)) {
            $depth = 'summary';
        }

        $analyzer = new ProjectAnalyzer($this->basePath);
        $result = $analyzer->analyze($depth);

        $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return '{}';
        }
        return $encoded;
    }
}
