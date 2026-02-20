# MCP Server — Technical Implementation Plan

## Overview

Build a TypeScript MCP server that wraps Coolify's native REST API and coolify-enhanced's additional API endpoints, enabling AI assistants to manage infrastructure through natural language.

## Project Structure

```
mcp-server/
├── package.json
├── tsconfig.json
├── .eslintrc.json
├── README.md
├── bin/
│   └── cli.ts                          # CLI entry point with shebang
├── src/
│   ├── index.ts                        # Main entry point
│   ├── lib/
│   │   ├── coolify-client.ts           # HTTP API client for Coolify
│   │   ├── mcp-server.ts              # MCP server setup and tool registration
│   │   └── types.ts                   # Shared TypeScript types
│   └── tools/
│       ├── servers.ts                  # Server management tools
│       ├── projects.ts                 # Project & environment tools
│       ├── applications.ts            # Application management tools
│       ├── databases.ts               # Database management tools
│       ├── services.ts                # Service management tools
│       ├── deployments.ts             # Deployment management tools
│       ├── environment-variables.ts   # Env var management tools
│       ├── security.ts                # Private keys & teams tools
│       ├── system.ts                  # Version, health, resources tools
│       ├── permissions.ts             # Enhanced: permission management tools
│       ├── resource-backups.ts        # Enhanced: resource backup tools
│       ├── templates.ts              # Enhanced: custom template tools
│       └── networks.ts               # Enhanced: network management tools
└── __tests__/
    ├── coolify-client.test.ts
    └── tools/
        └── *.test.ts
```

## Implementation Steps

### Step 1: Project Scaffolding

Create `mcp-server/` directory with package.json, tsconfig.json, and dependencies.

**package.json:**
```json
{
  "name": "@amirhmoradi/coolify-enhanced-mcp",
  "version": "1.0.0",
  "description": "MCP server for Coolify with enhanced features support",
  "type": "module",
  "main": "dist/index.js",
  "bin": {
    "coolify-enhanced-mcp": "dist/bin/cli.js"
  },
  "scripts": {
    "build": "tsc",
    "dev": "tsc --watch",
    "start": "node dist/index.js",
    "lint": "eslint src/",
    "test": "vitest run",
    "prepublishOnly": "npm run build"
  },
  "dependencies": {
    "@modelcontextprotocol/sdk": "^1.12.0",
    "zod": "^3.24.0"
  },
  "devDependencies": {
    "@types/node": "^22.0.0",
    "typescript": "^5.7.0",
    "vitest": "^3.0.0",
    "eslint": "^9.0.0"
  },
  "engines": {
    "node": ">=18.0.0"
  }
}
```

**tsconfig.json:**
```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "NodeNext",
    "moduleResolution": "NodeNext",
    "outDir": "dist",
    "rootDir": ".",
    "strict": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "declaration": true,
    "sourceMap": true
  },
  "include": ["src/**/*", "bin/**/*"],
  "exclude": ["node_modules", "dist", "__tests__"]
}
```

### Step 2: CoolifyClient — HTTP API Client

Single class handling all HTTP communication with Coolify. Key design:

