// ============================================================
// MCP Server Assembly
// ============================================================
// Creates and configures the MCP server with all tool modules.
// Core tools are always registered. Enhanced tools are
// conditionally registered when coolify-enhanced is detected.
// ============================================================

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import type { CoolifyClient } from "./coolify-client.js";

// Core tool modules (always available)
import { registerServerTools } from "../tools/servers.js";
import { registerProjectTools } from "../tools/projects.js";
import { registerApplicationTools } from "../tools/applications.js";
import { registerDatabaseTools } from "../tools/databases.js";
import { registerServiceTools } from "../tools/services.js";
import { registerDeploymentTools } from "../tools/deployments.js";
import { registerEnvVarTools } from "../tools/environment-variables.js";
import { registerDatabaseBackupTools } from "../tools/database-backups.js";
import { registerSecurityTools } from "../tools/security.js";
import { registerSystemTools } from "../tools/system.js";

// Enhanced tool modules (coolify-enhanced only)
import { registerPermissionTools } from "../tools/permissions.js";
import { registerResourceBackupTools } from "../tools/resource-backups.js";
import { registerTemplateTools } from "../tools/templates.js";
import { registerNetworkTools } from "../tools/networks.js";

export interface CreateMcpServerOptions {
  client: CoolifyClient;
  enhanced: boolean;
}

export function createMcpServer({ client, enhanced }: CreateMcpServerOptions): McpServer {
  const serverName = enhanced ? "coolify-enhanced-mcp" : "coolify-mcp";

  const server = new McpServer({
    name: serverName,
    version: "1.0.0",
  });

  // Core Coolify tools — always registered
  registerServerTools(server, client);
  registerProjectTools(server, client);
  registerApplicationTools(server, client);
  registerDatabaseTools(server, client);
  registerServiceTools(server, client);
  registerDeploymentTools(server, client);
  registerEnvVarTools(server, client);
  registerDatabaseBackupTools(server, client);
  registerSecurityTools(server, client);
  registerSystemTools(server, client);

  // Enhanced tools — only when coolify-enhanced is detected
  if (enhanced) {
    registerPermissionTools(server, client);
    registerResourceBackupTools(server, client);
    registerTemplateTools(server, client);
    registerNetworkTools(server, client);
  }

  return server;
}
