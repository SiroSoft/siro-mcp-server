# Changelog — siro-mcp-server

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
