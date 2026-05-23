<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Tool\ScaffoldMigration;

final class ScaffoldMigrationTest extends TestCase
{
    private string $basePath;
    private ScaffoldMigration $scaffold;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-mig-' . uniqid();
        @mkdir($this->basePath . '/database/migrations', 0777, true);
        $this->scaffold = new ScaffoldMigration($this->basePath);
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

    public function test_generates_create_migration(): void
    {
        $result = $this->scaffold->execute([
            'name' => 'create_users_table',
            'table' => 'users',
            'action' => 'create',
            'columns' => [
                ['name' => 'email', 'type' => 'string', 'unique' => true],
                ['name' => 'age', 'type' => 'integer', 'nullable' => true],
            ],
            'timestamps' => true,
        ]);

        $this->assertStringContainsString('database/migrations/', $result);
        $this->assertStringContainsString('OK', $result);

        // Find the generated file
        $files = glob($this->basePath . '/database/migrations/*.php');
        $this->assertNotEmpty($files);

        $content = file_get_contents($files[0]);
        $this->assertStringContainsString("Schema::create('users'", $content ?: '');
        $this->assertStringContainsString("string('email')", $content ?: '');
        $this->assertStringContainsString("->unique()", $content ?: '');
        $this->assertStringContainsString("integer('age')", $content ?: '');
        $this->assertStringContainsString("->nullable()", $content ?: '');
        $this->assertStringContainsString("timestamps()", $content ?: '');
    }

    public function test_generates_alter_migration(): void
    {
        $result = $this->scaffold->execute([
            'name' => 'add_phone_to_users',
            'table' => 'users',
            'action' => 'alter',
            'columns' => [
                ['name' => 'phone', 'type' => 'string', 'nullable' => true],
            ],
        ]);

        $this->assertStringContainsString('OK', $result);

        $files = glob($this->basePath . '/database/migrations/*.php');
        $this->assertNotEmpty($files);

        $content = file_get_contents($files[0]);
        $this->assertStringContainsString("Schema::table('users'", $content ?: '');
    }

    public function test_generates_drop_migration(): void
    {
        $result = $this->scaffold->execute([
            'name' => 'drop_users_table',
            'table' => 'users',
            'action' => 'drop',
        ]);

        $this->assertStringContainsString('OK', $result);

        $files = glob($this->basePath . '/database/migrations/*.php');
        $this->assertNotEmpty($files);

        $content = file_get_contents($files[0]);
        $this->assertStringContainsString("Schema::dropIfExists('users')", $content ?: '');
    }
}
