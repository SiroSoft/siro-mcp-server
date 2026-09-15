<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Context\ProjectAnalyzer;

final class ProjectAnalyzerTest extends TestCase
{
    private string $basePath;
    private ProjectAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-pa-' . uniqid();
        @mkdir($this->basePath . '/app/Models', 0777, true);
        @mkdir($this->basePath . '/app/Controllers', 0777, true);
        @mkdir($this->basePath . '/routes', 0777, true);
        file_put_contents($this->basePath . '/composer.json', '{"name": "test/project"}');
        $this->analyzer = new ProjectAnalyzer($this->basePath);
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

    public function test_get_project_info_from_composer(): void
    {
        $info = $this->analyzer->getProjectInfo();
        $this->assertEquals('test/project', $info['name']);
    }

    public function test_get_project_info_fallback(): void
    {
        $analyzer = new ProjectAnalyzer(sys_get_temp_dir() . '/nonexistent-' . uniqid());
        $info = $analyzer->getProjectInfo();
        $this->assertArrayHasKey('name', $info);
        $this->assertArrayHasKey('php_version', $info);
    }

    public function test_analyze_returns_summary(): void
    {
        $result = $this->analyzer->analyze('summary');
        $this->assertArrayHasKey('project', $result);
        $this->assertArrayHasKey('routes', $result);
        $this->assertArrayHasKey('models', $result);
        $this->assertArrayHasKey('controllers', $result);
        $this->assertArrayHasKey('services', $result);
        $this->assertArrayHasKey('middleware', $result);
        $this->assertArrayHasKey('architecture', $result);
    }

    public function test_analyze_returns_zero_counts_without_files(): void
    {
        $result = $this->analyzer->analyze();
        $this->assertEquals(0, $result['routes']['total']);
        $this->assertEquals(0, $result['models']['total']);
        $this->assertEquals(0, $result['controllers']['total']);
    }

    public function test_analyze_detects_routes(): void
    {
        file_put_contents($this->basePath . '/routes/api.php', <<<'PHP'
<?php

$router->get('/health', [HealthController::class, 'check']);
$router->resource('/products', ProductController::class);
PHP);

        $result = $this->analyzer->analyze();
        $this->assertEquals(2, $result['routes']['total']);
    }

    public function test_analyze_detects_models(): void
    {
        file_put_contents($this->basePath . '/app/Models/Product.php', <<<'PHP'
<?php

namespace App\Models;

use Siro\Core\Model;

final class Product extends Model
{
    protected string $table = 'products';
    protected array $fillable = ['name', 'price'];
    protected array $casts = ['price' => 'float'];
}
PHP);

        $result = $this->analyzer->analyze();
        $this->assertEquals(1, $result['models']['total']);
        $this->assertEquals('products', $result['models']['list'][0]['table']);
        $this->assertEquals(['name', 'price'], $result['models']['list'][0]['fillable']);
    }

    public function test_analyze_detects_controllers(): void
    {
        file_put_contents($this->basePath . '/app/Controllers/ProductController.php', <<<'PHP'
<?php

namespace App\Controllers;

use Siro\Core\Controller;

final class ProductController extends Controller
{
    public function index() {}
    public function show(string $id) {}
}
PHP);

        $result = $this->analyzer->analyze();
        $this->assertEquals(1, $result['controllers']['total']);
        $this->assertEquals('ProductController', $result['controllers']['list'][0]);
    }

    public function test_detects_auth_from_routes(): void
    {
        file_put_contents($this->basePath . '/routes/api.php', <<<'PHP'
<?php

$router->post('/auth/login', [AuthController::class, 'login']);
PHP);

        $result = $this->analyzer->analyze();
        $this->assertTrue($result['architecture']['has_auth']);
    }

    public function test_architecture_pattern_default(): void
    {
        $result = $this->analyzer->analyze();
        $this->assertStringContainsString('Controller → Model', $result['architecture']['pattern']);
    }
}
