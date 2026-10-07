<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Security\AuditLogger;

final class AuditLoggerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-audit-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    public function test_records_redacted_bounded_result(): void
    {
        $logger = new AuditLogger($this->basePath);
        $run = $logger->start('write_file', 1, ['password' => 'secret', 'note' => 'safe']);
        $this->assertTrue($logger->finish($run, 'completed', 'token=secret ' . str_repeat('x', 17000), true));

        $record = $logger->latest();
        $encoded = json_encode($record) ?: '';
        $this->assertNotNull($record);
        $this->assertStringNotContainsString('secret', $encoded);
        $this->assertStringContainsString('[truncated]', $encoded);
        $this->assertSame('completed', $record['status']);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) {
            $path = $directory . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
