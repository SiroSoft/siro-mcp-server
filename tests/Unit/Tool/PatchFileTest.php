<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Tool\PatchFile;

final class PatchFileTest extends TestCase
{
    private string $basePath;
    private PatchFile $tool;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-pf-' . uniqid();
        @mkdir($this->basePath . '/app', 0777, true);
        $this->tool = new PatchFile($this->basePath);
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

    public function test_diff_mode_returns_preview(): void
    {
        file_put_contents($this->basePath . '/app/file.txt', "line1\nline2\nline3");

        $result = $this->tool->execute([
            'path' => 'app/file.txt',
            'diff' => "line1\n-line2\n+modified2\nline3",
            'mode' => 'diff',
        ]);

        $this->assertStringContainsString('DIFF PREVIEW', $result);
        $this->assertStringContainsString('NOT applied', $result);
    }

    public function test_direct_mode_applies_patch(): void
    {
        file_put_contents($this->basePath . '/app/file.txt', "line1\nline2\nline3");

        $result = $this->tool->execute([
            'path' => 'app/file.txt',
            'diff' => "line1\n-line2\n+modified2\nline3",
            'mode' => 'direct',
        ]);

        $this->assertStringContainsString('OK', $result);
        $this->assertStringContainsString('Patch applied', $result);
        $content = file_get_contents($this->basePath . '/app/file.txt');
        $this->assertStringContainsString('modified2', $content ?: '');
        $this->assertStringNotContainsString("line2\n", $content ?: '');
    }

    public function test_error_on_nonexistent_file(): void
    {
        $result = $this->tool->execute([
            'path' => 'app/nonexistent.txt',
            'diff' => '+new line',
        ]);

        $this->assertStringContainsString('Error', $result);
        $this->assertStringContainsString('does not exist', $result);
    }

    public function test_requires_path(): void
    {
        $result = $this->tool->execute([
            'diff' => '+test',
        ]);

        $this->assertStringContainsString('Error', $result);
    }

    public function test_requires_diff(): void
    {
        $result = $this->tool->execute([
            'path' => 'app/file.txt',
        ]);

        $this->assertStringContainsString('Error', $result);
    }

    public function test_invalid_mode(): void
    {
        $result = $this->tool->execute([
            'path' => 'app/file.txt',
            'diff' => '+test',
            'mode' => 'invalid',
        ]);

        $this->assertStringContainsString('Error', $result);
        $this->assertStringContainsString('Invalid mode', $result);
    }

    public function test_blocks_path_traversal(): void
    {
        $result = $this->tool->execute([
            'path' => '../../etc/passwd',
            'diff' => '+hacked',
        ]);

        $this->assertStringContainsString('Error', $result);
    }

    public function test_direct_mode_appends_at_end_of_file(): void
    {
        file_put_contents($this->basePath . '/app/file.txt', "line1\nline2");

        $result = $this->tool->execute([
            'path' => 'app/file.txt',
            'diff' => "line1\nline2\n+line3",
            'mode' => 'direct',
        ]);

        $this->assertStringContainsString('OK', $result);
        $this->assertSame("line1\nline2\nline3", file_get_contents($this->basePath . '/app/file.txt'));
    }

    public function test_rejects_whitespace_only_context_mismatch(): void
    {
        file_put_contents($this->basePath . '/app/file.txt', "line1\nline2");

        $result = $this->tool->execute([
            'path' => 'app/file.txt',
            'diff' => "line1\n-line2 \n+changed",
        ]);

        $this->assertStringContainsString('Error applying diff', $result);
    }
}
