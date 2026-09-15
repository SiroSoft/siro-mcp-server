<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\Tool\AnalyzeProject;

final class AnalyzeProjectTest extends TestCase
{
    private string $basePath;
    private AnalyzeProject $tool;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-ap-' . uniqid();
        @mkdir($this->basePath . '/app/Models', 0777, true);
        @mkdir($this->basePath . '/app/Controllers', 0777, true);
        @mkdir($this->basePath . '/routes', 0777, true);
        file_put_contents($this->basePath . '/composer.json', '{"name": "test/project"}');
        file_put_contents($this->basePath . '/routes/api.php', "<?php\n\n\$router->get('/hello', [HelloController::class, 'index']);\n");
        $this->tool = new AnalyzeProject($this->basePath);
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

    public function test_get_name(): void
    {
        $this->assertEquals('analyze_project', $this->tool->getName());
    }

    public function test_returns_json_with_summary_depth(): void
    {
        $result = $this->tool->execute(['depth' => 'summary']);
        $this->assertJson($result);
        $data = json_decode($result, true);
        $this->assertArrayHasKey('project', $data);
        $this->assertArrayHasKey('routes', $data);
        $this->assertArrayHasKey('models', $data);
        $this->assertArrayHasKey('controllers', $data);
    }

    public function test_defaults_to_summary(): void
    {
        $result = $this->tool->execute([]);
        $this->assertJson($result);
    }

    public function test_returns_json_with_graph_depth(): void
    {
        $result = $this->tool->execute(['depth' => 'graph']);
        $this->assertJson($result);
    }

    public function test_falls_back_to_summary_for_invalid_depth(): void
    {
        $result = $this->tool->execute(['depth' => 'invalid']);
        $this->assertJson($result);
    }

    public function test_has_input_schema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('depth', $schema['properties']);
    }

    public function test_has_description(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }
}
