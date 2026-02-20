import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

const databaseTypeSchema = z
  .enum(["postgresql", "mysql", "mariadb", "mongodb", "redis", "clickhouse", "dragonfly", "keydb"])
  .describe("Database type");

export function registerDatabaseTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_databases",
    "List all databases across all projects and environments",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const databases = await client.listDatabases();
        return { content: [{ type: "text", text: JSON.stringify(databases, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_database",
    "Get detailed information about a specific database",
    { uuid: z.string().describe("Database UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const db = await client.getDatabase(uuid);
        return { content: [{ type: "text", text: JSON.stringify(db, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_database",
    "Create a new standalone database of the specified type",
    {
      type: databaseTypeSchema,
      project_uuid: z.string().describe("Project UUID"),
      server_uuid: z.string().describe("Server UUID"),
      name: z.string().optional().describe("Database name"),
      description: z.string().optional().describe("Description"),
      environment_name: z.string().optional().describe("Environment name (default: production)"),
      image: z.string().optional().describe("Custom Docker image"),
      is_public: z.boolean().optional().describe("Make publicly accessible"),
      public_port: z.number().optional().describe("Public port number"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async ({ type, ...data }) => {
      try {
        const db = await client.createDatabase(type, data);
        return { content: [{ type: "text", text: JSON.stringify(db, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "update_database",
    "Update database settings",
    {
      uuid: z.string().describe("Database UUID"),
      data: z.record(z.unknown()).describe("Key-value pairs to update (e.g., name, image, is_public, public_port)"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, data }) => {
      try {
        const db = await client.updateDatabase(uuid, data);
        return { content: [{ type: "text", text: JSON.stringify(db, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_database",
    "Delete a database and optionally clean up Docker resources",
    {
      uuid: z.string().describe("Database UUID"),
      delete_configurations: z.boolean().optional().describe("Delete configurations"),
      delete_volumes: z.boolean().optional().describe("Delete volumes"),
      docker_cleanup: z.boolean().optional().describe("Clean up Docker resources"),
      delete_connected_networks: z.boolean().optional().describe("Delete connected networks"),
    },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ uuid, ...opts }) => {
      try {
        await client.deleteDatabase(uuid, opts);
        return { content: [{ type: "text", text: `Database ${uuid} deleted successfully.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "start_database",
    "Start a stopped database",
    { uuid: z.string().describe("Database UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const result = await client.startDatabase(uuid);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "stop_database",
    "Stop a running database",
    { uuid: z.string().describe("Database UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const result = await client.stopDatabase(uuid);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "restart_database",
    "Restart a database (stop then start)",
    { uuid: z.string().describe("Database UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const result = await client.restartDatabase(uuid);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
