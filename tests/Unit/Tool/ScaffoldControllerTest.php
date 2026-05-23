<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Tool\ScaffoldController;

final class ScaffoldControllerTest extends TestCase
{
    private string $basePath;
    private ScaffoldController $scaffold;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-ctrl-' . uniqid();
        @mkdir($this->basePath . '/app/Controllers', 0777, true);
        $this->scaffold = new ScaffoldController($this->basePath);
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

    public function test_generates_controller_file(): void
    {
        $result = $this->scaffold->execute([
            'name' => 'Product',
            'model' => 'Product',
            'crud' => true,
        ]);

        $this->assertStringContainsString('app/Controllers/ProductController.php', $result);
        $this->assertFileExists($this->basePath . '/app/Controllers/ProductController.php');

        $content = file_get_contents($this->basePath . '/app/Controllers/ProductController.php');
        $this->assertStringContainsString('class ProductController extends Controller', $content ?: '');
        $this->assertStringContainsString('function index()', $content ?: '');
        $this->assertStringContainsString('function store', $content ?: '');
        $this->assertStringContainsString('function show', $content ?: '');
        $this->assertStringContainsString('function update', $content ?: '');
        $this->assertStringContainsString('function delete', $content ?: '');
    }

    public function test_generates_non_crud_controller(): void
    {
        $result = $this->scaffold->execute([
            'name' => 'Health',
            'crud' => false,
        ]);

        $this->assertStringContainsString('app/Controllers/HealthController.php', $result);
        $content = file_get_contents($this->basePath . '/app/Controllers/HealthController.php');
        $this->assertStringNotContainsString('function index', $content ?: '');
    }
}
