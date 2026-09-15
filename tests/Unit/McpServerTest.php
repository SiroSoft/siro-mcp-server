<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiroSoft\McpServer\McpServer;
use SiroSoft\McpServer\Resource\AuditResource;
use SiroSoft\McpServer\Security\ApprovalPolicy;
use SiroSoft\McpServer\Security\AuditLogger;

final class McpServerTest extends TestCase
{
    public function test_responds_to_ping(): void
    {
        $result = $this->invokeRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'ping',
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('result', $result);
        $this->assertEquals(1, $result['id']);
    }

    public function test_responds_to_initialize(): void
    {
        $result = $this->invokeRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26'],
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('result', $result);
        $this->assertEquals('2025-03-26', $result['result']['protocolVersion']);
        $this->assertEquals('siro-mcp-server', $result['result']['serverInfo']['name']);
        $this->assertEquals('0.3.0', $result['result']['serverInfo']['version']);
        $this->assertArrayHasKey('tools', $result['result']['capabilities']);
        $this->assertArrayHasKey('resources', $result['result']['capabilities']);
    }

    public function test_returns_null_for_notification(): void
    {
        $result = $this->invokeRequest([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        $this->assertNull($result);
    }

    public function test_rejects_unsupported_protocol_version(): void
    {
        $result = $this->invokeRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '0.2.0'],
        ]);

        $this->assertNotNull($result);
        $this->assertEquals(-32602, $result['error']['code']);
    }

    public function test_rejects_invalid_json_rpc_version(): void
    {
        $result = $this->invokeRequest([
            'jsonrpc' => '1.0',
            'id' => 1,
            'method' => 'ping',
        ]);

        $this->assertNotNull($result);
        $this->assertEquals(-32600, $result['error']['code']);
    }

    public function test_lists_tools_empty_when_none_registered(): void
    {
        $result = $this->invokeRequest([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]);

        $this->assertNotNull($result);
        $this->assertEquals([], $result['result']['tools']);
    }

    public function test_calls_registered_tool(): void
    {
        $server = new McpServer();
        $tool = new class implements \SiroSoft\McpServer\Tool\ToolInterface {
            public function getName(): string { return 'test_tool'; }
            public function getDescription(): string { return 'A test tool'; }
            public function getInputSchema(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $arguments): string { return 'executed:' . ($arguments['val'] ?? 'none'); }
        };
        $server->registerTool($tool);

        $result = $this->invokeRequestOn($server, [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'test_tool', 'arguments' => ['val' => 'hello']],
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('result', $result);
        $this->assertEquals('executed:hello', $result['result']['content'][0]['text']);
    }

    public function test_returns_error_for_unknown_method(): void
    {
        $result = $this->invokeRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'unknown_method',
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(-32601, $result['error']['code']);
    }

    public function test_returns_error_for_unknown_tool(): void
    {
        $result = $this->invokeRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'nonexistent'],
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(-32602, $result['error']['code']);
    }

    public function test_lists_resources(): void
    {
        $server = new McpServer();
        $resource = new class implements \SiroSoft\McpServer\Resource\ResourceInterface {
            public function getUriPrefix(): string { return 'test://'; }
            public function listResources(): array { return ['test://hello' => 'Hello Resource']; }
            public function readResource(string $uri): ?array { return ['text' => 'Hello World']; }
        };
        $server->registerResource($resource);

        $result = $this->invokeRequestOn($server, [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'resources/list',
        ]);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['result']['resources']);
        $this->assertEquals('test://hello', $result['result']['resources'][0]['uri']);
    }

    public function test_denies_mutation_without_operator_approval(): void
    {
        putenv('SIRO_MCP_APPROVAL_TOKEN=test-token');
        $logger = new AuditLogger(sys_get_temp_dir() . '/mcp-server-' . uniqid());
        $server = new McpServer($logger, new ApprovalPolicy());
        $executed = false;
        $server->registerTool(new class($executed) implements \SiroSoft\McpServer\Tool\ToolInterface {
            public function __construct(private bool &$executed) {}
            public function getName(): string { return 'write_file'; }
            public function getDescription(): string { return 'test mutation'; }
            public function getInputSchema(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $arguments): string { $this->executed = true; return 'changed'; }
        });

        $result = $this->invokeRequestOn($server, [
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => ['name' => 'write_file', 'arguments' => []],
        ]);

        putenv('SIRO_MCP_APPROVAL_TOKEN');
        $this->assertNotNull($result);
        $this->assertTrue($result['result']['isError']);
        $this->assertStringContainsString('Approval required', $result['result']['content'][0]['text']);
        $this->assertFalse($executed);
        $this->assertNotEmpty($result['result']['_meta']['runId']);
    }

    public function test_approved_mutation_is_audited(): void
    {
        putenv('SIRO_MCP_APPROVAL_TOKEN=test-token');
        $basePath = sys_get_temp_dir() . '/mcp-server-' . uniqid();
        $logger = new AuditLogger($basePath);
        $server = new McpServer($logger, new ApprovalPolicy());
        $server->registerTool(new class implements \SiroSoft\McpServer\Tool\ToolInterface {
            public function getName(): string { return 'write_file'; }
            public function getDescription(): string { return 'test mutation'; }
            public function getInputSchema(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $arguments): string { return 'password=should-not-be-stored'; }
        });
        $server->registerResource(new AuditResource($logger));

        $result = $this->invokeRequestOn($server, [
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'tools/call',
            'params' => ['name' => 'write_file', 'arguments' => ['approval_token' => 'test-token', 'password' => 'secret']],
        ]);

        putenv('SIRO_MCP_APPROVAL_TOKEN');
        $runs = $logger->recent();
        $this->assertNotNull($result);
        $this->assertFalse($result['result']['isError']);
        $this->assertCount(1, $runs);
        $this->assertStringNotContainsString('secret', json_encode($runs[0]) ?: '');
        $this->assertStringNotContainsString('should-not-be-stored', json_encode($runs[0]) ?: '');

        $audit = $this->invokeRequestOn($server, [
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'resources/read',
            'params' => ['uri' => 'siro://mcp/runs/latest'],
        ]);
        $this->assertNotNull($audit);
        $this->assertStringContainsString('completed', $audit['result']['contents'][0]['text']);
    }

    /**
     * Create a fresh server, invoke a request, return response.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function invokeRequest(array $request): ?array
    {
        return $this->invokeRequestOn(new McpServer(), $request);
    }

    /**
     * Invoke a request on an existing server instance.
     *
     * @param McpServer $server
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function invokeRequestOn(McpServer $server, array $request): ?array
    {
        // Use reflection to access the private handleRequest method
        $ref = new \ReflectionMethod(McpServer::class, 'handleRequest');
        $ref->setAccessible(true);
        return $ref->invoke($server, $request);
    }
}
