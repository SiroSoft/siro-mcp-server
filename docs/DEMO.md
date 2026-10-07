# Demo: HTTP 500 → Claude phân tích qua MCP → fix có approval

A 2-minute, fully runnable demo. No Claude subscription needed: `demo/error-analysis.php`
plays **both sides** of a real MCP session (JSON-RPC over stdio) against the actual
`McpServer` — the same 9 tools + 4 resources that `php siro mcp:serve` registers.

## Run it

```bash
php demo/error-analysis.php
```

Exit code `0` + final `=== DEMO OK ===` means every step below passed. Any failed
check prints `✗ FAILED` and exits non-zero. The broken app is kept in the temp
dir printed on the last line so you can inspect it.

## The storyline (7 MCP round-trips, 1 approval)

| # | Claude (script) → | MCP server ← |
|---|---|---|
| 0 | *setup:* broken app + `500` trace written to `storage/framework/traces/` | — |
| 1 | `initialize` | `siro-mcp-server 0.4.0`, tools + resources capabilities |
| 2 | `tools/list` | 9 tools (`analyze_project`, `patch_file`, scaffolds, …) |
| 3 | `tools/call analyze_project` | project summary: 1 model, 1 controller |
| 4 | `resources/read siro://debug/traces/latest` | `GET /api/products → 500`, `Class "Siro\Core\Http\Request" not found` at `ProductController.php:8` |
| 5 | hypothesis check **against installed core**: old class missing, `Siro\Core\Request` exists → root cause confirmed (computed, not hardcoded) | — |
| 6 | `tools/call read_documentation { topic: model }` | real `DATABASE.md` section from `vendor/sirosoft/core` |
| 7 | `tools/call patch_file { mode: direct, approval_token }` | `OK: Patch applied (1 removed, 1 added)` → `php -l` clean |

The bug is deliberately the stale `Siro\Core\Http\Request` import that old
scaffold output used to generate — the exact class of issue MCP scaffolding
used to *cause*, now the class of issue MCP helps *fix*.

## Replay it for real in Claude Code

With the server connected (see `README.md` §3), paste:

> `GET /api/products` returns 500. The trace is in `storage/framework/traces/`.
> Use the siro MCP tools to find the root cause, verify it against the installed
> core, and propose a patch. Apply it only after I approve.

Claude will walk the same path: `analyze_project` → `siro://debug/traces/latest`
→ `read_documentation` → `patch_file` (it will ask for your approval token).

## Recording a GIF/video

The script prints a clean linear transcript, ideal for terminal recording:

```bash
# terminal 1 — record
asciinema rec demo.cast -- php demo/error-analysis.php

# convert to GIF (https://github.com/asciinema/agg)
agg demo.cast demo.gif --font-size 14 --theme monokai
```

Tips for a convincing 60–90s cut:

1. Start on the failing request (`500` + trace file).
2. Keep the `initialize → analyze → trace → hypothesis check` beats uncut —
   the `old class MISSING / new class EXISTS` line is the money shot.
3. Fast-forward the `read_documentation` output (truncated in-script already).
4. End on `OK: Patch applied` + `php -l clean` + `DEMO OK`.
5. Add captions, not narration: "7 MCP calls · 1 approval · 0 context-switching".
