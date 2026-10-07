<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Resource;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Resource\DocsResource;

final class DocsResourceTest extends TestCase
{
    private string $basePath;
    private DocsResource $resource;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-drs-' . uniqid();
        @mkdir($this->basePath . '/vendor/sirosoft/core/docs', 0777, true);
        @mkdir($this->basePath . '/docs', 0777, true);
        $this->resource = new DocsResource($this->basePath);
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

    public function test_uri_prefix(): void
    {
        $this->assertEquals('siro://docs/', $this->resource->getUriPrefix());
    }

    public function test_lists_resources(): void
    {
        $resources = $this->resource->listResources();
        $this->assertArrayHasKey('siro://docs/quickstart', $resources);
        $this->assertArrayHasKey('siro://docs/database', $resources);
        $this->assertArrayHasKey('siro://docs/model', $resources);
        $this->assertArrayHasKey('siro://docs/cli', $resources);
        $this->assertArrayHasKey('siro://docs/route', $resources);
        $this->assertArrayHasKey('siro://docs/auth', $resources);
        $this->assertCount(14, $resources);
    }

    public function test_read_unknown_uri_returns_null(): void
    {
        $this->assertNull($this->resource->readResource('siro://docs/unknown'));
    }

    public function test_read_quickstart_when_not_found(): void
    {
        $result = $this->resource->readResource('siro://docs/quickstart');
        $this->assertNotNull($result);
        $this->assertStringContainsString('not available as a file', $result['text']);
    }

    public function test_read_database_from_docs(): void
    {
        file_put_contents($this->basePath . '/docs/DATABASE.md', '# Database Docs');
        $result = $this->resource->readResource('siro://docs/database');
        $this->assertNotNull($result);
        $this->assertStringContainsString('Database Docs', $result['text']);
    }

    public function test_read_without_file_returns_fallback(): void
    {
        $result = $this->resource->readResource('siro://docs/model');
        $this->assertNotNull($result);
        $this->assertStringContainsString('not available as a file', $result['text']);
    }

    public function test_read_cli_from_docs(): void
    {
        file_put_contents($this->basePath . '/docs/CLI.md', '# CLI Reference');
        $result = $this->resource->readResource('siro://docs/cli');
        $this->assertNotNull($result);
        $this->assertStringContainsString('CLI Reference', $result['text']);
    }

    public function test_read_quickstart_from_project_docs(): void
    {
        @mkdir($this->basePath . '/docs/guides', 0777, true);
        file_put_contents($this->basePath . '/docs/guides/QUICKSTART.md', '# Project Quickstart');
        $result = $this->resource->readResource('siro://docs/quickstart');
        $this->assertNotNull($result);
        $this->assertStringContainsString('Project Quickstart', $result['text']);
    }
}
