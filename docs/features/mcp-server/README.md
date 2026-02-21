# MCP Server for Coolify Enhanced

## Overview

A Model Context Protocol (MCP) server that enables AI assistants (Claude Desktop, Cursor, VS Code Copilot, Kiro IDE) to manage Coolify infrastructure through natural language. Wraps all ~105 Coolify native API endpoints plus coolify-enhanced features (permissions, resource backups, custom templates, network management).

## Components

- **CoolifyClient** (`src/lib/coolify-client.ts`) — HTTP client for Coolify's REST API with retry logic and 60+ typed methods
- **MCP Server** (`src/lib/mcp-server.ts`) — Tool registration and server assembly with conditional enhanced tool loading
- **Tool Modules** (`src/tools/*.ts`) — 14 tool modules organized by category, totaling 99 tools
- **Type Definitions** (`src/lib/types.ts`) — TypeScript interfaces for all API request/response types
- **CLI Entry** (`bin/cli.ts`) — Shebang-enabled entry point for `npx` usage

## Tool Categories

| Category | Tool Count | Source |
|----------|-----------|--------|
| Servers | 8 | Native Coolify API |
| Projects & Environments | 9 | Native Coolify API |
| Applications | 10 | Native Coolify API |
| Databases | 8 | Native Coolify API |
| Services | 8 | Native Coolify API |
| Deployments | 4 | Native Coolify API |
| Environment Variables | 10 | Native Coolify API |
| Database Backups | 5 | Native Coolify API |
| Security & Teams | 7 | Native Coolify API |
| System | 3 | Native Coolify API |
| **Permissions** | **5** | **Coolify Enhanced API** |
| **Resource Backups** | **5** | **Coolify Enhanced API** |
| **Custom Templates** | **7** | **Coolify Enhanced API** |
| **Networks** | **10** | **Coolify Enhanced API** |
| **Total** | **99** | |

72 core tools work with any Coolify v4 instance. 27 enhanced tools require the coolify-enhanced addon.

## Key Implementation Details

### Feature Auto-Detection

On startup, the server probes `GET /api/v1/resource-backups`:
- **200, 401, or 403** — endpoint exists; enhanced tools registered
- **404** — standard Coolify; only core tools registered
- **Network error** — defaults to core-only mode (no crash)

Override with `COOLIFY_ENHANCED=true` environment variable.

### Tool Annotations

Every tool includes `readOnlyHint`, `destructiveHint`, `idempotentHint`, and `openWorldHint` annotations, helping AI clients make safety decisions about tool execution.

### Error Handling

All tool handlers wrap API calls in try/catch and return `{ isError: true }` on failure with a descriptive error message.

### Retry Logic

`CoolifyClient` implements exponential backoff (2^attempt seconds, max 3 retries) for HTTP 429 (rate limit) and 5xx (server error) responses.

### Logging

All logging uses `console.error()` (stderr) because stdout is reserved for the MCP JSON-RPC protocol.

## File List

```
mcp-server/
├── package.json                          # @amirhmoradi/coolify-enhanced-mcp
├── tsconfig.json                         # ES2022, NodeNext modules
├── README.md                             # User-facing documentation
├── bin/cli.ts                            # CLI entry point (shebang)
├── src/
│   ├── index.ts                          # Main: config, detection, startup
│   ├── lib/
│   │   ├── coolify-client.ts             # HTTP client (60+ methods, retry, auth)
│   │   ├── mcp-server.ts               # Server assembly, conditional registration
│   │   └── types.ts                     # TypeScript interfaces for all API types
│   └── tools/
│       ├── servers.ts                    # 8 tools: CRUD + resources/domains/validate
│       ├── projects.ts                   # 9 tools: project + environment CRUD
│       ├── applications.ts              # 10 tools: CRUD + lifecycle + logs + deploy
│       ├── databases.ts                 # 8 tools: CRUD + lifecycle
│       ├── services.ts                  # 8 tools: CRUD + lifecycle
│       ├── deployments.ts               # 4 tools: list, get, cancel, app history
│       ├── environment-variables.ts     # 10 tools: app + service env CRUD + bulk
│       ├── database-backups.ts          # 5 tools: backup config + executions
│       ├── security.ts                  # 7 tools: private keys + teams
│       ├── system.ts                    # 3 tools: version, health, resources
│       ├── permissions.ts               # 5 tools: [Enhanced] project access mgmt
│       ├── resource-backups.ts          # 5 tools: [Enhanced] backup schedules
│       ├── templates.ts                # 7 tools: [Enhanced] template sources
│       └── networks.ts                 # 10 tools: [Enhanced] network management
└── __tests__/                            # Test files
```

## Related Docs

- [PRD](PRD.md) — Full product requirements with tool inventory and technical decisions
- [Plan](plan.md) — Technical implementation plan with code patterns
- [mcp-server/README.md](../../../mcp-server/README.md) — User-facing MCP server documentation
- [docs/api.md](../../api.md) — REST API documentation (including MCP section)
- [docs/architecture.md](../../architecture.md) — Architecture document (including MCP section)
- [MCP Specification](https://modelcontextprotocol.io) — Protocol specification
- [Coolify API Reference](https://coolify.io/docs/api-reference) — Native API docs
