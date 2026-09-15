<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Resource;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Resource\AppResource;

final class AppResourceTest extends TestCase
{
    private string $basePath;
    private AppResource $resource;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-ar-' . uniqid();
        @mkdir($this->basePath . '/routes', 0777, true);
        @mkdir($this->basePath . '/app/Models', 0777, true);
        @mkdir($this->basePath . '/app/Controllers', 0777, true);
        $this->resource = new AppResource($this->basePath);
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

    public function test_uri_prefix(): void
    {
        $this->assertEquals('siro://app/', $this->resource->getUriPrefix());
    }

    public function test_lists_resources(): void
    {
        $resources = $this->resource->listResources();
        $this->assertArrayHasKey('siro://app/routes', $resources);
        $this->assertArrayHasKey('siro://app/models', $resources);
        $this->assertArrayHasKey('siro://app/controllers', $resources);
        $this->assertArrayHasKey('siro://app/services', $resources);
        $this->assertArrayHasKey('siro://app/middleware', $resources);
        $this->assertArrayHasKey('siro://app/structure', $resources);
        $this->assertArrayHasKey('siro://app/config', $resources);
        $this->assertArrayHasKey('siro://app/openapi', $resources);
    }

    public function test_read_unknown_uri_returns_null(): void
    {
        $this->assertNull($this->resource->readResource('siro://app/unknown'));
    }

    public function test_read_routes_returns_text(): void
    {
        file_put_contents($this->basePath . '/routes/api.php', <<<'PHP'
<?php
$router->get('/test', [TestController::class, 'index']);
PHP);

        $result = $this->resource->readResource('siro://app/routes');
        $this->assertNotNull($result);
        $this->assertArrayHasKey('text', $result);
        $this->assertStringContainsString('API Routes', $result['text']);
    }

    public function test_read_models_returns_text(): void
    {
        file_put_contents($this->basePath . '/app/Models/Test.php', <<<'PHP'
<?php
namespace App\Models;
use Siro\Core\Model;
final class Test extends Model
{
    protected string $table = 'tests';
    protected array $fillable = ['name'];
}
PHP);

        $result = $this->resource->readResource('siro://app/models');
        $this->assertNotNull($result);
        $this->assertStringContainsString('Test', $result['text']);
    }

    public function test_read_controllers_returns_text(): void
    {
        file_put_contents($this->basePath . '/app/Controllers/TestController.php', <<<'PHP'
<?php
namespace App\Controllers;
use Siro\Core\Controller;
final class TestController extends Controller
{
    public function index() {}
}
PHP);

        $result = $this->resource->readResource('siro://app/controllers');
        $this->assertNotNull($result);
        $this->assertStringContainsString('TestController', $result['text']);
    }

    public function test_read_structure_returns_text(): void
    {
        $result = $this->resource->readResource('siro://app/structure');
        $this->assertNotNull($result);
        $this->assertArrayHasKey('text', $result);
        $this->assertNotEmpty($result['text']);
    }

    public function test_read_openapi(): void
    {
        $result = $this->resource->readResource('siro://app/openapi');
        $this->assertNotNull($result);
        $this->assertStringContainsString('OpenAPI', $result['text']);
    }
}
