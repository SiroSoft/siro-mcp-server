<?php

declare(strict_types=1);

/**
 * Minimal stdio MCP server for transport-level tests.
 *
 * Boots the same 9 tools + 4 resources as McpServeCommand::run() but keeps
 * STDOUT as pure newline-delimited JSON (no banner), so tests can assert
 * exact protocol framing. Base path comes from argv[1].
 *
 * Usage: php tests/Fixtures/stdio-server.php <basePath>
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

require __DIR__ . '/../../vendor/autoload.php';

$basePath = $argv[1] ?? '';
if ($basePath === '' || !is_dir($basePath)) {
    fwrite(STDERR, "Usage: php stdio-server.php <basePath>\n");
    exit(2);
}

$auditLogger = new AuditLogger($basePath);
$server = new McpServer($auditLogger, new ApprovalPolicy());
$server->registerTool(new AnalyzeProject($basePath));
$server->registerTool(new ReadDocumentation($basePath));
$server->registerTool(new ExecuteCli($basePath));
$server->registerTool(new WriteFile($basePath));
$server->registerTool(new PatchFile($basePath));
$server->registerTool(new ScaffoldModel($basePath));
$server->registerTool(new ScaffoldController($basePath));
$server->registerTool(new ScaffoldMigration($basePath));
$server->registerTool(new ScaffoldResource($basePath));
$server->registerResource(new DocsResource($basePath));
$server->registerResource(new AppResource($basePath));
$server->registerResource(new DebugResource($basePath));
$server->registerResource(new AuditResource($auditLogger));
$server->run();
