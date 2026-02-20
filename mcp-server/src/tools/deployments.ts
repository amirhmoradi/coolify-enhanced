import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

export function registerDeploymentTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_deployments",
    "List currently running or queued deployments",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const deployments = await client.listDeployments();
        return { content: [{ type: "text", text: JSON.stringify(deployments, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_deployment",
    "Get detailed information about a specific deployment including status and logs",
    { uuid: z.string().describe("Deployment UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const deployment = await client.getDeployment(uuid);
        return { content: [{ type: "text", text: JSON.stringify(deployment, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "cancel_deployment",
    "Cancel a running or queued deployment",
    { uuid: z.string().describe("Deployment UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const result = await client.cancelDeployment(uuid);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "list_app_deployments",
    "List deployment history for a specific application",
    { application_uuid: z.string().describe("Application UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ application_uuid }) => {
      try {
        const deployments = await client.listAppDeployments(application_uuid);
        return { content: [{ type: "text", text: JSON.stringify(deployments, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
