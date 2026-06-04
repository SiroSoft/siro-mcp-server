<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tool;

/**
 * read_documentation — Read Siro framework documentation by topic.
 *
 * Maps topics to doc files in vendor/sirosoft/core/docs/.
 *
 * @package SiroSoft\McpServer\Tool
 */
final class ReadDocumentation implements ToolInterface
{
    /** @var array<string, string> */
    private const DOC_MAP = [
        'quickstart' => 'guides/QUICKSTART.md',
        'database' => 'DATABASE.md',
        'model' => 'DATABASE.md#model-orm',
        'cli' => 'CLI.md',
        'route' => 'ROUTER.md',
        'router' => 'ROUTER.md',
        'auth' => 'JWT.md',
        'authentication' => 'JWT.md',
        'validation' => 'VALIDATION.md',
        'cache' => 'CACHE.md',
        'queue' => 'LOGGER.md',
        'mail' => 'LOGGER.md',
        'security' => 'SECURITY.md',
        'deployment' => 'ARCHITECTURE.md',
        'migration' => 'DATABASE.md#migrations',
        'architecture' => 'ARCHITECTURE.md',
        'performance' => 'PERFORMANCE.md',
        'logger' => 'LOGGER.md',
    ];

    /** @var array<string, string> */
    private const DOC_TOPIC_MAP = [
        'quickstart' => 'SiroPHP Quick Start Guide',
        'database' => 'Database — Query Builder, Model ORM & Schema Builder',
        'model' => 'Model — ORM, Relations, Accessors & Mutators',
        'cli' => 'CLI Reference — all Siro commands',
        'route' => 'Router — Route definitions, groups, middleware',
        'auth' => 'Authentication — JWT tokens, RBAC, middleware',
        'validation' => 'Validation — Request validation rules',
        'cache' => 'Caching — Cache drivers, tags, TTL',
        'queue' => 'Queue & Mail — Job processing, mail sending',
        'security' => 'Security — Best practices, OWASP, threat model',
        'deployment' => 'Deployment — Architecture, production setup',
        'migration' => 'Migrations — Schema Builder, Blueprint',
        'architecture' => 'Architecture — Framework internals, design decisions',
        'performance' => 'Performance — Benchmark, optimization',
        'logger' => 'Logger — Logging, tracing, debugging',
    ];

    private string $basePath;
    private string $coreDocsPath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');
        $this->coreDocsPath = $this->basePath . '/vendor/sirosoft/core/docs';
    }

    public function getName(): string
    {
        return 'read_documentation';
    }

    public function getDescription(): string
    {
        $topics = implode(', ', array_keys(self::DOC_MAP));
        return "Read Siro framework documentation by topic. Available topics: {$topics}";
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topic' => [
                    'type' => 'string',
                    'description' => 'Documentation topic to read',
                    'enum' => array_keys(self::DOC_MAP),
                ],
            ],
            'required' => ['topic'],
        ];
    }

    public function execute(array $arguments): string
    {
        $topic = $arguments['topic'] ?? '';
        if (!is_string($topic) || $topic === '') {
            return 'Error: topic parameter is required.';
        }

        if (!isset(self::DOC_MAP[$topic])) {
            return "Error: Unknown topic '{$topic}'. Available topics: " . implode(', ', array_keys(self::DOC_MAP));
        }

        $docFile = self::DOC_MAP[$topic];
        $topicTitle = self::DOC_TOPIC_MAP[$topic] ?? $topic;

        // Try core docs first
        $coreFile = $this->coreDocsPath . '/' . $docFile;

        // Try project-level docs for guides
        $projectDocsPath = $this->basePath . '/docs';
        $projectGuideFile = $projectDocsPath . '/' . $docFile;

        $content = null;

        if (file_exists($coreFile)) {
            $content = file_get_contents($coreFile);
        } elseif (file_exists($projectGuideFile)) {
            $content = file_get_contents($projectGuideFile);
        }

        if ($content === false || $content === null) {
            return "# {$topicTitle}\n\nDocumentation file not found. Ensure `sirosoft/core` is installed.";
        }

        // If topic points to a section (e.g. DATABASE.md#model-orm), extract that section
        $section = '';
        if (str_contains($docFile, '#')) {
            $parts = explode('#', $docFile);
            $section = $parts[1] ?? '';
        }

        // For section-specific topics, try to extract the relevant section
        if ($section !== '') {
            $sectionContent = $this->extractSection($content, $section);
            if ($sectionContent !== null) {
                return "# {$topicTitle}\n\n{$sectionContent}";
            }
        }

        // Truncate very large docs to first 200 lines
        $lines = explode("\n", $content);
        if (count($lines) > 200) {
            $lines = array_slice($lines, 0, 200);
            $lines[] = "\n... (documentation truncated. Use a more specific topic for details.)";
            $content = implode("\n", $lines);
        }

        return "# {$topicTitle}\n\n" . $content;
    }

    /**
     * Extract a section by heading name from markdown content.
     */
    private function extractSection(string $content, string $sectionName): ?string
    {
        $normalizedName = str_replace(['-', '_'], ' ', strtolower($sectionName));
        $lines = explode("\n", $content);

        $inSection = false;
        $sectionLines = [];
        $depth = 0;

        foreach ($lines as $line) {
            $lineLower = strtolower($line);

            // Check for heading match
            if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $m)) {
                $headingText = strtolower(trim($m[2]));
                $headingLevel = strlen($m[1]);

                if (!$inSection) {
                    // Check if this heading matches our target
                    if ($headingText === $normalizedName || str_contains($headingText, $normalizedName)) {
                        $inSection = true;
                        $depth = $headingLevel;
                        $sectionLines[] = $line;
                        continue;
                    }
                } else {
                    // We're in the section - stop at next heading of same or higher level
                    if ($headingLevel <= $depth && $headingText !== $normalizedName) {
                        break;
                    }
                    $sectionLines[] = $line;
                }
            } elseif ($inSection) {
                $sectionLines[] = $line;
            }
        }

        if ($sectionLines === []) {
            return null;
        }

        return implode("\n", $sectionLines);
    }
}
