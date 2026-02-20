# MCP Server for Coolify Enhanced

## Overview

A Model Context Protocol (MCP) server that enables AI assistants (Claude Desktop, Cursor, VS Code Copilot, Kiro IDE) to manage Coolify infrastructure through natural language. Wraps all ~105 Coolify native API endpoints plus coolify-enhanced features (permissions, resource backups, custom templates, network management).

## Components

- **CoolifyClient** (`src/lib/coolify-client.ts`) — HTTP client for Coolify's REST API with retry logic
- **MCP Server** (`src/lib/mcp-server.ts`) — Tool registration and server assembly
- **Tool Modules** (`src/tools/*.ts`) — 13 tool modules organized by category
- **Type Definitions** (`src/lib/types.ts`) — TypeScript interfaces for API types

## Tool Categories

| Category | Tool Count | Source |
|----------|-----------|--------|
| Servers | 8 | Native Coolify API |
| Projects & Environments | 8 | Native Coolify API |
| Applications | 10 | Native Coolify API |
| Databases | 8 | Native Coolify API |
| Services | 8 | Native Coolify API |
| Deployments | 5 | Native Coolify API |
| Environment Variables | 10 | Native Coolify API |
| Database Backups | 5 | Native Coolify API |
| Security & Teams | 7 | Native Coolify API |
| System | 3 | Native Coolify API |
| Permissions | 5 | Coolify Enhanced API |
| Resource Backups | 5 | Coolify Enhanced API |
| Custom Templates | 7 | Coolify Enhanced API |
| Networks | 10 | Coolify Enhanced API |

## File List

```
mcp-server/
├── package.json
├── tsconfig.json
├── README.md
├── bin/cli.ts
├── src/
│   ├── index.ts
│   ├── lib/
│   │   ├── coolify-client.ts
│   │   ├── mcp-server.ts
│   │   └── types.ts
│   └── tools/
│       ├── servers.ts
│       ├── projects.ts
│       ├── applications.ts
│       ├── databases.ts
│       ├── services.ts
│       ├── deployments.ts
│       ├── environment-variables.ts
│       ├── database-backups.ts
│       ├── security.ts
│       ├── system.ts
│       ├── permissions.ts
│       ├── resource-backups.ts
│       ├── templates.ts
│       └── networks.ts
└── __tests__/
```

## Related Docs

- [PRD](PRD.md) — Full product requirements with tool inventory
- [Plan](plan.md) — Technical implementation plan with code patterns
- [dazeb/coolify-mcp-enhanced](https://github.com/dazeb/coolify-mcp-enhanced) — Inspiration project
- [MCP Specification](https://modelcontextprotocol.io) — Protocol specification
- [Coolify API Reference](https://coolify.io/docs/api-reference) — Native API docs
