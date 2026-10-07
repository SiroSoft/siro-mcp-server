<?php

declare(strict_types=1);

/**
 * Runnable MCP demo: HTTP 500 → Claude-style analysis → approved fix.
 *
 * This script plays BOTH sides of a real MCP session over stdio JSON-RPC:
 *   1. Builds a small broken Siro app (stale controller import).
 *   2. Writes the 500 request trace + error log, like `php siro api:test` would.
 *   3. Boots McpServer exactly like `php siro mcp:serve` (9 tools, 4 resources).
 *   4. Drives the session a Claude agent would run: initialize → analyze →
 *      read trace → verify hypothesis against installed core → approved patch.
 *   5. Verifies the fix with `php -l` and exits 0 only if everything checks out.
 *
 * Usage:
 *   php demo/error-analysis.php
 *
 * No Claude subscription needed — every MCP request/response is printed,
 * so the transcript doubles as the storyboard for a GIF/video recording
 * (see docs/DEMO.md).
 */

use SiroSoft\McpServer\McpServer;
use SiroSoft\McpServer\Resource\AppResource;
use SiroSoft\McpServer\Resource\AuditResource;
use SiroSoft\McpServer\Resource\DebugResource;
use SiroSoft\McpServer\Resource\DocsResource;
use SiroSoft\McpServer\Security\ApprovalPolicy;
use SiroSoft\McpServer\Security\AuditLogger;
use SiroSoft\McpServer\Tool\AnalyzeProject;
use SiroSoft\McpServer\Tool\ExecuteCli;
use SiroSoft\McpServer\Tool\PatchFile;
use SiroSoft\McpServer\Tool\ReadDocumentation;
use SiroSoft\McpServer\Tool\ScaffoldController;
use SiroSoft\McpServer\Tool\ScaffoldMigration;
use SiroSoft\McpServer\Tool\ScaffoldModel;
use SiroSoft\McpServer\Tool\ScaffoldResource;
use SiroSoft\McpServer\Tool\WriteFile;

require __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

$failures = 0;

/** @param mixed $data */
function out(string $role, string $text, int $truncate = 0): void
{
    if ($truncate > 0 && strlen($text) > $truncate) {
        $text = substr($text, 0, $truncate) . "\n… [truncated " . (strlen($text) - $truncate) . " chars]";
    }
    echo $role . ' ' . $text . "\n";
}

function check(bool $cond, string $label): void
{
    global $failures;
    if ($cond) {
        echo "  ✓ {$label}\n";
    } else {
        $failures++;
        echo "  ✗ FAILED: {$label}\n";
    }
}

// ---------------------------------------------------------------------------
// Phase 0 — the broken app (stale scaffold output: wrong Request namespace)
// ---------------------------------------------------------------------------

echo "=== Siro MCP demo: GET /api/products → 500 → root cause → fix ===\n\n";

$app = sys_get_temp_dir() . '/siro-mcp-demo-' . uniqid();
@mkdir($app . '/app/Controllers', 0777, true);
@mkdir($app . '/app/Models', 0777, true);
@mkdir($app . '/routes', 0777, true);
@mkdir($app . '/storage/framework/traces', 0777, true);
@mkdir($app . '/storage/logs', 0777, true);

$buggyController = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

use Siro\Core\Controller;
use Siro\Core\Http\Request;
use Siro\Core\Response;
use App\Models\Product;

final class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $result = Product::query()->orderBy('id', 'DESC')->paginate($request->queryInt('per_page', 20), $request->queryInt('page', 1));
        return Response::paginated($result['data'], $result['meta'], 'Product list');
    }
}
PHP;

file_put_contents($app . '/app/Controllers/ProductController.php', $buggyController);
file_put_contents(
    $app . '/app/Models/Product.php',
    "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\nuse Siro\\Core\\Model;\n\nfinal class Product extends Model\n{\n    protected string \$table = 'products';\n}\n"
);
file_put_contents($app . '/routes/api.php', "<?php\n\n// GET /api/products → ProductController@index\n");

