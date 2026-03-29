import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

export function registerSystemTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "get_version",
    "Get the Coolify instance version",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const version = await client.getVersion();
        return { content: [{ type: "text", text: `Coolify version: ${version}` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "health_check",
    "Check if the Coolify instance is healthy and responding",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const healthy = await client.healthCheck();
        return {
          content: [{
            type: "text",
            text: healthy ? "Coolify is healthy and responding." : "Coolify health check failed.",
          }],
        };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "list_resources",
    "List all resources (applications, databases, services) across all projects",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const resources = await client.listResources();
        return { content: [{ type: "text", text: JSON.stringify(resources, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
