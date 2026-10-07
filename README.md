# Siro MCP Server

**Bridge your Siro PHP application with AI agents via the Model Context Protocol (MCP).**

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php)](composer.json)

---

## Overview

Siro MCP Server implements the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) — a JSON-RPC 2.0 standard that enables AI agents (Claude, GPT, Copilot, Cursor, and others) to interact with your Siro PHP application in real time.

The server runs over **stdio** and provides:

- **Tools** — Actions the AI agent can invoke (scaffold code, run commands, read/write files)
- **Resources** — Contextual data the AI agent can read (routes, models, debug traces, documentation)
- **Context Analyzers** — Deep project understanding (model graphs, route graphs, architecture analysis)
- **Security** — Path traversal protection, CLI command whitelist, sandboxed execution

---

## Quick Start

### 1. Install

```bash
composer require sirosoft/mcp-server
```

> Requires PHP 8.2+ and `sirosoft/core ^1.0.14`.

### 2. Start the Server

```bash
php siro mcp:serve
```

The server listens on **STDIN/STDOUT** for JSON-RPC requests. You should see:

```
    ⚡ Siro MCP Server v0.4.2 — Started
   Project: your-project
   Tools:   9 registered
   Resources: 4 providers
   Protocol: JSON-RPC 2.0 over stdio
   Waiting for AI agent connection...
```

### 3. Connect an AI Agent

Configure your MCP-compatible AI client to connect via stdio:

**Claude Desktop (claude_desktop_config.json):**
```json
{
  "mcpServers": {
    "siro": {
      "command": "php",
      "args": ["siro", "mcp:serve"],
      "cwd": "/path/to/your-project"
    }
  }
}
```

**Claude Code (terminal — recommended for Siro development):**

No JSON config needed. From your project root:

```bash
claude mcp add siro -- php siro mcp:serve
```

Verify the connection:

```bash
claude mcp list
# siro: php siro mcp:serve ✓ Connected
```

For a shared setup committed with the project (the whole team gets it on
`git pull`), create `.mcp.json` in the project root instead:

```json
{
  "mcpServers": {
    "siro": {
      "command": "php",
      "args": ["siro", "mcp:serve"],
      "env": {
        "SIRO_MCP_APPROVAL_TOKEN": "your-operator-token"
      }
    }
  }
}
```

> Claude Code launches stdio servers with the project directory as the working
> directory, so `php siro mcp:serve` resolves inside your app with no `cwd`
> needed. Mutating tools (`write_file`, `patch_file`, scaffolds) require
> `SIRO_MCP_APPROVAL_TOKEN` — see [`docs/OPERATIONS.md`](docs/OPERATIONS.md).
> Try the runnable error → analysis demo in [`docs/DEMO.md`](docs/DEMO.md).

**Cursor / VS Code (settings.json):**
```json
{
  "mcpServers": {
    "siro": {
      "command": "php",
      "args": ["siro", "mcp:serve"],
      "cwd": "/path/to/your-project"
    }
  }
}
```

### 4. See it in action (2-minute runnable demo)

Watch an AI agent diagnose a real `500` through MCP — no Claude subscription needed,
the script plays both sides over the actual JSON-RPC server:

```bash
php demo/error-analysis.php
```

It builds a broken app, walks the full MCP session (initialize → analyze →
read trace → verify against installed core → approved patch → `php -l`),
and prints every request/response. Full walkthrough: [`docs/DEMO.md`](docs/DEMO.md).

---

## Tools

The server registers **9 tools** that AI agents can invoke:

| Tool | Description |
|------|-------------|
| `analyze_project` | Deep analysis of routes, models, controllers, services, middleware, and architecture |
| `read_documentation` | Read Siro framework docs by topic (15+ topics available) |
| `execute_cli` | Run safe CLI commands via command whitelist (40+ allowed commands) |
| `write_file` | Create or overwrite files with path traversal protection |
| `patch_file` | Apply surgical diffs to existing files (safe preview mode) |
| `scaffold_model` | Generate model classes with fillable, casts, and relations |
| `scaffold_controller` | Generate controller classes with optional CRUD methods |
| `scaffold_migration` | Generate database migrations with columns, indexes, and foreign keys |
| `scaffold_resource` | Full CRUD scaffolding (model + migration + repository + service + controller + resource + routes + feature test) |

### Security

- **Path traversal protection**: All file operations are validated against the project root
- **CLI whitelist**: Only pre-approved commands are executable
- **Operator approval**: File writes, scaffolding, and destructive CLI commands require `SIRO_MCP_APPROVAL_TOKEN`
- **Audit trail**: Every tool call gets a run ID and is recorded with secret redaction
- **Destructive commands** (migrate, db:seed) require both approval and the explicit `--force` flag
- **Blocked commands**: `tinker`, `shell`, `exec`, `eval`, `system`, `passthru`

