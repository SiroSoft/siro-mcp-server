<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Tool\ExecuteCli;

final class ExecuteCliTest extends TestCase
{
    private string $basePath;
    private ExecuteCli $tool;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-cli-' . uniqid();
        @mkdir($this->basePath);
        $this->tool = new ExecuteCli($this->basePath);
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
        $this->assertEquals('execute_cli', $this->tool->getName());
    }

    public function test_requires_command(): void
    {
        $result = $this->tool->execute([]);
        $this->assertStringContainsString('Error', $result);
        $this->assertStringContainsString('command', $result);
    }

    public function test_blocks_blocklist_commands(): void
    {
        $result = $this->tool->execute(['command' => 'tinker']);
        $this->assertStringContainsString('blocked', $result);
    }

    public function test_blocks_shell(): void
    {
        $result = $this->tool->execute(['command' => 'shell']);
        $this->assertStringContainsString('blocked', $result);
    }

    public function test_rejects_non_whitelisted_command(): void
    {
        $result = $this->tool->execute(['command' => 'some:unknown']);
        $this->assertStringContainsString('not in the allowed', $result);
    }

    public function test_destructive_command_requires_force(): void
    {
        $result = $this->tool->execute(['command' => 'migrate']);
        $this->assertStringContainsString('destructive', $result);
        $this->assertStringContainsString('force', $result);
    }

    public function test_destructive_command_with_force_fails_no_siro(): void
    {
        $result = $this->tool->execute(['command' => 'migrate', 'force' => true]);
        $this->assertStringContainsString('not found', $result);
    }

    public function test_whitelisted_command_fails_no_siro(): void
    {
        $result = $this->tool->execute(['command' => 'route:list']);
        $this->assertStringContainsString('not found', $result);
    }

    public function test_has_input_schema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertArrayHasKey('type', $schema);
        $this->assertArrayHasKey('command', $schema['properties']);
        $this->assertArrayHasKey('force', $schema['properties']);
    }

    public function test_has_description(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }
}
