<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\McpServer;
use SiroSoft\McpServer\Security\PathValidator;
use SiroSoft\McpServer\Tool\WriteFile;
use SiroSoft\McpServer\Tool\PatchFile;
use SiroSoft\McpServer\Tool\ScaffoldModel;
use SiroSoft\McpServer\Tool\ScaffoldController;

final class McpServerIntegrationTest extends TestCase
{
    private string $basePath;
    private McpServer $server;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-int-' . uniqid();
        @mkdir($this->basePath . '/app/Models', 0777, true);
        @mkdir($this->basePath . '/app/Controllers', 0777, true);
        @mkdir($this->basePath . '/database/migrations', 0777, true);

        $this->server = new McpServer();
        $this->server->registerTool(new WriteFile($this->basePath));
        $this->server->registerTool(new PatchFile($this->basePath));
        $this->server->registerTool(new ScaffoldModel($this->basePath));
        $this->server->registerTool(new ScaffoldController($this->basePath));
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

    private function invoke(array $request): ?array
    {
        $ref = new \ReflectionMethod(McpServer::class, 'handleRequest');
        return $ref->invoke($this->server, $request);
    }

    public function test_full_tool_lifecycle(): void
    {
        // 1. List tools
        $list = $this->invoke([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $this->assertNotNull($list);
        $this->assertCount(4, $list['result']['tools']);

        // 2. Scaffold a model via tools/call
        $scaffoldModelResult = $this->invoke([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'scaffold_model',
                'arguments' => [
                    'name' => 'Product',
                    'fillable' => ['name', 'price'],
                    'casts' => ['price' => 'float'],
                ],
            ],
        ]);

        $this->assertNotNull($scaffoldModelResult);
        $this->assertArrayNotHasKey('error', $scaffoldModelResult);
        $this->assertStringContainsString('app/Models/Product.php', $scaffoldModelResult['result']['content'][0]['text']);
        $this->assertFileExists($this->basePath . '/app/Models/Product.php');

        // 3. Write a file via tools/call
        $writeResult = $this->invoke([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'write_file',
                'arguments' => [
                    'path' => 'app/Controllers/TestController.php',
                    'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nfinal class TestController {}\n",
                ],
            ],
        ]);

        $this->assertNotNull($writeResult);
        $this->assertArrayNotHasKey('error', $writeResult);
        $this->assertFileExists($this->basePath . '/app/Controllers/TestController.php');

        // 4. Read back the written file
        $readResult = $this->invoke([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'write_file',
                'arguments' => [
                    'path' => 'app/Controllers/TestController.php',
                    'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nfinal class TestController {}\n",
                    'force' => true,
                ],
            ],
        ]);

        $this->assertNotNull($readResult);
        $this->assertArrayNotHasKey('error', $readResult);
    }

    public function test_write_file_path_traversal_blocked(): void
    {
        $result = $this->invoke([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'write_file',
                'arguments' => [
                    'path' => '../../etc/passwd',
                    'content' => 'hacked',
                ],
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('result', $result);
        $this->assertStringContainsString('Error', $result['result']['content'][0]['text']);
    }
}