// A real app vendors sirosoft/core — mirror the installed core docs so
// read_documentation works exactly like in production.
$coreDocs = __DIR__ . '/../vendor/sirosoft/core/docs';
if (is_dir($coreDocs)) {
    @mkdir($app . '/vendor/sirosoft/core/docs', 0777, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($coreDocs, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        $target = $app . '/vendor/sirosoft/core/docs/' . $it->getSubPathName();
        if ($file->isDir()) {
            @mkdir($target, 0777, true);
        } else {
            @copy($file->getPathname(), $target);
        }
    }
}

// The 500 trace, as written by `php siro api:test` on a failed request.
$trace = [
    'method' => 'GET',
    'path' => '/api/products',
    'status' => '500',
    'duration' => 0.143,
    'error' => [
        'message' => 'Class "Siro\\Core\\Http\\Request" not found',
        'file' => 'app/Controllers/ProductController.php',
        'line' => 8,
        'trace' => "#0 app/Controllers/ProductController.php(8): spl_autoload()\n#1 Router.php(210): dispatch()",
    ],
];
file_put_contents($app . '/storage/framework/traces/trace_demo500.json', json_encode($trace, JSON_PRETTY_PRINT));
file_put_contents($app . '/storage/logs/error.log', '[500] GET /api/products — Class "Siro\\Core\\Http\\Request" not found' . "\n");

out('[app]', "GET /api/products → 500  (broken app at {$app})");

// ---------------------------------------------------------------------------
// Phase 1 — boot the MCP server, exactly like `php siro mcp:serve`
// ---------------------------------------------------------------------------

putenv('SIRO_MCP_APPROVAL_TOKEN=demo-operator-token');
$approvalToken = 'demo-operator-token';

$auditLogger = new AuditLogger($app);
$server = new McpServer($auditLogger, new ApprovalPolicy());
$server->registerTool(new AnalyzeProject($app));
$server->registerTool(new ReadDocumentation($app));
$server->registerTool(new ExecuteCli($app));
$server->registerTool(new WriteFile($app));
$server->registerTool(new PatchFile($app));
$server->registerTool(new ScaffoldModel($app));
$server->registerTool(new ScaffoldController($app));
$server->registerTool(new ScaffoldMigration($app));
$server->registerTool(new ScaffoldResource($app));
$server->registerResource(new DocsResource($app));
$server->registerResource(new AppResource($app));
$server->registerResource(new DebugResource($app));
$server->registerResource(new AuditResource($auditLogger));

$handle = new ReflectionMethod(McpServer::class, 'handleRequest');

/** @param array<string, mixed> $request @return array<string, mixed> */
$call = static function (array $request) use ($server, $handle): array {
    $response = $handle->invoke($server, $request);
    if (!is_array($response)) {
        fwrite(STDERR, "No response for " . json_encode($request) . "\n");
        exit(1);
    }
    /** @var array<string, mixed> $response */
    return $response;
};

$id = 0;
$nextId = static function () use (&$id): int {
    return ++$id;
};

echo "\n--- Claude connects over stdio ---\n";

// 1. initialize
$res = $call(['jsonrpc' => '2.0', 'id' => $nextId(), 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']]);
$serverInfo = $res['result']['serverInfo'] ?? [];
out('→', 'initialize { protocolVersion: 2025-03-26 }');
out('←', 'server: ' . ($serverInfo['name'] ?? '?') . ' ' . ($serverInfo['version'] ?? '?'));
check(($serverInfo['name'] ?? '') === 'siro-mcp-server', 'server identity');

// 2. tools/list
$res = $call(['jsonrpc' => '2.0', 'id' => $nextId(), 'method' => 'tools/list']);
$tools = array_column($res['result']['tools'] ?? [], 'name');
out('→', 'tools/list');
out('←', count($tools) . ' tools: ' . implode(', ', $tools));
check(in_array('analyze_project', $tools, true) && in_array('patch_file', $tools, true), 'expected tools registered');

// 3. analyze_project
$res = $call(['jsonrpc' => '2.0', 'id' => $nextId(), 'method' => 'tools/call', 'params' => ['name' => 'analyze_project', 'arguments' => []]]);
$analysis = (string) ($res['result']['content'][0]['text'] ?? '');
out('→', 'tools/call analyze_project {}');
out('←', $analysis, 700);
check(str_contains($analysis, 'ProductController') || str_contains($analysis, 'Product'), 'analysis sees the app');

// 4. resources/list + read the failing trace
$res = $call(['jsonrpc' => '2.0', 'id' => $nextId(), 'method' => 'resources/list']);
$uris = array_column($res['result']['resources'] ?? [], 'uri');
out('→', 'resources/list');
out('←', implode(', ', $uris));
check(in_array('siro://debug/traces/latest', $uris, true), 'trace resource advertised');

$res = $call(['jsonrpc' => '2.0', 'id' => $nextId(), 'method' => 'resources/read', 'params' => ['uri' => 'siro://debug/traces/latest']]);
$traceText = (string) ($res['result']['contents'][0]['text'] ?? '');
out('→', 'resources/read siro://debug/traces/latest');
out('←', $traceText);

// ---------------------------------------------------------------------------
// Phase 2 — Claude reasons from REAL server output (nothing hardcoded)
// ---------------------------------------------------------------------------

echo "\n--- Claude's reasoning (computed from the trace above) ---\n";
preg_match('/^Message:\s*(.+)/m', $traceText, $m);
preg_match('/^File:\s*(.+)/m', $traceText, $f);
preg_match('/^Line:\s*(.+)/m', $traceText, $l);
$message = trim($m[1] ?? '');
$file = trim($f[1] ?? '');
$line = trim($l[1] ?? '');
echo "  error : {$message}\n  where : {$file}:{$line}\n";

preg_match('/"([^"]+)"/', $message, $cls);
$missingClass = $cls[1] ?? '';
$hypothesis = str_replace('Siro\\Core\\Http\\Request', 'Siro\\Core\\Request', $missingClass);
$oldMissing = $missingClass !== '' && !class_exists($missingClass);
$newExists = $hypothesis !== '' && class_exists($hypothesis);
echo "  hypothesis: {$missingClass} was moved → {$hypothesis}\n";
echo '  check: ' . ($oldMissing ? 'old class MISSING' : 'old class exists?!') . ' / ' . ($newExists ? 'new class EXISTS' : 'new class missing?!') . "\n";
check($oldMissing && $newExists, 'hypothesis verified against installed core');

// Confirm with framework docs over MCP (not memory).
$res = $call(['jsonrpc' => '2.0', 'id' => $nextId(), 'method' => 'tools/call', 'params' => ['name' => 'read_documentation', 'arguments' => ['topic' => 'model']]]);
$docs = (string) ($res['result']['content'][0]['text'] ?? '');
out('→', 'tools/call read_documentation { topic: model }');
out('←', $docs, 300);

// ---------------------------------------------------------------------------
// Phase 3 — approved surgical fix, then verify
// ---------------------------------------------------------------------------

echo "\n--- Claude applies the fix (approval-gated) ---\n";
$lines = explode("\n", $buggyController);
$diffLines = [];
foreach ($lines as $lineText) {
    if ($lineText === 'use Siro\\Core\\Http\\Request;') {
        $diffLines[] = '-use Siro\\Core\\Http\\Request;';
        $diffLines[] = '+use Siro\\Core\\Request;';
    } else {
        $diffLines[] = ' ' . $lineText;
    }
}
$res = $call([
    'jsonrpc' => '2.0',
    'id' => $nextId(),
    'method' => 'tools/call',
    'params' => [
        'name' => 'patch_file',
        'arguments' => [
            'path' => 'app/Controllers/ProductController.php',
            'diff' => implode("\n", $diffLines),
            'mode' => 'direct',
            'approval_token' => $approvalToken,
        ],
    ],
]);
$patchResult = (string) ($res['result']['content'][0]['text'] ?? '');
out('→', 'tools/call patch_file { path, diff, mode: direct, approval_token: *** }');
out('←', $patchResult);
check(str_starts_with($patchResult, 'OK:'), 'patch applied');

$fixed = (string) file_get_contents($app . '/app/Controllers/ProductController.php');
check(str_contains($fixed, 'use Siro\\Core\\Request;') && !str_contains($fixed, 'Http\\Request'), 'import fixed in file');

$output = [];
$exitCode = 1;
exec(PHP_BINARY . ' -l ' . escapeshellarg($app . '/app/Controllers/ProductController.php'), $output, $exitCode);
check($exitCode === 0, 'php -l clean: ' . implode(' ', $output));

// ---------------------------------------------------------------------------
// Finale
// ---------------------------------------------------------------------------

echo "\n=== DEMO " . ($failures === 0 ? 'OK' : "FAILED ({$failures} checks)") . ' === requests: ' . $id . ' | approvals: 1 | app kept at: ' . $app . " ===\n";
exit($failures === 0 ? 0 : 1);
