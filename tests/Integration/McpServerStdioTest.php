<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Transport-level tests: real MCP server process over stdio pipes.
 *
 * Unlike the reflection-based tests, this suite verifies exact wire behavior:
 * newline-delimited JSON framing, error recovery (server survives bad input),
 * notification silence, and the approval gate end to end.
 */
final class McpServerStdioTest extends TestCase
{
    private string $basePath;

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    private const APPROVAL_TOKEN = 'test-token-123';

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mcp-stdio-' . uniqid();
        @mkdir($this->basePath . '/app/Models', 0777, true);
        @mkdir($this->basePath . '/storage/framework/traces', 0777, true);
        @mkdir($this->basePath . '/vendor/sirosoft/core/docs', 0777, true);

        file_put_contents(
            $this->basePath . '/app/Models/Product.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\nuse Siro\\Core\\Model;\n\nfinal class Product extends Model {}\n"
        );
        file_put_contents(
            $this->basePath . '/storage/framework/traces/trace_1.json',
            json_encode([
                'method' => 'GET', 'path' => '/api/products', 'status' => '500', 'duration' => 0.05,
                'error' => ['message' => 'boom', 'file' => 'app/Models/Product.php', 'line' => 9],
            ])
        );
        file_put_contents($this->basePath . '/vendor/sirosoft/core/docs/DATABASE.md', "# Database\n\nContent here.\n");

        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = array_merge(getenv(), ['SIRO_MCP_APPROVAL_TOKEN' => self::APPROVAL_TOKEN]);
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../Fixtures/stdio-server.php', $this->basePath],
            $descriptorSpec,
            $pipes,
            null,
            $env
        );
        $this->assertIsResource($process, 'failed to spawn stdio server');
        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        // NOTE: intentionally left in blocking mode. Non-blocking reads and
        // stream_select() do not work on Windows pipes; instead we rely on
        // blocking fgets() (one line per response) plus a ping-after pattern
        // for silence detection (see test_stdio_protocol_robustness).
    }

    protected function tearDown(): void
    {
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
        }
        if (is_resource($this->process)) {
            // Closing stdin sends EOF; the server loop must exit on its own.
            $exit = proc_close($this->process);
            $this->assertSame(0, $exit, 'stdio server did not exit cleanly on EOF');
        }
        $this->rmdir($this->basePath);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @param array<string, mixed> $request */
    private function send(array $request): void
    {
        $line = json_encode($request, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($line);
        fwrite($this->stdin, $line . "\n");
    }

    private function sendRaw(string $line): void
    {
        fwrite($this->stdin, $line . "\n");
    }

    /**
     * Read exactly one newline-delimited JSON response (blocking).
     * Every request bearing an id gets exactly one response line, so a
     * blocking fgets() always terminates — promptly on data, or at EOF if
     * the server died (which fails the test immediately instead of hanging
     * on a timeout).
     *
     * @return array<string, mixed>
     */
    private function readResponse(): array
    {
        $line = fgets($this->stdout);
        if ($line === false) {
            $this->fail('server closed STDOUT unexpectedly (crashed?)');
        }
        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded, 'server STDOUT line is not valid JSON: ' . substr($line, 0, 200));
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function test_stdio_handshake_and_discovery(): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']]);
        $init = $this->readResponse();
        $this->assertNotNull($init);
        $this->assertSame(1, $init['id']);
        $this->assertSame('0.4.2', $init['result']['serverInfo']['version']);

        $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']);
        $ping = $this->readResponse();
        $this->assertNotNull($ping);
        $this->assertSame(2, $ping['id']);
        $this->assertSame([], $ping['result']);

        $this->send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);
        $tools = $this->readResponse();
        $this->assertNotNull($tools);
        $this->assertCount(9, $tools['result']['tools']);

        $this->send(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/list']);
        $resources = $this->readResponse();
        $this->assertNotNull($resources);
        $uris = array_column($resources['result']['resources'], 'uri');
        $this->assertContains('siro://debug/traces/latest', $uris);
    }

    public function test_stdio_error_trace_flow(): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'analyze_project', 'arguments' => []]]);
        $analysis = $this->readResponse();
        $this->assertNotNull($analysis);
        $this->assertStringContainsString('Product', $analysis['result']['content'][0]['text']);
        $this->assertFalse($analysis['result']['isError']);

        $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'siro://debug/traces/latest']]);
        $trace = $this->readResponse();
        $this->assertNotNull($trace);
        $this->assertStringContainsString('/api/products', $trace['result']['contents'][0]['text']);
        $this->assertStringContainsString('boom', $trace['result']['contents'][0]['text']);

        $this->send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'read_documentation', 'arguments' => ['topic' => 'database']]]);
        $docs = $this->readResponse();
        $this->assertNotNull($docs);
        $this->assertStringContainsString('Content here', $docs['result']['content'][0]['text']);
    }

    public function test_stdio_protocol_robustness(): void
    {
        // 1. Garbage line → parse error, server MUST stay alive.
        $this->sendRaw('this is not json{{{');
        $parseError = $this->readResponse();
        $this->assertSame(-32700, $parseError['error']['code']);

        // 2. Server still answers after the garbage.
        $this->send(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'ping']);
        $ping = $this->readResponse();
        $this->assertSame(10, $ping['id']);

        // 3. Notification (no id) → absolute silence. Proved by sending a
        // ping right after: the ONLY line back must be the ping response.
        // Any stray output from the notification would arrive first instead.
        $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->send(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'ping']);
        $afterNotification = $this->readResponse();
        $this->assertSame(99, $afterNotification['id'], 'notification produced output on STDOUT');

        // 4. Unknown method / tool / resource → typed errors, still alive.
        $this->send(['jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/fly']);
        $unknownMethod = $this->readResponse();
        $this->assertSame(-32601, $unknownMethod['error']['code']);

        $this->send(['jsonrpc' => '2.0', 'id' => 12, 'method' => 'tools/call', 'params' => ['name' => 'nope', 'arguments' => []]]);
        $unknownTool = $this->readResponse();
        $this->assertStringContainsString('Unknown tool', $unknownTool['result']['content'][0]['text'] ?? $unknownTool['error']['message'] ?? '');

        $this->send(['jsonrpc' => '2.0', 'id' => 13, 'method' => 'resources/read', 'params' => ['uri' => 'siro://nope/x']]);
        $unknownResource = $this->readResponse();
        $this->assertSame(-32602, $unknownResource['error']['code']);

        $this->send(['jsonrpc' => '2.0', 'id' => 14, 'method' => 'ping']);
        $alive = $this->readResponse();
        $this->assertSame(14, $alive['id']);
    }

    public function test_stdio_approval_gate_end_to_end(): void
    {
        file_put_contents($this->basePath . '/app/Models/Note.php', "<?php\n// v1\n");

        // 1. Mutation without token → fail closed, file untouched.
        $this->send([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'patch_file', 'arguments' => [
                'path' => 'app/Models/Note.php', 'diff' => " <?php\n-// v1\n+// v2\n", 'mode' => 'direct',
            ]],
        ]);
        $denied = $this->readResponse();
        $this->assertNotNull($denied);
        $this->assertTrue($denied['result']['isError']);
        $this->assertStringContainsString('Approval required', $denied['result']['content'][0]['text']);
        $this->assertStringContainsString('v1', (string) file_get_contents($this->basePath . '/app/Models/Note.php'));

        // 2. Same call with operator token → applied.
        $this->send([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'patch_file', 'arguments' => [
                'path' => 'app/Models/Note.php', 'diff' => " <?php\n-// v1\n+// v2\n", 'mode' => 'direct',
                'approval_token' => self::APPROVAL_TOKEN,
            ]],
        ]);
        $applied = $this->readResponse();
        $this->assertNotNull($applied);
        $this->assertFalse($applied['result']['isError']);
        $this->assertStringContainsString('v2', (string) file_get_contents($this->basePath . '/app/Models/Note.php'));

        $output = [];
        $exitCode = 1;
        exec(PHP_BINARY . ' -l ' . escapeshellarg($this->basePath . '/app/Models/Note.php'), $output, $exitCode);
        $this->assertSame(0, $exitCode);
    }
}
