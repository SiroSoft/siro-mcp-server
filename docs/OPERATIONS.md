# Siro MCP Operations Guide

This guide covers safe day-to-day use of Siro MCP Server v0.3.0.

## Security Model

The server uses three layers:

1. MCP tools are exposed over local stdio, not a network listener.
2. File tools are restricted to the project root and CLI execution uses a whitelist.
3. State-changing tools fail closed unless the process has `SIRO_MCP_APPROVAL_TOKEN` and the request includes the matching `approval_token`.

State-changing tools:

- `write_file`
- `patch_file`
- `scaffold_model`
- `scaffold_controller`
- `scaffold_migration`
- `scaffold_resource`
- `execute_cli` for `migrate`, `migrate:fresh`, `migrate:rollback`, and `db:seed`

Never put the approval token in source control, prompts, or audit text. Use an environment variable or a secret manager.

## Local Setup

PowerShell:

```powershell
$env:SIRO_MCP_APPROVAL_TOKEN = "read-from-your-secret-store"
php siro mcp:serve
```

Bash:

```bash
export SIRO_MCP_APPROVAL_TOKEN="read-from-your-secret-store"
php siro mcp:serve
```

Read-only tools work without the token. Mutation requests without approval return `Approval required` and do not execute.

## Safe Workflow

1. Call `analyze_project` with `depth=summary`.
2. Read the relevant topic with `read_documentation`.
3. Use `patch_file` with the default `mode=diff` to preview changes.
4. Review the preview and send `approval_token` only when the change is accepted.
5. Use `mode=direct` or a scaffold tool.
6. Run a read-only CLI check such as `route:list`, `doctor`, or `migrate:status`.
7. Capture the returned `_meta.runId`.
8. Read `siro://mcp/runs/<run_id>` or `siro://mcp/runs/latest` to verify the result.

## Audit Records

Records are appended to:

```text
storage/framework/mcp/runs.jsonl
```

Each record includes:

- `id`, `request_id`, `tool`
- `started_at`, `finished_at`, `duration_ms`
- `status`: `completed`, `failed`, or `denied`
- `approved`
- redacted `arguments` and bounded `result`

Secrets are redacted by key and by common `key=value` patterns. Audit files can contain project paths and generated content, so protect the storage directory with normal application permissions.

## Production Topology

Do not expose MCP directly from the public web container. Run it as a separate, operator-controlled process with:

- a read-only project mount by default;
- a separate write-enabled workspace for approved changes;
- a secret manager for `SIRO_MCP_APPROVAL_TOKEN`;
- OS-level user and filesystem restrictions;
- audit storage outside the public document root.

For Siro Showcase, MCP is a Composer development dependency and is intentionally excluded from the production web image. Use the local or staging project as the MCP control plane.

## Incident Response

If an unexpected mutation is reported:

1. Stop the MCP process.
2. Preserve `storage/framework/mcp/runs.jsonl`.
3. Search by `runId`, tool name, and timestamp.
4. Rotate `SIRO_MCP_APPROVAL_TOKEN`.
5. Review the affected files and Git diff before restarting.

## Health Checklist

```bash
composer check
composer audit --format=table
php siro list
php siro mcp:serve
```

The expected startup banner reports 9 tools, 4 resource providers, and server version `0.3.0`.