For the complete approval, audit, incident-response, and production topology
runbook, see [`docs/OPERATIONS.md`](docs/OPERATIONS.md).

---

## Resources

AI agents can read contextual data via `siro://` URIs:

| URI | Description |
|-----|-------------|
| `siro://docs/*` | Framework documentation (15+ topics) |
| `siro://app/routes` | Registered API routes |
| `siro://app/models` | Models with fillable, casts, and relations |
| `siro://app/controllers` | All controllers with public methods |
| `siro://app/services` | Service layer classes |
| `siro://app/middleware` | Core and app middleware |
| `siro://app/structure` | Project directory tree |
| `siro://app/config` | Application configuration |
| `siro://app/openapi` | OpenAPI specification |
| `siro://debug/traces/latest` | Latest request trace |
| `siro://debug/errors/latest` | Latest application error |
| `siro://mcp/runs/latest` | Latest MCP tool execution, including status and duration |

---

## Architecture

```
┌─────────────────────────────────────┐
│          AI Agent (Client)          │
│   (Claude, GPT, Copilot, Cursor)   │
└──────────────┬──────────────────────┘
               │ JSON-RPC 2.0 over stdio
┌──────────────▼──────────────────────┐
│          Siro MCP Server            │
│                                      │
│  ┌─────────┐  ┌──────────────────┐  │
│  │  Tools  │  │   Resources      │  │
│  │ (9 reg) │  │  (4 providers)   │  │
│  ├─────────┤  ├──────────────────┤  │
│  │ Project │  │ siro://docs/*    │  │
│  │ Analyzer│  │ siro://app/*     │  │
│  │ CLI     │  │ siro://debug/*   │  │
│  │ File IO │  │                  │  │
│  │Scaffolds│  │  Context:        │  │
│  └─────────┘  │  ModelGraph      │  │
│               │  RouteGraph      │  │
│  ┌─────────┐  │  ProjectAnalyzer │  │
│  │Security │  └──────────────────┘  │
│  │ Path    │                        │
│  │ Valid.  │  Comm: McpServeCommand │
│  │ Whitelst│  (php siro mcp:serve)  │
│  └─────────┘                        │
└─────────────────────────────────────┘
```

### Key Design Decisions

- **JSON-RPC 2.0 over stdio**: Zero network overhead, works with any MCP-compatible client
- **Plugin architecture**: Tools and resources implement `ToolInterface` and `ResourceInterface` for extensibility
- **Security-first**: `PathValidator` prevents directory traversal; `ExecuteCli` enforces command whitelist
- **Siro auto-discovery**: Registers via `composer.json` `extra.siro.commands` — no config needed
- **Context engine**: `ProjectAnalyzer`, `ModelGraph`, and `RouteGraph` provide AI agents with deep project understanding

---

## Development

### Project Structure

```
siro-mcp-server/
├── src/
│   ├── Commands/
│   │   └── McpServeCommand.php     # CLI entry point
│   ├── Context/
│   │   ├── ModelGraph.php          # Model relation graph
│   │   ├── ProjectAnalyzer.php     # Full project analysis
│   │   └── RouteGraph.php          # Route dependency graph
│   ├── Resource/
│   │   ├── ResourceInterface.php   # Resource contract
│   │   ├── AppResource.php         # siro://app/*
│   │   ├── DebugResource.php       # siro://debug/*
│   │   └── DocsResource.php        # siro://docs/*
│   ├── Security/
│   │   └── PathValidator.php       # Path traversal protection
│   ├── Tool/
│   │   ├── ToolInterface.php       # Tool contract
│   │   ├── AnalyzeProject.php
│   │   ├── ExecuteCli.php
│   │   ├── PatchFile.php
│   │   ├── ReadDocumentation.php
│   │   ├── ScaffoldController.php
│   │   ├── ScaffoldMigration.php
│   │   ├── ScaffoldModel.php
│   │   ├── ScaffoldResource.php
│   │   └── WriteFile.php
│   └── McpServer.php               # Core MCP JSON-RPC handler
├── composer.json
├── LICENSE
└── README.md
```

---

## Versioning

This project follows [Semantic Versioning](https://semver.org/).

- **v0.1.x** — Initial release (tools + resources + context + security)
- **v0.2.x** — Protocol enhancements, additional tools
- **v0.3.x** — Audited approval-gated execution, run resources
- **v0.4.x** — Full layered CRUD scaffolding, core 1.3 support

---

## License

[MIT](LICENSE) © 2026 SiroSoft
