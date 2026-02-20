import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

export function registerServiceTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_services",
    "List all one-click services across all projects and environments",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const services = await client.listServices();
        return { content: [{ type: "text", text: JSON.stringify(services, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_service",
    "Get detailed information about a specific service including compose config and sub-containers",
    { uuid: z.string().describe("Service UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const service = await client.getService(uuid);
        return { content: [{ type: "text", text: JSON.stringify(service, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_service",
    "Create a one-click service (e.g., plausible, grafana, minio, etc.) or a custom Docker Compose service",
    {
      type: z.string().describe("Service type (e.g., 'plausible', 'grafana', 'minio', 'uptime-kuma') or use 'docker-compose' for custom compose"),
      project_uuid: z.string().describe("Project UUID"),
      server_uuid: z.string().describe("Server UUID"),
      name: z.string().optional().describe("Service name"),
      description: z.string().optional().describe("Description"),
      environment_name: z.string().optional().describe("Environment name (default: production)"),
      instant_deploy: z.boolean().optional().describe("Deploy immediately after creation"),
      docker_compose: z.string().optional().describe("Docker Compose YAML content (for custom compose type)"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async (params) => {
      try {
        const service = await client.createService(params);
        return { content: [{ type: "text", text: JSON.stringify(service, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "update_service",
    "Update service settings",
    {
      uuid: z.string().describe("Service UUID"),
      data: z.record(z.unknown()).describe("Key-value pairs to update"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, data }) => {
      try {
        const service = await client.updateService(uuid, data);
        return { content: [{ type: "text", text: JSON.stringify(service, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_service",
    "Delete a service and optionally clean up Docker resources",
    {
      uuid: z.string().describe("Service UUID"),
      delete_configurations: z.boolean().optional().describe("Delete configurations"),
      delete_volumes: z.boolean().optional().describe("Delete volumes"),
      docker_cleanup: z.boolean().optional().describe("Clean up Docker resources"),
      delete_connected_networks: z.boolean().optional().describe("Delete connected networks"),
    },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ uuid, ...opts }) => {
      try {
        await client.deleteService(uuid, opts);
        return { content: [{ type: "text", text: `Service ${uuid} deleted successfully.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "start_service",
    "Start (deploy) a service",
    { uuid: z.string().describe("Service UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const result = await client.startService(uuid);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "stop_service",
    "Stop a running service",
    { uuid: z.string().describe("Service UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const result = await client.stopService(uuid);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "restart_service",
    "Restart a service",
    { uuid: z.string().describe("Service UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const result = await client.restartService(uuid);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
