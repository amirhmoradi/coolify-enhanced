import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

const resourceTypeSchema = z
  .enum([
    "application",
    "service",
    "standalone-postgresql",
    "standalone-mysql",
    "standalone-mariadb",
    "standalone-mongodb",
    "standalone-redis",
    "standalone-clickhouse",
    "standalone-dragonfly",
    "standalone-keydb",
  ])
  .describe("Resource type");

export function registerNetworkTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_server_networks",
    "[Enhanced] List all managed Docker networks on a server (environment, shared, proxy, system)",
    { server_uuid: z.string().describe("Server UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ server_uuid }) => {
      try {
        const networks = await client.listServerNetworks(server_uuid);
        return { content: [{ type: "text", text: JSON.stringify(networks, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_network",
    "[Enhanced] Create a shared Docker network on a server for cross-environment communication",
    {
      server_uuid: z.string().describe("Server UUID"),
      name: z.string().describe("Network name"),
      is_internal: z.boolean().optional().describe("Internal-only network (no external access)"),
      subnet: z.string().optional().describe("Custom subnet (e.g., '172.20.0.0/16')"),
      gateway: z.string().optional().describe("Custom gateway"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async ({ server_uuid, ...data }) => {
      try {
        const network = await client.createNetwork(server_uuid, data);
        return { content: [{ type: "text", text: JSON.stringify(network, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_network",
    "[Enhanced] Get details of a managed network including Docker inspection data",
    {
      server_uuid: z.string().describe("Server UUID"),
      network_uuid: z.string().describe("Network UUID"),
    },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ server_uuid, network_uuid }) => {
      try {
        const network = await client.getNetwork(server_uuid, network_uuid);
        return { content: [{ type: "text", text: JSON.stringify(network, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_network",
    "[Enhanced] Delete a managed Docker network from a server",
    {
      server_uuid: z.string().describe("Server UUID"),
      network_uuid: z.string().describe("Network UUID"),
    },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ server_uuid, network_uuid }) => {
      try {
        await client.deleteNetwork(server_uuid, network_uuid);
        return { content: [{ type: "text", text: `Network ${network_uuid} deleted.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "sync_networks",
    "[Enhanced] Sync managed networks from Docker and reconcile resource assignments",
    { server_uuid: z.string().describe("Server UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ server_uuid }) => {
      try {
        const result = await client.syncNetworks(server_uuid);
        return { content: [{ type: "text", text: result ? JSON.stringify(result, null, 2) : `Network sync completed for server ${server_uuid}.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "migrate_proxy",
    "[Enhanced] Run proxy isolation migration: creates dedicated proxy network and connects FQDN-bearing resources",
    { server_uuid: z.string().describe("Server UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ server_uuid }) => {
      try {
        const result = await client.migrateProxy(server_uuid);
        return { content: [{ type: "text", text: result ? JSON.stringify(result, null, 2) : `Proxy migration completed for server ${server_uuid}.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "cleanup_proxy",
    "[Enhanced] Cleanup old proxy networks by disconnecting proxy from non-proxy managed networks",
    { server_uuid: z.string().describe("Server UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ server_uuid }) => {
      try {
        const result = await client.cleanupProxy(server_uuid);
        return { content: [{ type: "text", text: result ? JSON.stringify(result, null, 2) : `Proxy cleanup completed for server ${server_uuid}.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "list_resource_networks",
    "[Enhanced] List all networks a resource is attached to",
    {
      resource_type: resourceTypeSchema,
      resource_uuid: z.string().describe("Resource UUID"),
    },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ resource_type, resource_uuid }) => {
      try {
        const networks = await client.listResourceNetworks(resource_type, resource_uuid);
        return { content: [{ type: "text", text: JSON.stringify(networks, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "attach_resource_network",
    "[Enhanced] Attach a resource to a managed Docker network",
    {
      resource_type: resourceTypeSchema,
      resource_uuid: z.string().describe("Resource UUID"),
      network_uuid: z.string().describe("Network UUID to attach"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ resource_type, resource_uuid, network_uuid }) => {
      try {
        const result = await client.attachResourceNetwork(resource_type, resource_uuid, { network_uuid });
        return { content: [{ type: "text", text: result ? JSON.stringify(result, null, 2) : `Resource attached to network ${network_uuid}.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "detach_resource_network",
    "[Enhanced] Detach a resource from a managed Docker network",
    {
      resource_type: resourceTypeSchema,
      resource_uuid: z.string().describe("Resource UUID"),
      network_uuid: z.string().describe("Network UUID to detach"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ resource_type, resource_uuid, network_uuid }) => {
      try {
        await client.detachResourceNetwork(resource_type, resource_uuid, network_uuid);
        return { content: [{ type: "text", text: `Resource detached from network ${network_uuid}.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
