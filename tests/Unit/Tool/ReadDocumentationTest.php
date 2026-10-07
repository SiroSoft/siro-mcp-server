<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Tool\ReadDocumentation;

final class ReadDocumentationTest extends TestCase
{
    private string $basePath;
    private ReadDocumentation $tool;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-doc-' . uniqid();
        @mkdir($this->basePath . '/vendor/sirosoft/core/docs', 0777, true);
        @mkdir($this->basePath . '/docs', 0777, true);
        $this->tool = new ReadDocumentation($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->basePath);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function test_get_name(): void
    {
        $this->assertEquals('read_documentation', $this->tool->getName());
    }

    public function test_requires_topic(): void
    {
        $result = $this->tool->execute([]);
        $this->assertStringContainsString('Error', $result);
    }

    public function test_unknown_topic_returns_error(): void
    {
        $result = $this->tool->execute(['topic' => 'nonexistent']);
        $this->assertStringContainsString('Error', $result);
        $this->assertStringContainsString('Unknown topic', $result);
    }

    public function test_returns_message_when_doc_file_missing(): void
    {
        $result = $this->tool->execute(['topic' => 'database']);
        $this->assertStringContainsString('not found', $result);
    }

    public function test_reads_from_project_docs(): void
    {
        $this->createDocFile('docs/DATABASE.md', '# Database Docs');
        $result = $this->tool->execute(['topic' => 'database']);
        $this->assertStringContainsString('Database Docs', $result);
    }

    public function test_reads_from_vendor_docs(): void
    {
        $this->createDocFile('vendor/sirosoft/core/docs/DATABASE.md', '# Vendor DB Docs');
        $result = $this->tool->execute(['topic' => 'database']);
        $this->assertStringContainsString('Vendor DB Docs', $result);
    }

    public function test_reads_section_topic_from_vendor_docs(): void
    {
        $this->createDocFile(
            'vendor/sirosoft/core/docs/DATABASE.md',
            "# Database\n\n## Model ORM\n\nModels live in App\\Models.\n\n## Migrations\n\nRun php siro migrate.\n"
        );
        $result = $this->tool->execute(['topic' => 'model']);
        $this->assertStringContainsString('Models live in', $result);
        $this->assertStringNotContainsString('not found', $result);
        $this->assertStringNotContainsString('Run php siro migrate', $result);
    }

    public function test_has_input_schema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertArrayHasKey('type', $schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('topic', $schema['properties']);
    }

    public function test_has_description(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }

    private function createDocFile(string $relativePath, string $content): void
    {
        $fullPath = $this->basePath . '/' . $relativePath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, $content);
    }
}