```typescript
// src/lib/coolify-client.ts

export interface CoolifyClientConfig {
  baseUrl: string;
  accessToken: string;
  timeout?: number; // default 30s
  retries?: number; // default 3
}

export class CoolifyClient {
  private config: CoolifyClientConfig;

  constructor(config: CoolifyClientConfig) { ... }

  // Generic request method with retry logic
  private async request<T>(
    method: string,
    path: string,
    options?: { body?: unknown; params?: Record<string, string> }
  ): Promise<T> { ... }

  // ---- Native Coolify API ----
  // Servers
  async listServers(): Promise<Server[]> { ... }
  async getServer(uuid: string): Promise<Server> { ... }
  async getServerResources(uuid: string): Promise<Resource[]> { ... }
  async getServerDomains(uuid: string): Promise<Domain[]> { ... }
  async validateServer(uuid: string): Promise<ValidationResult> { ... }
  async createServer(data: CreateServerInput): Promise<Server> { ... }
  async updateServer(uuid: string, data: UpdateServerInput): Promise<Server> { ... }
  async deleteServer(uuid: string): Promise<void> { ... }

  // Projects
  async listProjects(): Promise<Project[]> { ... }
  async getProject(uuid: string): Promise<Project> { ... }
  async createProject(data: CreateProjectInput): Promise<Project> { ... }
  async updateProject(uuid: string, data: UpdateProjectInput): Promise<Project> { ... }
  async deleteProject(uuid: string): Promise<void> { ... }

  // Environments
  async listEnvironments(projectUuid: string): Promise<Environment[]> { ... }
  async getEnvironment(projectUuid: string, envName: string): Promise<Environment> { ... }
  async createEnvironment(projectUuid: string, data: CreateEnvironmentInput): Promise<Environment> { ... }
  async deleteEnvironment(projectUuid: string, envName: string): Promise<void> { ... }

  // Applications
  async listApplications(): Promise<Application[]> { ... }
  async getApplication(uuid: string): Promise<Application> { ... }
  async createPublicApplication(data: CreatePublicAppInput): Promise<Application> { ... }
  async updateApplication(uuid: string, data: UpdateApplicationInput): Promise<Application> { ... }
  async deleteApplication(uuid: string): Promise<void> { ... }
  async startApplication(uuid: string): Promise<void> { ... }
  async stopApplication(uuid: string): Promise<void> { ... }
  async restartApplication(uuid: string): Promise<void> { ... }
  async getApplicationLogs(uuid: string, opts?: LogOptions): Promise<string> { ... }

  // Databases
  async listDatabases(): Promise<Database[]> { ... }
  async getDatabase(uuid: string): Promise<Database> { ... }
  async createDatabase(type: string, data: CreateDatabaseInput): Promise<Database> { ... }
  async updateDatabase(uuid: string, data: UpdateDatabaseInput): Promise<Database> { ... }
  async deleteDatabase(uuid: string, opts?: DeleteOptions): Promise<void> { ... }
  async startDatabase(uuid: string): Promise<void> { ... }
  async stopDatabase(uuid: string): Promise<void> { ... }
  async restartDatabase(uuid: string): Promise<void> { ... }

  // Services
  async listServices(): Promise<Service[]> { ... }
  async getService(uuid: string): Promise<Service> { ... }
  async createService(data: CreateServiceInput): Promise<Service> { ... }
  async updateService(uuid: string, data: UpdateServiceInput): Promise<Service> { ... }
  async deleteService(uuid: string, opts?: DeleteOptions): Promise<void> { ... }
  async startService(uuid: string): Promise<void> { ... }
  async stopService(uuid: string): Promise<void> { ... }
  async restartService(uuid: string): Promise<void> { ... }

  // Environment Variables
  async listAppEnvs(uuid: string): Promise<EnvVar[]> { ... }
  async createAppEnv(uuid: string, data: CreateEnvVarInput): Promise<EnvVar> { ... }
  async updateAppEnv(uuid: string, data: UpdateEnvVarInput): Promise<EnvVar> { ... }
  async bulkUpdateAppEnvs(uuid: string, data: BulkEnvVarInput): Promise<void> { ... }
  async deleteAppEnv(appUuid: string, envUuid: string): Promise<void> { ... }
  async listServiceEnvs(uuid: string): Promise<EnvVar[]> { ... }
  async createServiceEnv(uuid: string, data: CreateEnvVarInput): Promise<EnvVar> { ... }
  async updateServiceEnv(uuid: string, data: UpdateEnvVarInput): Promise<EnvVar> { ... }
  async bulkUpdateServiceEnvs(uuid: string, data: BulkEnvVarInput): Promise<void> { ... }
  async deleteServiceEnv(svcUuid: string, envUuid: string): Promise<void> { ... }

  // Deployments
  async deploy(opts: DeployInput): Promise<DeployResult> { ... }
  async listDeployments(): Promise<Deployment[]> { ... }
  async getDeployment(uuid: string): Promise<Deployment> { ... }
  async cancelDeployment(uuid: string): Promise<void> { ... }
  async listAppDeployments(appUuid: string): Promise<Deployment[]> { ... }

  // Database Backups
  async listDbBackups(dbUuid: string): Promise<BackupConfig[]> { ... }
  async createDbBackup(dbUuid: string, data: CreateBackupInput): Promise<BackupConfig> { ... }
  async updateDbBackup(dbUuid: string, backupUuid: string, data: UpdateBackupInput): Promise<BackupConfig> { ... }
  async deleteDbBackup(dbUuid: string, backupUuid: string): Promise<void> { ... }
  async listDbBackupExecutions(dbUuid: string, backupUuid: string): Promise<BackupExecution[]> { ... }

  // Security
  async listPrivateKeys(): Promise<PrivateKey[]> { ... }
  async createPrivateKey(data: CreateKeyInput): Promise<PrivateKey> { ... }
  async getPrivateKey(uuid: string): Promise<PrivateKey> { ... }
  async deletePrivateKey(uuid: string): Promise<void> { ... }

  // System
  async getVersion(): Promise<string> { ... }
  async healthCheck(): Promise<boolean> { ... }
  async listResources(): Promise<Resource[]> { ... }
  async listTeams(): Promise<Team[]> { ... }
  async getCurrentTeam(): Promise<Team> { ... }
  async getTeamMembers(): Promise<TeamMember[]> { ... }

  // ---- Coolify Enhanced API ----
  // Permissions
  async listProjectAccess(projectUuid: string): Promise<ProjectAccess[]> { ... }
  async grantProjectAccess(projectUuid: string, data: GrantAccessInput): Promise<ProjectAccess> { ... }
  async updateProjectAccess(projectUuid: string, userId: number, data: UpdateAccessInput): Promise<ProjectAccess> { ... }
  async revokeProjectAccess(projectUuid: string, userId: number): Promise<void> { ... }
  async checkUserPermission(projectUuid: string, userId: number, permission: string): Promise<PermissionCheck> { ... }

  // Resource Backups
  async listResourceBackups(): Promise<ResourceBackup[]> { ... }
  async createResourceBackup(data: CreateResourceBackupInput): Promise<ResourceBackup> { ... }
  async getResourceBackup(uuid: string): Promise<ResourceBackup> { ... }
  async triggerResourceBackup(uuid: string): Promise<void> { ... }
  async deleteResourceBackup(uuid: string): Promise<void> { ... }

  // Custom Templates
  async listTemplateSources(): Promise<TemplateSource[]> { ... }
  async createTemplateSource(data: CreateTemplateSourceInput): Promise<TemplateSource> { ... }
  async getTemplateSource(uuid: string): Promise<TemplateSource> { ... }
  async updateTemplateSource(uuid: string, data: UpdateTemplateSourceInput): Promise<TemplateSource> { ... }
  async deleteTemplateSource(uuid: string): Promise<void> { ... }
  async syncTemplateSource(uuid: string): Promise<void> { ... }
  async syncAllTemplateSources(): Promise<void> { ... }

  // Networks
  async listServerNetworks(serverUuid: string): Promise<ManagedNetwork[]> { ... }
  async createNetwork(serverUuid: string, data: CreateNetworkInput): Promise<ManagedNetwork> { ... }
  async getNetwork(serverUuid: string, networkUuid: string): Promise<ManagedNetwork> { ... }
  async deleteNetwork(serverUuid: string, networkUuid: string): Promise<void> { ... }
  async syncNetworks(serverUuid: string): Promise<void> { ... }
  async migrateProxy(serverUuid: string): Promise<void> { ... }
  async cleanupProxy(serverUuid: string): Promise<void> { ... }
  async listResourceNetworks(type: string, uuid: string): Promise<ManagedNetwork[]> { ... }
  async attachResourceNetwork(type: string, uuid: string, data: AttachNetworkInput): Promise<void> { ... }
  async detachResourceNetwork(type: string, uuid: string, networkUuid: string): Promise<void> { ... }

  // Feature detection
  async isEnhanced(): Promise<boolean> { ... }
}
```

