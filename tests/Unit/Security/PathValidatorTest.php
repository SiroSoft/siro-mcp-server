<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Security\PathValidator;

final class PathValidatorTest extends TestCase
{
    private string $projectRoot;
    private PathValidator $validator;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/mcp-pv-' . uniqid();
        @mkdir($this->projectRoot . '/app/Models', 0777, true);
        file_put_contents($this->projectRoot . '/app/Models/User.php', '<?php');
        $this->validator = new PathValidator($this->projectRoot);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->projectRoot);
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

    public function test_resolves_valid_path(): void
    {
        $resolved = $this->validator->resolve('app/Models/User.php');
        $this->assertStringContainsString('User.php', $resolved);
        $this->assertStringContainsString(basename($this->projectRoot), $resolved);
    }

    public function test_throws_on_dot_dot_traversal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Path traversal');
        $this->validator->resolve('../../etc/passwd');
    }

    public function test_throws_on_dot_dot_in_middle(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->resolve('app/../../etc/passwd');
    }

    public function test_returns_true_for_is_within_project(): void
    {
        $this->assertTrue($this->validator->isWithinProject('app/Models/User.php'));
    }

    public function test_returns_false_for_is_within_project_on_traversal(): void
    {
        $this->assertFalse($this->validator->isWithinProject('../../etc/passwd'));
    }

    public function test_resolves_nonexistent_path_within_project(): void
    {
        $resolved = $this->validator->resolve('app/newfile.txt');
        $this->assertStringContainsString('newfile.txt', $resolved);
    }

    public function test_throws_when_nonexistent_parent_outside_project(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->resolve('../../newdir/newfile.txt');
    }

    public function test_handles_backslash_separators(): void
    {
        $resolved = $this->validator->resolve('app\\Models\\User.php');
        $this->assertStringContainsString('User.php', $resolved);
    }

    public function test_handles_leading_slash(): void
    {
        $resolved = $this->validator->resolve('/app/Models/User.php');
        $this->assertStringContainsString('User.php', $resolved);
    }

    public function test_rejects_sibling_path_with_matching_prefix(): void
    {
        $sibling = $this->projectRoot . '-other';
        @mkdir($sibling, 0777, true);
        file_put_contents($sibling . '/secret.txt', 'secret');

        try {
            $this->expectException(\RuntimeException::class);
            $this->validator->resolve($sibling . '/secret.txt');
        } finally {
            @unlink($sibling . '/secret.txt');
            @rmdir($sibling);
        }
    }
}
