<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Context\RouteGraph;

final class RouteGraphTest extends TestCase
{
    private string $basePath;
    private RouteGraph $graph;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-rg-' . uniqid();
        @mkdir($this->basePath . '/routes', 0777, true);
        $this->graph = new RouteGraph($this->basePath);
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

    public function test_returns_empty_when_no_route_file(): void
    {
        $this->assertEquals([], $this->graph->parse());
    }

    public function test_parses_resource_routes(): void
    {
        file_put_contents($this->basePath . '/routes/api.php', <<<'PHP'
<?php

$router->resource('products', ProductController::class);
PHP);

        $routes = $this->graph->parse();
        $this->assertCount(5, $routes);
        $methods = array_column($routes, 'method');
        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
    }

    public function test_parses_explicit_routes(): void
    {
        file_put_contents($this->basePath . '/routes/api.php', <<<'PHP'
<?php

$router->get('/health', 'HealthController@check');
PHP);

        $routes = $this->graph->parse();
        $this->assertCount(1, $routes);
        $this->assertEquals('GET', $routes[0]['method']);
        $this->assertEquals('/health', $routes[0]['path']);
    }

    public function test_parses_group_prefix(): void
    {
        file_put_contents($this->basePath . '/routes/api.php', <<<'PHP'
<?php

$router->group('/api/v1', function() {
    $router->resource('items', ItemController::class);
});
PHP);

        $routes = $this->graph->parse();
        $this->assertNotEmpty($routes);
        $this->assertStringContainsString('/api/v1', $routes[0]['path']);
    }

    public function test_getRoutes_calls_parse(): void
    {
        file_put_contents($this->basePath . '/routes/api.php', <<<'PHP'
<?php

$router->get('/test', fn() => 'ok');
PHP);

        $routes = $this->graph->getRoutes();
        $this->assertCount(1, $routes);
    }

    public function test_handles_closure_handlers(): void
    {
        file_put_contents($this->basePath . '/routes/api.php', <<<'PHP'
<?php

$router->get('/hello', function() { return 'Hello'; });
PHP);

        $routes = $this->graph->parse();
        $this->assertEquals('Closure', $routes[0]['handler']);
    }
}