### Step 3: Type Definitions

```typescript
// src/lib/types.ts
// Define all TypeScript interfaces for API request/response types
// These match Coolify's API response schemas
```

### Step 4: Tool Modules

Each tool module exports a function that registers tools on the MCP server:

```typescript
// Example: src/tools/servers.ts
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import { CoolifyClient } from "../lib/coolify-client.js";

export function registerServerTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_servers",
    "List all Coolify servers with their status, IP, and resource count",
    {},
    { annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false } },
    async () => {
      const servers = await client.listServers();
      return {
        content: [{
          type: "text",
          text: JSON.stringify(servers, null, 2)
        }]
      };
    }
  );

  server.tool(
    "get_server",
    "Get detailed information about a specific Coolify server",
    { uuid: z.string().describe("Server UUID") },
    { annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false } },
    async ({ uuid }) => {
      const server = await client.getServer(uuid);
      return {
        content: [{
          type: "text",
          text: JSON.stringify(server, null, 2)
        }]
      };
    }
  );

  // ... more server tools
}
```

### Step 5: MCP Server Assembly

```typescript
// src/lib/mcp-server.ts
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { CoolifyClient } from "./coolify-client.js";

// Import tool registration functions
import { registerServerTools } from "../tools/servers.js";
import { registerProjectTools } from "../tools/projects.js";
import { registerApplicationTools } from "../tools/applications.js";
import { registerDatabaseTools } from "../tools/databases.js";
import { registerServiceTools } from "../tools/services.js";
import { registerDeploymentTools } from "../tools/deployments.js";
import { registerEnvVarTools } from "../tools/environment-variables.js";
import { registerSecurityTools } from "../tools/security.js";
import { registerSystemTools } from "../tools/system.js";
// Enhanced tools (conditional)
import { registerPermissionTools } from "../tools/permissions.js";
import { registerResourceBackupTools } from "../tools/resource-backups.js";
import { registerTemplateTools } from "../tools/templates.js";
import { registerNetworkTools } from "../tools/networks.js";

export function createMcpServer(client: CoolifyClient, enhanced: boolean): McpServer {
  const server = new McpServer({
    name: "coolify-enhanced-mcp",
    version: "1.0.0",
    capabilities: {
      tools: {},
      resources: {},
      prompts: {}
    }
  });

  // Core Coolify tools (always registered)
  registerServerTools(server, client);
  registerProjectTools(server, client);
  registerApplicationTools(server, client);
  registerDatabaseTools(server, client);
  registerServiceTools(server, client);
  registerDeploymentTools(server, client);
  registerEnvVarTools(server, client);
  registerSecurityTools(server, client);
  registerSystemTools(server, client);

  // Enhanced tools (only when coolify-enhanced is detected)
  if (enhanced) {
    registerPermissionTools(server, client);
    registerResourceBackupTools(server, client);
    registerTemplateTools(server, client);
    registerNetworkTools(server, client);
  }

  return server;
}
```

