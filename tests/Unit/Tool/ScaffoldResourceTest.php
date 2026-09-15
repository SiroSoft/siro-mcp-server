<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Tool\ScaffoldResource;

final class ScaffoldResourceTest extends TestCase
{
    private string $basePath;
    private ScaffoldResource $scaffold;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-sr-' . uniqid();
        @mkdir($this->basePath . '/app/Models', 0777, true);
        @mkdir($this->basePath . '/app/Controllers', 0777, true);
        @mkdir($this->basePath . '/database/migrations', 0777, true);
        @mkdir($this->basePath . '/routes', 0777, true);
        file_put_contents($this->basePath . '/routes/api.php', "<?php\n\n");
        $this->scaffold = new ScaffoldResource($this->basePath);
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

    public function test_generates_full_crud_scaffolding(): void
    {
        $result = $this->scaffold->execute([
            'name' => 'Product',
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'price', 'type' => 'float'],
            ],
        ]);

        $this->assertStringContainsString('app/Models/Product.php', $result);
        $this->assertStringContainsString('database/migrations/', $result);
        $this->assertStringContainsString('app/Controllers/ProductController.php', $result);
        $this->assertStringContainsString('routes/api.php', $result);

        $this->assertFileExists($this->basePath . '/app/Models/Product.php');
        $this->assertFileExists($this->basePath . '/app/Controllers/ProductController.php');

        $migrations = glob($this->basePath . '/database/migrations/*.php');
        $this->assertNotEmpty($migrations);

        $modelContent = file_get_contents($this->basePath . '/app/Models/Product.php');
        $this->assertStringContainsString("table = 'products'", $modelContent ?: '');
        $this->assertStringContainsString("'title'", $modelContent ?: '');
        $this->assertStringContainsString("'price' => 'float'", $modelContent ?: '');
    }

    public function test_generates_resource_transformer(): void
    {
        @mkdir($this->basePath . '/app/Resources', 0777, true);

        $result = $this->scaffold->execute([
            'name' => 'Post',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'resource' => true,
        ]);

        $this->assertStringContainsString('app/Resources/PostResource.php', $result);
        $this->assertFileExists($this->basePath . '/app/Resources/PostResource.php');
    }

    public function test_requires_name(): void
    {
        $result = $this->scaffold->execute([]);
        $this->assertStringContainsString('Error', $result);
    }

    public function test_generates_with_soft_deletes(): void
    {
        $this->scaffold->execute([
            'name' => 'Post',
            'columns' => [['name' => 'title', 'type' => 'string']],
            'soft_deletes' => true,
        ]);

        $modelContent = file_get_contents($this->basePath . '/app/Models/Post.php');
        $this->assertStringContainsString('SoftDeletes', $modelContent ?: '');
    }
}
