# Changelog — siro-mcp-server

## [0.4.1] - 2026-10-07

### Fixed
- `read_documentation` topics carrying a `#section` fragment (`model`,
  `migration`) no longer report "file not found" — the fragment is stripped
  for file lookup and the section is extracted after reading.

## [0.4.0] - 2026-10-07

### Added
- `scaffold_resource` now generates the full layered stack: model + migration
  + repository + service + controller + API resource + routes + feature test
  (previously model + migration + controller + routes only).
- Scaffolded controllers follow the current core conventions (`Siro\Core\Request`,
  `Siro\Core\Response`, `Response::paginated/success/created/error`,
  `Model::query()->paginate()`) and support service-layer delegation.
- Strict input validation for scaffold names, columns, relations, and route
  prefixes with fail-closed error messages.

### Fixed
- Model scaffolding emits the real core namespaces
  (`Siro\Core\DB\SoftDeletes`, `Siro\Core\DB\Relations\*`) instead of
  non-existent `Siro\Core\Model\SoftDeletes` / `Siro\Core\ModelRelations\*`.
- Relation methods are generated inside the model class body (previously
  appended after the closing brace).
- Generated services use core's `getAll`/`getById` naming over the repository's
  `findAll`/`findById`; generated API resources implement `toArray(): array`
  over `$this->data` per `Siro\Core\Resource`.

### Dependencies
- `sirosoft/core` lockfile aligned to v1.3.0 (`^1.0.14` constraint covers 1.x).

## [0.3.0] - 2026-09-16

### Added
- Redacted JSONL audit records for every MCP tool execution.
- `siro://mcp/runs/*` resources for reading recent execution results.
- Operator approval token gate for mutations and destructive CLI commands.
- Run IDs in tool response metadata.

### Security
- Mutation tools fail closed when `SIRO_MCP_APPROVAL_TOKEN` is not configured.
- Audit output truncates large values and redacts secrets, passwords, tokens, and API keys.

## [0.2.0] - 2026-09-15

### Added
- MCP protocol negotiation for supported 2024-11-05 and 2025.x clients.
- JSON-RPC parse and invalid-request error responses.

### Fixed
- Path validation now enforces the project-root boundary correctly.
- Patch application validates context exactly and handles end-of-file additions.
- Server and package versions are aligned.

## [0.1.1] - 2026-06-15

### Fixed
- Minor bug fixes and stability improvements

## [0.1.0] - 2026-06-01

### Added
- Initial release of Siro MCP Server
- 9 tools: analyze_project, read_documentation, execute_cli, write_file, patch_file, scaffold_model, scaffold_controller, scaffold_migration, scaffold_resource
- 3 resource providers: App, Debug, Docs
- Context analyzers: ModelGraph, RouteGraph, ProjectAnalyzer
- Path traversal protection, CLI command whitelist
- JSON-RPC 2.0 over stdio
