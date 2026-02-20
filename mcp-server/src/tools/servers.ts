import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

export function registerServerTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_servers",
    "List all Coolify servers with their status, IP address, and connectivity",
    {},
    {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      },
    async () => {
      try {
        const servers = await client.listServers();
        return { content: [{ type: "text", text: JSON.stringify(servers, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_server",
    "Get detailed information about a specific Coolify server including settings and proxy config",
    { uuid: z.string().describe("Server UUID") },
    {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      },
    async ({ uuid }) => {
      try {
        const result = await client.getServer(uuid);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_server_resources",
    "Get all resources (applications, databases, services) deployed on a server",
    { uuid: z.string().describe("Server UUID") },
    {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      },
    async ({ uuid }) => {
      try {
        const resources = await client.getServerResources(uuid);
        return { content: [{ type: "text", text: JSON.stringify(resources, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_server_domains",
    "Get all domains configured on a server",
    { uuid: z.string().describe("Server UUID") },
    {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      },
    async ({ uuid }) => {
      try {
        const domains = await client.getServerDomains(uuid);
        return { content: [{ type: "text", text: JSON.stringify(domains, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "validate_server",
    "Validate server connectivity and SSH access",
    { uuid: z.string().describe("Server UUID") },
    {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      },
    async ({ uuid }) => {
      try {
        const result = await client.validateServer(uuid);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_server",
    "Register a new server in Coolify",
    {
      name: z.string().describe("Server name"),
      ip: z.string().describe("Server IP address"),
      private_key_uuid: z.string().describe("UUID of the private key for SSH access"),
      description: z.string().optional().describe("Server description"),
      port: z.number().optional().describe("SSH port (default: 22)"),
      user: z.string().optional().describe("SSH user (default: root)"),
      is_build_server: z.boolean().optional().describe("Use as build server"),
      instant_validate: z.boolean().optional().describe("Validate immediately after creation"),
    },
    {
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: false,
        openWorldHint: false,
      },
    async (params) => {
      try {
        const result = await client.createServer(params);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "update_server",
    "Update settings for an existing Coolify server",
    {
      uuid: z.string().describe("Server UUID"),
      name: z.string().optional().describe("New server name"),
      description: z.string().optional().describe("New description"),
      ip: z.string().optional().describe("New IP address"),
      port: z.number().optional().describe("New SSH port"),
      user: z.string().optional().describe("New SSH user"),
    },
    {
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      },
    async ({ uuid, ...data }) => {
      try {
        const result = await client.updateServer(uuid, data);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_server",
    "Delete a server from Coolify. This removes the server registration but does not affect the actual server",
    { uuid: z.string().describe("Server UUID") },
    {
        readOnlyHint: false,
        destructiveHint: true,
        idempotentHint: false,
        openWorldHint: false,
      },
    async ({ uuid }) => {
      try {
        await client.deleteServer(uuid);
        return { content: [{ type: "text", text: `Server ${uuid} deleted successfully.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
