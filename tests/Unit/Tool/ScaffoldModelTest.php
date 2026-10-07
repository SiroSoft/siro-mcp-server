<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Tool\ScaffoldModel;

final class ScaffoldModelTest extends TestCase
{
    private string $basePath;
    private ScaffoldModel $scaffold;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-sm-' . uniqid();
        @mkdir($this->basePath . '/app/Models', 0777, true);
        $this->scaffold = new ScaffoldModel($this->basePath);
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

    public function test_generates_basic_model(): void
    {
        $result = $this->scaffold->execute(['name' => 'Product']);

        $this->assertStringContainsString('app/Models/Product.php', $result);
        $this->assertFileExists($this->basePath . '/app/Models/Product.php');

        $content = file_get_contents($this->basePath . '/app/Models/Product.php');
        $this->assertStringContainsString('class Product extends Model', $content ?: '');
        $this->assertStringContainsString("table = 'products'", $content ?: '');
    }

    public function test_generates_model_with_fillable_and_casts(): void
    {
        $this->scaffold->execute([
            'name' => 'User',
            'fillable' => ['name', 'email'],
            'casts' => ['email' => 'string', 'is_active' => 'bool'],
        ]);

        $content = file_get_contents($this->basePath . '/app/Models/User.php');
        $this->assertStringContainsString("'name'", $content ?: '');
        $this->assertStringContainsString("'email'", $content ?: '');
        $this->assertStringContainsString("'is_active' => 'bool'", $content ?: '');
    }

    public function test_generates_model_with_relations(): void
    {
        $this->scaffold->execute([
            'name' => 'Category',
            'relations' => [
                ['type' => 'hasMany', 'target' => 'Product'],
            ],
        ]);

        $content = file_get_contents($this->basePath . '/app/Models/Category.php');
        $this->assertStringContainsString('function product()', $content ?: '');
        $this->assertStringContainsString('$this->hasMany(Product::class)', $content ?: '');
        $this->assertStringContainsString('Siro\\Core\\DB\\Relations\\HasMany', $content ?: '');
        $this->assertStringNotContainsString('ModelRelations', $content ?: '');
        // Relation method must be inside the class body, not after the closing brace.
        $posMethod = strpos($content ?: '', 'function product()');
        $posLastBrace = strrpos($content ?: '', '}');
        $this->assertNotFalse($posMethod);
        $this->assertNotFalse($posLastBrace);
        $this->assertLessThan($posLastBrace, $posMethod);
    }

    public function test_generates_model_with_soft_deletes(): void
    {
        $this->scaffold->execute([
            'name' => 'Post',
            'soft_deletes' => true,
        ]);

        $content = file_get_contents($this->basePath . '/app/Models/Post.php');
        $this->assertStringContainsString('use SoftDeletes', $content ?: '');
        $this->assertStringContainsString('use Siro\\Core\\DB\\SoftDeletes;', $content ?: '');
        $this->assertStringNotContainsString('Model\\SoftDeletes', $content ?: '');
    }

    public function test_requires_name(): void
    {
        $result = $this->scaffold->execute([]);
        $this->assertStringContainsString('Error', $result);
    }

    public function test_studly_cases_name(): void
    {
        $this->scaffold->execute(['name' => 'order_item']);

        $content = file_get_contents($this->basePath . '/app/Models/OrderItem.php');
        $this->assertStringContainsString('class OrderItem', $content ?: '');
    }
}
