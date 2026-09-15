<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Resource;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Resource\DebugResource;

final class DebugResourceTest extends TestCase
{
    private string $basePath;
    private DebugResource $resource;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-dr-' . uniqid();
        $this->resource = new DebugResource($this->basePath);
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
        $this->assertEquals('siro://debug/', $this->resource->getUriPrefix());
    }

    public function test_lists_errors_only_when_no_traces(): void
    {
        $resources = $this->resource->listResources();
        $this->assertArrayNotHasKey('siro://debug/traces/latest', $resources);
        $this->assertArrayHasKey('siro://debug/errors/latest', $resources);
    }

    public function test_lists_traces_when_trace_dir_exists(): void
    {
        @mkdir($this->basePath . '/storage/framework/traces', 0777, true);
        $resources = $this->resource->listResources();
        $this->assertArrayHasKey('siro://debug/traces/latest', $resources);
    }

    public function test_read_unknown_uri_returns_null(): void
    {
        $this->assertNull($this->resource->readResource('siro://debug/unknown'));
    }

    public function test_read_traces_when_no_trace_dir(): void
    {
        $result = $this->resource->readResource('siro://debug/traces/latest');
        $this->assertNotNull($result);
        $this->assertStringContainsString('No trace directory', $result['text']);
    }

    public function test_read_errors_when_no_log_dir(): void
    {
        $result = $this->resource->readResource('siro://debug/errors/latest');
        $this->assertNotNull($result);
        $this->assertStringContainsString('No log directory', $result['text']);
    }

    public function test_read_latest_trace_from_file(): void
    {
        $traceDir = $this->basePath . '/storage/framework/traces';
        @mkdir($traceDir, 0777, true);
        file_put_contents($traceDir . '/trace-001.json', json_encode([
            'method' => 'GET',
            'path' => '/api/test',
            'status' => '200',
            'duration' => 0.05,
        ]));

        $result = $this->resource->readResource('siro://debug/traces/latest');
        $this->assertNotNull($result);
        $this->assertStringContainsString('GET', $result['text']);
        $this->assertStringContainsString('/api/test', $result['text']);
    }

    public function test_read_latest_error_from_log(): void
    {
        $logDir = $this->basePath . '/storage/logs';
        @mkdir($logDir, 0777, true);
        file_put_contents($logDir . '/error.log', '[2026-01-01] CRITICAL: Something broke');

        $result = $this->resource->readResource('siro://debug/errors/latest');
        $this->assertNotNull($result);
        $this->assertStringContainsString('CRITICAL', $result['text']);
    }
}
