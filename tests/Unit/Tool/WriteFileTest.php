<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Tool\WriteFile;

final class WriteFileTest extends TestCase
{
    private string $basePath;
    private WriteFile $tool;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-wf-' . uniqid();
        @mkdir($this->basePath . '/app', 0777, true);
        $this->tool = new WriteFile($this->basePath);
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

    public function test_writes_new_file(): void
    {
        $result = $this->tool->execute([
            'path' => 'app/test.txt',
            'content' => 'hello world',
        ]);

        $this->assertStringContainsString('OK', $result);
        $this->assertStringContainsString('app/test.txt', $result);
        $this->assertFileExists($this->basePath . '/app/test.txt');
        $this->assertEquals('hello world', file_get_contents($this->basePath . '/app/test.txt'));
    }

    public function test_requires_path(): void
    {
        $result = $this->tool->execute([
            'content' => 'data',
        ]);

        $this->assertStringContainsString('Error', $result);
    }

    public function test_content_defaults_to_empty(): void
    {
        $result = $this->tool->execute([
            'path' => 'app/empty.txt',
        ]);

        $this->assertStringContainsString('OK', $result);
        $this->assertFileExists($this->basePath . '/app/empty.txt');
        $this->assertEquals('', file_get_contents($this->basePath . '/app/empty.txt'));
    }

    public function test_warns_on_existing_file_without_force(): void
    {
        file_put_contents($this->basePath . '/app/existing.txt', 'original');

        $result = $this->tool->execute([
            'path' => 'app/existing.txt',
            'content' => 'overwrite',
        ]);

        $this->assertStringContainsString('Warning', $result);
        $this->assertEquals('original', file_get_contents($this->basePath . '/app/existing.txt'));
    }

    public function test_overwrites_with_force(): void
    {
        file_put_contents($this->basePath . '/app/existing.txt', 'original');

        $result = $this->tool->execute([
            'path' => 'app/existing.txt',
            'content' => 'overwritten',
            'force' => true,
        ]);

        $this->assertStringContainsString('OK', $result);
        $this->assertEquals('overwritten', file_get_contents($this->basePath . '/app/existing.txt'));
    }

    public function test_blocks_path_traversal(): void
    {
        $result = $this->tool->execute([
            'path' => '../../escape.txt',
            'content' => 'hacked',
        ]);

        $this->assertStringContainsString('Error', $result);
        $this->assertStringContainsString('traversal', $result);
    }

    public function test_writes_in_subdir_when_parent_exists(): void
    {
        @mkdir($this->basePath . '/app/NewDir', 0777, true);
        $result = $this->tool->execute([
            'path' => 'app/NewDir/file.txt',
            'content' => 'deep',
        ]);

        $this->assertStringContainsString('OK', $result);
        $this->assertFileExists($this->basePath . '/app/NewDir/file.txt');
    }
}
