<?php

declare(strict_types=1);

namespace SiroSoft\McpServer\Commands;

use SiroSoft\McpServer\McpServer;
use SiroSoft\McpServer\Tool\AnalyzeProject;
use SiroSoft\McpServer\Tool\ReadDocumentation;
use SiroSoft\McpServer\Tool\ExecuteCli;
use SiroSoft\McpServer\Tool\WriteFile;
use SiroSoft\McpServer\Tool\PatchFile;
use SiroSoft\McpServer\Tool\ScaffoldModel;
use SiroSoft\McpServer\Tool\ScaffoldController;
use SiroSoft\McpServer\Tool\ScaffoldMigration;
use SiroSoft\McpServer\Tool\ScaffoldResource;
use SiroSoft\McpServer\Resource\DocsResource;
use SiroSoft\McpServer\Resource\AppResource;
use SiroSoft\McpServer\Resource\DebugResource;
use SiroSoft\McpServer\Resource\AuditResource;
use SiroSoft\McpServer\Security\ApprovalPolicy;
use SiroSoft\McpServer\Security\AuditLogger;

/**
 * mcp:serve — Start the Siro MCP Server for AI agents.
 *
 * Registers all tools and resources, then starts the JSON-RPC stdio loop.
 *
 * @package SiroSoft\McpServer\Commands
 */
final class McpServeCommand implements \Siro\Core\Commands\CommandInterface
{
    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $auditLogger = new AuditLogger($this->basePath);
        $server = new McpServer($auditLogger, new ApprovalPolicy());

        // ── Register all Phase 0+ tools ──
        $server->registerTool(new AnalyzeProject($this->basePath));
        $server->registerTool(new ReadDocumentation($this->basePath));
        $server->registerTool(new ExecuteCli($this->basePath));
        $server->registerTool(new WriteFile($this->basePath));
        $server->registerTool(new PatchFile($this->basePath));
        $server->registerTool(new ScaffoldModel($this->basePath));
        $server->registerTool(new ScaffoldController($this->basePath));
        $server->registerTool(new ScaffoldMigration($this->basePath));
        $server->registerTool(new ScaffoldResource($this->basePath));

        // ── Register all Resources ──
        $server->registerResource(new DocsResource($this->basePath));
        $server->registerResource(new AppResource($this->basePath));
        $server->registerResource(new DebugResource($this->basePath));
        $server->registerResource(new AuditResource($auditLogger));

        // ── Add stderr banner ──
        fwrite(STDERR, "⚡ Siro MCP Server v0.4.2 — Started\n");
        fwrite(STDERR, "   Project: " . basename($this->basePath) . "\n");
        fwrite(STDERR, "   Tools:   9 registered\n");
        fwrite(STDERR, "   Resources: 4 providers\n");
        fwrite(STDERR, "   Protocol: JSON-RPC 2.0 over stdio\n");
        fwrite(STDERR, "   Waiting for AI agent connection...\n");

        // ── Start the stdio server loop ──
        $server->run();

        return 0;
    }
}
