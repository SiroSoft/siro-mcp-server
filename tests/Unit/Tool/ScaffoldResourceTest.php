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
        $this->assertStringContainsString('app/Repositories/ProductRepository.php', $result);
        $this->assertStringContainsString('app/Services/ProductService.php', $result);
        $this->assertStringContainsString('tests/Feature/ProductTest.php', $result);
        $this->assertStringContainsString('routes/api.php', $result);

        $this->assertFileExists($this->basePath . '/app/Models/Product.php');
        $this->assertFileExists($this->basePath . '/app/Controllers/ProductController.php');
        $this->assertFileExists($this->basePath . '/app/Repositories/ProductRepository.php');
        $this->assertFileExists($this->basePath . '/app/Services/ProductService.php');
        $this->assertFileExists($this->basePath . '/app/Resources/ProductResource.php');
        $this->assertFileExists($this->basePath . '/tests/Feature/ProductTest.php');

        $migrations = glob($this->basePath . '/database/migrations/*.php');
        $this->assertNotEmpty($migrations);

        $modelContent = file_get_contents($this->basePath . '/app/Models/Product.php');
        $this->assertStringContainsString("table = 'products'", $modelContent ?: '');
        $this->assertStringContainsString("'title'", $modelContent ?: '');
        $this->assertStringContainsString("'price' => 'float'", $modelContent ?: '');

        $serviceContent = file_get_contents($this->basePath . '/app/Services/ProductService.php');
        $this->assertStringContainsString('ProductRepository', $serviceContent ?: '');
        $this->assertStringContainsString('function getAll(', $serviceContent ?: '');
        $this->assertStringContainsString('function getById(', $serviceContent ?: '');
        $this->assertStringContainsString('->findAll(', $serviceContent ?: '');
        $this->assertStringContainsString('->findById(', $serviceContent ?: '');

        $controllerContent = file_get_contents($this->basePath . '/app/Controllers/ProductController.php');
        $this->assertStringContainsString('use Siro\\Core\\Request;', $controllerContent ?: '');
        $this->assertStringContainsString('use Siro\\Core\\Response;', $controllerContent ?: '');
        $this->assertStringContainsString("Response::paginated(ProductResource::collection(\$result['data'])", $controllerContent ?: '');
        $this->assertStringContainsString('->getAll(', $controllerContent ?: '');
        $this->assertStringContainsString('->getById(', $controllerContent ?: '');
        $this->assertStringNotContainsString('response()->', $controllerContent ?: '');
        $this->assertStringNotContainsString('->toJson()', $controllerContent ?: '');

        $resourceContent = file_get_contents($this->basePath . '/app/Resources/ProductResource.php');
        $this->assertStringContainsString('function toArray(): array', $resourceContent ?: '');
        $this->assertStringContainsString("\$this->data['title']", $resourceContent ?: '');
        $this->assertStringNotContainsString('object $model', $resourceContent ?: '');
        $this->assertStringNotContainsString('$this->title', $resourceContent ?: '');

        $testContent = file_get_contents($this->basePath . '/tests/Feature/ProductTest.php');
        $this->assertStringContainsString('testUpdateReturns404ForUnknownId', $testContent ?: '');

        foreach ([
            $this->basePath . '/app/Models/Product.php',
            $this->basePath . '/app/Repositories/ProductRepository.php',
            $this->basePath . '/app/Services/ProductService.php',
            $this->basePath . '/app/Controllers/ProductController.php',
            $this->basePath . '/app/Resources/ProductResource.php',
            $this->basePath . '/tests/Feature/ProductTest.php',
        ] as $file) {
            $output = [];
            $exitCode = 1;
            exec(PHP_BINARY . ' -l ' . escapeshellarg($file), $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
        }
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

    public function test_rejects_unsafe_schema_input(): void
    {
        $result = $this->scaffold->execute([
            'name' => 'Product',
            'columns' => [['name' => 'name', 'type' => 'string']],
            'route_prefix' => '../products',
        ]);

        $this->assertStringContainsString('safe route path', $result);
        $this->assertFileDoesNotExist($this->basePath . '/app/Models/Product.php');
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
        $this->assertStringContainsString('use Siro\\Core\\DB\\SoftDeletes;', $modelContent ?: '');
        $this->assertStringNotContainsString('Model\\SoftDeletes', $modelContent ?: '');
    }
}