### Step 6: Entry Point

```typescript
// src/index.ts
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { CoolifyClient } from "./lib/coolify-client.js";
import { createMcpServer } from "./lib/mcp-server.js";

async function main() {
  const baseUrl = process.env.COOLIFY_BASE_URL;
  const accessToken = process.env.COOLIFY_ACCESS_TOKEN;

  if (!baseUrl || !accessToken) {
    console.error("COOLIFY_BASE_URL and COOLIFY_ACCESS_TOKEN are required");
    process.exit(1);
  }

  const client = new CoolifyClient({
    baseUrl: baseUrl.replace(/\/$/, ""),
    accessToken,
  });

  // Detect enhanced features
  let enhanced = process.env.COOLIFY_ENHANCED === "true";
  if (!enhanced) {
    try {
      enhanced = await client.isEnhanced();
    } catch {
      // Assume not enhanced
    }
  }

  const server = createMcpServer(client, enhanced);
  const transport = new StdioServerTransport();
  await server.connect(transport);
}

main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
```

### Step 7: CLI Binary

```typescript
// bin/cli.ts
#!/usr/bin/env node
import "../src/index.js";
```

## Key Implementation Patterns

### Error Handling

All tool handlers wrap API calls in try/catch and return structured error responses:

```typescript
async ({ uuid }) => {
  try {
    const result = await client.getServer(uuid);
    return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { content: [{ type: "text", text: `Error: ${message}` }], isError: true };
  }
}
```

### Retry Logic

The CoolifyClient implements exponential backoff for transient failures:

```typescript
private async requestWithRetry<T>(...): Promise<T> {
  for (let attempt = 0; attempt <= this.config.retries; attempt++) {
    try {
      return await this.request<T>(...);
    } catch (error) {
      if (attempt === this.config.retries || !isRetryable(error)) throw error;
      await sleep(Math.pow(2, attempt) * 1000);
    }
  }
}
```

### Feature Detection

```typescript
async isEnhanced(): Promise<boolean> {
  try {
    await this.request("GET", "/api/v1/resource-backups");
    return true;
  } catch {
    return false;
  }
}
```

## Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| `@modelcontextprotocol/sdk` | `^1.12.0` | Official MCP TypeScript SDK |
| `zod` | `^3.24.0` | Schema validation for tool parameters |
| `typescript` | `^5.7.0` | TypeScript compiler (dev) |
| `vitest` | `^3.0.0` | Test framework (dev) |
| `@types/node` | `^22.0.0` | Node.js type definitions (dev) |

## Testing Strategy

1. **Unit tests** for CoolifyClient (mock HTTP responses)
2. **Tool registration tests** (verify all tools are registered with correct schemas)
3. **Integration tests** (optional, against a live Coolify instance with `COOLIFY_TEST_URL`)
